<?php
declare(strict_types=1);

// ============================================================
// admin/poll.php — JSON endpoint for organizer moderation page.
// Returns current counts + full photo lists by section.
// Organizer scope only (superadmin uses server-rendered pages).
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['mpd_user_id'])) {
    http_response_code(401);
    exit(json_encode(['ok' => false]));
}

$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

if ($party_id === 0) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'No party assigned.']));
}

$counts   = db_count_photos_by_status($party_id);
$pending  = db_get_photos('pending',  $party_id);
$approved = db_get_photos('approved', $party_id);
$removed  = db_get_photos('removed',  $party_id);

$slim = fn(array $p) => [
    'uuid'               => $p['uuid'],
    'original_extension' => $p['original_extension'],
    'upload_timestamp'   => $p['upload_timestamp'],
    'approved_at'        => $p['approved_at'] ?? null,
    'ip_display'         => $p['ip_display'],
    'uploaded_by'        => $p['uploaded_by'] ?? null,
    'status'             => $p['status'],
];

echo json_encode([
    'ok'      => true,
    'counts'  => $counts,
    'pending' => array_map($slim, $pending),
    'approved'=> array_map($slim, $approved),
    'removed' => array_map($slim, $removed),
]);
