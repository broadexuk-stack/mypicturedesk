<?php
declare(strict_types=1);

// ============================================================
// admin/moderate.php — AJAX endpoint for approve / reject.
// Always returns JSON. Must be called from an authenticated
// admin session.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Session ─────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
session_start();

// Must be logged in
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Not authenticated.']));
}

// Only POST
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

// ── Input validation ─────────────────────────────────────────
$uuid   = $_POST['uuid']   ?? '';
$action = $_POST['action'] ?? '';

if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid photo ID.']));
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid action.']));
}

// ── Fetch photo record ───────────────────────────────────────
$photo = db_get_photo($uuid);
if ($photo === null) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Photo not found.']));
}

// ── Handle approve ───────────────────────────────────────────
if ($action === 'approve') {
    $ext        = $photo['original_extension'];
    $disk_ext   = output_extension($ext);           // HEIC → jpg on disk
    $qPath      = QUARANTINE_DIR . '/' . $uuid . '.' . $ext;
    $gPath      = GALLERY_DIR    . '/' . $uuid . '.' . $disk_ext;
    $tPath      = THUMBS_DIR     . '/' . $uuid . '.' . $disk_ext;

    if (!file_exists($qPath)) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'Quarantine file not found. It may have already been processed.']));
    }

    // Ensure gallery directories exist
    foreach ([GALLERY_DIR, THUMBS_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log("moderate.php: cannot create directory $dir");
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => 'Server storage error.']));
        }
    }

    // Process: strip EXIF, resize, generate thumbnail
    $processed = process_image($qPath, $gPath, $tPath, $ext);

    if (!$processed) {
        // Fallback: copy raw file without processing rather than refusing to approve
        error_log("moderate.php: process_image failed for $uuid — copying raw file");
        if (!@copy($qPath, $gPath) || !@copy($qPath, $tPath)) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => 'Could not move photo to gallery.']));
        }
    }

    // Set safe permissions
    @chmod($gPath, 0644);
    @chmod($tPath, 0644);

    // Remove original and quarantine thumbnail
    @unlink($qPath);
    $qThumbExt = output_extension($ext);
    @unlink(QUARANTINE_DIR . '/thumbs/' . $uuid . '.' . $qThumbExt);

    // Update DB
    if (!db_update_photo_status($uuid, 'approved')) {
        error_log("moderate.php: db_update_photo_status failed for $uuid approve");
    }

    exit(json_encode(['ok' => true, 'action' => 'approved']));
}

// ── Handle reject ────────────────────────────────────────────
if ($action === 'reject') {
    $ext     = $photo['original_extension'];
    $dskExt  = output_extension($ext);

    // Delete from whichever location the file currently lives in
    $locations = [
        QUARANTINE_DIR . '/' . $uuid . '.' . $ext,
        QUARANTINE_DIR . '/thumbs/' . $uuid . '.' . $dskExt,  // quarantine preview thumb
        GALLERY_DIR    . '/' . $uuid . '.' . $dskExt,
        THUMBS_DIR     . '/' . $uuid . '.' . $dskExt,
    ];
    foreach ($locations as $path) {
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    // Update DB — rejected photos are never recoverable
    if (!db_update_photo_status($uuid, 'rejected')) {
        error_log("moderate.php: db_update_photo_status failed for $uuid reject");
    }

    exit(json_encode(['ok' => true, 'action' => 'rejected']));
}
