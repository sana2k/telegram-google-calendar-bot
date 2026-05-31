<?php
define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN');
define('OWNER_CHAT_ID', 0);
define('OPENAI_API_KEY', 'YOUR_OPENAI_KEY');
define('GOOGLE_REDIRECT_URI', 'https://yourdomain.com/path/to/google_oauth.php');
define('GOOGLE_CALENDAR_ID', 'primary');
define('SECRETS_DIR', '/var/www/secrets/calendar-bot');
define('AUDIO_DIR',   SECRETS_DIR . '/audio');
define('DEBUG_LOG',   false);
define('GOOGLE_CREDENTIALS_FILE', SECRETS_DIR . '/credentials.json');
define('GOOGLE_TOKEN_FILE',       SECRETS_DIR . '/token.json');
