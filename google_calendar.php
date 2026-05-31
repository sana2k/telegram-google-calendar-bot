<?php

require_once __DIR__ . '/vendor/autoload.php';

function getGoogleClient()
{
    $client = new Google_Client();
    $client->setAuthConfig(GOOGLE_CREDENTIALS_FILE);
    $client->setAccessToken(json_decode(file_get_contents(GOOGLE_TOKEN_FILE), true));

    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents(GOOGLE_TOKEN_FILE, json_encode($client->getAccessToken(), JSON_PRETTY_PRINT));
        } else {
            throw new Exception('Google token expired. Please reconnect Google Calendar.');
        }
    }

    return $client;
}

function createGoogleCalendarEvent($e)
{
    if (!validDate($e['date'] ?? '')) {
        return ['success' => false, 'error' => 'I couldn\'t make out the date — please try again.'];
    }

    $tz     = new DateTimeZone('Asia/Dubai');
    $allDay = !validTime($e['start_time'] ?? '');

    $payload = [
        'summary'     => $e['title'] ?: 'Untitled',
        'location'    => $e['location'] ?? '',
        'description' => $e['description'] ?? '',
    ];

    if ($allDay) {
        $start = new DateTime($e['date'], $tz);
        $end   = (clone $start)->modify('+1 day');           // Google end date is exclusive
        $payload['start'] = ['date' => $start->format('Y-m-d')];
        $payload['end']   = ['date' => $end->format('Y-m-d')];
        $whenLabel = $start->format('D j M') . ' (all day)';
    } else {
        $start = new DateTime($e['date'] . ' ' . $e['start_time'], $tz);
        $end   = validTime($e['end_time'] ?? '')
            ? new DateTime($e['date'] . ' ' . $e['end_time'], $tz)
            : (clone $start)->modify('+60 minutes');
        $payload['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Dubai'];
        $payload['end']   = ['dateTime' => $end->format('Y-m-d\TH:i:s'),   'timeZone' => 'Asia/Dubai'];
        $whenLabel = $start->format('D j M, g:i A') . ' – ' . $end->format('g:i A');
    }

    $recurring = false;
    if (!empty($e['recurrence'])) {
        $rule = trim($e['recurrence']);
        if (stripos($rule, 'RRULE') !== 0) $rule = 'RRULE:' . $rule;
        $payload['recurrence'] = [$rule];
        $recurring = true;
    }

    try {
        $service = new Google_Service_Calendar(getGoogleClient());
        $created = $service->events->insert(GOOGLE_CALENDAR_ID, new Google_Service_Calendar_Event($payload));

        return [
            'success'   => true,
            'id'        => $created->id,
            'html_link' => $created->htmlLink,
            'title'     => $created->summary,
            'when'      => $whenLabel,
            'recurring' => $recurring,
        ];
    } catch (Exception $e2) {
        return ['success' => false, 'error' => $e2->getMessage()];
    }
}

function validDate($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
function validTime($t) { return is_string($t) && preg_match('/^\d{1,2}:\d{2}$/', $t); }

function getGoogleEventTitle($id)
{
    try {
        $service = new Google_Service_Calendar(getGoogleClient());
        return $service->events->get(GOOGLE_CALENDAR_ID, $id)->summary ?? '(no title)';
    } catch (Exception $e) {
        return '';
    }
}

function getCalendarEvents($timeMinIso, $timeMaxIso)
{
    try {
        $service = new Google_Service_Calendar(getGoogleClient());
        $events  = $service->events->listEvents(GOOGLE_CALENDAR_ID, [
            'timeMin'      => $timeMinIso,
            'timeMax'      => $timeMaxIso,
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'maxResults'   => 50,
        ]);

        $out = [];
        foreach ($events->getItems() as $e) {
            $out[] = [
                'id'       => $e->id,
                'title'    => $e->summary ?? '(no title)',
                'start'    => $e->start->dateTime ?? $e->start->date,
                'end'      => $e->end->dateTime   ?? $e->end->date,
                'location' => $e->location ?? '',
            ];
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

function getUpcomingEvents()
{
    return getCalendarEvents(date('c'), date('c', strtotime('+30 days')));
}

function updateGoogleEventById($id, array $c)
{
    if (empty($id)) return ['success' => false, 'error' => 'No matching event found.'];

    try {
        $service  = new Google_Service_Calendar(getGoogleClient());
        $event    = $service->events->get(GOOGLE_CALENDAR_ID, $id);
        $tz       = new DateTimeZone('Asia/Dubai');
        $oldStart = new DateTime($event->start->dateTime ?? $event->start->date, $tz);
        $oldEnd   = new DateTime($event->end->dateTime   ?? $event->end->date, $tz);
        $duration = $oldEnd->getTimestamp() - $oldStart->getTimestamp();

        $date  = validDate($c['new_date'] ?? '')       ? $c['new_date']       : $oldStart->format('Y-m-d');
        $stime = validTime($c['new_start_time'] ?? '') ? $c['new_start_time'] : $oldStart->format('H:i');

        $newStart = new DateTime("$date $stime", $tz);
        $newEnd   = validTime($c['new_end_time'] ?? '')
            ? new DateTime("$date " . $c['new_end_time'], $tz)
            : (clone $newStart)->modify("+{$duration} seconds");

        if (!empty($c['new_title']))    $event->setSummary($c['new_title']);
        if (!empty($c['new_location'])) $event->setLocation($c['new_location']);

        $event->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $newStart->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Dubai',
        ]));
        $event->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $newEnd->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Dubai',
        ]));

        $updated = $service->events->update(GOOGLE_CALENDAR_ID, $id, $event);
        return [
            'success'   => true,
            'title'     => $updated->summary,
            'start'     => $newStart->format('D j M, g:i A'),
            'html_link' => $updated->htmlLink,
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deleteGoogleEventById($id)
{
    if (empty($id)) return ['success' => false, 'error' => 'No matching event found.'];
    try {
        $service = new Google_Service_Calendar(getGoogleClient());
        $title   = $service->events->get(GOOGLE_CALENDAR_ID, $id)->summary;
        $service->events->delete(GOOGLE_CALENDAR_ID, $id);
        return ['success' => true, 'title' => $title];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function findConflicts($e)
{
    if (!validDate($e['date'] ?? '') || !validTime($e['start_time'] ?? '')) return [];

    $tz    = new DateTimeZone('Asia/Dubai');
    $start = new DateTime($e['date'] . ' ' . $e['start_time'], $tz);
    $end   = validTime($e['end_time'] ?? '')
        ? new DateTime($e['date'] . ' ' . $e['end_time'], $tz)
        : (clone $start)->modify('+60 minutes');

    $dayStart = (clone $start); $dayStart->setTime(0, 0, 0);
    $dayEnd   = (clone $start); $dayEnd->setTime(23, 59, 59);

    $conflicts = [];
    foreach (getCalendarEvents($dayStart->format('c'), $dayEnd->format('c')) as $x) {
        if (strlen($x['start']) <= 10) continue;   // skip all-day events
        $xs = new DateTime($x['start'], $tz);
        $xe = new DateTime($x['end'], $tz);
        if ($start < $xe && $end > $xs) {           // overlap
            $conflicts[] = $x;
        }
    }
    return $conflicts;
}