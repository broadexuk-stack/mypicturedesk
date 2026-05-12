<?php
declare(strict_types=1);

// ============================================================
// admin/toggle_status.php — AJAX endpoint: pause / resume a party.
// POST: csrf_token, active (0|1)
// Response: { ok: true, active: bool } | { ok: false, error: "..." }
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

function ts_err(int $code, string $msg): never {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ts_err(405, 'Method not allowed.');

$user_id  = (int)($_SESSION['mpd_user_id'] ?? 0);
$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

if ($user_id === 0) ts_err(401, 'Not logged in.');

$csrf = $_SESSION['admin_csrf'] ?? '';
if (!$csrf || !hash_equals($csrf, $_POST['csrf_token'] ?? '')) ts_err(403, 'Invalid request.');

$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    ts_err(401, 'Session expired. Please log in again.');
}
$_SESSION['admin_last_active'] = time();

// Organizer can only toggle their own party (resolved from session)
if ($role !== 'organizer' && $role !== 'superadmin') ts_err(403, 'Not authorised.');

if ($party_id === 0) ts_err(400, 'No party assigned.');

// Verify organizer owns this party
if ($role === 'organizer') {
    $parties = mpd_get_parties_for_organizer($user_id);
    $owned   = array_map('intval', array_column($parties, 'id'));
    if (!in_array($party_id, $owned, true)) ts_err(403, 'Not authorised.');
}

$active = (bool)(int)($_POST['active'] ?? 0);
mpd_toggle_party_active($party_id, $active);

echo json_encode(['ok' => true, 'active' => $active]);
