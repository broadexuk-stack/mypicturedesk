<?php
declare(strict_types=1);

// ============================================================
// gallery.php — Public JSON endpoint for the approved gallery.
// Called by app.js every 30 seconds.
//
// GET /party/gallery.php?json=1&id=party-slug
// Response: { "photos": [ { "thumb": "...", "full": "..." }, … ] }
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/image.php';

if (($_GET['json'] ?? '') !== '1') {
    http_response_code(400);
    exit('Use ?json=1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$slug = trim($_GET['id'] ?? '');
if ($slug === '') {
    echo json_encode(['photos' => []]);
    exit;
}

$party = mpd_get_party_by_slug($slug);
if ($party === false) {
    echo json_encode(['photos' => [], 'active' => false]);
    exit;
}
if (!$party['is_active']) {
    echo json_encode([
        'photos'         => [],
        'active'         => false,
        'organiser_name' => $party['organiser_name'] ?? '',
    ]);
    exit;
}

$dirs   = mpd_party_dirs($party['slug']);
$photos = db_get_photos('approved', (int)$party['id']);

$response = [];
foreach ($photos as $p) {
    $disk_ext = output_extension($p['original_extension']);
    $uuid     = $p['uuid'];
    $file     = $dirs['gallery'] . '/' . $uuid . '.' . $disk_ext;

    if (!file_exists($file)) continue;

    // Serve files via image.php to keep uploads outside public_html
    $base = 'image.php?party=' . urlencode($party['slug']);
    $response[] = [
        'thumb'       => $base . '&dir=gallery_thumbs&uuid=' . $uuid . '&ext=' . $disk_ext,
        'full'        => $base . '&dir=gallery&uuid='        . $uuid . '&ext=' . $disk_ext,
        'approved_at' => $p['approved_at'] ?? '',
    ];
}

echo json_encode(['photos' => $response, 'active' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
