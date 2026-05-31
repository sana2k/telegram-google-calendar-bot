<?php

function transcribeAudio($filePath)
{
    $url = 'https://api.openai.com/v1/audio/transcriptions';

    $postFields = [
        'model' => 'gpt-4o-mini-transcribe',
        'file' => new CURLFile($filePath),
        'response_format' => 'json',
        'prompt'          => 'Calendar voice command. Likely verbs: create, schedule, add, move, reschedule, rename, change, update, cancel, delete. Usually mentions a meeting or event, a day, and a time, and sometimes a location',
    ];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'success' => false,
            'error' => $error
        ];
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['text'])) {
        return [
            'success' => true,
            'text' => $data['text']
        ];
    }

    return [
        'success' => false,
        'error' => $response
    ];
}


function extractEventDetails($text, array $calendar = [])
{
    $today   = date('Y-m-d');
    $dayName = date('l');

    $system = '
You convert a user instruction into a calendar action.
Current date: ' . $today . ' (' . $dayName . '). Timezone: Asia/Dubai.

You are given the user\'s UPCOMING EVENTS as JSON (id, title, start, end, location).

How to match an event the user refers to:
1. Filter events to the DAY mentioned (resolve weekdays from the current date).
2. If exactly ONE event is on that day, that IS the match — even if the spoken
   time or title differs (titles are often wrong or mis-heard).
3. If MULTIPLE events are on that day, pick the one whose start time is closest
   to the time said. Treat bare "o\'clock" times as daytime: "3 o\'clock" = 15:00,
   "10 o\'clock" = 10:00, unless the user clearly says morning/evening.
4. Return its "id" as target_event_id. Never invent ids.
5. Use intent="unknown" only if NO event is on the referenced day or the request
   is too vague. In "message", name any event you DID find on that day.

Intent:
- create/schedule/add -> create_event
- move/reschedule/change/rename/update -> update_event
- delete/cancel/remove -> delete_event
- If the verb is unclear or mis-transcribed but the user clearly points at an
  existing event and gives a change, assume update_event.
- If the message just names an event/activity (optionally with a date, time, or
  place), has NO edit/delete verb, and does NOT refer to an event already in the
  list, treat it as create_event.
  "Friends wedding on June 2", "Dentist Thursday 9am", "Lunch with Sara tomorrow"
  -> create_event.

All-day events:
- If a DATE is given but NO time, leave start_time and end_time as "". Do not invent
  a time — empty time means an all-day event.
  "Friends wedding on June 2" -> date="2026-06-02", start_time="", end_time="".

Recurrence (create only):
- A recurring event MUST still set "date" to the FIRST occurrence date (resolved
  from the current date), plus start_time/end_time as normal. Never leave date empty.
- Set "recurrence" to a Google RRULE starting with "RRULE:". DTSTART is timed in
  Asia/Dubai, so any UNTIL must be UTC with a trailing Z.
  Patterns:
    every Monday              -> "RRULE:FREQ=WEEKLY;BYDAY=MO"
    every weekday             -> "RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR"
    daily                     -> "RRULE:FREQ=DAILY"
    every month               -> "RRULE:FREQ=MONTHLY"
  Bounded ranges:
    "for 4 weeks"             -> append ";COUNT=4"
    "in June" / "this month"  -> append ";UNTIL=YYYYMMDDT235959Z" (last day of span, UTC)
  Worked example — "every Monday in June from 10am to 12noon", first Monday 2026-06-01:
    date="2026-06-01", start_time="10:00", end_time="12:00",
    recurrence="RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20260630T235959Z"
- If it does not repeat, recurrence = "".

Other rules:
- "from X to Y" means start_time=X and end_time=Y (X is the start).
- For update, fill only the new_* fields the user changed; leave the rest "".
- start_time/end_time as HH:MM (24h). Dates as YYYY-MM-DD.

Return ONLY JSON, no markdown:
{
  "intent": "create_event|update_event|delete_event|unknown",
  "message": "",
  "title": "", "date": "", "start_time": "", "end_time": "", "location": "", "description": "", "recurrence": "",
  "target_event_id": "",
  "new_title": "", "new_date": "", "new_start_time": "", "new_end_time": "", "new_location": ""
}

UPCOMING EVENTS:
' . json_encode($calendar, JSON_PRETTY_PRINT);

    $payload = [
        'model'           => 'gpt-4o-mini',
        'temperature'     => 0,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $text],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) { $err = curl_error($ch); curl_close($ch); return ['success' => false, 'error' => $err]; }
    curl_close($ch);

    $content = json_decode($response, true)['choices'][0]['message']['content'] ?? '';
    $event   = json_decode(trim($content), true);

    return $event
        ? ['success' => true, 'event' => $event]
        : ['success' => false, 'error' => $content ?: $response];
}

function parseDateTimeChange($text)
{
    $today   = date('Y-m-d');
    $dayName = date('l');

    $system = "Extract ONLY what the user explicitly states about a new date/time.
Current date: {$today} ({$dayName}). Timezone: Asia/Dubai.
- If they give a day or date -> new_date as YYYY-MM-DD, else \"\".
- If they give a start time -> new_start_time as HH:MM (24h), else \"\".
- \"from X to Y\" -> new_start_time=X, new_end_time=Y.
- If they give an end time -> new_end_time, else \"\".
- \"3 o'clock\" means 15:00 unless morning is stated.
Never fill a field the user did not mention.
Return ONLY JSON: {\"new_date\":\"\",\"new_start_time\":\"\",\"new_end_time\":\"\"}";

    $payload = [
        'model'           => 'gpt-4o-mini',
        'temperature'     => 0,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $text],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $content = json_decode($response, true)['choices'][0]['message']['content'] ?? '{}';
    return json_decode($content, true) ?: ['new_date' => '', 'new_start_time' => '', 'new_end_time' => ''];
}