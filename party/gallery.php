<?php
declare(strict_types=1);

// ============================================================
// gallery.php — Public JSON endpoint for the approved gallery.
// Called by app.js every 30 seconds.
//
// GET /party/gallery.php?json=1
// Response: { "photos": [ { "thumb": "...", "full": "..." }, … ] }
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/image.php';

// Only serve JSON responses from this endpoint
if (($_GET['json'] ?? '') !== '1') {
    http_response_code(400);
    exit('Use ?json=1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$photos   = db_get_photos('approved');
$response = [];

foreach ($photos as $p) {
    // Determine the actual extension on disk (HEIC is converted to jpg on approval)
    $disk_ext = output_extension($p['original_extension']);
    $uuid     = $p['uuid'];

    // Paths are relative to the party/ directory — the browser resolves them
    // against the page URL (https://domain.com/party/).
    $thumb = 'gallery/thumbs/' . $uuid . '.' . $disk_ext;
    $full  = 'gallery/'        . $uuid . '.' . $disk_ext;

    // Only include photos whose files actually exist on disk
    if (file_exists(GALLERY_DIR . '/' . $uuid . '.' . $disk_ext)) {
        $response[] = [
            'thumb'       => $thumb,
            'full'        => $full,
            'approved_at' => $p['approved_at'] ?? '',
        ];
    }
}

echo json_encode(['photos' => $response], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
