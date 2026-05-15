<?php
declare(strict_types=1);

// ============================================================
// admin/thumb.php — Serves quarantine thumbnails and full
// quarantine images to authenticated admins.
// Files are outside public_html so must be proxied.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['mpd_user_id'])) {
    http_response_code(403);
    exit;
}

// ── Validate inputs ──────────────────────────────────────────
$uuid  = $_GET['uuid']  ?? '';
$slug  = preg_replace('/[^a-z0-9\-_]/', '', strtolower($_GET['party'] ?? ''));
$full  = !empty($_GET['full']);

if (!preg_match('/^[0-9a-f]{32}$/', $uuid) || $slug === '') {
    http_response_code(400);
    exit;
}

// ── Authorise: organizer may only access their own party ──────
$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

$party = mpd_get_party_by_slug($slug);
if ($party === false) { http_response_code(404); exit; }

if ($role === 'organizer' && (int)$party['id'] !== $party_id) {
    http_response_code(403);
    exit;
}

$dirs = mpd_party_dirs($slug);

// ── Prevent path traversal ────────────────────────────────────
$base = realpath(rtrim(UPLOADS_BASE, '/'));

// ── Full-size request ─────────────────────────────────────────
if ($full) {
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $candidate = $dirs['quarantine'] . '/' . $uuid . '.' . $ext;
        $real      = realpath($candidate);
        if ($real && $base && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real)) {
            $mime = match ($ext) {
                'png'  => 'image/png',
                'webp' => 'image/webp',
                default=> 'image/jpeg',
            };
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($real));
            header('Cache-Control: private, max-age=60');
            header('X-Content-Type-Options: nosniff');
            readfile($real);
            exit;
        }
    }
    // Also check for HEIC original (serve thumb instead — fall through)
}

// ── Thumbnail ────────────────────────────────────────────────
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    $candidate = $dirs['quarantine_thumbs'] . '/' . $uuid . '.' . $ext;
    $real      = realpath($candidate);
    if ($real && $base && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real)) {
        $mime = match ($ext) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            default=> 'image/jpeg',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }
}

http_response_code(404);
