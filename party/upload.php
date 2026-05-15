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

function json_error(int $code, string $msg): never {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Method not allowed.');
}

// ── Detect post_max_size exceeded ───────────────────────────
// When a POST body exceeds post_max_size, PHP silently empties
// $_POST and $_FILES, which would otherwise cause a confusing
// CSRF failure. Catch it here and return a clear size error.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_error(413, sprintf('Photo is too large. Maximum size is %d MB.', MAX_FILE_SIZE_MB));
}

// ── Session & CSRF ──────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

$client_token  = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (!$session_token || !hash_equals($session_token, $client_token)) {
    json_error(403, 'Invalid request. Please refresh the page and try again.');
}

// ── Resolve party ───────────────────────────────────────────
$party_slug = trim($_POST['party_slug'] ?? '');
if ($party_slug === '') {
    json_error(400, 'Missing party identifier.');
}

$party = mpd_get_party_by_slug($party_slug);
if ($party === false) {
    json_error(404, 'This party is not available.');
}
if (!$party['is_active']) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => 'The gallery has been paused.', 'party_paused' => true]));
}

$party_id = (int)$party['id'];

// ── IP extraction ───────────────────────────────────────────
$raw_ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash = hash_ip($raw_ip);
$ip_disp = partial_ip($raw_ip);

// ── Rate limiting ───────────────────────────────────────────
if (!db_check_rate_limit($party_id, $ip_hash)) {
    json_error(429, sprintf(
        'You\'ve uploaded a lot of photos! The limit is %d per %d hours. Please try again later.',
        RATE_LIMIT_UPLOADS,
        RATE_LIMIT_WINDOW_HOURS
    ));
}

// ── File presence check ─────────────────────────────────────
if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    json_error(400, 'No photo was received. Please try again.');
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
    json_error(400, $phpErrors[$file['error']] ?? 'Upload failed (code ' . $file['error'] . ').');
}

// ── File size ───────────────────────────────────────────────
if ($file['size'] > MAX_FILE_SIZE_BYTES) {
    json_error(400, sprintf('Photo is too large. Maximum size is %d MB.', MAX_FILE_SIZE_MB));
}

if (!is_uploaded_file($file['tmp_name'])) {
    json_error(400, 'Invalid upload. Please try again.');
}

// ── Magic-byte validation ───────────────────────────────────
$detected_ext = validate_magic_bytes($file['tmp_name']);
if ($detected_ext === null) {
    json_error(400, 'This file type is not accepted. Please upload a JPEG, PNG, WebP, or HEIC photo.');
}

// ── Ensure per-party directories exist ─────────────────────
$dirs = mpd_ensure_party_dirs($party['slug']);

if (!is_writable($dirs['quarantine'])) {
    error_log('upload.php: quarantine dir not writable: ' . $dirs['quarantine']);
    json_error(500, 'Server storage error. Please contact the host.');
}

// ── Generate UUID filename ──────────────────────────────────
$uuid            = bin2hex(random_bytes(16));
$quarantine_path = $dirs['quarantine'] . '/' . $uuid . '.' . $detected_ext;

// ── Move to quarantine ──────────────────────────────────────
if (!move_uploaded_file($file['tmp_name'], $quarantine_path)) {
    error_log("upload.php: move_uploaded_file failed to $quarantine_path");
    json_error(500, 'Could not save your photo. Please try again.');
}
chmod($quarantine_path, 0644);

// ── Generate quarantine thumbnail ──────────────────────────
$thumbExt   = ($detected_ext === 'heic') ? 'jpg' : $detected_ext;
$qThumbPath = $dirs['quarantine_thumbs'] . '/' . $uuid . '.' . $thumbExt;

if (!generate_quarantine_thumb($quarantine_path, $qThumbPath, $detected_ext)) {
    error_log("upload.php: generate_quarantine_thumb failed for uuid=$uuid");
} else {
    chmod($qThumbPath, 0644);
}

// ── Sanitise optional uploader name ────────────────────────
$raw_name    = $_POST['uploaded_by'] ?? '';
$uploaded_by = mb_substr(trim($raw_name), 0, 100, 'UTF-8');
$uploaded_by = preg_replace('/[\x00-\x1f\x7f<>]/', '', $uploaded_by);

// ── Record in database ──────────────────────────────────────
db_insert_photo($party_id, $uuid, $detected_ext, $ip_hash, $ip_disp, $uploaded_by);
db_log_upload_attempt($party_id, $ip_hash);

// ── Optional email notification ─────────────────────────────
$notify = $party['notify_email'] ?? '';
if ($notify !== '') {
    $pname   = $party['party_name'];
    $subject = '[' . $pname . '] New photo awaiting approval';
    $body    = mpd_render_email('email_notify_body', [
        'party_name'  => htmlspecialchars($pname),
        'uploaded_by' => htmlspecialchars($uploaded_by ?: '(anonymous)'),
        'ip_display'  => $ip_disp,
        'upload_time' => date('Y-m-d H:i:s'),
        'admin_url'   => BASE_URL . '/party/admin/index.php',
    ]);
    mpd_send_email($notify, $subject, $body);
}

// ── Success ─────────────────────────────────────────────────
echo json_encode(['ok' => true, 'message' => 'Photo received! It will appear once approved.']);
