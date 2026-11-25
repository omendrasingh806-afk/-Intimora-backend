<?php
// Intimora — Premium engine (referral based)
//
// Source of truth is the Telegram "Premium Ledger" channel: append-only JSON
// messages, never edited. MySQL is not used for premium, so both PHP accounts
// and the Node/Vercel backend read exactly the same ledger.
//
// Ledger records (one JSON message each):
//   {"t":"prem_period","id":"pp_...","uid":"...","source":"referral",
//    "hours":5,"seconds":18000,"refsUsed":["REF-A","REF-B"],"amountPaise":0,
//    "startedAt":1740000000,"endsAt":1740018000,"at":1740000000}
//   {"t":"prem_adjust","id":"pa_...","uid":"...","seconds":-18000,"note":"...","at":...}
//
// Rules:
//   * 1 unused referral = 2.5 hours (9000 s). Redeem 1 → 2.5h, 2 → 5h, 4 → 10h.
//   * A referred user can be spent only once (refsUsed is the idempotency key).
//   * Premium always extends from max(now, current end) — no time is wasted.
//   * Stream links are signed but never time out: the same video always gets
//     the same link. What the link may play still follows the account — a
//     full-video link carries the premium end time and stops at that moment.

require_once __DIR__ . '/config.php';

if (!defined('PREMIUM_SECONDS_PER_REF')) {
    define('PREMIUM_SECONDS_PER_REF', 9000); // 2 hours 30 minutes
}
if (!defined('PREMIUM_LEDGER_TTL')) {
    define('PREMIUM_LEDGER_TTL', 60); // in-request/disk read cache
}

// === Ledger reading =========================================================

function premium_ledger($fresh = false) {
    static $cache = null;
    static $at = 0;

    if (!$fresh && $cache !== null && (time() - $at) < PREMIUM_LEDGER_TTL) {
        return $cache;
    }

    // Disk copy: the ledger is appended to, never edited, so a minute-old copy
    // is safe and saves a full premium-channel read on every status call.
    if (!$fresh && function_exists('fc_get')) {
        $disk = fc_get('prem_ledger', PREMIUM_LEDGER_TTL);
        if (is_array($disk)) {
            $cache = $disk;
            $at = time();
            return $cache;
        }
    }

    $entries = [];
    $messages = store_read_messages(TG_CHANNEL_PREMIUM);
    foreach ($messages as $msg) {
        $text = $msg['message'] ?? '';
        if (!is_string($text) || $text === '' || $text[0] !== '{') {
            continue;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            continue;
        }
        $t = (string) ($data['t'] ?? '');
        if ($t === 'prem_period' || $t === 'prem_adjust') {
            $entries[] = $data;
        }
    }

    $cache = $entries;
    $at = time();
    if (function_exists('fc_set')) {
        fc_set('prem_ledger', $entries);
    }
    return $cache;
}

function premium_ledger_write($entry) {
    $entry['at'] = $entry['at'] ?? time();
    tg_send_message(TG_CHANNEL_PREMIUM, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (function_exists('fc_forget')) {
        fc_forget('prem_ledger');
        if ((string) ($entry['uid'] ?? '') !== '') {
            fc_forget('prem_until_' . sha1((string) $entry['uid']));
        }
    }
    premium_ledger(true); // refresh the read cache
    return $entry;
}

// === State ==================================================================

// End timestamp of the user's premium (0 = never activated).
function premium_until($uid, $fresh = false) {
    $uid = (string) $uid;
    $until = 0;
    $adjust = 0;

    foreach (premium_ledger($fresh) as $e) {
        if ((string) ($e['uid'] ?? '') !== $uid) {
            continue;
        }
        if (($e['t'] ?? '') === 'prem_period') {
            $until = max($until, (int) ($e['endsAt'] ?? 0));
        } elseif (($e['t'] ?? '') === 'prem_adjust') {
            $adjust += (int) ($e['seconds'] ?? 0);
        }
    }

    return max(0, $until + $adjust);
}

function premium_is_active($uid, $at = null) {
    $uid = (string) $uid;
    if ($uid === '') {
        return false;
    }
    $at = $at ?? time();

    // The stream route asks this on every range request, so the ledger answer
    // is kept for a minute per user: seeking must not read Telegram each time.
    if (function_exists('fc_get')) {
        $cached = fc_get('prem_until_' . sha1($uid), 60);
        if (is_int($cached)) {
            return $cached > $at;
        }
    }

    $until = premium_until($uid);
    if (function_exists('fc_set')) {
        fc_set('prem_until_' . sha1($uid), $until);
    }
    return $until > $at;
}

// Which of the user's referrals are still unspent.
function premium_referral_slots($uid) {
    $user = store_find_user_by_uid((string) $uid);
    if (!$user) {
        return ['total' => 0, 'used' => 0, 'available' => [], 'myCode' => ''];
    }

    $myCode = store_user_referral_code($user);
    if ($myCode === '') {
        return ['total' => 0, 'used' => 0, 'available' => [], 'myCode' => ''];
    }

    $mine = [];
    foreach (store_read_referrals() as $r) {
        if ((string) ($r['ownerCode'] ?? '') === $myCode) {
            $code = (string) ($r['referredCode'] ?? '');
            if ($code !== '' && !isset($mine[$code])) {
                $mine[$code] = (int) ($r['timestamp'] ?? 0);
            }
        }
    }
    asort($mine);

    // Anything already listed in a period's refsUsed is spent forever.
    $used = [];
    foreach (premium_ledger() as $e) {
        if ((string) ($e['uid'] ?? '') !== (string) $uid) {
            continue;
        }
        if (($e['t'] ?? '') !== 'prem_period') {
            continue;
        }
        foreach ((array) ($e['refsUsed'] ?? []) as $code) {
            $used[(string) $code] = true;
        }
    }

    $available = [];
    foreach ($mine as $code => $ts) {
        if (!isset($used[$code])) {
            $available[] = $code;
        }
    }

    return [
        'total' => count($mine),
        'used' => count($mine) - count($available),
        'available' => $available,
        'myCode' => $myCode,
    ];
}

// Live status bypasses the ledger TTL. A caller that just refreshed the ledger
// after a write may reuse that snapshot. The countdown uses the current clock.
function premium_status($uid, $fresh = true) {
    $until = (int) premium_until((string) $uid, $fresh);
    if ($fresh && (string) $uid !== '' && function_exists('fc_set')) {
        // Keep stream/list gating on this host in step with the live answer.
        fc_set('prem_until_' . sha1((string) $uid), $until);
    }
    $slots = premium_referral_slots($uid);
    $now = time();
    $active = $until > $now;

    return [
        'active' => $active,
        'until' => $active ? $until : 0,
        'serverNow' => $now,
        'remainingSeconds' => $active ? ($until - $now) : 0,
        'referrals' => [
            'total' => $slots['total'],
            'used' => $slots['used'],
            'available' => count($slots['available']),
            'availableCodes' => $slots['available'],
            'secondsEach' => PREMIUM_SECONDS_PER_REF,
            'hoursEach' => PREMIUM_SECONDS_PER_REF / 3600,
        ],
        'referralCode' => $slots['myCode'],
    ];
}

// Full history: every period, plus totals by source.
function premium_history($uid) {
    $uid = (string) $uid;
    $periods = [];
    $totalSeconds = 0;
    $totalPaise = 0;
    $bySource = [];

    foreach (premium_ledger() as $e) {
        if ((string) ($e['uid'] ?? '') !== $uid) {
            continue;
        }
        $t = (string) ($e['t'] ?? '');

        if ($t === 'prem_period') {
            $seconds = (int) ($e['seconds'] ?? 0);
            $periods[] = [
                'id' => (string) ($e['id'] ?? ''),
                'source' => (string) ($e['source'] ?? 'referral'),
                'hours' => (float) ($e['hours'] ?? 0),
                'seconds' => $seconds,
                'refsUsed' => array_values((array) ($e['refsUsed'] ?? [])),
                'amountPaise' => (int) ($e['amountPaise'] ?? 0),
                'startedAt' => (int) ($e['startedAt'] ?? 0),
                'endsAt' => (int) ($e['endsAt'] ?? 0),
                'at' => (int) ($e['at'] ?? 0),
            ];
            if ($seconds > 0) {
                $totalSeconds += $seconds;
            }
            $totalPaise += (int) ($e['amountPaise'] ?? 0);
            $src = (string) ($e['source'] ?? 'referral');
            if (!isset($bySource[$src])) {
                $bySource[$src] = ['periods' => 0, 'seconds' => 0, 'amountPaise' => 0];
            }
            $bySource[$src]['periods']++;
            $bySource[$src]['seconds'] += $seconds;
            $bySource[$src]['amountPaise'] += (int) ($e['amountPaise'] ?? 0);
        } elseif ($t === 'prem_adjust') {
            $seconds = (int) ($e['seconds'] ?? 0);
            $periods[] = [
                'id' => (string) ($e['id'] ?? ''),
                'source' => 'adjustment',
                'hours' => round($seconds / 3600, 2),
                'seconds' => $seconds,
                'refsUsed' => [],
                'amountPaise' => 0,
                'startedAt' => 0,
                'endsAt' => 0,
                'note' => (string) ($e['note'] ?? ''),
                'at' => (int) ($e['at'] ?? 0),
            ];
            $totalSeconds += $seconds;
        }
    }

    usort($periods, function ($a, $b) {
        return ($b['at'] ?? 0) <=> ($a['at'] ?? 0);
    });

    return [
        'periods' => $periods,
        'total' => [
            'seconds' => $totalSeconds,
            'hours' => round($totalSeconds / 3600, 2),
            'amountPaise' => $totalPaise,
            'amountRupees' => round($totalPaise / 100, 2),
            'bySource' => $bySource,
        ],
    ];
}

// === Activation =============================================================

// Spend $count unspent referrals and extend premium by count * 2.5 h.
function premium_redeem_referrals($uid, $count) {
    $uid = (string) $uid;
    $count = (int) $count;

    if ($count < 1) {
        return ['ok' => false, 'error' => 'Kam se kam 1 referral chahiye'];
    }

    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found', 'code' => 404];
    }

    // Read the ledger fresh: a stale copy could spend the same referral twice.
    $slots = premium_referral_slots($uid);
    $available = $slots['available'];

    if (!$available) {
        return ['ok' => false, 'error' => 'Koi referral available nahi hai'];
    }
    if ($count > count($available)) {
        return ['ok' => false, 'error' => 'Sirf ' . count($available) . ' referral available hain'];
    }

    $chosen = array_slice($available, 0, $count);
    $seconds = $count * PREMIUM_SECONDS_PER_REF;

    $now = time();
    $start = max($now, premium_until($uid, true));
    $end = $start + $seconds;

    $entry = premium_ledger_write([
        't' => 'prem_period',
        'id' => 'pp_' . bin2hex(random_bytes(6)),
        'uid' => $uid,
        'source' => 'referral',
        'hours' => $seconds / 3600,
        'seconds' => $seconds,
        'refsUsed' => $chosen,
        'amountPaise' => 0,
        'startedAt' => $start,
        'endsAt' => $end,
    ]);

    return [
        'ok' => true,
        'entry' => $entry,
        'status' => premium_status($uid, false),
    ];
}

// Admin grant (used when a payment is settled by hand, or to correct a mistake).
function premium_grant($uid, $hours, $source = 'admin', $amountPaise = 0, $note = '') {
    $uid = (string) $uid;
    $seconds = (int) round(((float) $hours) * 3600);
    if ($uid === '' || $seconds === 0) {
        return ['ok' => false, 'error' => 'uid and hours required'];
    }

    $now = time();
    $start = max($now, premium_until($uid, true));
    $end = max($start, $start + $seconds);

    $entry = premium_ledger_write([
        't' => 'prem_period',
        'id' => 'pp_' . bin2hex(random_bytes(6)),
        'uid' => $uid,
        'source' => $source,
        'hours' => $seconds / 3600,
        'seconds' => $seconds,
        'refsUsed' => [],
        'amountPaise' => (int) $amountPaise,
        'note' => $note,
        'startedAt' => $start,
        'endsAt' => $end,
    ]);

    return ['ok' => true, 'entry' => $entry, 'status' => premium_status($uid, false)];
}

// === Signed stream links ====================================================
// A stream URL carries ?t=<payload>.<hmac>. The payload holds the user, the
// video, the kind and the premium end time. There is no issue/expiry stamp, so
// a link never expires on its own; a full video only plays while the account's
// premium (the end time in the link) is still running.

function premium_b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function premium_b64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function premium_token($uid, $videoId, $kind = 'video') {
    $payload = [
        'u' => (string) $uid,
        'v' => (string) $videoId,
        'k' => $kind === 'trailer' ? 'trailer' : 'video',
        'p' => premium_until((string) $uid),
    ];
    $body = premium_b64url(json_encode($payload));
    $sig = hash_hmac('sha256', $body, PREMIUM_SECRET);
    return $body . '.' . $sig;
}

function premium_token_check($token, $videoId, $kind) {
    if (!is_string($token) || !str_contains($token, '.')) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expect = hash_hmac('sha256', $body, PREMIUM_SECRET);
    if (!hash_equals($expect, $sig)) {
        return null;
    }
    $payload = json_decode(premium_b64url_decode($body), true);
    if (!is_array($payload)) {
        return null;
    }
    if ((string) ($payload['v'] ?? '') !== (string) $videoId) {
        return null;
    }
    if ((string) ($payload['k'] ?? '') !== ($kind === 'trailer' ? 'trailer' : 'video')) {
        return null;
    }
    // No expiry check: the link is permanent. For a full video the stream route
    // asks the ledger whether this uid's premium is running right now; the `p`
    // field is only informational and never grants access by itself.
    return $payload;
}

// === Video shaping ==========================================================

function premium_video_flag($video) {
    return (($video['premium'] ?? false) === true || ($video['premium'] ?? '') === 'true');
}

function premium_has_trailer($video) {
    $t = $video['trailer'] ?? null;
    return is_array($t) && (string) ($t['docId'] ?? $t['tg_doc_id'] ?? '') !== '' || (is_array($t) && !empty($t['bestfile']['download_link']));
}

// Apply gating to the public JSON of one video.
//  - normal video: unchanged
//  - premium + active user: stream token, no permanent BestFile link
//  - premium + inactive + trailer: only the trailer link
//  - premium + inactive + no trailer: no stream at all
//
// `type` tells the frontend what the `bestfile` link in this answer is:
// "full" (the whole file) or "trailer" (only the trailer copy). It is decided
// here, from the account's premium status, instead of leaving the client to
// guess from `premium` + `locked`.
//
// $detail marks a single-video request: there a paying account gets the full
// file alone and no trailer link at all.
function premium_shape_video($pub, $video, $uid, $detail = false) {
    $isPremium = premium_video_flag($video);
    $hasTrailer = premium_has_trailer($video);
    $active = false;

    $pub['locked'] = false;
    $pub['premium'] = $isPremium;
    $pub['hasTrailer'] = $hasTrailer;
    $pub['type'] = 'full';
    if (isset($pub['bestfile']) && is_array($pub['bestfile'])) {
        $pub['bestfile']['type'] = 'full';
    }

    // A third-party video is played by its own source: there is no stream of
    // ours to gate and nothing to unlock.
    if (!empty($pub['external'])) {
        return $pub;
    }

    if (!$isPremium) {
        if ($hasTrailer) {
            // Public on purpose: a trailer needs no login and no token.
            $pub['trailerUrl'] = '/api/videos/' . rawurlencode((string) ($video['id'] ?? '')) . '/stream?kind=trailer';
        }
        return $pub;
    }

    $active = $uid !== '' && premium_is_active($uid);
    $id = (string) ($video['id'] ?? '');

    if ($active) {
        // The account is paying right now: it gets the full file, both as a
        // signed stream URL and as the BestFile link (public_with_direct_links
        // turns the page link into a fresh direct URL).
        $pub['streamUrl'] = '/api/videos/' . rawurlencode($id) . '/stream?t=' . premium_token($uid, $id, 'video');
        $pub['type'] = 'full';
        if (isset($pub['bestfile']) && is_array($pub['bestfile'])) {
            $pub['bestfile']['type'] = 'full';
        }
    } else {
        // Never expose a permanent BestFile link for premium content: a locked
        // account only ever sees the trailer, so that is what `type` reports.
        $pub['bestfile'] = [
            'download_id' => '',
            'download_link' => '',
            'size' => (int) ($video['bestfile']['size'] ?? 0),
            'type' => '',
        ];
        $pub['locked'] = true;
        $pub['type'] = $hasTrailer ? 'trailer' : 'none';
        unset($pub['streamUrl']);
    }

    if ($hasTrailer && !($active && $detail)) {
        // Public on purpose: the trailer plays even when the account is locked.
        $pub['trailerUrl'] = '/api/videos/' . rawurlencode($id) . '/stream?kind=trailer';
    } else {
        unset($pub['trailerUrl']);
    }

    if ($active && $detail) {
        // A single-video request from a paying account carries the full file
        // alone: no trailer link that could be mistaken for the video.
        unset($pub['trailerBestfile'], $pub['trailerPageUrl']);
    }

    return $pub;
}

// === Request helpers ========================================================

// Firebase ID token → uid, or '' when there is no/invalid token.
//
// The body/query token is accepted too (the browser avoids a CORS preflight
// that way) and the verified identity is reused from the same disk cache as
// verify_firebase_token(): a signed-in visitor must not pay a Firebase round
// trip on every video list request.
function premium_uid_optional() {
    $token = '';
    if (function_exists('get_bearer_token')) {
        $token = (string) (get_bearer_token() ?? '');
    } else {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $m)) {
            $token = trim($m[1]);
        }
    }
    if ($token === '') {
        return '';
    }

    if (!function_exists('verify_firebase_token')) {
        return '';
    }
    $decoded = verify_firebase_token($token);
    return is_array($decoded) ? (string) ($decoded['localId'] ?? '') : '';
}
