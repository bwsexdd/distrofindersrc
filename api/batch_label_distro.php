<?php
// batch_label_distro.php — Label Finder için toplu album distributor sorgusu (Standalone & Zero-DB)
// POST /api/batch_label_distro.php
// Body: { album_ids: string[] }
// Response: { success: true, results: { ALBUM_ID: { distributor, upload_date, distro_website, distro_emails } } }
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/engine.php';
require_once __DIR__ . '/../core/distro_info.php';

// ─── Input ────────────────────────────────────────────────────────────────────
$raw      = file_get_contents('php://input');
$body     = json_decode($raw, true);
$albumIds = $body['album_ids'] ?? [];

if (empty($albumIds) || !is_array($albumIds)) {
    echo json_encode(['success' => false, 'error' => 'album_ids gerekli.']);
    exit;
}
$albumIds = array_slice(array_values($albumIds), 0, 50);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function bld_spotifyIdToHex(string $id62): string {
    $chars  = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = array_fill(0, 16, 0);
    for ($i = 0; $i < strlen($id62); $i++) {
        $carry = strpos($chars, $id62[$i]);
        for ($j = 15; $j >= 0; $j--) {
            $carry      += 62 * $result[$j];
            $result[$j]  = $carry & 0xFF;
            $carry     >>= 8;
        }
    }
    return implode('', array_map(fn($b) => str_pad(dechex($b), 2, '0', STR_PAD_LEFT), $result));
}

function bld_getDistributorInfo(string $uuid, array $DISTRO_INFO): array {
    $key   = strtolower(str_replace('-', '', $uuid));
    $entry = $DISTRO_INFO[$key] ?? null;
    if (!$entry) return ['name' => '', 'website' => '', 'emails' => []];

    $name    = is_array($entry) ? ($entry['name'] ?? '') : (string)$entry;
    $website = is_array($entry) ? ($entry['website'] ?? '') : '';
    $emails  = [];
    $raw     = is_array($entry) ? ($entry['support_email'] ?? $entry['support_emails'] ?? '') : '';
    if (is_array($raw)) {
        $emails = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        foreach (explode(',', $raw) as $e) {
            $e = trim($e);
            if ($e) $emails[] = ['email' => $e, 'purpose' => 'Support'];
        }
    }
    return ['name' => $name, 'website' => $website, 'emails' => $emails];
}

function bld_getInternalToken(): array {
    $cached = cache_get_token('internal_totp');
    if ($cached) {
        $d = json_decode($cached, true);
        if (!empty($d['token'])) return $d;
    }

    try {
        $token = eng_getSpotifyWebToken();
        if ($token) {
            $result = ['token' => $token, 'clientId' => SPOTIFY_CLIENT_ID];
            cache_set_token('internal_totp', json_encode($result), 25 * 60);
            return $result;
        }
    } catch (Exception $e) {}

    // Fallback: vercel token servisi
    $ch = curl_init('https://spotify-tokener-api.vercel.app/api/getToken');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $data = json_decode($resp, true);
        if (!empty($data['accessToken'])) {
            return ['token' => $data['accessToken'], 'clientId' => $data['clientId'] ?? SPOTIFY_CLIENT_ID];
        }
    }

    throw new Exception('Spotify internal token alınamadı.');
}

function bld_getWebApiToken(bool $forceRefresh = false): string {
    if (!$forceRefresh) {
        $cached = cache_get_token('webapi');
        if ($cached) return $cached;
    }

    $clientId     = SPOTIFY_CLIENT_ID;
    $clientSecret = SPOTIFY_CLIENT_SECRET;
    if (!$clientId || !$clientSecret) throw new Exception('Spotify credentials eksik.');

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new Exception("Web API token alınamadı: HTTP $code");
    $data = json_decode($body, true);
    if (empty($data['access_token'])) throw new Exception('access_token boş.');

    $expiresIn = (int)($data['expires_in'] ?? 3600) - 60;
    cache_set_token('webapi', $data['access_token'], $expiresIn);

    return $data['access_token'];
}

function bld_multiGet(array $urls, array $headers): array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 0.1);
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $key => $ch) {
        $results[$key] = [
            'body' => curl_multi_getcontent($ch),
            'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ─── Processing ───────────────────────────────────────────────────────────────
$results = [];

try {
    // ── 1. Her albümün ilk track'ini Web API ile PARALEL al ───────────────────
    $webToken = bld_getWebApiToken();
    $albumTrackUrls = [];
    foreach ($albumIds as $aid) {
        $albumTrackUrls[$aid] = "https://api.spotify.com/v1/albums/{$aid}/tracks?limit=1&market=TR";
    }

    $webResponses = bld_multiGet($albumTrackUrls, ["Authorization: Bearer $webToken", 'Accept: application/json']);

    // 401 kontrolü
    $webAll401 = true;
    foreach ($webResponses as $res) {
        if ($res['code'] !== 401) { $webAll401 = false; break; }
    }
    if ($webAll401 && !empty($webResponses)) {
        cache_del_token('webapi');
        $webToken     = bld_getWebApiToken(true);
        $webResponses = bld_multiGet($albumTrackUrls, ["Authorization: Bearer $webToken", 'Accept: application/json']);
    }

    $albumFirstTrack = [];
    foreach ($webResponses as $aid => $res) {
        if ($res['code'] !== 200) continue;
        $data = json_decode($res['body'], true);
        $firstTrack = $data['items'][0]['id'] ?? null;
        if ($firstTrack) $albumFirstTrack[$aid] = $firstTrack;
    }

    $albumTrackHex = [];
    foreach ($albumFirstTrack as $aid => $tid) {
        $albumTrackHex[$aid] = bld_spotifyIdToHex($tid);
    }

    // ── 2. spclient'tan paralel çek ──────────────────────────────────────────
    if (!empty($albumTrackHex)) {
        $tokenData     = bld_getInternalToken();
        $internalToken = $tokenData['token'];

        $spclientUrls = [];
        foreach ($albumTrackHex as $aid => $hex) {
            $spclientUrls[$aid] = "https://spclient.wg.spotify.com/metadata/4/track/{$hex}?market=from_token";
        }

        $spclientHeaders = [
            "Authorization: Bearer $internalToken",
            'Accept: application/json',
            'app-platform: WebPlayer',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];

        $spclientResponses = bld_multiGet($spclientUrls, $spclientHeaders);

        // 401 kontrolü
        $allFailed = true;
        foreach ($spclientResponses as $res) {
            if ($res['code'] === 200) { $allFailed = false; break; }
        }
        if ($allFailed && !empty($spclientResponses)) {
            cache_del_token('internal_totp');
            $tokenData          = bld_getInternalToken();
            $internalToken      = $tokenData['token'];
            $spclientHeaders[0] = "Authorization: Bearer $internalToken";
            $spclientResponses  = bld_multiGet($spclientUrls, $spclientHeaders);
        }

        foreach ($albumIds as $aid) {
            if (!isset($albumTrackHex[$aid])) {
                $results[$aid] = ['distributor' => null, 'upload_date' => '-', 'distro_website' => '', 'distro_emails' => []];
                continue;
            }
            $res  = $spclientResponses[$aid] ?? ['body' => null, 'code' => 0];
            $data = ($res['code'] === 200 && $res['body']) ? json_decode($res['body'], true) : null;

            $uuid       = is_array($data) ? ($data['licensor']['uuid'] ?? null) : null;
            $liveTs     = is_array($data) ? ($data['earliest_live_timestamp'] ?? null) : null;
            $uploadDate = $liveTs ? date('Y-m-d', $liveTs) : '-';
            $dInfo      = $uuid ? bld_getDistributorInfo($uuid, $DISTRO_INFO) : ['name' => '', 'website' => '', 'emails' => []];

            $results[$aid] = [
                'distributor'    => $dInfo['name'] ?: null,
                'upload_date'    => $uploadDate,
                'distro_website' => $dInfo['website'],
                'distro_emails'  => $dInfo['emails'],
            ];
        }
    }

    foreach ($albumIds as $aid) {
        if (!isset($results[$aid])) {
            $results[$aid] = ['distributor' => null, 'upload_date' => '-', 'distro_website' => '', 'distro_emails' => []];
        }
    }

    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    foreach ($albumIds as $aid) {
        $results[$aid] = ['distributor' => null, 'upload_date' => '-', 'distro_website' => '', 'distro_emails' => []];
    }
    echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
}
