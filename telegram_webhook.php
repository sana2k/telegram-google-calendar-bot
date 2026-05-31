<?php

require 'config.php';
require 'telegram.php';
require 'openai.php';
require 'google_calendar.php';

function ack()
{
    set_time_limit(120);
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: application/json');
    header('Content-Length: 11');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();   // Telegram gets its 200 now; we keep working
    }
}

$update = json_decode(file_get_contents('php://input'), true);

if (DEBUG_LOG) {
    file_put_contents(SECRETS_DIR . '/last_update.json', json_encode($update, JSON_PRETTY_PRINT));
}

ack();

/*
|--------------------------------------------------------------------------
| Button taps (callback_query)
|--------------------------------------------------------------------------
*/
if (isset($update['callback_query'])) {
    $cb     = $update['callback_query'];
    $chatId = $cb['message']['chat']['id'] ?? null;

    if ($chatId == OWNER_CHAT_ID) {
        handleCallback($chatId, $cb['data'] ?? '', $cb['id'], $cb['message']['message_id'] ?? null);
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
$chatId = $update['message']['chat']['id'] ?? null;
if (!$chatId) {
    exit;
}
if ($chatId != OWNER_CHAT_ID) {
    sendTelegramMessage($chatId, '❌ Unauthorized user.');
    exit;
}

/*
|--------------------------------------------------------------------------
| Resolve incoming text — typed, or transcribed from a voice note
|--------------------------------------------------------------------------
*/
$isVoice = isset($update['message']['voice']);

if ($isVoice) {
    $audioPath = downloadTelegramFile($update['message']['voice']['file_id']);
    if (!$audioPath) {
        sendTelegramMessage($chatId, '❌ Could not download the voice note.');
        exit;
    }
    $transcription = transcribeAudio($audioPath);
    @unlink($audioPath);

    if (!$transcription['success']) {
        sendTelegramMessage($chatId, "❌ Transcription failed:\n" . $transcription['error']);
        exit;
    }
    $text = trim($transcription['text']);
} else {
    $text = trim($update['message']['text'] ?? '');
}

/*
|--------------------------------------------------------------------------
| Commands
|--------------------------------------------------------------------------
*/
if ($text === '/help' || $text === '/start') {
    sendTelegramMessage($chatId, helpText());
    exit;
}
if ($text === '/cancel') {
    clearState($chatId);
    sendTelegramMessage($chatId, '❌ Cancelled.');
    exit;
}
if ($text === '/create') {
    startCreateFlow($chatId);
    exit;
}
if ($text === '/update') {
    startUpdateFlow($chatId);
    exit;
}
if ($text === '/delete') {
    startDeleteFlow($chatId);
    exit;
}
if ($text === '/today') { 
    sendAgenda($chatId, 'today'); 
    exit; 
}
if ($text === '/week')  { 
    sendAgenda($chatId, 'week');  
    exit; 
}

/*
|--------------------------------------------------------------------------
| If a guided flow is in progress, this message answers the current step
|--------------------------------------------------------------------------
*/
$state = loadState($chatId);
if ($state) {
    advanceFlow($chatId, $state, $text);
    exit;
}

/*
|--------------------------------------------------------------------------
| Otherwise: natural-language one-shot (voice or text)
|--------------------------------------------------------------------------
*/
if ($text !== '') {
    processInstruction($chatId, $text, $isVoice);
}

echo 'OK';


/* ========================================================================
 |  Conversation state (file-backed, per chat, 10-min expiry)
 ======================================================================== */

function statePath($chatId)
{
    return SECRETS_DIR . '/state/' . intval($chatId) . '.json';
}

function loadState($chatId)
{
    $p = statePath($chatId);
    if (!is_file($p)) return null;

    $s = json_decode(file_get_contents($p), true);
    if (!$s || (time() - ($s['ts'] ?? 0)) > 600) {
        @unlink($p);
        return null;
    }
    return $s;
}

function saveState($chatId, $state)
{
    $dir = SECRETS_DIR . '/state';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $state['ts'] = time();
    file_put_contents(statePath($chatId), json_encode($state));
}

function clearState($chatId)
{
    @unlink(statePath($chatId));
}


/* ========================================================================
 |  Guided /create flow
 ======================================================================== */

function startCreateFlow($chatId)
{
    saveState($chatId, ['flow' => 'create', 'step' => 'title', 'data' => []]);
    sendTelegramMessage($chatId, "📝 What's the event?\n<i>(e.g. \"Meeting with Jeff Bezos\")</i>");
}

function advanceFlow($chatId, $state, $text)
{
    $flow = $state['flow'] ?? '';

    if ($flow === 'create') {
        advanceCreate($chatId, $state, $text);
        return;
    }
    if ($flow === 'update' && ($state['step'] ?? '') === 'value') {
        applyUpdateValue($chatId, $state, $text);
        return;
    }

    // Mid-flow but a button tap is expected
    sendTelegramMessage($chatId, "👆 Please tap a button above, or send /cancel.");
}

function advanceCreate($chatId, $state, $text)
{
    switch ($state['step']) {
        case 'title':
            $state['data']['title'] = $text;
            $state['step'] = 'when';
            saveState($chatId, $state);
            sendTelegramMessage($chatId, "🕒 When?\n<i>(e.g. \"Monday 3pm\" or \"June 5 from 2 to 4pm\")</i>");
            break;

        case 'when':
            $state['data']['when'] = $text;
            $state['step'] = 'location';
            saveState($chatId, $state);
            sendTelegramMessage($chatId, "📍 Where? Type a location or tap Skip.", [
                'inline_keyboard' => [[['text' => 'Skip', 'callback_data' => 'create_skip_loc']]],
            ]);
            break;

        case 'location':
            $state['data']['location'] = $text;
            showCreatePreview($chatId, $state);
            break;

        case 'confirm':
            $e = $state['event'] ?? null;
            if (!$e) {
                clearState($chatId);
                sendTelegramMessage($chatId, "Nothing pending. Send /create to start again.");
                break;
            }
            $when = (new DateTime($e['date'] . ' ' . $e['start_time'], new DateTimeZone('Asia/Dubai')))
                ->format('D j M, g:i A');
            sendTelegramMessage($chatId,
                "You have an unconfirmed event:\n\n📝 <b>" . htmlspecialchars($e['title']) . "</b>\n🕒 {$when}"
                . "\n\nConfirm it below, or send /cancel to discard.",
                ['inline_keyboard' => [[
                    ['text' => '✅ Confirm', 'callback_data' => 'create_confirm'],
                    ['text' => '❌ Cancel',  'callback_data' => 'create_cancel'],
                ]]]);
            break;
    }
}

function showCreatePreview($chatId, $state, $messageId = null)
{
    $d = $state['data'];

    $instruction = "Create an event titled \"{$d['title']}\" on {$d['when']}"
        . (!empty($d['location']) ? " at {$d['location']}" : "");

    $res = extractEventDetails($instruction, []);
    $e   = $res['event'] ?? [];

    if (empty($res['success']) || empty($e['date']) || empty($e['start_time'])) {
        clearState($chatId);
        $msg = "❌ I couldn't work out the date from \"{$d['when']}\".\nStart again with /create.";
        if ($messageId) { editMessageText($chatId, $messageId, $msg); }
        else            { sendTelegramMessage($chatId, $msg); }
        return;
    }

    $e['title']    = $d['title'];
    $e['location'] = $d['location'] ?? '';
    $e['intent']   = 'create_event';

    $state['step']  = 'confirm';
    $state['event'] = $e;
    saveState($chatId, $state);

    $tz = new DateTimeZone('Asia/Dubai');
    if (validTime($e['start_time'] ?? '')) {
        $when = (new DateTime($e['date'] . ' ' . $e['start_time'], $tz))->format('D j M, g:i A');
    } else {
        $when = (new DateTime($e['date'], $tz))->format('D j M') . ' (all day)';
    }

    $extra = '';
    if (!empty($e['recurrence'])) $extra .= "\n🔁 Repeating";
    $conflicts = findConflicts($e);
    if (!empty($conflicts)) $extra .= "\n\n" . conflictText($conflicts, $tz);

    $preview = "Please confirm:\n\n📝 <b>" . htmlspecialchars($e['title']) . "</b>\n🕒 {$when}"
        . (!empty($e['location']) ? "\n📍 " . htmlspecialchars($e['location']) : "")
        . $extra;

    $markup = ['inline_keyboard' => [[
        ['text' => '✅ Confirm', 'callback_data' => 'create_confirm'],
        ['text' => '❌ Cancel',  'callback_data' => 'create_cancel'],
    ]]];

    if ($messageId) { editMessageText($chatId, $messageId, $preview, $markup); }
    else            { sendTelegramMessage($chatId, $preview, $markup); }
}

function startUpdateFlow($chatId) { startPickFlow($chatId, 'update'); }
function startDeleteFlow($chatId) { startPickFlow($chatId, 'delete'); }

function startPickFlow($chatId, $flow)
{
    $events = getUpcomingEvents();
    if (empty($events)) {
        sendTelegramMessage($chatId, "📭 No upcoming events in the next 30 days.");
        return;
    }

    $events = array_slice($events, 0, 15);
    $prefix = $flow === 'update' ? 'u_pick:' : 'd_pick:';

    $rows = [];
    $list = [];
    foreach ($events as $i => $e) {
        $when  = (new DateTime($e['start'], new DateTimeZone('Asia/Dubai')))->format('D j M, g:i A');
        $label = $e['title'] . ' — ' . $when;
        if (mb_strlen($label) > 60) $label = mb_substr($label, 0, 57) . '…';

        $rows[] = [['text' => $label, 'callback_data' => $prefix . $i]];
        $list[] = ['id' => $e['id'], 'title' => $e['title']];
    }

    saveState($chatId, ['flow' => $flow, 'step' => 'pick', 'events' => $list]);

    $verb = $flow === 'update' ? 'update' : 'delete';
    sendTelegramMessage($chatId, "Which event do you want to {$verb}?", ['inline_keyboard' => $rows]);
}

function applyUpdateValue($chatId, $state, $text)
{
    $id    = $state['event_id'] ?? '';
    $field = $state['field'] ?? '';

    if ($id === '') {
        clearState($chatId);
        sendTelegramMessage($chatId, "That request expired. Send /update again.");
        return;
    }

    $changes = [];

    if ($field === 'title') {
        $changes['new_title'] = $text;
    } elseif ($field === 'location') {
        $changes['new_location'] = $text;
    } elseif ($field === 'datetime') {
        $c = parseDateTimeChange($text);
        $changes['new_date']       = $c['new_date']       ?? '';
        $changes['new_start_time'] = $c['new_start_time'] ?? '';
        $changes['new_end_time']   = $c['new_end_time']   ?? '';

        if (!validDate($changes['new_date']) && !validTime($changes['new_start_time']) && !validTime($changes['new_end_time'])) {
            clearState($chatId);
            sendTelegramMessage($chatId, "❌ Couldn't read a date/time from \"{$text}\". Send /update again.");
            return;
        }
    }

    $r = updateGoogleEventById($id, $changes);
    clearState($chatId);

    sendTelegramMessage($chatId, $r['success']
        ? "✅ Updated: <b>{$r['title']}</b>\n🕒 {$r['start']}\n\n{$r['html_link']}"
        : "❌ Couldn't update it:\n{$r['error']}");
}

function handleCallback($chatId, $data, $callbackId, $messageId)
{
    answerCallback($callbackId);   // stop the button spinner
    $state = loadState($chatId);

    /* ---------- quick actions (work off the event id, no state) ---------- */
    if (strpos($data, 'qa_edit:') === 0) {
        $id    = substr($data, 8);
        $title = getGoogleEventTitle($id);
        if ($title === '') {
            editMessageText($chatId, $messageId, "That event no longer exists.");
            return;
        }
        saveState($chatId, ['flow' => 'update', 'step' => 'field', 'event_id' => $id, 'event_title' => $title]);
        $t = htmlspecialchars($title);
        editMessageText($chatId, $messageId, "✏️ What do you want to change on <b>{$t}</b>?", [
            'inline_keyboard' => [[
                ['text' => '📅 Date/Time', 'callback_data' => 'u_field:datetime'],
                ['text' => '📝 Title',     'callback_data' => 'u_field:title'],
                ['text' => '📍 Location',  'callback_data' => 'u_field:location'],
            ]],
        ]);
        return;
    }
    if (strpos($data, 'qa_del:') === 0) {
        $id    = substr($data, 7);
        $title = getGoogleEventTitle($id);
        if ($title === '') {
            editMessageText($chatId, $messageId, "That event no longer exists.");
            return;
        }
        $t = htmlspecialchars($title);
        editMessageText($chatId, $messageId, "🗑️ Delete <b>{$t}</b>?", [
            'inline_keyboard' => [[
                ['text' => '✅ Yes, delete', 'callback_data' => 'qa_delyes:' . $id],
                ['text' => '❌ No',          'callback_data' => 'qa_delno'],
            ]],
        ]);
        return;
    }
    if (strpos($data, 'qa_delyes:') === 0) {
        $id = substr($data, 10);
        $r  = deleteGoogleEventById($id);
        editMessageText($chatId, $messageId, $r['success']
            ? "🗑️ Deleted: <b>" . htmlspecialchars($r['title']) . "</b>"
            : "❌ Couldn't delete it:\n{$r['error']}");
        return;
    }
    if ($data === 'qa_delno') {
        editMessageText($chatId, $messageId, "❌ Deletion cancelled.");
        return;
    }

    /* ---------- create ---------- */
    if ($data === 'create_skip_loc') {
        if ($state && ($state['step'] ?? '') === 'location') {
            $state['data']['location'] = '';
            showCreatePreview($chatId, $state, $messageId);
        }
        return;
    }
    if ($data === 'create_cancel') {
        clearState($chatId);
        editMessageText($chatId, $messageId, "❌ Cancelled.");
        return;
    }
    if ($data === 'create_confirm') {
        if (!$state || empty($state['event'])) {
            editMessageText($chatId, $messageId, "That request expired. Start again with /create.");
            return;
        }
        $r   = createGoogleCalendarEvent($state['event']);
        $loc = $state['event']['location'] ?? '';
        clearState($chatId);
        if ($r['success']) {
            editMessageText($chatId, $messageId,
                "✅ Created: <b>{$r['title']}</b>\n🕒 {$r['when']}"
                . ($r['recurring'] ? "\n🔁 Repeating" : "")
                . ($loc !== '' ? "\n📍 {$loc}" : "")
                . "\n\n{$r['html_link']}",
                quickActions($r['id']));
        } else {
            editMessageText($chatId, $messageId, "❌ Couldn't create it:\n{$r['error']}");
        }
        return;
    }

    /* ---------- update: pick event ---------- */
    if (strpos($data, 'u_pick:') === 0) {
        $i = (int) substr($data, 7);
        if (!$state || ($state['flow'] ?? '') !== 'update' || !isset($state['events'][$i])) {
            editMessageText($chatId, $messageId, "That list expired. Send /update again.");
            return;
        }
        $state['event_id']    = $state['events'][$i]['id'];
        $state['event_title'] = $state['events'][$i]['title'];
        $state['step']        = 'field';
        saveState($chatId, $state);
        $t = htmlspecialchars($state['event_title']);
        editMessageText($chatId, $messageId, "✏️ What do you want to change on <b>{$t}</b>?", [
            'inline_keyboard' => [[
                ['text' => '📅 Date/Time', 'callback_data' => 'u_field:datetime'],
                ['text' => '📝 Title',     'callback_data' => 'u_field:title'],
                ['text' => '📍 Location',  'callback_data' => 'u_field:location'],
            ]],
        ]);
        return;
    }

    /* ---------- update: pick field ---------- */
    if (strpos($data, 'u_field:') === 0) {
        $field = substr($data, 8);
        if (!$state || ($state['flow'] ?? '') !== 'update') {
            editMessageText($chatId, $messageId, "That request expired. Send /update again.");
            return;
        }
        $state['field'] = $field;
        $state['step']  = 'value';
        saveState($chatId, $state);
        $prompts = [
            'datetime' => "🕒 Type the new date/time\n<i>(e.g. \"Wednesday 3pm\" or \"from 2 to 4pm\")</i>",
            'title'    => "📝 Type the new title",
            'location' => "📍 Type the new location",
        ];
        editMessageText($chatId, $messageId, $prompts[$field] ?? "Type the new value:");
        return;
    }

    /* ---------- delete: pick -> confirm ---------- */
    if (strpos($data, 'd_pick:') === 0) {
        $i = (int) substr($data, 7);
        if (!$state || ($state['flow'] ?? '') !== 'delete' || !isset($state['events'][$i])) {
            editMessageText($chatId, $messageId, "That list expired. Send /delete again.");
            return;
        }
        $state['event_id']    = $state['events'][$i]['id'];
        $state['event_title'] = $state['events'][$i]['title'];
        $state['step']        = 'confirm';
        saveState($chatId, $state);
        $t = htmlspecialchars($state['event_title']);
        editMessageText($chatId, $messageId, "🗑️ Delete <b>{$t}</b>?", [
            'inline_keyboard' => [[
                ['text' => '✅ Yes, delete', 'callback_data' => 'd_confirm'],
                ['text' => '❌ No',          'callback_data' => 'd_cancel'],
            ]],
        ]);
        return;
    }
    if ($data === 'd_confirm') {
        if (!$state || empty($state['event_id'])) {
            editMessageText($chatId, $messageId, "That request expired. Send /delete again.");
            return;
        }
        $r = deleteGoogleEventById($state['event_id']);
        clearState($chatId);
        editMessageText($chatId, $messageId, $r['success']
            ? "🗑️ Deleted: <b>" . htmlspecialchars($r['title']) . "</b>"
            : "❌ Couldn't delete it:\n{$r['error']}");
        return;
    }
    if ($data === 'd_cancel') {
        clearState($chatId);
        editMessageText($chatId, $messageId, "❌ Cancelled.");
        return;
    }
}

/* ========================================================================
 |  Natural-language one-shot pipeline (voice or typed)
 ======================================================================== */

function processInstruction($chatId, $text, $isVoice = false)
{
    $calendar    = getUpcomingEvents();
    $eventResult = extractEventDetails($text, $calendar);

    $heard  = $isVoice ? "\n\n📝 Heard: \"" . $text . "\"" : '';
    $markup = null;

    if (!$eventResult['success']) {
        sendTelegramMessage($chatId, "❌ Couldn't understand that." . $heard);
        return;
    }

    $event  = $eventResult['event'];
    $intent = $event['intent'] ?? 'unknown';

    if ($intent === 'create_event') {
        $conflicts = findConflicts($event);

        if (!empty($conflicts)) {
            $event['intent'] = 'create_event';
            saveState($chatId, ['flow' => 'create', 'step' => 'confirm', 'event' => $event]);

            $tz = new DateTimeZone('Asia/Dubai');
            sendTelegramMessage($chatId,
                conflictText($conflicts, $tz) . "\n\nCreate anyway?" . $heard,
                ['inline_keyboard' => [[
                    ['text' => '✅ Create anyway', 'callback_data' => 'create_confirm'],
                    ['text' => '❌ Cancel',        'callback_data' => 'create_cancel'],
                ]]]);
            return;
        }

        $r = createGoogleCalendarEvent($event);
        if ($r['success']) {
            $reply = "✅ Created: <b>{$r['title']}</b>\n🕒 {$r['when']}"
                   . ($r['recurring'] ? "\n🔁 Repeating" : "")
                   . (!empty($event['location']) ? "\n📍 {$event['location']}" : "")
                   . "\n\n{$r['html_link']}";
            $markup = quickActions($r['id']);
        } else {
            $reply = "❌ Couldn't create it:\n{$r['error']}";
        }
    } elseif ($intent === 'update_event') {
        $r = updateGoogleEventById($event['target_event_id'] ?? '', $event);
        $reply = $r['success']
            ? "✅ Updated: <b>{$r['title']}</b>\n🕒 {$r['start']}\n\n{$r['html_link']}"
            : "❌ Couldn't update it:\n{$r['error']}";

    } elseif ($intent === 'delete_event') {
        $r = deleteGoogleEventById($event['target_event_id'] ?? '');
        $reply = $r['success']
            ? "🗑️ Deleted: <b>{$r['title']}</b>"
            : "❌ Couldn't delete it:\n{$r['error']}";

    } else {
        $reply = "🤔 " . ($event['message'] ?: "I couldn't match that to an event.");
    }

    sendTelegramMessage($chatId, $reply . $heard, $markup);
}


/* ========================================================================
 |  Help
 ======================================================================== */

function helpText()
{
    return "🤖 <b>Calendar Bot</b>\n"
        . "Type /create for a guided step-by-step, or just send a voice note / text like:\n\n"
        . "• Create a meeting with Jeff Bezos on Monday at 10am\n"
        . "• Schedule standup every weekday at 9am\n"
        . "• Team huddle every Monday in June from 10am to 12pm\n"
        . "• Move the Monday meeting to Wednesday\n"
        . "• Rename the Monday 10am meeting to Remote Team Meeting\n"
        . "• Cancel the Tuesday 3pm\n\n"
        . "I'll warn you if a new event clashes with an existing one.\n\n"
        . "<b>Commands</b>\n"
        . "/create – guided event creation\n"
        . "/update – change an existing event\n"
        . "/delete – delete an event\n"
        . "/today – today's agenda\n"
        . "/week – next 7 days\n"
        . "/cancel – abort the current flow\n"
        . "/help – show this";
}

function quickActions($eventId)
{
    if (!$eventId || strlen('qa_delyes:' . $eventId) > 64) return null;
    return ['inline_keyboard' => [[
        ['text' => '✏️ Edit',   'callback_data' => 'qa_edit:' . $eventId],
        ['text' => '🗑 Delete', 'callback_data' => 'qa_del:'  . $eventId],
    ]]];
}

function fmtEventLine($e, $tz)
{
    if (strlen($e['start']) > 10) {
        $s    = (new DateTime($e['start'], $tz))->format('g:i A');
        $en   = (new DateTime($e['end'],   $tz))->format('g:i A');
        $time = "{$s} – {$en}";
    } else {
        $time = "All day";
    }
    $loc = !empty($e['location']) ? "  📍 " . htmlspecialchars($e['location']) : '';
    return "🕒 {$time}  <b>" . htmlspecialchars($e['title']) . "</b>{$loc}";
}

function conflictText($conflicts, $tz)
{
    $lines = [];
    foreach ($conflicts as $e) {
        $s = (new DateTime($e['start'], $tz))->format('g:i A');
        $lines[] = "• " . htmlspecialchars($e['title']) . " ({$s})";
    }
    return "⚠️ <b>Overlaps with:</b>\n" . implode("\n", $lines);
}

function sendAgenda($chatId, $scope)
{
    $tz    = new DateTimeZone('Asia/Dubai');
    $start = new DateTime('today', $tz);

    if ($scope === 'today') {
        $end    = (clone $start)->modify('+1 day');
        $header = "📅 <b>Today</b> — " . $start->format('D j M');
    } else {
        $end    = (clone $start)->modify('+7 days');
        $header = "📅 <b>Next 7 days</b>";
    }

    $events = getCalendarEvents($start->format('c'), $end->format('c'));

    if (empty($events)) {
        sendTelegramMessage($chatId, $header . "\n\nNothing scheduled. 🎉");
        return;
    }

    if ($scope === 'today') {
        $msg = $header . "\n";
        foreach ($events as $e) {
            $msg .= "\n" . fmtEventLine($e, $tz);
        }
    } else {
        $byDay = [];
        foreach ($events as $e) {
            $byDay[substr($e['start'], 0, 10)][] = $e;
        }
        $msg = $header . "\n";
        foreach ($byDay as $day => $list) {
            $msg .= "\n<b>" . (new DateTime($day, $tz))->format('D j M') . "</b>\n";
            foreach ($list as $e) {
                $msg .= fmtEventLine($e, $tz) . "\n";
            }
        }
    }

    sendTelegramMessage($chatId, $msg);
}