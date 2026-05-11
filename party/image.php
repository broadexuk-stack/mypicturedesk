<?php
declare(strict_types=1);

// ============================================================
// image.php — Public proxy that serves approved gallery images
// and quarantine thumbnails stored outside public_html.
//
// GET params:
//   party  = party slug
//   dir    = gallery | gallery_thumbs | quarantine | quarantine_thumbs
//   uuid   = 32-char hex UUID
//   ext    = jpg | jpeg | png | webp | gif
//
// Only 'gallery' and 'gallery_thumbs' are publicly accessible.
// Admin-only dirs (quarantine, quarantine_thumbs) require an
// active admin session; those requests are served via admin/thumb.php.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

function img_error(int $code): never {
    http_response_code($code);
    exit;
}

// ── Validate inputs ─────────────────────────────────────────
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($_GET['party'] ?? ''));
$dir  = $_GET['dir'] ?? '';
$uuid = $_GET['uuid'] ?? '';
$ext  = strtolower($_GET['ext'] ?? '');

if ($slug === '' || $uuid === '' || $ext === '') img_error(400);

// Validate UUID: exactly 32 hex chars
if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) img_error(400);

// Only safe extensions
$allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
if (!in_array($ext, $allowed_exts, true)) img_error(400);

// Only public directories are served here
$allowed_dirs = ['gallery', 'gallery_thumbs'];
if (!in_array($dir, $allowed_dirs, true)) img_error(403);

// ── Resolve file path ────────────────────────────────────────
$party = mpd_get_party_by_slug($slug);
if ($party === false || !$party['is_active']) img_error(404);

$dirs = mpd_party_dirs($slug);
if (!isset($dirs[$dir])) img_error(400);

$file_path = $dirs[$dir] . '/' . $uuid . '.' . $ext;

// Prevent path traversal (mpd_party_dirs already constructs a clean path,
// but double-check the resolved path stays within UPLOADS_BASE)
$real = realpath($file_path);
$base = realpath(rtrim(UPLOADS_BASE, '/'));
if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    img_error(404);
}

if (!is_file($real)) img_error(404);

// ── Serve the file ───────────────────────────────────────────
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

// Cache for 7 days — gallery images are immutable once approved
$etag = '"' . md5($real . filemtime($real)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=604800, immutable');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
readfile($real);
