<?php
declare(strict_types=1);

// ============================================================
// upload.php — Handles multipart/form-data POST from the guest
// page. Always responds with JSON.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/image.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed.']));
}

// ── Session & CSRF ──────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

$client_token  = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (!$session_token || !hash_equals($session_token, $client_token)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Invalid request. Please refresh the page and try again.']));
}

// ── IP extraction ───────────────────────────────────────────
// Prefer the real IP even behind a proxy, but don't trust headers blindly.
// If your server sits behind a trusted reverse proxy, trust HTTP_X_FORWARDED_FOR.
$raw_ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash   = hash_ip($raw_ip);
$ip_disp   = partial_ip($raw_ip);

// ── Rate limiting ───────────────────────────────────────────
if (!db_check_rate_limit($ip_hash, false)) {
    http_response_code(429);
    exit(json_encode([
        'ok'    => false,
        'error' => sprintf(
            'You\'ve uploaded a lot of photos! The limit is %d per %d hours. Please try again later.',
            RATE_LIMIT_UPLOADS,
            RATE_LIMIT_WINDOW_HOURS
        ),
    ]));
}

// ── File presence check ─────────────────────────────────────
if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'No photo was received. Please try again.']));
}

$file = $_FILES['photo'];

// ── PHP upload error codes ──────────────────────────────────
if ($file['error'] !== UPLOAD_ERR_OK) {
    $phpErrors = [
        UPLOAD_ERR_INI_SIZE   => 'The photo is too large for the server (ini limit).',
        UPLOAD_ERR_FORM_SIZE  => 'The photo is too large.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (no tmp dir). Contact the host.',
        UPLOAD_ERR_CANT_WRITE => 'Server disk error. Contact the host.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server. Contact the host.',
    ];
    $msg = $phpErrors[$file['error']] ?? 'Upload failed (code ' . $file['error'] . ').';
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

// ── File size ───────────────────────────────────────────────
if ($file['size'] > MAX_FILE_SIZE_BYTES) {
    http_response_code(400);
    exit(json_encode([
        'ok'    => false,
        'error' => sprintf('Photo is too large. Maximum size is %d MB.', MAX_FILE_SIZE_MB),
    ]));
}

// Sanity-check that the reported tmp path actually exists
if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid upload. Please try again.']));
}

// ── Magic-byte validation ───────────────────────────────────
// We ignore the client-supplied MIME type entirely.
$detected_ext = validate_magic_bytes($file['tmp_name']);
if ($detected_ext === null) {
    http_response_code(400);
    exit(json_encode([
        'ok'    => false,
        'error' => 'This file type is not accepted. Please upload a JPEG, PNG, WebP, or HEIC photo.',
    ]));
}

// ── Quarantine directory check ──────────────────────────────
if (!is_dir(QUARANTINE_DIR) || !is_writable(QUARANTINE_DIR)) {
    error_log('upload.php: QUARANTINE_DIR is missing or not writable: ' . QUARANTINE_DIR);
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Server storage error. Please contact the host.']));
}

// ── Generate server-side UUID filename ─────────────────────
// Client-supplied filename is discarded entirely.
$uuid      = bin2hex(random_bytes(16));  // 32 hex chars
$quarantine_path = QUARANTINE_DIR . '/' . $uuid . '.' . $detected_ext;

// ── Move to quarantine ──────────────────────────────────────
if (!move_uploaded_file($file['tmp_name'], $quarantine_path)) {
    error_log("upload.php: move_uploaded_file failed to $quarantine_path");
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Could not save your photo. Please try again.']));
}

// Set safe permissions on the stored file
chmod($quarantine_path, 0644);

// ── Generate quarantine thumbnail for admin preview ─────────
$qThumbDir  = QUARANTINE_DIR . '/thumbs';
$thumbExt   = ($detected_ext === 'heic') ? 'jpg' : $detected_ext;
$qThumbPath = $qThumbDir . '/' . $uuid . '.' . $thumbExt;

if (!is_dir($qThumbDir)) {
    mkdir($qThumbDir, 0755, true);
}
if (!generate_quarantine_thumb($quarantine_path, $qThumbPath, $detected_ext)) {
    // Non-fatal — admin will see a broken image placeholder instead
    error_log("upload.php: generate_quarantine_thumb failed for uuid=$uuid");
} else {
    chmod($qThumbPath, 0644);
}

// ── Sanitise optional uploader name ────────────────────────
$raw_name    = $_POST['uploaded_by'] ?? '';
$uploaded_by = mb_substr(trim($raw_name), 0, 100, 'UTF-8');
// Strip control characters and angle brackets
$uploaded_by = preg_replace('/[\x00-\x1f\x7f<>]/', '', $uploaded_by);

// ── Write to database / flat file ──────────────────────────
if (!db_insert_photo($uuid, $detected_ext, $ip_hash, $ip_disp, $uploaded_by)) {
    // Non-fatal: photo is saved, just not recorded. Log and continue.
    error_log("upload.php: db_insert_photo failed for uuid=$uuid");
}

// ── Record rate-limit attempt ───────────────────────────────
db_check_rate_limit($ip_hash, true);

// ── Optional email notification ─────────────────────────────
if (NOTIFY_EMAIL !== '') {
    $subject = '[' . PARTY_NAME . '] New photo awaiting approval';
    $body    = "A new photo has been uploaded and is waiting for your approval.\n\n"
             . "UUID:      $uuid\n"
             . "Type:      $detected_ext\n"
             . "Name:      " . ($uploaded_by ?: '(anonymous)') . "\n"
             . "IP (partial): $ip_disp\n"
             . "Time:      " . date('Y-m-d H:i:s') . " UTC\n\n"
             . "Review it here: " . BASE_URL . "/admin/\n";
    $headers = "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n"
             . "X-Mailer: PHP/" . PHP_VERSION;
    @mail(NOTIFY_EMAIL, $subject, $body, $headers);
}

// ── Success ─────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'Photo received! It will appear once approved.']);
