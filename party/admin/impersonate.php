<?php
declare(strict_types=1);

// ============================================================
// admin/impersonate.php — Superadmin session impersonation.
// action=start : swap session to an organiser (superadmin only)
// action=stop  : restore original superadmin session
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$submitted = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['admin_csrf'], $submitted)) {
    header('Location: index.php'); exit;
}

$action = $_POST['action'] ?? '';

// ── Start impersonation ──────────────────────────────────────
if ($action === 'start') {
    if (($_SESSION['mpd_role'] ?? '') !== 'superadmin') {
        header('Location: index.php'); exit;
    }

    $org_id = (int)($_POST['organiser_id'] ?? 0);
    $org    = $org_id > 0 ? mpd_get_user_by_id($org_id) : false;

    if ($org === false || $org['role'] !== 'organizer') {
        header('Location: parties.php'); exit;
    }

    $parties    = mpd_get_parties_for_organizer($org_id);
    $wanted_pid = (int)($_POST['party_id'] ?? 0);

    // Find the requested party; fall back to the organiser's first party
    $chosen = null;
    if ($wanted_pid > 0) {
        foreach ($parties as $p) {
            if ((int)$p['id'] === $wanted_pid) { $chosen = $p; break; }
        }
    }
    if ($chosen === null && !empty($parties)) {
        $chosen = $parties[0];
    }

    // Preserve real identity
    $_SESSION['mpd_real_user_id'] = $_SESSION['mpd_user_id'];
    $_SESSION['mpd_real_role']    = 'superadmin';
    $_SESSION['mpd_real_csrf']    = $_SESSION['admin_csrf'];

    // Become the organiser
    $_SESSION['mpd_user_id']    = $org_id;
    $_SESSION['mpd_role']       = 'organizer';
    $_SESSION['mpd_party_id']   = $chosen ? (int)$chosen['id']  : 0;
    $_SESSION['mpd_party_slug'] = $chosen ? $chosen['slug']      : '';
    $_SESSION['admin_csrf']     = bin2hex(random_bytes(32));
    $_SESSION['admin_last_active'] = time();

    header('Location: index.php'); exit;
}

// ── Stop impersonation ───────────────────────────────────────
if ($action === 'stop') {
    if (empty($_SESSION['mpd_real_user_id'])) {
        header('Location: index.php'); exit;
    }

    $_SESSION['mpd_user_id']  = $_SESSION['mpd_real_user_id'];
    $_SESSION['mpd_role']     = 'superadmin';
    $_SESSION['admin_csrf']   = $_SESSION['mpd_real_csrf'] ?? bin2hex(random_bytes(32));
    $_SESSION['admin_last_active'] = time();

    unset(
        $_SESSION['mpd_real_user_id'],
        $_SESSION['mpd_real_role'],
        $_SESSION['mpd_real_csrf'],
        $_SESSION['mpd_party_id'],
        $_SESSION['mpd_party_slug']
    );

    header('Location: parties.php'); exit;
}

header('Location: index.php');
exit;
