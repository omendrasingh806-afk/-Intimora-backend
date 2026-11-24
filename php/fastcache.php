<?php
// Intimora PHP Backend — tiny file cache
//
// Every request on this hosting pays ~200 ms of network/startup cost, and the
// Telegram MTProto round trips on top of it. This keeps the expensive parts
// (channel history, thumbnails, moov headers, Firebase token lookups) in the
// system temp dir so repeat requests are served from disk.
//
// InfinityFree blocks mkdir() outside htdocs on some plans, so the directory is
// attempted once and we fall back to the temp dir itself.

if (!defined('INTIMORA_FASTCACHE')) {
define('INTIMORA_FASTCACHE', true);

function fc_dir() {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $base = sys_get_temp_dir() . '/intimora_cache';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    if (is_dir($base) && is_writable($base)) {
        return $dir = $base;
    }
    return $dir = sys_get_temp_dir();
}

function fc_path($key) {
    return fc_dir() . '/im_' . md5($key) . '.fc';
}

// Existence is checked with is_file() first: on this hosting MadelineProto's
// error handler turns even an @-suppressed filemtime() warning into an
// exception, so a cache miss must not raise one at all.
function fc_mtime($file) {
    if (!is_file($file)) {
        return false;
    }
    clearstatcache(true, $file);
    return @filemtime($file);
}

// Serialized value with a TTL in seconds.
function fc_get($key, $ttl) {
    $file = fc_path($key);
    $mtime = fc_mtime($file);
    if (!$mtime || (time() - $mtime) > $ttl) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $value = @unserialize($raw);
    return $value === false ? null : $value;
}

// Atomic write: a half-written file must never be read by another request.
function fc_write($key, $bytes) {
    $file = fc_path($key);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        @unlink($tmp);
        @file_put_contents($file, $bytes, LOCK_EX);
        return;
    }
    if (!@rename($tmp, $file)) {
        @unlink($file);
        @rename($tmp, $file);
    }
}

// Age of a cache entry in seconds, or null when it does not exist.
function fc_age($key) {
    $mtime = fc_mtime(fc_path($key));
    return $mtime ? max(0, time() - $mtime) : null;
}

// Value ignoring the TTL — used to serve stale data and to refresh incrementally.
function fc_peek($key) {
    $file = fc_path($key);
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $value = @unserialize($raw);
    return $value === false ? null : $value;
}

function fc_set($key, $value) {
    fc_write($key, serialize($value));
}

function fc_forget($key) {
    @unlink(fc_path($key));
}

// Path of a fresh binary cache file (thumbnails, mp4 header chunk), or null.
function fc_file($key, $ttl) {
    $file = fc_path($key);
    $mtime = fc_mtime($file);
    if (!$mtime || (time() - $mtime) > $ttl) {
        return null;
    }
    return $file;
}

function fc_put($key, $bytes) {
    fc_write($key, $bytes);
}

}
