<?php
declare(strict_types=1);

// ============================================================
// admin/thumb.php — Serves quarantine thumbnails to admins.
// Quarantine/ is blocked from direct HTTP access, so thumbnails
// for pending photos must be proxied through this script.
// Unauthenticated requests receive a 403 with no file data.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/image.php';

ini_set('session.cookie_httponly', '1');
session_start();

// Must be a logged-in admin
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    exit;
}

// Validate UUID — 32 lowercase hex chars, nothing else
$uuid = $_GET['uuid'] ?? '';
if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) {
    http_response_code(400);
    exit;
}

// Find the thumbnail — HEIC originals are stored as jpg thumbs
$qThumbDir = QUARANTINE_DIR . '/thumbs';
$found     = null;
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    $candidate = $qThumbDir . '/' . $uuid . '.' . $ext;
    if (file_exists($candidate)) {
        $found    = $candidate;
        $foundExt = $ext;
        break;
    }
}

if ($found === null) {
    http_response_code(404);
    exit;
}

$mime = match ($foundExt) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    default       => 'image/jpeg',
};

header('Content-Type: '   . $mime);
header('Content-Length: ' . filesize($found));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($found);
