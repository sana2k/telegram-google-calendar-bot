<?php

function telegramApi($method, $data = [])
{
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/" . $method;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function sendTelegramMessage($chatId, $text, $replyMarkup = null)
{
    $data = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    return telegramApi('sendMessage', $data);
}

function answerCallback($callbackId, $text = '')
{
    return telegramApi('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
    ]);
}

function downloadTelegramFile($fileId)
{
    $fileInfo = telegramApi('getFile', ['file_id' => $fileId]);

    if (empty($fileInfo['result']['file_path'])) {
        return false;
    }

    $downloadUrl = "https://api.telegram.org/file/bot" . TELEGRAM_BOT_TOKEN
                 . "/" . $fileInfo['result']['file_path'];

    if (!is_dir(AUDIO_DIR)) {
        @mkdir(AUDIO_DIR, 0755, true);
    }

    $audio = @file_get_contents($downloadUrl);
    if ($audio === false) {
        return false;
    }

    $localFile = AUDIO_DIR . '/' . uniqid('voice_', true) . '.ogg';
    file_put_contents($localFile, $audio);

    return $localFile;
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = null)
{
    if ($replyMarkup === null) {
        $replyMarkup = ['inline_keyboard' => []];   // clears any existing buttons
    }
    return telegramApi('editMessageText', [
        'chat_id'      => $chatId,
        'message_id'   => $messageId,
        'text'         => $text,
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode($replyMarkup),
    ]);
}