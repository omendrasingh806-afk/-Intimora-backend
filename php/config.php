<?php
// Intimora PHP Backend — Configuration
//
// These values are NOT committed: this repository is public, and the Telegram
// session string / DB password / keys below grant full access to the account.
// Fill them in locally (or on the server) after copying this folder.

if (defined('TG_API_ID')) {
    return;
}

// === Telegram MTProto ===
define('TG_API_ID', '');
define('TG_API_HASH', '');
// Telethon / GramJS string session of the account that reads the channels
define('TELEGRAM_SESSION', '');

// === Telegram Channels ===
define('TG_CHANNEL_VIDEO', '');
define('TG_CHANNEL_THUMB', '');
define('TG_CHANNEL_META', '');
define('TG_CHANNEL_USERS', '');
define('TG_CHANNEL_REFERRALS', '');

// === MySQL (not required by the current Telegram-based storage) ===
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// === Firebase ===
define('FIREBASE_API_KEY', '');
define('FIREBASE_AUTH_DOMAIN', '');

// === Admin ===
define('ADMIN_API_KEY', '');

// === Site ===
define('SITE_URL', '');

// === Cache ===
// NO mkdir() — InfinityFree only allows directory creation in htdocs
$cacheDir = sys_get_temp_dir() . '/intimora_cache';
if (!is_dir($cacheDir)) {
    $cacheDir = sys_get_temp_dir();
}

// === FFmpeg (InfinityFree does NOT have ffmpeg — will be skipped) ===
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('FFPROBE_PATH', '/usr/bin/ffprobe');
