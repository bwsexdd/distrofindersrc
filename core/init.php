<?php
// init.php — Core application bootstrap (Zero Database / JSON Engine)

$_cfg = require __DIR__ . '/config.php';

define('SPOTIFY_CLIENT_ID',     $_cfg['spotify_client_id'] ?? '');
define('SPOTIFY_CLIENT_SECRET', $_cfg['spotify_client_secret'] ?? '');
unset($_cfg);

// ── File-based Token Cache ───────────────────────────────────────────────────
function cache_get_token(string $key): ?string {
    $file = sys_get_temp_dir() . '/df_sp_token_' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(@file_get_contents($file), true);
    if (is_array($data) && ($data['exp'] ?? 0) > time()) {
        return $data['val'] ?? null;
    }
    return null;
}

function cache_set_token(string $key, string $val, int $ttlSecs): void {
    $file = sys_get_temp_dir() . '/df_sp_token_' . md5($key) . '.json';
    @file_put_contents($file, json_encode(['val' => $val, 'exp' => time() + $ttlSecs]));
}

function cache_del_token(string $key): void {
    $file = sys_get_temp_dir() . '/df_sp_token_' . md5($key) . '.json';
    if (file_exists($file)) @unlink($file);
}
