<?php
// album_api.php — Album Finder backend (Standalone & Zero-DB)
// GET /api/album_api.php?id=ALBUM_ID_OR_URL&action=info|playcounts
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/engine.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function alb_jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function alb_extractAlbumId(string $input): string {
    $input = trim($input);
    if (preg_match('/album[\/:]([a-zA-Z0-9]{22})/', $input, $m)) return $m[1];
    if (preg_match('/^[a-zA-Z0-9]{22}$/', $input)) return $input;
    return $input;
}

function alb_msToMin(?int $ms): string {
    if (!$ms) return '-';
    $s = intdiv($ms, 1000);
    return intdiv($s, 60) . ':' . str_pad($s % 60, 2, '0', STR_PAD_LEFT);
}

function alb_spotifyIdToHex(string $id62): string {
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

function alb_getWebApiToken(bool $forceRefresh = false): string {
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

function alb_spotifyGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 401) throw new Exception("Spotify 401 — token geçersiz.");
    if ($code !== 200) throw new Exception("Spotify API hatası: HTTP $code — " . substr($body, 0, 120));
    $data = json_decode($body, true);
    if (!is_array($data)) throw new Exception("Spotify API JSON parse hatası.");
    return $data;
}

function alb_multiGet(array $urls, string $token): array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Accept: application/json',
            ],
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

// ─── Input ────────────────────────────────────────────────────────────────────
$rawInput = trim($_GET['id'] ?? '');
$action   = trim($_GET['action'] ?? 'info');

if (!$rawInput) alb_jsonError('id parametresi gerekli.');

$albumId = alb_extractAlbumId($rawInput);
if (!preg_match('/^[A-Za-z0-9]{22}$/', $albumId)) alb_jsonError('Geçersiz Spotify album ID.');

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: playcounts — GraphQL partner API'den albüm bazlı playcount
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'playcounts') {
    try {
        $internalToken = cache_get_token('internal_totp');
        if ($internalToken) {
            $d = json_decode($internalToken, true);
            $internalToken = $d['token'] ?? null;
        }

        if (!$internalToken) {
            $internalToken = eng_getSpotifyWebToken();
            if ($internalToken) {
                cache_set_token('internal_totp', json_encode(['token' => $internalToken, 'clientId' => SPOTIFY_CLIENT_ID]), 25 * 60);
            }
        }

        $gqlBody = json_encode([
            'operationName' => 'getAlbum',
            'variables'     => ['uri' => "spotify:album:{$albumId}", 'locale' => '', 'offset' => 0, 'limit' => 300],
            'extensions'    => ['persistedQuery' => ['version' => 1, 'sha256Hash' => 'b9bfabef66ed756e5e13f68a942deb60bd4125ec1f1be8cc42769dc0259b4b10']],
        ]);

        $ch = curl_init('https://api-partner.spotify.com/pathfinder/v2/query');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $gqlBody,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $internalToken",
                'Content-Type: application/json',
                'Accept: application/json',
                'app-platform: WebPlayer',
                'spotify-app-version: 896000000',
                'Origin: https://open.spotify.com',
                'Referer: https://open.spotify.com/',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) alb_jsonError("GraphQL hatası: HTTP $code", 500);

        $data = json_decode($res, true);
        $playcounts = [];

        foreach ($data['data']['albumUnion']['tracksV2']['items'] ?? [] as $item) {
            $t = $item['track'] ?? $item;
            if (!empty($t['uri'])) {
                $parts = explode(':', $t['uri']);
                $tid   = end($parts);
                $pc    = $t['playcount'] ?? null;
                $playcounts[$tid] = $pc !== null ? number_format((int)$pc, 0, ',', '.') : '-';
            }
        }

        foreach ($data['data']['albumUnion']['discs']['items'] ?? [] as $disc) {
            foreach ($disc['tracks']['items'] ?? [] as $item) {
                $t = $item['track'] ?? $item;
                if (!empty($t['uri'])) {
                    $parts = explode(':', $t['uri']);
                    $tid   = end($parts);
                    $pc    = $t['playcount'] ?? null;
                    if (!isset($playcounts[$tid])) {
                        $playcounts[$tid] = $pc !== null ? number_format((int)$pc, 0, ',', '.') : '-';
                    }
                }
            }
        }

        echo json_encode([
            'success'    => true,
            'album_id'   => $albumId,
            'playcounts' => $playcounts,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        alb_jsonError($e->getMessage(), 500);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: info (default) — albüm bilgisi + track listesi
// ══════════════════════════════════════════════════════════════════════════════

try {
    $token = alb_getWebApiToken();

    $albumRes = alb_spotifyGet(
        "https://api.spotify.com/v1/albums/{$albumId}?market=TR",
        $token
    );

    if (empty($albumRes['id'])) alb_jsonError('Albüm bulunamadı.', 404);

    $album        = $albumRes;
    $rawTracks    = $albumRes['tracks']['items'] ?? [];
    $nextTrackUrl = $albumRes['tracks']['next'] ?? null;

    while ($nextTrackUrl) {
        $page         = alb_spotifyGet($nextTrackUrl, $token);
        $rawTracks    = array_merge($rawTracks, $page['items'] ?? []);
        $nextTrackUrl = $page['next'] ?? null;
    }

    foreach ($rawTracks as &$rt) {
        if (!empty($rt['linked_from']['id'])) {
            $rt['id']          = $rt['linked_from']['id'];
            $rt['spotify_url'] = $rt['linked_from']['external_urls']['spotify']
                                 ?? $rt['external_urls']['spotify']
                                 ?? null;
        }
    }
    unset($rt);

    $trackIds = [];
    foreach ($rawTracks as $rt) {
        if (!empty($rt['id'])) $trackIds[] = $rt['id'];
    }
    $trackIds = array_values(array_unique($trackIds));
    $isrcBatchUrls = [];
    foreach (array_chunk($trackIds, 50) as $i => $chunk) {
        $isrcBatchUrls[$i] = "https://api.spotify.com/v1/tracks?ids=" . implode(',', $chunk);
    }

    $isrcMap = [];
    foreach (array_chunk($isrcBatchUrls, 5, true) as $batchChunk) {
        $responses = alb_multiGet($batchChunk, $token);
        foreach ($responses as $res) {
            if ($res['code'] !== 200) continue;
            $data = json_decode($res['body'], true);
            foreach ($data['tracks'] ?? [] as $ft) {
                if (empty($ft)) continue;
                $tid  = $ft['id'] ?? null;
                $isrc = $ft['external_ids']['isrc'] ?? null;
                $linkedId = $ft['linked_from']['id'] ?? null;
                if ($tid)      $isrcMap[$tid]      = $isrc;
                if ($linkedId) $isrcMap[$linkedId] = $isrc;
            }
        }
    }

    $tracks = [];
    foreach ($rawTracks as $t) {
        if (empty($t['id'])) continue;
        $tracks[] = [
            'id'           => $t['id'],
            'hex_id'       => alb_spotifyIdToHex($t['id']),
            'name'         => $t['name'],
            'artists'      => implode(', ', array_column($t['artists'] ?? [], 'name')),
            'track_number' => $t['track_number'] ?? null,
            'disc_number'  => $t['disc_number'] ?? null,
            'duration_ms'  => $t['duration_ms'] ?? null,
            'duration'     => alb_msToMin($t['duration_ms'] ?? null),
            'explicit'     => !empty($t['explicit']),
            'isrc'         => $isrcMap[$t['id']] ?? null,
            'spotify_url'  => $t['external_urls']['spotify'] ?? null,
            'distributor'  => null,
            'upload_date'  => '-',
            'playcount'    => null,
        ];
    }

    echo json_encode([
        'success' => true,
        'album'   => [
            'id'           => $album['id'],
            'name'         => $album['name'],
            'artists'      => implode(', ', array_column($album['artists'] ?? [], 'name')),
            'cover'        => $album['images'][0]['url'] ?? null,
            'release_date' => $album['release_date'] ?? '-',
            'total_tracks' => $album['total_tracks'] ?? count($tracks),
            'label'        => $album['label'] ?? '-',
            'upc'          => $album['external_ids']['upc'] ?? '-',
            'album_type'   => $album['album_type'] ?? null,
            'spotify_url'  => $album['external_urls']['spotify'] ?? null,
        ],
        'tracks' => $tracks,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    alb_jsonError($e->getMessage(), 500);
}
