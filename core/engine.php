<?php
/**
 * core/engine.php — Spotify data engine
 * Token, playcount, metadata helpers
 */

if (!defined('ENGINE_LOADED')) define('ENGINE_LOADED', true);

set_time_limit(120);

// ─── Cached Web API Token ─────────────────────────────────────────────────────

function eng_getCachedWebApiToken(): string {
    $cached = cache_get_token('webapi');
    if ($cached) return $cached;

    // Yeni token al
    $clientId     = SPOTIFY_CLIENT_ID;
    $clientSecret = SPOTIFY_CLIENT_SECRET;
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

    // Cache'e kaydet (1 saat geçerli)
    $expiresIn = (int)($data['expires_in'] ?? 3600) - 60;
    cache_set_token('webapi', $data['access_token'], $expiresIn);

    return $data['access_token'];
}

// ─── cURL ─────────────────────────────────────────────────────────────────────

function eng_curlGet(string $url, array $headers = [], int $timeout = 15): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res !== false && $code >= 200 && $code < 300) ? $res : null;
}

function eng_curlPost(string $url, string $body, array $headers = [], int $timeout = 10): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res !== false && $code >= 200 && $code < 300) ? $res : null;
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function eng_spotifyBase62ToHex(string $id62): string {
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

function eng_hexToSpotifyBase62(string $hex): string {
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

function eng_msToTime(int $ms): string {
    $total = intdiv($ms, 1000);
    return intdiv($total, 60) . ':' . str_pad($total % 60, 2, '0', STR_PAD_LEFT);
}

// ─── TOTP TOKEN ───────────────────────────────────────────────────────────────

function eng_fetchSecretDict(): array {
    $raw = eng_curlGet('https://raw.githubusercontent.com/CycloneAddons/spotify-token-generator/main/secrets/secretDict.json');
    if ($raw) {
        $data = json_decode($raw, true);
        if (is_array($data) && count($data) > 0) return $data;
    }
    return eng_extractSecretsFromSpotify();
}

function eng_extractSecretsFromSpotify(): array {
    $html = eng_curlGet('https://open.spotify.com/');
    if (!$html) throw new RuntimeException('Cannot get Spotify Homepage.');
    if (!preg_match('/"(https:\/\/[^" ]+\/web-player\.[0-9a-f]+\.js)"/', $html, $m))
        throw new RuntimeException('Cannot find Player JS URL.');
    $js = eng_curlGet($m[1]);
    if (!$js) throw new RuntimeException('Cannot get Player JS.');
    preg_match_all('/\{\s*secret\s*:\s*(["\'])(.*?)\1\s*,\s*version\s*:\s*(\d+)\s*\}/s', $js, $matches, PREG_SET_ORDER);
    if (!$matches) throw new RuntimeException('Cannot find Secret.');
    $dict = [];
    foreach ($matches as $match) {
        $dict[(string)(int)$match[3]] = array_map('ord', str_split($match[2]));
    }
    return $dict;
}

function eng_getLatestSecret(array $dict): array {
    $maxVer = max(array_map('intval', array_keys($dict)));
    return ['version' => $maxVer, 'secret' => $dict[(string)$maxVer]];
}

function eng_getServerTime(): int {
    $raw = eng_curlGet('https://open.spotify.com/api/server-time');
    if (!$raw) throw new RuntimeException('Cannot get server time.');
    $data = json_decode($raw, true);
    if (empty($data['serverTime'])) throw new RuntimeException('serverTime parse error.');
    return (int)$data['serverTime'];
}

function eng_generateTOTP(int $timestamp, array $secretArray): int {
    $transformed = [];
    foreach ($secretArray as $i => $elem) {
        $transformed[] = $elem ^ (($i % 33) + 9);
    }
    $joinedSecret = implode('', array_map('strval', $transformed));
    $secretBytes  = hex2bin(bin2hex($joinedSecret));
    $counter      = intdiv($timestamp, 30);
    $counterBuf   = pack('J', $counter);
    $hmac         = hash_hmac('sha1', $counterBuf, $secretBytes, true);
    $offset       = ord($hmac[strlen($hmac) - 1]) & 0xf;
    $code = ((ord($hmac[$offset])     & 0x7f) << 24)
          | ((ord($hmac[$offset + 1]) & 0xff) << 16)
          | ((ord($hmac[$offset + 2]) & 0xff) <<  8)
          |  (ord($hmac[$offset + 3]) & 0xff);
    return $code % 1000000;
}

function eng_getSpotifyWebToken(): string {
    $timestamp = eng_getServerTime();
    $latest    = eng_getLatestSecret(eng_fetchSecretDict());
    $totp      = eng_generateTOTP($timestamp, $latest['secret']);
    $params = http_build_query([
        'reason'      => 'init',
        'productType' => 'web-player',
        'totp'        => str_pad((string)$totp, 6, '0', STR_PAD_LEFT),
        'totpVer'     => $latest['version'],
        'ts'          => $timestamp,
    ]);
    $raw = eng_curlGet("https://open.spotify.com/api/token?$params", [
        'Accept: application/json',
        'Referer: https://open.spotify.com/',
        'Origin: https://open.spotify.com',
    ]);
    if (!$raw) throw new RuntimeException('Cannot get Spotify token.');
    $data = json_decode($raw, true);
    if (empty($data['accessToken'])) throw new RuntimeException('Cannot find accessToken.');
    return $data['accessToken'];
}

// ─── PLAYCOUNT ────────────────────────────────────────────────────────────────

function eng_getPlaycountForAlbum(string $albumId62): array {
    $token = eng_getSpotifyWebToken();
    $body = json_encode([
        'variables'     => ['uri' => "spotify:album:$albumId62", 'locale' => '', 'offset' => 0, 'limit' => 300],
        'operationName' => 'getAlbum',
        'extensions'    => ['persistedQuery' => ['version' => 1, 'sha256Hash' => 'b9bfabef66ed756e5e13f68a942deb60bd4125ec1f1be8cc42769dc0259b4b10']],
    ]);
    $raw = eng_curlPost('https://api-partner.spotify.com/pathfinder/v2/query', $body, [
        'Authorization: Bearer ' . $token,
        'app-platform: WebPlayer',
        'spotify-app-version: 896000000',
        'Content-Type: application/json;charset=UTF-8',
        'Accept: application/json',
        'Origin: https://open.spotify.com',
        'Referer: https://open.spotify.com/',
    ]);
    if (!$raw) return [];
    $data = json_decode($raw, true);
    $playcounts = [];
    // tracksV2
    foreach ($data['data']['albumUnion']['tracksV2']['items'] ?? [] as $item) {
        $t = $item['track'] ?? $item;
        if (!empty($t['uri'])) {
            $parts = explode(':', $t['uri']);
            $tid = end($parts);
            $playcounts[$tid] = $t['playcount'] ?? null;
        }
    }
    // discs fallback
    foreach ($data['data']['albumUnion']['discs']['items'] ?? [] as $disc) {
        foreach ($disc['tracks']['items'] ?? [] as $item) {
            $t = $item['track'] ?? $item;
            if (!empty($t['uri'])) {
                $parts = explode(':', $t['uri']);
                $tid = end($parts);
                $playcounts[$tid] = $t['playcount'] ?? null;
            }
        }
    }
    return $playcounts;
}

/**
 * Tek track için playcount — album ID'sinden tüm albümü çekip track'i bulur
 */
function eng_getPlaycountForTrack(string $trackId62, string $albumId62): ?string {
    $playcounts = eng_getPlaycountForAlbum($albumId62);
    $pc = $playcounts[$trackId62] ?? null;
    if ($pc === null) return null;
    return number_format((int)$pc, 0, ',', '.');
}
