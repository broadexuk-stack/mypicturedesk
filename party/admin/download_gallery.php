<?php
declare(strict_types=1);

// ============================================================
// admin/download_gallery.php — Streams approved photos as ZIP.
// Requires authenticated organizer or superadmin session.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['mpd_user_id'])) {
    http_response_code(401);
    exit('Not authenticated.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive is not available on this server.');
}

$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

// Superadmin can download any party via ?party=slug
if ($role === 'superadmin' && isset($_GET['party'])) {
    $p = mpd_get_party_by_slug($_GET['party']);
    if ($p !== false) $party_id = (int)$p['id'];
}

if ($party_id === 0) {
    http_response_code(403);
    exit('No party available.');
}

$party = mpd_get_party_by_id($party_id);
if (!$party) {
    http_response_code(404);
    exit('Party not found.');
}

// Organizer may only download their own party
if ($role === 'organizer' && (int)$party['organizer_id'] !== (int)$_SESSION['mpd_user_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$dirs   = mpd_party_dirs($party['slug']);
$photos = db_get_photos('approved', $party_id);

if (empty($photos)) {
    http_response_code(404);
    exit('No approved photos to download.');
}

$tmpFile = tempnam(sys_get_temp_dir(), 'gallery_') . '.zip';
$zip     = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create ZIP archive.');
}

foreach ($photos as $p) {
    $ext  = output_extension($p['original_extension']);
    $path = $dirs['gallery'] . '/' . $p['uuid'] . '.' . $ext;
    if (!file_exists($path)) continue;

    $namePart  = !empty($p['uploaded_by'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($p['uploaded_by'], 0, 30)) . '_'
        : 'photo_';
    $ts        = date('Ymd_His', strtotime($p['upload_timestamp']));
    $shortUuid = substr($p['uuid'], 0, 6);
    $zipName   = $namePart . $ts . '_' . $shortUuid . '.' . $ext;

    $zip->addFile($path, $zipName);
}

$zip->close();

$slug_safe  = preg_replace('/[^a-z0-9\-]/', '', $party['slug']);
$dlFilename = 'gallery_' . $slug_safe . '_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dlFilename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($tmpFile);
unlink($tmpFile);
