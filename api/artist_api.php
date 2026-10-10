<?php
// artist_api.php — Artist Finder backend (Standalone & Zero-DB)
// GET /api/artist_api.php?id=ARTIST_ID_OR_URL&mode=basic
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(90);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/init.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function af_jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function af_spotifyIdToHex(string $id62): string {
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

function af_msToMin(?int $ms): string {
    if (!$ms) return '-';
    $s = intdiv($ms, 1000);
    return intdiv($s, 60) . ':' . str_pad($s % 60, 2, '0', STR_PAD_LEFT);
}

function af_extractArtistId(string $input): string {
    $input = trim($input);
    if (preg_match('/artist\/([A-Za-z0-9]+)/', $input, $m)) return $m[1];
    if (preg_match('/^[A-Za-z0-9]{22}$/', $input)) return $input;
    return $input;
}

function af_getWebApiToken(bool $forceRefresh = false): string {
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

function af_spotifyGet(string $url, string $token): array {
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

function af_multiGet(array $urls, string $token): array {
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
$mode     = trim($_GET['mode'] ?? 'basic');

if (!$rawInput) af_jsonError('id parametresi gerekli. Spotify artist URL veya ID girin.');

$artistId = af_extractArtistId($rawInput);
if (!preg_match('/^[A-Za-z0-9]{22}$/', $artistId)) af_jsonError('Geçersiz Spotify artist ID.');

try {
    $token = af_getWebApiToken();

    // ── 1. Artist bilgisi ─────────────────────────────────────────────────────
    $artist = af_spotifyGet(
        "https://api.spotify.com/v1/artists/{$artistId}",
        $token
    );

    // ── 2. Sanatçının TÜM albümlerini çek (single + album, market=TR) ─────────
    $albums  = [];
    $nextUrl = "https://api.spotify.com/v1/artists/{$artistId}/albums?include_groups=album,single&limit=50&market=TR";

    while ($nextUrl) {
        $page = af_spotifyGet($nextUrl, $token);
        foreach ($page['items'] ?? [] as $item) {
            $albums[] = [
                'id'           => $item['id'],
                'name'         => $item['name'],
                'album_type'   => $item['album_type'] ?? 'single',
                'release_date' => $item['release_date'] ?? '-',
                'images'       => $item['images'] ?? [],
            ];
        }
        $nextUrl = $page['next'] ?? null;
    }

    // ── 3. Her albümün ilk sayfa track'lerini PARALEL çek (curl_multi) ────────
    $albumTrackUrls = [];
    foreach ($albums as $alb) {
        $albumTrackUrls[$alb['id']] = "https://api.spotify.com/v1/albums/{$alb['id']}/tracks?limit=50&market=TR";
    }

    $PARALLEL_CHUNK = 30;
    $albumChunks    = array_chunk($albumTrackUrls, $PARALLEL_CHUNK, true);

    $albumMap = [];
    foreach ($albums as $alb) {
        $albumMap[$alb['id']] = $alb;
    }

    $tracks  = [];
    $seenIds = [];

    foreach ($albumChunks as $chunk) {
        $responses = af_multiGet($chunk, $token);

        $extraPages = [];
        foreach ($responses as $albumId => $res) {
            if ($res['code'] !== 200) continue;
            $page  = json_decode($res['body'], true);
            $album = $albumMap[$albumId] ?? [];

            foreach ($page['items'] ?? [] as $t) {
                if (empty($t['id']) || isset($seenIds[$t['id']])) continue;
                $artistIds = array_column($t['artists'] ?? [], 'id');
                if (!in_array($artistId, $artistIds)) continue;
                $seenIds[$t['id']] = true;
                $tracks[] = [
                    'id'           => $t['id'],
                    'hex_id'       => af_spotifyIdToHex($t['id']),
                    'name'         => $t['name'],
                    'artists'      => implode(', ', array_column($t['artists'] ?? [], 'name')),
                    'album_id'     => $albumId,
                    'album_name'   => $album['name'] ?? '-',
                    'album_cover'  => $album['images'][0]['url'] ?? null,
                    'album_type'   => $album['album_type'] ?? 'single',
                    'release_date' => $album['release_date'] ?? '-',
                    'duration_ms'  => $t['duration_ms'] ?? null,
                    'duration'     => af_msToMin($t['duration_ms'] ?? null),
                    'explicit'     => !empty($t['explicit']),
                    'track_number' => $t['track_number'] ?? null,
                    'spotify_url'  => $t['external_urls']['spotify'] ?? null,
                    'isrc'         => null,
                    'distributor'  => null,
                    'upload_date'  => '-',
                ];
            }

            if (!empty($page['next'])) {
                $extraPages[] = ['albumId' => $albumId, 'next' => $page['next']];
            }
        }

        foreach ($extraPages as $ep) {
            $nextUrl = $ep['next'];
            $albumId = $ep['albumId'];
            $album   = $albumMap[$albumId] ?? [];
            while ($nextUrl) {
                $page    = af_spotifyGet($nextUrl, $token);
                $nextUrl = $page['next'] ?? null;
                foreach ($page['items'] ?? [] as $t) {
                    if (empty($t['id']) || isset($seenIds[$t['id']])) continue;
                    $artistIds = array_column($t['artists'] ?? [], 'id');
                    if (!in_array($artistId, $artistIds)) continue;
                    $seenIds[$t['id']] = true;
                    $tracks[] = [
                        'id'           => $t['id'],
                        'hex_id'       => af_spotifyIdToHex($t['id']),
                        'name'         => $t['name'],
                        'artists'      => implode(', ', array_column($t['artists'] ?? [], 'name')),
                        'album_id'     => $albumId,
                        'album_name'   => $album['name'] ?? '-',
                        'album_cover'  => $album['images'][0]['url'] ?? null,
                        'album_type'   => $album['album_type'] ?? 'single',
                        'release_date' => $album['release_date'] ?? '-',
                        'duration_ms'  => $t['duration_ms'] ?? null,
                        'duration'     => af_msToMin($t['duration_ms'] ?? null),
                        'explicit'     => !empty($t['explicit']),
                        'track_number' => $t['track_number'] ?? null,
                        'spotify_url'  => $t['external_urls']['spotify'] ?? null,
                        'isrc'         => null,
                        'distributor'  => null,
                        'upload_date'  => '-',
                    ];
                }
            }
        }
    }

    // ── 4. ISRC batch — 50'şer paralel ───────────────────────────────────────
    $trackIds = array_column($tracks, 'id');
    $isrcMap  = [];

    $isrcChunks = array_chunk($trackIds, 50);
    $isrcBatchUrls = [];
    foreach ($isrcChunks as $i => $chunk) {
        $isrcBatchUrls[$i] = "https://api.spotify.com/v1/tracks?ids=" . implode(',', $chunk) . "&market=TR";
    }

    foreach (array_chunk($isrcBatchUrls, 5, true) as $batchChunk) {
        $responses = af_multiGet($batchChunk, $token);
        foreach ($responses as $res) {
            if ($res['code'] !== 200) continue;
            $data = json_decode($res['body'], true);
            foreach ($data['tracks'] ?? [] as $ft) {
                if (!empty($ft['id'])) {
                    $isrcMap[$ft['id']] = $ft['external_ids']['isrc'] ?? null;
                }
            }
        }
    }

    foreach ($tracks as &$t) {
        $t['isrc'] = $isrcMap[$t['id']] ?? null;
    }
    unset($t);

    usort($tracks, fn($a, $b) => strcmp($b['release_date'], $a['release_date']));

    echo json_encode([
        'success' => true,
        'artist'  => [
            'id'          => $artist['id'],
            'name'        => $artist['name'],
            'image'       => $artist['images'][0]['url'] ?? null,
            'followers'   => number_format($artist['followers']['total'] ?? 0, 0, ',', '.'),
            'genres'      => $artist['genres'] ?? [],
            'spotify_url' => $artist['external_urls']['spotify'] ?? null,
        ],
        'total'  => count($tracks),
        'tracks' => $tracks,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    af_jsonError($e->getMessage(), 500);
}
