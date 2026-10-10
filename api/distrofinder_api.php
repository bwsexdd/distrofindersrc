<?php
// distrofinder_api.php — Spotify Track Info API (Standalone & Zero-DB)
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/distro_info.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function getDistributorInfo($uuid, $DISTRO_INFO) {
    if (!$uuid) return ['name'=>'Bilinmiyor','website'=>'','support_emails'=>[]];
    $lower = strtolower(str_replace('-', '', $uuid));

    if (!isset($DISTRO_INFO[$lower])) {
        return ['name'=>'Bilinmeyen ('.substr($uuid,0,8).'...)','website'=>'','support_emails'=>[]];
    }
    $entry = $DISTRO_INFO[$lower];
    if (is_string($entry)) {
        return ['name'=>$entry,'website'=>'','support_emails'=>[]];
    }
    $emails = [];
    $raw = $entry['support_email'] ?? $entry['support_emails'] ?? '';
    if (is_array($raw)) {
        $emails = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        foreach (explode(',', $raw) as $e) {
            $e = trim($e);
            if ($e) $emails[] = ['email'=>$e,'purpose'=>'Support'];
        }
    }
    return [
        'name'            => $entry['name']    ?? 'Bilinmiyor',
        'website'         => $entry['website'] ?? '',
        'support_emails'  => $emails,
    ];
}

function hexToSpotifyId($hex) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $bytes = [];
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $bytes[] = hexdec(substr($hex, $i, 2));
    }
    $result = '';
    while (!empty($bytes) && max($bytes) > 0) {
        $rem = 0; $newBytes = [];
        foreach ($bytes as $b) {
            $cur = $rem * 256 + $b;
            $newBytes[] = intdiv($cur, 62);
            $rem = $cur % 62;
        }
        $result = $chars[$rem] . $result;
        $bytes = $newBytes;
        while (count($bytes) > 1 && $bytes[0] === 0) array_shift($bytes);
    }
    return str_pad($result, 22, '0', STR_PAD_LEFT);
}

function extractTrackId($input) {
    $input = trim($input);
    if (preg_match('/track[\/:]([a-zA-Z0-9]{22})/', $input, $m)) {
        return spotifyIdToHex($m[1]);
    }
    if (preg_match('/^[a-zA-Z0-9]{22}$/', $input)) {
        return spotifyIdToHex($input);
    }
    if (preg_match('/^[a-fA-F0-9]{32}$/', $input)) {
        return strtolower($input);
    }
    throw new Exception('Geçersiz Spotify URL, Track ID veya Hex ID.');
}

function spotifyIdToHex($id) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = array_fill(0, 16, 0);
    for ($i = 0; $i < strlen($id); $i++) {
        $carry = strpos($chars, $id[$i]);
        for ($j = 15; $j >= 0; $j--) {
            $carry += 62 * $result[$j];
            $result[$j] = $carry & 0xFF;
            $carry >>= 8;
        }
    }
    return implode('', array_map(fn($b) => str_pad(dechex($b), 2, '0', STR_PAD_LEFT), $result));
}

function msToTime($ms) {
    if (!$ms) return '-';
    $totalSec = floor($ms / 1000);
    $min      = floor($totalSec / 60);
    $sec      = $totalSec % 60;
    return $min . ':' . str_pad($sec, 2, '0', STR_PAD_LEFT);
}

function getSpotifyToken() {
    $cached = cache_get_token('internal_totp');
    if ($cached) {
        $d = json_decode($cached, true);
        if (!empty($d['token'])) return $d;
    }

    require_once __DIR__ . '/../core/engine.php';
    try {
        $token = eng_getSpotifyWebToken();
        if ($token) {
            $result = ['token' => $token, 'clientId' => SPOTIFY_CLIENT_ID];
            cache_set_token('internal_totp', json_encode($result), 25 * 60);
            return $result;
        }
    } catch (Exception $e) {}

    // Fallback: vercel token servisi
    try {
        $ch = curl_init('https://spotify-tokener-api.vercel.app/api/getToken');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200) {
            $data = json_decode($body, true);
            if (!empty($data['accessToken'])) {
                return ['token' => $data['accessToken'], 'clientId' => $data['clientId'] ?? SPOTIFY_CLIENT_ID];
            }
        }
    } catch (Exception $e) {}

    throw new Exception('Spotify internal token alınamadı.');
}

function getWebApiToken(bool $forceRefresh = false) {
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

function getWebApiTokenWithRetry() {
    return getWebApiToken();
}

function getTrackInfo($trackHex, $DISTRO_INFO) {
    // ── Faz 1: Spotify Web API ───────────────────────────────────────────────
    $webToken  = getWebApiTokenWithRetry();
    $trackId62 = hexToSpotifyId($trackHex);

    $ch = curl_init("https://api.spotify.com/v1/tracks/{$trackId62}?market=TR");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $webToken",
            'Accept: application/json',
        ],
    ]);
    $wBody = curl_exec($ch);
    $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($wCode === 401) {
        cache_del_token('webapi');
        $webToken = getWebApiToken(true);
        $ch = curl_init("https://api.spotify.com/v1/tracks/{$trackId62}?market=TR");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>["Authorization: Bearer $webToken",'Accept: application/json']]);
        $wBody = curl_exec($ch); $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    }

    if ($wCode !== 200) throw new Exception("Spotify Web API hatası: HTTP $wCode");
    $w = json_decode($wBody, true);

    $albumId = $w['album']['id'] ?? null;
    $upc = '-';
    $webLabel = '-';
    if ($albumId) {
        $ch2 = curl_init("https://api.spotify.com/v1/albums/{$albumId}?market=TR");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $webToken",
                'Accept: application/json',
            ],
        ]);
        $aBody = curl_exec($ch2);
        $aCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($aCode === 200) {
            $a        = json_decode($aBody, true);
            $upc      = $a['external_ids']['upc'] ?? '-';
            $webLabel = $a['label'] ?? '-';
        }
    }

    // ── Faz 2: Internal Metadata API ─────────────────────────────────────────
    $distroName   = 'Bilinmiyor';
    $distroWeb    = '';
    $distroEmails = [];
    $uploadDate   = '-';
    $metaLabel    = '-';
    $playcount    = null;

    try {
        $tokenData = getSpotifyToken();
        $token     = $tokenData['token'];

        $url = "https://spclient.wg.spotify.com/metadata/4/track/{$trackHex}?market=from_token";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Accept: application/json',
                'app-platform: WebPlayer',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $d = json_decode($body, true);
            $uuid   = $d['licensor']['uuid'] ?? null;
            $dInfo  = getDistributorInfo($uuid, $DISTRO_INFO);
            $distroName   = $dInfo['name'];
            $distroWeb    = $dInfo['website'];
            $distroEmails = $dInfo['support_emails'];

            $liveTs = $d['earliest_live_timestamp'] ?? null;
            if ($liveTs) $uploadDate = date('Y-m-d', $liveTs);

            if (!empty($d['album']['label'])) {
                $metaLabel = $d['album']['label'];
            }

            // Playcount
            try {
                require_once __DIR__ . '/../core/engine.php';
                $albumGid = $d['album']['gid'] ?? null;
                if ($albumGid) {
                    $albumHexStr = ctype_xdigit($albumGid) && strlen($albumGid) === 32
                        ? strtolower($albumGid)
                        : bin2hex($albumGid);
                    if (strlen($albumHexStr) === 32) {
                        $albumId62 = eng_hexToSpotifyBase62($albumHexStr);
                        $pc = eng_getPlaycountForTrack($trackId62, $albumId62);
                        if ($pc !== null) $playcount = $pc;
                    }
                }
            } catch (Exception $e2) {}
        }
    } catch (Exception $e) {}

    $trackName   = $w['name'] ?? '-';
    $artists     = implode(', ', array_column($w['artists'] ?? [], 'name')) ?: '-';
    $albumName   = $w['album']['name'] ?? '-';
    $albumCover  = $w['album']['images'][0]['url'] ?? null;
    $releaseDate = $w['album']['release_date'] ?? '-';
    $isrc        = $w['external_ids']['isrc'] ?? '-';
    $durationMs  = $w['duration_ms'] ?? null;
    $explicit    = !empty($w['explicit']);
    $trackNumber = $w['track_number'] ?? '-';
    $discNumber  = $w['disc_number'] ?? '-';
    $spotifyUrl  = $w['external_urls']['spotify'] ?? '';
    $finalLabel  = ($metaLabel !== '-') ? $metaLabel : $webLabel;

    return [
        'track_name'     => $trackName,
        'artist'         => $artists,
        'album'          => $albumName,
        'album_cover'    => $albumCover,
        'label'          => $finalLabel,
        'distributor'    => $distroName,
        'distro_website' => $distroWeb,
        'distro_emails'  => $distroEmails,
        'release_date'   => $releaseDate,
        'upload_date'    => $uploadDate,
        'isrc'           => $isrc,
        'upc'            => $upc,
        'spotify_url'    => $spotifyUrl,
        'playcount'      => $playcount !== null ? number_format((int)$playcount, 0, ',', '.') : '-',
        'duration'       => msToTime($durationMs),
        'duration_ms'    => $durationMs,
        'explicit'       => $explicit,
        'track_number'   => $trackNumber,
        'disc_number'    => $discNumber,
    ];
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Router ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'full';
$id     = trim($_GET['id'] ?? '');

if (!$id) jsonError('id parametresi gerekli.');

try {
    $trackHex = extractTrackId($id);

    // ── action=basic: sadece Web API ──────────────────────────────────────────
    if ($action === 'basic') {
        $webToken  = getWebApiToken();
        $trackId62 = hexToSpotifyId($trackHex);

        $ch = curl_init("https://api.spotify.com/v1/tracks/{$trackId62}?market=TR");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>["Authorization: Bearer $webToken",'Accept: application/json']]);
        $wBody = curl_exec($ch); $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

        if ($wCode === 401) {
            cache_del_token('webapi');
            $webToken = getWebApiToken(true);
            $ch = curl_init("https://api.spotify.com/v1/tracks/{$trackId62}?market=TR");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>["Authorization: Bearer $webToken",'Accept: application/json']]);
            $wBody = curl_exec($ch); $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        }

        if ($wCode !== 200) throw new Exception("Spotify Web API hatası: HTTP $wCode");
        $w = json_decode($wBody, true);

        $albumId = $w['album']['id'] ?? null;
        $upc = '-'; $label = '-';
        if ($albumId) {
            $ch2 = curl_init("https://api.spotify.com/v1/albums/{$albumId}?market=TR");
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>["Authorization: Bearer $webToken",'Accept: application/json']]);
            $aBody = curl_exec($ch2); $aCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);
            if ($aCode === 200) {
                $a     = json_decode($aBody, true);
                $upc   = $a['external_ids']['upc'] ?? '-';
                $label = $a['label'] ?? '-';
            }
        }

        echo json_encode([
            'success' => true,
            'hex_id'  => $trackHex,
            'data'    => [
                'track_name'     => $w['name'] ?? '-',
                'artist'         => implode(', ', array_column($w['artists'] ?? [], 'name')) ?: '-',
                'album'          => $w['album']['name'] ?? '-',
                'album_cover'    => $w['album']['images'][0]['url'] ?? null,
                'label'          => $label,
                'distributor'    => null,
                'distro_website' => '',
                'distro_emails'  => [],
                'release_date'   => $w['album']['release_date'] ?? '-',
                'upload_date'    => null,
                'isrc'           => $w['external_ids']['isrc'] ?? '-',
                'upc'            => $upc,
                'playcount'      => null,
                'duration'       => msToTime($w['duration_ms'] ?? null),
                'duration_ms'    => $w['duration_ms'] ?? null,
                'explicit'       => !empty($w['explicit']),
                'track_number'   => $w['track_number'] ?? '-',
                'disc_number'    => $w['disc_number'] ?? '-',
                'spotify_url'    => $w['external_urls']['spotify'] ?? '',
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'distro') {
        $tokenData = getSpotifyToken();
        $token     = $tokenData['token'];

        $url = "https://spclient.wg.spotify.com/metadata/4/track/{$trackHex}?market=from_token";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Accept: application/json',
                'app-platform: WebPlayer',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) throw new Exception("Metadata API hatası: HTTP $code" . ($curlErr ? " | cURL: $curlErr" : ''));
        $d    = json_decode($body, true);
        $uuid = $d['licensor']['uuid'] ?? null;

        $dInfo = getDistributorInfo($uuid, $DISTRO_INFO);
        $liveTs = $d['earliest_live_timestamp'] ?? null;
        $label = $d['album']['label'] ?? '-';

        $playcount = null;
        try {
            require_once __DIR__ . '/../core/engine.php';
            $trackId62   = hexToSpotifyId($trackHex);
            $albumGid    = $d['album']['gid'] ?? null;
            if ($albumGid) {
                $albumHexStr = ctype_xdigit($albumGid) && strlen($albumGid) === 32
                    ? strtolower($albumGid)
                    : bin2hex($albumGid);
                if (strlen($albumHexStr) === 32) {
                    $albumId62 = eng_hexToSpotifyBase62($albumHexStr);
                    $pc = eng_getPlaycountForTrack($trackId62, $albumId62);
                    if ($pc !== null) $playcount = $pc;
                }
            }
        } catch (Exception $e2) {}

        echo json_encode([
            'success'        => true,
            'distributor'    => $dInfo['name'],
            'distro_website' => $dInfo['website'],
            'distro_emails'  => $dInfo['support_emails'],
            'upload_date'    => $liveTs ? date('Y-m-d', $liveTs) : '-',
            'licensor_uuid'  => $uuid ?? 'Bulunamadı',
            'label'          => $label,
            'playcount'      => $playcount ?? '-',
            'album_id'       => $d['album']['gid'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── action=full veya track: tüm bilgi ───────────────────────────────────
    $info = getTrackInfo($trackHex, $DISTRO_INFO);
    echo json_encode([
        'success' => true,
        'hex_id'  => $trackHex,
        'data'    => $info,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}
