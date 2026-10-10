<?php
// batch_distro.php — Toplu distributor sorgusu (Standalone & Zero-DB)
// POST /api/batch_distro.php
// Body: { track_ids: string[] }
// Response: { success: true, results: { TRACK_ID: { distributor, upload_date } } }
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
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$trackIds = $body['track_ids'] ?? [];
if (empty($trackIds) || !is_array($trackIds)) {
    echo json_encode(['success' => false, 'error' => 'track_ids gerekli.']);
    exit;
}
$trackIds = array_slice(array_values($trackIds), 0, 50);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function bd_spotifyIdToHex(string $id62): string {
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

function bd_getDistributorName(string $uuid, array $DISTRO_INFO): string {
    $key   = strtolower(str_replace('-', '', $uuid));
    $entry = $DISTRO_INFO[$key] ?? null;
    if (!$entry) return '';
    return is_array($entry) ? ($entry['name'] ?? '') : (string)$entry;
}

function bd_getInternalToken(): array {
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

function bd_multiMetadata(array $hexMap, string $token): array {
    $mh      = curl_multi_init();
    $handles = [];

    $headers = [
        "Authorization: Bearer $token",
        'Accept: application/json',
        'app-platform: WebPlayer',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ];

    foreach ($hexMap as $sid => $hex) {
        $ch = curl_init("https://spclient.wg.spotify.com/metadata/4/track/{$hex}?market=from_token");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$sid] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 0.1);
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $sid => $ch) {
        $results[$sid] = [
            'body' => curl_multi_getcontent($ch),
            'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ─── Parallel Metadata Fetch ──────────────────────────────────────────────────
$results = [];
$hexMap  = [];
foreach ($trackIds as $sid) {
    $hexMap[$sid] = bd_spotifyIdToHex($sid);
}

try {
    $tokenData = bd_getInternalToken();
    $token     = $tokenData['token'];

    $responses = bd_multiMetadata($hexMap, $token);

    // Token fail kontrolü
    $allFailed = true;
    foreach ($responses as $res) {
        if ($res['code'] === 200) { $allFailed = false; break; }
    }

    if ($allFailed && !empty($responses)) {
        cache_del_token('internal_totp');
        $tokenData = bd_getInternalToken();
        $token     = $tokenData['token'];
        $responses = bd_multiMetadata($hexMap, $token);
    }

    foreach ($trackIds as $sid) {
        $res  = $responses[$sid] ?? ['body' => null, 'code' => 0];
        $data = ($res['code'] === 200 && $res['body']) ? json_decode($res['body'], true) : null;

        $uuid       = (is_array($data) ? ($data['licensor']['uuid'] ?? null) : null);
        $liveTs     = (is_array($data) ? ($data['earliest_live_timestamp'] ?? null) : null);
        $distroName = $uuid ? bd_getDistributorName($uuid, $DISTRO_INFO) : '';
        $uploadDate = $liveTs ? date('Y-m-d', $liveTs) : '-';

        $results[$sid] = [
            'distributor' => $distroName ?: null,
            'upload_date' => $uploadDate,
        ];
    }
} catch (Exception $e) {
    foreach ($trackIds as $sid) {
        if (!isset($results[$sid])) {
            $results[$sid] = ['distributor' => null, 'upload_date' => '-'];
        }
    }
}

echo json_encode([
    'success' => true,
    'results' => $results,
], JSON_UNESCAPED_UNICODE);
