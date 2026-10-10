<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'name'        => 'insta: bwsexd',
    'description' => 'Spotify Music Distributor & Metadata REST API (Zero-DB / JSON Engine)',

    'endpoints'   => [
        'track'              => '/api/distrofinder_api.php?id={track_id}&action=full',
        'album'              => '/api/album_api.php?id={album_id}',
        'artist'             => '/api/artist_api.php?id={artist_id}',
        'playlist'           => '/api/playlist_api.php?id={playlist_id}',
    ],
    'dataset'       => 'distributor.json (112+ Verified Music Distributors)',
    'documentation' => 'See README.md for endpoint parameters and usage',
    'timestamp'     => time()
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
