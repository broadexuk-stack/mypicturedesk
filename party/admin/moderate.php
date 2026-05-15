<?php
declare(strict_types=1);

// ============================================================
// admin/moderate.php — AJAX endpoint for photo moderation.
// Actions: approve | reject | remove | restore | purge_all
// Requires authenticated organizer or superadmin session.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/cloudinary.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_err(int $code, string $msg): never {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['mpd_user_id'])) {
    json_err(401, 'Not authenticated.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err(405, 'Method not allowed.');
}

// ── CSRF ─────────────────────────────────────────────────────
if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
    json_err(403, 'CSRF validation failed.');
}

// ── Resolve party scope ───────────────────────────────────────
$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

// Superadmin can operate on any party by passing a uuid that belongs to it;
// we resolve party_id from the photo record after UUID lookup.
if ($role !== 'organizer' && $role !== 'superadmin') {
    json_err(403, 'Insufficient permissions.');
}
if ($role === 'organizer' && $party_id === 0) {
    json_err(403, 'No party assigned to this account.');
}

// ── Action validation ────────────────────────────────────────
$action = $_POST['action'] ?? '';
if (!in_array($action, ['approve', 'reject', 'remove', 'restore', 'purge_all'], true)) {
    json_err(400, 'Invalid action.');
}

// ── purge_all ────────────────────────────────────────────────
if ($action === 'purge_all') {
    $removed = db_get_photos('removed', $party_id);
    $party   = mpd_get_party_by_id($party_id);
    $dirs    = $party ? mpd_party_dirs($party['slug']) : null;

    foreach ($removed as $p) {
        $dskExt = output_extension($p['original_extension']);
        if ($dirs) {
            @unlink($dirs['gallery']        . '/' . $p['uuid'] . '.' . $dskExt);
            @unlink($dirs['gallery_thumbs'] . '/' . $p['uuid'] . '.' . $dskExt);
        }
        if (!empty($p['cloudinary_public_id'])) {
            cloudinary_delete($p['cloudinary_public_id']);
        }
        db_set_photo_status($p['uuid'], $party_id, 'rejected');
    }
    mpd_log('photo.wastebasket_emptied', [
        'party.id'     => $party_id,
        'photos.count' => count($removed),
        'user.id'      => (int)($_SESSION['mpd_user_id'] ?? 0),
        'user.role'    => $role,
    ]);
    exit(json_encode(['ok' => true, 'action' => 'purged', 'count' => count($removed)]));
}

// ── UUID validation ───────────────────────────────────────────
$uuid = $_POST['uuid'] ?? '';
if (!preg_match('/^[0-9a-f]{32}$/', $uuid)) json_err(400, 'Invalid photo ID.');

// For superadmin, resolve party_id from the photo itself
if ($role === 'superadmin') {
    // We need to find the photo across all parties
    $pdo = db_pdo();
    $st  = $pdo->prepare('SELECT * FROM photos WHERE uuid = :uuid LIMIT 1');
    $st->execute([':uuid' => $uuid]);
    $photo = $st->fetch();
    if (!$photo) json_err(404, 'Photo not found.');
    $party_id = (int)$photo['party_id'];
} else {
    $photo = db_get_photo_by_uuid($uuid, $party_id);
    if (!$photo) json_err(404, 'Photo not found.');
}

$party = mpd_get_party_by_id($party_id);
if (!$party) json_err(500, 'Party not found.');
$dirs = mpd_party_dirs($party['slug']);

// ── approve ───────────────────────────────────────────────────
if ($action === 'approve') {
    $ext      = $photo['original_extension'];
    $disk_ext = output_extension($ext);
    $qPath    = $dirs['quarantine']   . '/' . $uuid . '.' . $ext;
    $gPath    = $dirs['gallery']      . '/' . $uuid . '.' . $disk_ext;
    $tPath    = $dirs['gallery_thumbs'] . '/' . $uuid . '.' . $disk_ext;

    if (!file_exists($qPath)) json_err(404, 'Quarantine file not found.');

    $processed = process_image($qPath, $gPath, $tPath, $ext);
    if (!$processed) {
        error_log("moderate.php: process_image failed for $uuid — copying raw");
        if (!@copy($qPath, $gPath) || !@copy($qPath, $tPath)) {
            json_err(500, 'Could not move photo to gallery.');
        }
    }

    @chmod($gPath, 0644);
    @chmod($tPath, 0644);
    @unlink($qPath);
    @unlink($dirs['quarantine_thumbs'] . '/' . $uuid . '.' . $disk_ext);
    // Also try jpg thumb (HEIC originals generate jpg thumbs)
    if ($disk_ext !== 'jpg') {
        @unlink($dirs['quarantine_thumbs'] . '/' . $uuid . '.jpg');
    }

    // ── Upload to Cloudinary if enabled for this party ────────
    if (!empty($party['cloudinary_enabled']) && cloudinary_globally_configured()) {
        $cld_id = cloudinary_public_id($party['slug'], $uuid);
        $result = cloudinary_upload($gPath, $cld_id);
        if ($result !== false) {
            // Use the public_id Cloudinary actually assigned (may include folder prefix)
            $stored_id = $result['public_id'] ?? $cld_id;
            try {
                db_set_photo_cloudinary_id($uuid, $party_id, $stored_id);
                @unlink($gPath);
                @unlink($tPath);
            } catch (\Throwable $e) {
                error_log("moderate.php: db_set_photo_cloudinary_id failed for $uuid: " . $e->getMessage());
            }
        } else {
            error_log("moderate.php: Cloudinary upload failed for $uuid — keeping local files");
        }
    }

    db_set_photo_status($uuid, $party_id, 'approved');
    mpd_log('photo.approved', [
        'photo.uuid'  => $uuid,
        'party.id'    => $party_id,
        'party.slug'  => $party['slug'],
        'user.id'     => (int)($_SESSION['mpd_user_id'] ?? 0),
        'user.role'   => $role,
    ]);
    exit(json_encode(['ok' => true, 'action' => 'approved']));
}

// ── reject ────────────────────────────────────────────────────
if ($action === 'reject') {
    $ext    = $photo['original_extension'];
    $dskExt = output_extension($ext);
    foreach ([
        $dirs['quarantine']        . '/' . $uuid . '.' . $ext,
        $dirs['quarantine_thumbs'] . '/' . $uuid . '.jpg',
        $dirs['quarantine_thumbs'] . '/' . $uuid . '.' . $dskExt,
        $dirs['gallery']           . '/' . $uuid . '.' . $dskExt,
        $dirs['gallery_thumbs']    . '/' . $uuid . '.' . $dskExt,
    ] as $path) {
        if (file_exists($path)) @unlink($path);
    }
    if (!empty($photo['cloudinary_public_id'])) {
        cloudinary_delete($photo['cloudinary_public_id']);
    }
    db_set_photo_status($uuid, $party_id, 'rejected');
    mpd_log('photo.rejected', [
        'photo.uuid' => $uuid,
        'party.id'   => $party_id,
        'party.slug' => $party['slug'],
        'user.id'    => (int)($_SESSION['mpd_user_id'] ?? 0),
        'user.role'  => $role,
    ]);
    exit(json_encode(['ok' => true, 'action' => 'rejected']));
}

// ── remove ────────────────────────────────────────────────────
if ($action === 'remove') {
    db_set_photo_status($uuid, $party_id, 'removed');
    mpd_log('photo.moved_to_wastebasket', [
        'photo.uuid' => $uuid,
        'party.id'   => $party_id,
        'party.slug' => $party['slug'],
        'user.id'    => (int)($_SESSION['mpd_user_id'] ?? 0),
        'user.role'  => $role,
    ]);
    exit(json_encode(['ok' => true, 'action' => 'removed']));
}

// ── restore ───────────────────────────────────────────────────
if ($action === 'restore') {
    db_set_photo_status($uuid, $party_id, 'approved');
    mpd_log('photo.restored_from_wastebasket', [
        'photo.uuid' => $uuid,
        'party.id'   => $party_id,
        'party.slug' => $party['slug'],
        'user.id'    => (int)($_SESSION['mpd_user_id'] ?? 0),
        'user.role'  => $role,
    ]);
    exit(json_encode(['ok' => true, 'action' => 'restored']));
}
