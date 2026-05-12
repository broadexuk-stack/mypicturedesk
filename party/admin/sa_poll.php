<?php
declare(strict_types=1);

// ============================================================
// admin/sa_poll.php — Live photo feed for the superadmin grid.
// Returns the current page of photos as JSON.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

function json_out(array $data): never {
    exit(json_encode($data));
}

// Auth: superadmin only (impersonation also accepted since real role is stored separately)
if (empty($_SESSION['mpd_user_id'])) {
    http_response_code(401); json_out(['ok' => false]);
}
$role = $_SESSION['mpd_real_role'] ?? $_SESSION['mpd_role'] ?? '';
if ($role !== 'superadmin') {
    http_response_code(401); json_out(['ok' => false]);
}

// Session timeout
$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    http_response_code(401); json_out(['ok' => false]);
}

$per_page_raw = (int)($_GET['pp'] ?? 20);
$per_page     = in_array($per_page_raw, [20, 60, 150], true) ? $per_page_raw : 20;
$page         = max(1, (int)($_GET['pg'] ?? 1));
$party_filter = max(0, (int)($_GET['party'] ?? 0));
$filter_pid   = $party_filter > 0 ? $party_filter : null;

$photos = db_get_photos_paginated($per_page, ($page - 1) * $per_page, $filter_pid);
$total  = db_count_all_photos($filter_pid);

json_out(['ok' => true, 'photos' => $photos, 'total' => $total]);
