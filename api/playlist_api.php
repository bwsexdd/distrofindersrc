<?php
// playlist_api.php — Playlist Finder backend (Standalone & Zero-DB)
// GET /api/playlist_api.php?id=PLAYLIST_ID_OR_URL&mode=basic 
//
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/init.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pl_jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function pl_spotifyIdToHex(string $id62): string {
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

function pl_msToMin(?int $ms): string {
    if (!$ms) return '-';
    $s = intdiv($ms, 1000);
    return intdiv($s, 60) . ':' . str_pad($s % 60, 2, '0', STR_PAD_LEFT);
}

function pl_extractPlaylistId(string $input): string {
    $input = trim($input);
    if (preg_match('/playlist\/([A-Za-z0-9]+)/', $input, $m)) return $m[1];
    if (preg_match('/^[A-Za-z0-9]{22}$/', $input)) return $input;
    return $input;
}

function pl_getWebApiToken(bool $forceRefresh = false): string {
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

function pl_spotifyGet(string $url, string $token): array {
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

// ─── Input ────────────────────────────────────────────────────────────────────
$rawInput = trim($_GET['id'] ?? '');
$mode     = trim($_GET['mode'] ?? 'basic');

if (!$rawInput) pl_jsonError('id parametresi gerekli. Spotify playlist URL veya ID girin.');

$playlistId = pl_extractPlaylistId($rawInput);
if (!preg_match('/^[A-Za-z0-9]{22}$/', $playlistId)) pl_jsonError('Geçersiz Spotify playlist ID.');

try {
    $token = pl_getWebApiToken();

    // ── 1. Playlist bilgisi ───────────────────────────────────────────────────
    $pl = pl_spotifyGet(
        "https://api.spotify.com/v1/playlists/{$playlistId}?market=TR&fields=id,name,description,public,followers,images,owner,tracks.total,external_urls",
        $token
    );

    // ── 2. Tüm track'leri çek (100'er sayfalı, max 500) ──────────────────────
    $tracks  = [];
    $nextUrl = "https://api.spotify.com/v1/playlists/{$playlistId}/tracks?limit=100&market=TR"
             . "&fields=next,items(added_at,track(id,name,duration_ms,explicit,track_number,external_ids,external_urls,artists,album))";
    $count   = 0;

    while ($nextUrl && $count < 500) {
        $page = pl_spotifyGet($nextUrl, $token);
        foreach ($page['items'] ?? [] as $item) {
            $t = $item['track'] ?? null;
            if (!$t || empty($t['id'])) continue;

            $addedAt = isset($item['added_at'])
                ? substr($item['added_at'], 0, 10)
                : '-';

            $tracks[] = [
                'id'           => $t['id'],
                'hex_id'       => pl_spotifyIdToHex($t['id']),
                'name'         => $t['name'],
                'artists'      => implode(', ', array_column($t['artists'] ?? [], 'name')),
                'album_name'   => $t['album']['name'] ?? '-',
                'album_cover'  => $t['album']['images'][0]['url'] ?? null,
                'album_type'   => $t['album']['album_type'] ?? null,
                'release_date' => $t['album']['release_date'] ?? '-',
                'duration_ms'  => $t['duration_ms'] ?? null,
                'duration'     => pl_msToMin($t['duration_ms'] ?? null),
                'explicit'     => !empty($t['explicit']),
                'isrc'         => $t['external_ids']['isrc'] ?? null,
                'spotify_url'  => $t['external_urls']['spotify'] ?? null,
                'added_at'     => $addedAt,
                'distributor'  => null,
                'upload_date'  => '-',
            ];
            $count++;
        }
        $nextUrl = $page['next'] ?? null;
    }

    echo json_encode([
        'success'  => true,
        'playlist' => [
            'id'          => $pl['id'],
            'name'        => $pl['name'],
            'description' => strip_tags($pl['description'] ?? ''),
            'owner'       => $pl['owner']['display_name'] ?? '?',
            'followers'   => number_format($pl['followers']['total'] ?? 0, 0, ',', '.'),
            'cover'       => $pl['images'][0]['url'] ?? null,
            'spotify_url' => $pl['external_urls']['spotify'] ?? null,
            'total'       => count($tracks),
        ],
        'total'  => count($tracks),
        'tracks' => $tracks,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    pl_jsonError($e->getMessage(), 500);
}
