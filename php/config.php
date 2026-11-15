<?php
// Intimora PHP Backend — Configuration (ALL VALUES HARDCODED)
// Nothing to set — just upload and go!

// Safe against being included twice (require vs require_once)
if (defined('TG_API_ID')) {
    return;
}

// === Telegram MTProto ===
define('TG_API_ID', '37144075');
define('TG_API_HASH', '57a11adf8679a2e7a2b6184ed7bb9c70');
define('TELEGRAM_SESSION', '1BQANOTEuMTA4LjU2LjExNwG7xI2giu7fxyG7MsovMdZ20Gtnox2LmhxA8ZmmOtQKw7imL0mBZ4e2bG7Dd3a0s3bqWYVqLzizh7e2kWCFaV8pT5HPR5wEOuPNfxLLCL3Gjsiw/qNRV8DiPMmXWEGMoncujjEz0isNOucrrJdk9ZUyB0EFGHiX4xCyiAs9WfjJ5U8zigz847ET50CsEQS2EmVOYxhkvyyWN9st8uEQ42awadLj6RgDyetXpXuZi/ajSro1ROxAmQriNx+N42e6xhy+dvHFcAPv4BSFGgA3UP3UMTwOR7ZNYNIFX6gUpqmgnnWfcPF0Y3vCLAdcYRPArrsVjCzSprDsGCBVHyTI+Wl97w==');

// === Telegram Channels ===
define('TG_CHANNEL_VIDEO', '-1003756299546');
define('TG_CHANNEL_THUMB', '-1004360811639');
define('TG_CHANNEL_META', '-1003777974779');
define('TG_CHANNEL_USERS', '-1004368963146');
define('TG_CHANNEL_REFERRALS', '-1004451213988');

// === MySQL (EDIT THESE 2 VALUES ONLY) ===
// Get from: InfinityFree Dashboard → MySQL Databases
define('DB_HOST', 'localhost');
define('DB_NAME', 'intimora');
define('DB_USER', 'if0_43413402');
define('DB_PASS', 'jGuhBV4XZ5');

// === Firebase ===
define('FIREBASE_API_KEY', 'AIzaSyD91f9kLylYXZnZjPSZOfjk1HFJ0Y-v7Yo');
define('FIREBASE_AUTH_DOMAIN', 'intimora-app.firebaseapp.com');

// === Admin ===
define('ADMIN_API_KEY', 'Prashant');

// === Site ===
define('SITE_URL', 'https://intimora-api2.site.je');

// === Cache ===
// NO mkdir() — InfinityFree only allows directory creation in htdocs
// Use system temp dir which already exists
$cacheDir = sys_get_temp_dir() . '/intimora_cache';
if (!is_dir($cacheDir)) {
    $cacheDir = sys_get_temp_dir();
}

// === FFmpeg (InfinityFree does NOT have ffmpeg — will be skipped) ===
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('FFPROBE_PATH', '/usr/bin/ffprobe');
