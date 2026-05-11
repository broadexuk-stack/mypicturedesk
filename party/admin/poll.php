<?php
declare(strict_types=1);

// ============================================================
// admin/poll.php — Lightweight JSON endpoint for dynamic updates.
// Returns current counts + all photos by section.
// Requires authenticated admin session; GET only.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

ini_set('session.cookie_httponly', '1');
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    exit(json_encode(['ok' => false]));
}

$counts   = db_counts();
$pending  = db_get_photos('pending');
$approved = db_get_photos('approved');
$removed  = db_get_photos('removed');

// Trim to only the fields the client needs
$slim = fn(array $p) => [
    'uuid'               => $p['uuid'],
    'original_extension' => $p['original_extension'],
    'upload_timestamp'   => $p['upload_timestamp'],
    'approved_at'        => $p['approved_at'] ?? null,
    'ip_display'         => $p['ip_display'],
    'status'             => $p['status'],
];

echo json_encode([
    'ok'      => true,
    'counts'  => $counts,
    'pending' => array_map($slim, $pending),
    'approved'=> array_map($slim, $approved),
    'removed' => array_map($slim, $removed),
]);
