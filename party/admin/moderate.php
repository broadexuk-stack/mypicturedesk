<?php
declare(strict_types=1);

// ============================================================
// admin/moderate.php — AJAX endpoint for all photo actions.
// Actions: approve | reject | remove | restore | purge_all
// Always returns JSON. Requires authenticated admin session.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Session ─────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Not authenticated.']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed.']));
}

// ── CSRF ─────────────────────────────────────────────────────
$client_csrf  = $_POST['csrf_token'] ?? '';
$session_csrf = $_SESSION['admin_csrf'] ?? '';
if (!$session_csrf || !hash_equals($session_csrf, $client_csrf)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'CSRF validation failed.']));
}

$action = $_POST['action'] ?? '';

if (!in_array($action, ['approve', 'reject', 'remove', 'restore', 'purge_all'], true)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid action.']));
}

// ── purge_all — no UUID needed, handled before UUID validation ─
if ($action === 'purge_all') {
    $removed = db_get_photos('removed');
    $count   = 0;
    foreach ($removed as $p) {
        $dskExt = output_extension($p['original_extension']);
        @unlink(GALLERY_DIR . '/' . $p['uuid'] . '.' . $dskExt);
        @unlink(THUMBS_DIR  . '/' . $p['uuid'] . '.' . $dskExt);
        db_update_photo_status($p['uuid'], 'rejected');
        $count++;
    }
    exit(json_encode(['ok' => true, 'action' => 'purged', 'count' => $count]));
}

// ── UUID validation (required for all other actions) ─────────
$uuid = $_POST['uuid'] ?? '';
if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid photo ID.']));
}

$photo = db_get_photo($uuid);
if ($photo === null) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Photo not found.']));
}

// ── approve — move from quarantine to gallery ─────────────────
if ($action === 'approve') {
    $ext      = $photo['original_extension'];
    $disk_ext = output_extension($ext);
    $qPath    = QUARANTINE_DIR . '/' . $uuid . '.' . $ext;
    $gPath    = GALLERY_DIR    . '/' . $uuid . '.' . $disk_ext;
    $tPath    = THUMBS_DIR     . '/' . $uuid . '.' . $disk_ext;

    if (!file_exists($qPath)) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'Quarantine file not found.']));
    }

    foreach ([GALLERY_DIR, THUMBS_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => 'Server storage error.']));
        }
    }

    $processed = process_image($qPath, $gPath, $tPath, $ext);
    if (!$processed) {
        error_log("moderate.php: process_image failed for $uuid — copying raw file");
        if (!@copy($qPath, $gPath) || !@copy($qPath, $tPath)) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => 'Could not move photo to gallery.']));
        }
    }

    @chmod($gPath, 0644);
    @chmod($tPath, 0644);
    @unlink($qPath);
    @unlink(QUARANTINE_DIR . '/thumbs/' . $uuid . '.' . $disk_ext);

    db_update_photo_status($uuid, 'approved');
    exit(json_encode(['ok' => true, 'action' => 'approved']));
}

// ── reject — permanently delete a pending/quarantined photo ───
if ($action === 'reject') {
    $ext    = $photo['original_extension'];
    $dskExt = output_extension($ext);
    foreach ([
        QUARANTINE_DIR . '/' . $uuid . '.' . $ext,
        QUARANTINE_DIR . '/thumbs/' . $uuid . '.' . $dskExt,
        GALLERY_DIR    . '/' . $uuid . '.' . $dskExt,
        THUMBS_DIR     . '/' . $uuid . '.' . $dskExt,
    ] as $path) {
        if (file_exists($path)) @unlink($path);
    }
    db_update_photo_status($uuid, 'rejected');
    exit(json_encode(['ok' => true, 'action' => 'rejected']));
}

// ── remove — move approved photo to wastebasket (files kept) ──
if ($action === 'remove') {
    db_update_photo_status($uuid, 'removed');
    exit(json_encode(['ok' => true, 'action' => 'removed']));
}

// ── restore — move wastebasket photo back to approved ─────────
if ($action === 'restore') {
    db_update_photo_status($uuid, 'approved');
    exit(json_encode(['ok' => true, 'action' => 'restored']));
}
