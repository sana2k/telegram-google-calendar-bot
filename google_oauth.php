<?php

require 'config.php';
require __DIR__ . '/vendor/autoload.php';

$client = new Google_Client();
$client->setApplicationName('Telegram Calendar Bot');
$client->setScopes([Google_Service_Calendar::CALENDAR]);
$client->setAuthConfig(GOOGLE_CREDENTIALS_FILE);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setRedirectUri(GOOGLE_REDIRECT_URI);

if (!isset($_GET['code'])) {
    $authUrl = $client->createAuthUrl();

    echo '<a href="' . htmlspecialchars($authUrl) . '">Connect Google Calendar</a>';
    exit;
}

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    echo '<pre>';
    print_r($token);
    echo '</pre>';
    exit;
}

file_put_contents(
    GOOGLE_TOKEN_FILE,
    json_encode($token, JSON_PRETTY_PRINT)
);

echo '✅ Google Calendar connected. You can close this page.';