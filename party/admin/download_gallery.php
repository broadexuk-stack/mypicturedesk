<?php
declare(strict_types=1);

// ============================================================
// admin/download_gallery.php — Streams all approved gallery
// photos as a single ZIP archive. Requires admin session.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    exit('Not authenticated.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive is not available on this server.');
}

$photos = db_get_photos('approved');

if (empty($photos)) {
    http_response_code(404);
    exit('No approved photos to download.');
}

// Build ZIP into a temp file so we can send Content-Length
$tmpFile = tempnam(sys_get_temp_dir(), 'gallery_') . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create ZIP archive.');
}

foreach ($photos as $p) {
    $ext  = output_extension($p['original_extension']);
    $path = GALLERY_DIR . '/' . $p['uuid'] . '.' . $ext;

    if (!file_exists($path)) continue;

    // Build a human-readable name inside the ZIP
    $namePart = !empty($p['uploaded_by'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($p['uploaded_by'], 0, 30)) . '_'
        : 'photo_';
    $ts        = date('Ymd_His', strtotime($p['upload_timestamp']));
    $shortUuid = substr($p['uuid'], 0, 6);
    $zipName   = $namePart . $ts . '_' . $shortUuid . '.' . $ext;

    $zip->addFile($path, $zipName);
}

$zip->close();

$dlFilename = 'gallery_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dlFilename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($tmpFile);
unlink($tmpFile);
