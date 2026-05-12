<?php
declare(strict_types=1);

// ============================================================
// admin/switch_party.php — Switch the active party in session.
// POST only. Verifies the chosen party belongs to this organiser.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

$user_id = (int)($_SESSION['mpd_user_id'] ?? 0);
$role    = $_SESSION['mpd_role'] ?? '';

if ($user_id === 0 || $role !== 'organizer') {
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: index.php'); exit;
}

$party_id = (int)($_POST['party_id'] ?? 0);
if ($party_id > 0) {
    foreach (mpd_get_parties_for_organizer($user_id) as $p) {
        if ((int)$p['id'] === $party_id) {
            $_SESSION['mpd_party_id']   = $party_id;
            $_SESSION['mpd_party_slug'] = $p['slug'];
            break;
        }
    }
}

$allowed  = ['index.php', 'organizer_settings.php'];
$redirect = in_array($_POST['redirect'] ?? '', $allowed, true) ? $_POST['redirect'] : 'index.php';
header('Location: ' . $redirect);
exit;
