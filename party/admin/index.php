<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/cloudinary.php';

// ── Session setup ───────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

// ── Security headers ────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; "
     . "img-src 'self' data: blob: https://res.cloudinary.com; "
     . "script-src 'self' 'nonce-$nonce'; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; "
     . "connect-src 'self'; "
     . "object-src 'none'; "
     . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// ── Session timeout ──────────────────────────────────────────
$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active'])) {
    if (time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
        session_unset(); session_destroy(); session_start();
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
}
if (isset($_SESSION['mpd_user_id'])) {
    $_SESSION['admin_last_active'] = time();
}

$logged_in = !empty($_SESSION['mpd_user_id']);
$role      = $_SESSION['mpd_role'] ?? '';
$error_msg = '';

// ── Logout ───────────────────────────────────────────────────
if ($logged_in && isset($_GET['logout'])) {
    if (hash_equals($_SESSION['admin_csrf'], $_GET['logout'])) {
        session_unset(); session_destroy();
        header('Location: index.php');
        exit;
    }
}

// ── Login POST ───────────────────────────────────────────────
if (!$logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_csrf'], $submitted_csrf)) {
        $error_msg = 'Invalid request. Please try again.';
    } else {
        $email      = trim($_POST['email'] ?? '');
        $pw         = $_POST['password'] ?? '';
        $email_hash = hash('sha256', strtolower($email));

        $client_ip = partial_ip($_SERVER['REMOTE_ADDR'] ?? '');

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

        if (db_is_login_locked($email_hash)) {
            mpd_log('user.login', [
                'event.outcome'    => 'locked',
                'client.address'   => $client_ip,
                'http.user_agent'  => $ua,
            ]);
            $error_msg = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } else {
            $user = mpd_get_user_by_email($email);

            if ($user && $user['is_active'] && $user['password_hash'] &&
                password_verify($pw, $user['password_hash'])) {

                db_clear_login_failures($email_hash);
                session_regenerate_id(true);
                $_SESSION['mpd_user_id']      = (int)$user['id'];
                $_SESSION['mpd_role']         = $user['role'];
                $_SESSION['admin_last_active']= time();
                $_SESSION['admin_csrf']       = bin2hex(random_bytes(32));
                mpd_update_last_login((int)$user['id']);

                mpd_log('user.login', [
                    'event.outcome'   => 'success',
                    'user.id'         => (int)$user['id'],
                    'user.role'       => $user['role'],
                    'client.address'  => $client_ip,
                    'http.user_agent' => $ua,
                ]);

                // Organizer: resolve their party
                if ($user['role'] === 'organizer') {
                    $parties = mpd_get_parties_for_organizer((int)$user['id']);
                    if (!empty($parties)) {
                        $_SESSION['mpd_party_id']   = (int)$parties[0]['id'];
                        $_SESSION['mpd_party_slug'] = $parties[0]['slug'];
                    }
                }
                header('Location: index.php');
                exit;
            } else {
                db_record_login_failure($email_hash);
                mpd_log('user.login', [
                    'event.outcome'   => 'failure',
                    'client.address'  => $client_ip,
                    'http.user_agent' => $ua,
                ]);
                $error_msg = 'Login failed. Please check your email and password.';
                usleep(500_000);
            }
        }
    }
}

$csrf = $_SESSION['admin_csrf'];

// ── Role-specific data loading ───────────────────────────────
$party_slug = $_SESSION['mpd_party_slug'] ?? '';
$party_id   = (int)($_SESSION['mpd_party_id'] ?? 0);

// Superadmin: load paginated global view
$sa_parties     = [];
$sa_photos      = [];
$sa_total       = 0;
$sa_per_page    = 20;
$sa_page        = 1;
$sa_party_filter= 0;

// Organizer: load moderation data
$org_parties = [];
$counts      = [];
$pending     = [];
$approved    = [];
$removed     = [];
$is_active   = true;

if ($logged_in) {
    if ($role === 'superadmin') {
        $sa_parties      = mpd_get_all_parties();
        $sa_per_page_raw = (int)($_GET['pp'] ?? 20);
        $sa_per_page     = in_array($sa_per_page_raw, [20, 60, 150], true) ? $sa_per_page_raw : 20;
        $sa_page         = max(1, (int)($_GET['pg'] ?? 1));
        $sa_party_filter = max(0, (int)($_GET['party'] ?? 0));
        $filter_pid      = $sa_party_filter > 0 ? $sa_party_filter : null;
        $sa_total        = db_count_all_photos($filter_pid);
        $sa_photos       = db_get_photos_paginated($sa_per_page, ($sa_page - 1) * $sa_per_page, $filter_pid);
    } elseif ($role === 'organizer' && $party_id > 0) {
        $org_parties = mpd_get_parties_for_organizer((int)$_SESSION['mpd_user_id']);
        $party_data  = mpd_get_party_by_id($party_id);
        $is_active   = $party_data ? (bool)$party_data['is_active'] : true;
        $counts   = db_count_photos_by_status($party_id);
        $pending  = db_get_photos('pending',  $party_id);
        $approved = db_get_photos('approved', $party_id);
        $removed  = db_get_photos('removed',  $party_id);
    }
}

// ── Helpers ──────────────────────────────────────────────────

function thumb_url(array $p, string $slug): string {
    if ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at']))) {
        return 'thumb.php?uuid=' . urlencode($p['uuid']) . '&party=' . urlencode($slug);
    }
    if (!empty($p['cloudinary_public_id'])) {
        return cloudinary_admin_thumb_url($p['cloudinary_public_id']);
    }
    $ext = output_extension($p['original_extension']);
    return '../image.php?party=' . urlencode($slug)
         . '&dir=gallery_thumbs&uuid=' . urlencode($p['uuid']) . '&ext=' . urlencode($ext);
}

function full_url(array $p, string $slug): string {
    if ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at']))) {
        return 'thumb.php?uuid=' . urlencode($p['uuid']) . '&party=' . urlencode($slug) . '&full=1';
    }
    if (!empty($p['cloudinary_public_id'])) {
        return cloudinary_full_url($p['cloudinary_public_id']);
    }
    $ext = output_extension($p['original_extension']);
    return '../image.php?party=' . urlencode($slug)
         . '&dir=gallery&uuid=' . urlencode($p['uuid']) . '&ext=' . urlencode($ext);
}

function dl_filename(array $p): string {
    $name = !empty($p['uploaded_by'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($p['uploaded_by'], 0, 30)) . '_'
        : 'photo_';
    $ts  = date('Ymd_His', strtotime($p['upload_timestamp']));
    $ext = output_extension($p['original_extension']);
    return $name . $ts . '.' . $ext;
}

// Impersonation: get the organiser's email for the banner label
$impersonating_email = '';
if (!empty($_SESSION['mpd_real_user_id']) && $role === 'organizer') {
    $imp_user = mpd_get_user_by_id((int)$_SESSION['mpd_user_id']);
    $impersonating_email = $imp_user ? $imp_user['email'] : '';
}

$page_title = $role === 'superadmin' ? 'Super Admin — MyPictureDesk'
    : 'Admin — ' . htmlspecialchars($party_slug);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $page_title ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; font-size: 1rem; }

    /* ── Login ── */
    .login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
    .login-card { background: #2d1b69; border-radius: 16px; padding: 40px 36px; width: 100%; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.4); }
    .login-card h1 { font-size: 1.6rem; font-weight: 900; margin-bottom: 24px; text-align: center; }
    .login-card label { display: block; font-size: 0.9rem; margin-bottom: 6px; color: #c9b8ff; margin-top: 16px; }
    .login-card input[type=email],
    .login-card input[type=password] { width: 100%; padding: 12px 14px; border-radius: 8px; border: 2px solid #4b35a0; background: #160f35; color: #f0ebff; font-size: 1rem; font-family: inherit; }
    .login-card input:focus { outline: none; border-color: #f5a623; }
    .btn-login { margin-top: 20px; width: 100%; padding: 14px; background: #f5a623; color: #1a1035; font-weight: 900; font-size: 1.1rem; border: none; border-radius: 10px; cursor: pointer; font-family: inherit; }
    .btn-login:hover { background: #e6941a; }
    .login-error { background: #c0392b; color: #fff; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.95rem; }
    .login-links { text-align: center; margin-top: 16px; font-size: 0.82rem; }
    .login-links a { color: #9c7fff; }

    /* ── Stats / nav bar ── */
    .stats-bar {
      position: sticky; top: 0; z-index: 50; height: 50px;
      background: #160f35; border-bottom: 1px solid #2d1b69;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 20px; gap: 12px;
    }
    .stats-bar .stat-items { display: flex; align-items: center; gap: 20px; }
    .stat-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #c9b8ff; white-space: nowrap; }
    .stat-item strong { color: #f5a623; font-size: 1rem; font-weight: 900; }
    .stat-item.has-pending strong { color: #e74c3c; }
    .stats-bar .nav-links { display: flex; gap: 14px; align-items: center; }
    .stats-bar .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; white-space: nowrap; }
    .stats-bar .nav-link:hover { color: #f5a623; }
    .stats-bar .nav-link.active { color: #f5a623; font-weight: 700; }
    .stats-bar .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; white-space: nowrap; }
    .stats-bar .signout:hover { color: #f5a623; }
    .poll-dot { width: 7px; height: 7px; border-radius: 50%; background: #27ae60; display: inline-block; margin-left: 6px; transition: background 0.3s; }
    .poll-dot.error { background: #e74c3c; }

    /* ── Super admin toolbar ── */
    .sa-toolbar { max-width: 1400px; margin: 16px auto 0; padding: 0 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .sa-toolbar select, .sa-toolbar input[type=submit] { font-family: inherit; font-size: 0.85rem; padding: 7px 12px; border-radius: 8px; border: 1px solid #4b35a0; background: #2d1b69; color: #f0ebff; cursor: pointer; }
    .sa-toolbar input[type=submit] { background: #f5a623; color: #1a1035; font-weight: 700; border: none; }
    .sa-toolbar input[type=submit]:hover { background: #e6941a; }
    .pp-btn { font-family: inherit; font-size: 0.82rem; padding: 6px 12px; border-radius: 8px; border: 1px solid #4b35a0; background: #2d1b69; color: #c9b8ff; cursor: pointer; text-decoration: none; }
    .pp-btn.active { background: #7c3aed; border-color: #7c3aed; color: #fff; font-weight: 700; }
    .pp-btn:hover:not(.active) { background: #3d2494; }
    .sa-total { font-size: 0.82rem; color: #6b5ca5; margin-left: auto; }

    .sa-pagination { max-width: 1400px; margin: 16px auto 0; padding: 0 20px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .page-btn { font-family: inherit; font-size: 0.82rem; padding: 6px 14px; border-radius: 8px; border: 1px solid #4b35a0; background: #2d1b69; color: #c9b8ff; cursor: pointer; text-decoration: none; }
    .page-btn:hover { background: #3d2494; }
    .page-info { font-size: 0.82rem; color: #6b5ca5; padding: 0 4px; }

    /* ── Admin body ── */
    .admin-body { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .section-heading { font-size: 1rem; font-weight: 900; color: #c9b8ff; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
    .section-heading .count-pill { background: #e74c3c; color: #fff; border-radius: 999px; padding: 1px 8px; font-size: 0.75rem; }
    .section-divider { border: none; border-top: 2px solid #2d1b69; margin: 32px 0; }

    /* ── Photo grid ── */
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
    .photo-card { background: #2d1b69; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
    .photo-card img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; cursor: zoom-in; }
    .photo-card .card-meta { padding: 8px 10px; font-size: 0.75rem; color: #c9b8ff; flex: 1; }
    .photo-card .card-meta time { display: block; margin-bottom: 2px; }
    .photo-card .card-actions { display: flex; gap: 6px; padding: 8px 10px; }
    .btn-approve { flex: 1; padding: 8px 0; background: #27ae60; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit; }
    .btn-approve:hover { background: #219150; }
    .btn-reject  { flex: 1; padding: 8px 0; background: #e74c3c; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit; }
    .btn-reject:hover { background: #c0392b; }
    .btn-remove  { flex: 1; padding: 8px 0; background: #4a3580; color: #c9b8ff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit; }
    .btn-remove:hover { background: #5a4590; color: #fff; }
    .btn-restore { flex: 1; padding: 8px 0; background: #2471a3; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit; }
    .btn-restore:hover { background: #1a5276; }
    .btn-dl-photo { flex: 0 0 auto; padding: 8px 10px; background: #0f2d47; color: #7fb3e8; border: none; border-radius: 8px; font-size: 0.85rem; cursor: pointer; text-decoration: none; display: flex; align-items: center; line-height: 1; }
    .btn-dl-photo:hover { background: #163d5e; color: #a8cfee; }
    .empty-msg { color: #4a3580; font-size: 0.95rem; padding: 16px 0; }
    .card-name { display: block; color: #f5a623; font-weight: 700; margin-bottom: 2px; }
    .card-party-badge { display: inline-block; background: #160f35; color: #9c7fff; font-size: 0.68rem; font-weight: 700; border-radius: 6px; padding: 1px 6px; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.04em; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .approved-heading-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
    .approved-heading-bar .section-heading,
    .wastebasket-bar .section-heading { margin-bottom: 0; }
    .heading-count-sub { color:#4a3580; font-weight:400; text-transform:none; letter-spacing:0; font-size:0.85rem; }
    .btn-dl-gallery { padding: 7px 14px; background: #0f2d47; color: #7fb3e8; border: none; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-dl-gallery:hover { background: #163d5e; color: #a8cfee; }
    .btn-dl-gallery[aria-disabled="true"] { opacity: 0.35; pointer-events: none; }
    .wastebasket-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
    .btn-purge { padding: 8px 20px; background: #922b21; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; font-family: inherit; }
    .btn-purge:hover { background: #7b241c; }
    .btn-purge:disabled { opacity: 0.4; cursor: not-allowed; }

    .photo-card.removing { opacity: 0; transform: scale(0.9); transition: all 0.3s ease; pointer-events: none; }
    @keyframes card-pop { from { opacity: 0; transform: scale(0.85); } to { opacity: 1; transform: scale(1); } }
    .photo-card.card-new { animation: card-pop 0.35s ease; }

    /* ── Lightbox ── */
    .lb { position: fixed; inset: 0; z-index: 200; background: rgba(0,0,0,0.93); display: flex; align-items: center; justify-content: center; padding: 16px; }
    .lb[hidden] { display: none; }
    .lb-figure { display: flex; flex-direction: column; align-items: center; max-width: min(92vw, 960px); gap: 14px; }
    .lb-img { max-width: 100%; max-height: 72vh; object-fit: contain; border-radius: 10px; display: block; }
    .lb-meta { background: rgba(255,255,255,0.07); border-radius: 10px; padding: 12px 18px; width: 100%; display: flex; flex-wrap: wrap; gap: 4px 28px; }
    .lb-meta-row { display: flex; gap: 4px; font-size: 0.85rem; color: #c9b8ff; align-items: center; }
    .lb-label { color: #f5a623; font-weight: 700; }
    .lb-close { position: fixed; top: 14px; right: 18px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.6rem; line-height: 1; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .lb-close:hover { background: rgba(255,255,255,0.25); }
    .lb-nav { position: fixed; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); border: none; color: #fff; font-size: 2.4rem; line-height: 1; width: 50px; height: 70px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; }
    .lb-nav:hover:not(:disabled) { background: rgba(255,255,255,0.22); }
    .lb-nav:disabled { opacity: 0.18; cursor: default; }
    .lb-prev { left: 10px; }
    .lb-next { right: 10px; }
    .lb-counter { font-size: 0.78rem; color: #6b5ca5; position: fixed; bottom: 14px; left: 50%; transform: translateX(-50%); }

    /* ── Impersonation banner ── */
    .impersonate-banner { background: #f5a623; color: #1a1035; padding: 8px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 0.88rem; font-weight: 700; flex-wrap: wrap; }
    .impersonate-banner form { display: inline; }
    .btn-stop-imp { padding: 5px 14px; background: #1a1035; color: #f5a623; border: none; border-radius: 6px; font-weight: 700; font-size: 0.82rem; cursor: pointer; font-family: inherit; }
    .btn-stop-imp:hover { background: #2d1b69; color: #f5a623; }

    /* ── Organizer nav links ── */
    .org-nav { max-width:1400px; margin:12px auto 0; padding:0 20px; display:flex; gap:10px; flex-wrap:wrap; }
    .org-nav-link { font-family:inherit; font-size:0.82rem; padding:7px 14px; background:#2d1b69; color:#c9b8ff; border-radius:8px; text-decoration:none; border:1px solid #4b35a0; }
    .org-nav-link:hover { background:#3d2494; color:#f0ebff; }

    /* ── Party switcher ── */
    .party-switch-sel { font-family:inherit; font-size:0.82rem; padding:5px 10px; border-radius:8px; border:1px solid #4b35a0; background:#2d1b69; color:#c9b8ff; cursor:pointer; max-width:200px; }

    /* ── Status pill + pause/resume button ── */
    .status-pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; transition:background 0.2s, color 0.2s; }
    .pill-live   { background:#1a4a2e; color:#6ee7a0; }
    .pill-paused { background:#4a1a1a; color:#f87171; }
    .status-pill.poll-error { outline:2px solid #f5a623; outline-offset:2px; }
    .btn-pause-topbar { padding:4px 12px; border:none; border-radius:6px; font-size:0.78rem; font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap; }
    .btn-pause-live   { background:#4a3580; color:#c9b8ff; }
    .btn-pause-live:hover   { background:#5a4590; color:#fff; }
    .btn-pause-paused { background:#27ae60; color:#fff; }
    .btn-pause-paused:hover { background:#219150; }
    .btn-pause-topbar:disabled { opacity:0.5; cursor:not-allowed; }
  </style>
</head>
<body>

<?php if (!$logged_in): ?>
<!-- ══════════════════ LOGIN ══════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <h1>🔐 Admin Login</h1>
    <?php if ($error_msg): ?>
      <div class="login-error" role="alert"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <form method="post" action="index.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autofocus autocomplete="username"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <label for="pw">Password</label>
      <input type="password" id="pw" name="password" required autocomplete="current-password">
      <button class="btn-login" type="submit">Sign In</button>
    </form>
    <div class="login-links">
      <a href="setpassword.php">Set / reset password with invitation link</a>
    </div>
  </div>
</div>

<?php elseif ($role === 'superadmin'): ?>
<!-- ══════════════════ SUPER ADMIN DASHBOARD ══════════════════ -->

<div class="stats-bar" role="region" aria-label="Super Admin">
  <div class="nav-links">
    <a class="nav-link active" href="index.php">📸 Dashboard</a>
    <a class="nav-link" href="parties.php">🎉 Parties</a>
    <a class="nav-link" href="users.php">👥 Users</a>
    <a class="nav-link" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <div class="nav-links">
    <span class="stat-item">⭐ Super Admin</span>
    <span class="stat-item" style="color:#6b5ca5;font-size:0.78rem;">
      <?= count($sa_parties) ?> <?= count($sa_parties) === 1 ? 'party' : 'parties' ?>
    </span>
    <span class="stat-item" title="Live — updates every 10 s">
      <span class="poll-dot" id="sa-poll-dot"></span>
    </span>
    <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
  </div>
</div>

<!-- Party filter + per-page toolbar -->
<form class="sa-toolbar" method="get" action="index.php" id="sa-filter-form">
  <label for="sa-party-sel" style="font-size:0.82rem;color:#c9b8ff;">Party:</label>
  <select id="sa-party-sel" name="party">
    <option value="0" <?= $sa_party_filter === 0 ? 'selected' : '' ?>>All parties</option>
    <?php foreach ($sa_parties as $pt): ?>
      <option value="<?= (int)$pt['id'] ?>"
              <?= $sa_party_filter === (int)$pt['id'] ? 'selected' : '' ?>>
        <?= $pt['is_active'] ? '▶' : '⏸' ?> <?= htmlspecialchars($pt['party_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label for="sa-pp-sel" style="font-size:0.82rem;color:#c9b8ff;">Per page:</label>
  <select id="sa-pp-sel" name="pp">
    <?php foreach ([20, 60, 150] as $pp): ?>
      <option value="<?= $pp ?>" <?= $sa_per_page === $pp ? 'selected' : '' ?>><?= $pp ?></option>
    <?php endforeach; ?>
  </select>

  <input type="hidden" name="pg" value="1">

  <span class="sa-total"><?= $sa_total ?> photos total</span>
</form>

<?php
$sa_pages = max(1, (int)ceil($sa_total / $sa_per_page));
if ($sa_pages > 1):
?>
<div class="sa-pagination">
  <?php if ($sa_page > 1): ?>
    <a class="page-btn" href="?party=<?= $sa_party_filter ?>&pp=<?= $sa_per_page ?>&pg=<?= $sa_page - 1 ?>">← Prev</a>
  <?php endif; ?>
  <span class="page-info">Page <?= $sa_page ?> of <?= $sa_pages ?></span>
  <?php if ($sa_page < $sa_pages): ?>
    <a class="page-btn" href="?party=<?= $sa_party_filter ?>&pp=<?= $sa_per_page ?>&pg=<?= $sa_page + 1 ?>">Next →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="admin-body">
  <div class="photo-grid" id="sa-grid" role="list">
    <?php if (empty($sa_photos)): ?>
      <p class="empty-msg">No photos found.</p>
    <?php else: ?>
      <?php foreach ($sa_photos as $p):
        $p_slug   = $p['party_slug'] ?? '';
        $disk_ext = output_extension($p['original_extension']);
        $thumb_src = ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at'])))
            ? 'thumb.php?uuid=' . urlencode($p['uuid']) . '&party=' . urlencode($p_slug)
            : '../image.php?party=' . urlencode($p_slug) . '&dir=gallery_thumbs&uuid=' . urlencode($p['uuid']) . '&ext=' . urlencode($disk_ext);
        $full_src = ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at'])))
            ? 'thumb.php?uuid=' . urlencode($p['uuid']) . '&party=' . urlencode($p_slug) . '&full=1'
            : '../image.php?party=' . urlencode($p_slug) . '&dir=gallery&uuid=' . urlencode($p['uuid']) . '&ext=' . urlencode($disk_ext);
      ?>
      <div class="photo-card" id="sa-card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
           data-full-url="<?= htmlspecialchars($full_src) ?>"
           data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?>"
           data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
           data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
           data-filetype="<?= htmlspecialchars(strtoupper($disk_ext)) ?>"
           data-section-label="<?= htmlspecialchars(ucfirst($p['status'])) ?>">
        <img src="<?= htmlspecialchars($thumb_src) ?>" alt="Photo" loading="lazy" onerror="this.style.display='none'">
        <div class="card-meta">
          <span class="card-party-badge"><?= htmlspecialchars($p['party_name'] ?? $p_slug) ?></span>
          <?php if (!empty($p['uploaded_by'])): ?>
            <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
          <?php endif; ?>
          <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
          <?= htmlspecialchars(ucfirst($p['status'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($sa_pages > 1): ?>
<div class="sa-pagination" style="margin-bottom:32px;">
  <?php if ($sa_page > 1): ?>
    <a class="page-btn" href="?party=<?= $sa_party_filter ?>&pp=<?= $sa_per_page ?>&pg=<?= $sa_page - 1 ?>">← Prev</a>
  <?php endif; ?>
  <span class="page-info">Page <?= $sa_page ?> of <?= $sa_pages ?></span>
  <?php if ($sa_page < $sa_pages): ?>
    <a class="page-btn" href="?party=<?= $sa_party_filter ?>&pp=<?= $sa_per_page ?>&pg=<?= $sa_page + 1 ?>">Next →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════ ORGANIZER MODERATION VIEW ══════════════════ -->

<?php if ($impersonating_email !== ''): ?>
<div class="impersonate-banner" role="alert">
  <span>👁 Viewing as organiser: <strong><?= htmlspecialchars($impersonating_email) ?></strong><?= $party_slug !== '' ? ' &mdash; party: <strong>' . htmlspecialchars($party_slug) . '</strong>' : '' ?></span>
  <form method="post" action="impersonate.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="stop">
    <button type="submit" class="btn-stop-imp">&#8592; Return to superadmin</button>
  </form>
</div>
<?php endif; ?>

<!-- Stats / nav bar -->
<div class="stats-bar" role="region" aria-label="Statistics">
  <div class="stat-items">
    <div class="stat-item <?= ($counts['pending'] ?? 0) > 0 ? 'has-pending' : '' ?>">
      ⏳ Pending <strong id="stat-pending"><?= $counts['pending'] ?? 0 ?></strong>
    </div>
    <div class="stat-item">
      ✅ Approved <strong id="stat-approved"><?= $counts['approved'] ?? 0 ?></strong>
    </div>
    <div class="stat-item">
      🗑️ Wastebasket <strong id="stat-removed"><?= $counts['removed'] ?? 0 ?></strong>
    </div>
    <div class="stat-item">
      📸 Total <strong><?= ($counts['pending'] ?? 0) + ($counts['approved'] ?? 0) + ($counts['removed'] ?? 0) ?></strong>
    </div>
    <div class="stat-item">
      <span class="status-pill <?= $is_active ? 'pill-live' : 'pill-paused' ?>"
            id="status-pill"
            title="Updates every 10 s">
        <?= $is_active ? 'Live' : 'Paused' ?>
      </span>
    </div>
  </div>
  <div class="nav-links">
    <?php if (count($org_parties) > 1): ?>
    <form id="party-switch-form" method="post" action="switch_party.php" style="display:flex;align-items:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="redirect" value="index.php">
      <select class="party-switch-sel" id="party-switch-sel" name="party_id" aria-label="Switch party">
        <?php foreach ($org_parties as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $party_id ? 'selected' : '' ?>>
            <?= $p['is_active'] ? '▶' : '⏸' ?> <?= htmlspecialchars($p['party_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <button class="btn-pause-topbar <?= $is_active ? 'btn-pause-live' : 'btn-pause-paused' ?>"
            id="btn-pause-toggle"
            title="<?= $is_active ? 'Pause the gallery — guests will see a paused message' : 'Resume the gallery for guests' ?>">
      <?= $is_active ? '⏸ Pause' : '▶ Resume' ?>
    </button>
    <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
  </div>
</div>

<!-- Organizer quick-nav -->
<nav class="org-nav">
  <a class="org-nav-link" href="organizer_settings.php" title="Change or update the configuration of your party">⚙️ Party Settings</a>
  <a class="org-nav-link" href="qrcode.php" title="Download a QR Code for people to scan to take pictures">📱 QR Code</a>
  <a class="org-nav-link" href="download_gallery.php" title="Download a zip file containing all your party pictures" <?= empty($approved) ? 'aria-disabled="true" style="opacity:.4;pointer-events:none"' : '' ?>>⬇ Download Gallery</a>
  <a class="org-nav-link" href="../slideshow.php?id=<?= urlencode($party_slug) ?>" target="_blank" title="Open a slideshow window to display all your pictures" <?= empty($approved) ? 'aria-disabled="true" style="opacity:.4;pointer-events:none"' : '' ?>>▶ Slideshow</a>
</nav>

<div class="admin-body">

  <!-- ── Pending ── -->
  <div class="section-heading">
    ⏳ Awaiting Approval
    <span class="count-pill" id="pending-pill"<?= ($counts['pending'] ?? 0) === 0 ? ' hidden' : '' ?>><?= $counts['pending'] ?? 0 ?></span>
  </div>

  <div class="photo-grid" id="pending-grid" role="list">
    <?php if (empty($pending)): ?>
      <p class="empty-msg">No photos waiting for review.</p>
    <?php else: ?>
      <?php foreach ($pending as $p): ?>
      <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
           data-full-url="<?= htmlspecialchars(full_url($p, $party_slug)) ?>"
           data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?>"
           data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
           data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
           data-filetype="<?= htmlspecialchars(strtoupper($p['original_extension'])) ?>"
           data-section-label="Awaiting Approval">
        <img src="<?= htmlspecialchars(thumb_url($p, $party_slug)) ?>" alt="Pending photo" loading="lazy" onerror="this.style.display='none'">
        <div class="card-meta">
          <?php if (!empty($p['uploaded_by'])): ?>
            <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
          <?php endif; ?>
          <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
          IP: <?= htmlspecialchars($p['ip_display']) ?>
        </div>
        <div class="card-actions">
          <button class="btn-approve" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="approve" data-section="pending" aria-label="Approve" title="Move this image into your gallery">✅</button>
          <button class="btn-remove"  data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="remove"  data-section="pending" aria-label="Move to wastebasket" title="Move this picture into the wastebasket before removal">🗑️</button>
          <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p, $party_slug)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download" title="Download this picture to your device">⬇</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <hr class="section-divider">

  <!-- ── Approved ── -->
  <div class="approved-heading-bar">
    <div class="section-heading">
      ✅ In the Gallery
      <span class="heading-count-sub">(<?= $counts['approved'] ?? 0 ?>)</span>
    </div>
    <a href="download_gallery.php"
       class="btn-dl-gallery"
       <?= empty($approved) ? 'aria-disabled="true"' : '' ?>
       aria-label="Download all gallery photos as ZIP"
       title="Download a zip file containing all your party pictures">
      ⬇ Download Gallery
    </a>
  </div>

  <div class="photo-grid" id="approved-grid" role="list">
    <?php if (empty($approved)): ?>
      <p class="empty-msg">No approved photos yet.</p>
    <?php else: ?>
      <?php foreach ($approved as $p): ?>
      <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
           data-full-url="<?= htmlspecialchars(full_url($p, $party_slug)) ?>"
           data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['approved_at'] ?? $p['upload_timestamp']))) ?>"
           data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
           data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
           data-filetype="<?= htmlspecialchars(strtoupper(output_extension($p['original_extension']))) ?>"
           data-section-label="In the Gallery">
        <img src="<?= htmlspecialchars(thumb_url($p, $party_slug)) ?>" alt="Approved photo" loading="lazy" onerror="this.style.display='none'">
        <div class="card-meta">
          <?php if (!empty($p['uploaded_by'])): ?>
            <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
          <?php endif; ?>
          <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['approved_at'] ?? $p['upload_timestamp']))) ?></time>
          IP: <?= htmlspecialchars($p['ip_display']) ?>
        </div>
        <div class="card-actions">
          <button class="btn-remove" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="remove" data-section="approved" aria-label="Move to wastebasket" title="Move this picture into the wastebasket before removal">🗑️ Remove</button>
          <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p, $party_slug)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download" title="Download this picture to your device">⬇</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <hr class="section-divider">

  <!-- ── Wastebasket ── -->
  <div class="wastebasket-bar">
    <div class="section-heading">
      🗑️ Wastebasket
      <span class="count-pill" id="waste-pill"<?= ($counts['removed'] ?? 0) === 0 ? ' hidden' : '' ?>><?= $counts['removed'] ?? 0 ?></span>
    </div>
    <button class="btn-purge" id="btn-purge-all"
            <?= empty($removed) ? 'disabled' : '' ?>
            aria-label="Permanently delete all wastebasket photos"
            title="Permanently delete all images in your wastebasket">
      🗑️ Empty Wastebasket
    </button>
  </div>

  <div class="photo-grid" id="wastebasket-grid" role="list">
  <?php if (empty($removed)): ?>
    <p class="empty-msg">Wastebasket is empty.</p>
  <?php else: ?>
    <?php foreach ($removed as $p): ?>
    <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
         data-full-url="<?= htmlspecialchars(full_url($p, $party_slug)) ?>"
         data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?>"
         data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
         data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
         data-filetype="<?= htmlspecialchars(strtoupper($p['original_extension'])) ?>"
         data-section-label="Wastebasket">
      <img src="<?= htmlspecialchars(thumb_url($p, $party_slug)) ?>" alt="Wastebasket photo" loading="lazy" onerror="this.style.display='none'">
      <div class="card-meta">
        <?php if (!empty($p['uploaded_by'])): ?>
          <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
        <?php endif; ?>
        <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
        IP: <?= htmlspecialchars($p['ip_display']) ?>
      </div>
      <div class="card-actions">
        <button class="btn-restore" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="restore" data-section="removed" aria-label="Restore to gallery">↩️ Restore</button>
        <button class="btn-reject"  data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="reject"  data-section="removed" aria-label="Delete permanently">✕</button>
        <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p, $party_slug)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download" title="Download this picture to your device">⬇</a>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

</div><!-- /admin-body -->

<?php endif; /* role branches */ ?>

<!-- ── Lightbox (shared by both roles) ── -->
<div id="lb" class="lb" hidden role="dialog" aria-modal="true" aria-label="Photo preview">
  <button class="lb-close" id="lb-close" aria-label="Close lightbox">×</button>
  <button class="lb-nav lb-prev" id="lb-prev" aria-label="Previous photo">&#8249;</button>
  <button class="lb-nav lb-next" id="lb-next" aria-label="Next photo">&#8250;</button>
  <figure class="lb-figure">
    <img id="lb-img" src="" alt="Full size photo" class="lb-img">
    <figcaption class="lb-meta">
      <div class="lb-meta-row" id="lb-name-row"><span class="lb-label">Name:</span><span id="lb-name"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Uploaded:</span><span id="lb-time"></span></div>
      <div class="lb-meta-row"><span class="lb-label">IP:</span><span id="lb-ip"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Section:</span><span id="lb-section"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Type:</span><span id="lb-type"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Resolution:</span><span id="lb-res">—</span></div>
    </figcaption>
  </figure>
  <div class="lb-counter" id="lb-counter"></div>
</div>

<?php if ($logged_in): ?>
<script nonce="<?= $nonce ?>">
(function () {
  'use strict';
  const CSRF          = <?= json_encode($csrf) ?>;
  const IS_SUPERADMIN = <?= json_encode($role === 'superadmin') ?>;
  const PARTY_SLUG    = <?= json_encode($party_slug) ?>;
  const SA_PARTY_FILTER = <?= json_encode($sa_party_filter) ?>;
  const SA_PER_PAGE     = <?= json_encode($sa_per_page) ?>;
  const SA_PAGE         = <?= json_encode($sa_page) ?>;
  const CLOUD_NAME    = <?= json_encode(defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '') ?>;

  // ── Lightbox ─────────────────────────────────────────────────
  const lb         = document.getElementById('lb');
  const lbImg      = document.getElementById('lb-img');
  const lbTime     = document.getElementById('lb-time');
  const lbIp       = document.getElementById('lb-ip');
  const lbSection  = document.getElementById('lb-section');
  const lbCloseBtn = document.getElementById('lb-close');
  const lbPrev     = document.getElementById('lb-prev');
  const lbNext     = document.getElementById('lb-next');
  const lbCounter  = document.getElementById('lb-counter');
  const lbName     = document.getElementById('lb-name');
  const lbNameRow  = document.getElementById('lb-name-row');
  const lbType     = document.getElementById('lb-type');
  const lbRes      = document.getElementById('lb-res');

  lbImg.addEventListener('load', () => {
    if (lbImg.naturalWidth && lbImg.naturalHeight)
      lbRes.textContent = lbImg.naturalWidth + ' × ' + lbImg.naturalHeight;
  });

  let lbCards = [], lbIdx = 0;

  function lbGetCards() {
    return [...document.querySelectorAll('.photo-card:not(.removing)')];
  }

  function lbRefresh() {
    const card = lbCards[lbIdx];
    if (!card) return;
    lbRes.textContent     = '—';
    lbImg.src             = card.dataset.fullUrl || '';
    lbTime.textContent    = card.dataset.timestamp || '';
    lbIp.textContent      = card.dataset.ip || '';
    lbSection.textContent = card.dataset.sectionLabel || '';
    lbType.textContent    = card.dataset.filetype || '—';
    const name = card.dataset.name || '';
    if (lbName)    lbName.textContent = name;
    if (lbNameRow) lbNameRow.hidden   = !name;
    lbPrev.disabled    = lbIdx === 0;
    lbNext.disabled    = lbIdx === lbCards.length - 1;
    lbCounter.textContent = (lbIdx + 1) + ' / ' + lbCards.length;
  }

  function lbOpen(card) {
    lbCards = lbGetCards();
    lbIdx   = lbCards.indexOf(card);
    if (lbIdx === -1) lbIdx = 0;
    lbRefresh();
    lb.hidden = false;
    document.body.style.overflow = 'hidden';
    lbCloseBtn.focus();
  }

  function lbHide() {
    lb.hidden = true;
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  lbCloseBtn.addEventListener('click', lbHide);
  lb.addEventListener('click', e => { if (e.target === lb) lbHide(); });
  lbPrev.addEventListener('click', () => { if (lbIdx > 0) { lbIdx--; lbRefresh(); } });
  lbNext.addEventListener('click', () => { if (lbIdx < lbCards.length - 1) { lbIdx++; lbRefresh(); } });

  document.addEventListener('keydown', e => {
    if (lb.hidden) return;
    if (e.key === 'Escape')     { lbHide(); }
    if (e.key === 'ArrowLeft'  && lbIdx > 0)                  { lbIdx--; lbRefresh(); }
    if (e.key === 'ArrowRight' && lbIdx < lbCards.length - 1) { lbIdx++; lbRefresh(); }
  });

  document.addEventListener('click', e => {
    const img = e.target.closest('.photo-card img');
    if (!img) return;
    lbOpen(img.closest('.photo-card'));
  });

  // ── Shared utilities ─────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function outputExt(ext) {
    const l = (ext || '').toLowerCase();
    return (l === 'heic' || l === 'heif') ? 'jpg' : l;
  }

  function fmtDate(ts) {
    if (!ts) return '';
    const d = new Date(ts.replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
         + ' ' + d.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit'});
  }

  // ── Superadmin live grid ──────────────────────────────────────
  if (IS_SUPERADMIN) {
    const saDot = document.getElementById('sa-poll-dot');

    // Wire up filter form selects
    const saForm = document.getElementById('sa-filter-form');
    if (saForm) {
      ['sa-party-sel', 'sa-pp-sel'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => saForm.submit());
      });
    }

    function buildSACard(p) {
      const slug = p.party_slug || '';
      const ext  = outputExt(p.original_extension);
      let thumbSrc, fullSrc;
      if (p.status === 'pending' || (p.status === 'removed' && !p.approved_at)) {
        thumbSrc = 'thumb.php?uuid=' + encodeURIComponent(p.uuid) + '&party=' + encodeURIComponent(slug);
        fullSrc  = thumbSrc + '&full=1';
      } else {
        thumbSrc = '../image.php?party=' + encodeURIComponent(slug)
                 + '&dir=gallery_thumbs&uuid=' + encodeURIComponent(p.uuid)
                 + '&ext=' + encodeURIComponent(ext);
        fullSrc  = '../image.php?party=' + encodeURIComponent(slug)
                 + '&dir=gallery&uuid=' + encodeURIComponent(p.uuid)
                 + '&ext=' + encodeURIComponent(ext);
      }
      const div = document.createElement('div');
      div.className = 'photo-card';
      div.id = 'sa-card-' + p.uuid;
      div.setAttribute('role', 'listitem');
      div.dataset.fullUrl      = fullSrc;
      div.dataset.timestamp    = fmtDate(p.upload_timestamp);
      div.dataset.ip           = p.ip_display;
      div.dataset.name         = p.uploaded_by || '';
      div.dataset.sectionLabel = (p.status || '').charAt(0).toUpperCase() + (p.status || '').slice(1);
      div.dataset.filetype     = ext.toUpperCase();
      const nameLine = p.uploaded_by ? `<span class="card-name">👤 ${escHtml(p.uploaded_by)}</span>` : '';
      div.innerHTML = `<img src="${escHtml(thumbSrc)}" alt="Photo" loading="lazy" onerror="this.style.display='none'">`
        + `<div class="card-meta">`
        + `<span class="card-party-badge">${escHtml(p.party_name || slug)}</span>`
        + nameLine
        + `<time>${escHtml(fmtDate(p.upload_timestamp))}</time>`
        + escHtml(div.dataset.sectionLabel)
        + `</div>`;
      return div;
    }

    function reconcileSAGrid(photos) {
      const grid = document.getElementById('sa-grid');
      if (!grid) return;
      const incoming = new Map(photos.map(p => [p.uuid, p]));
      const existing = new Set();
      grid.querySelectorAll('.photo-card').forEach(card => {
        const uuid = card.id.replace('sa-card-', '');
        existing.add(uuid);
        if (!incoming.has(uuid) && !card.classList.contains('removing')) {
          card.classList.add('removing');
          setTimeout(() => card.remove(), 320);
        }
      });
      photos.filter(p => !existing.has(p.uuid)).forEach(p => {
        const card = buildSACard(p);
        const first = grid.querySelector('.photo-card');
        if (first) grid.insertBefore(card, first); else grid.appendChild(card);
      });
      const visible = grid.querySelectorAll('.photo-card:not(.removing)').length;
      let emptyEl = grid.querySelector('.empty-msg');
      if (visible === 0 && !emptyEl) {
        emptyEl = document.createElement('p');
        emptyEl.className = 'empty-msg';
        emptyEl.textContent = 'No photos found.';
        grid.appendChild(emptyEl);
      } else if (visible > 0 && emptyEl) {
        emptyEl.remove();
      }
    }

    function pollSA() {
      fetch('sa_poll.php?party=' + SA_PARTY_FILTER + '&pp=' + SA_PER_PAGE + '&pg=' + SA_PAGE, { cache: 'no-store' })
        .then(r => {
          if (r.status === 401) { location.reload(); return null; }
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(data => {
          if (!data || !data.ok) return;
          if (saDot) saDot.classList.remove('error');
          reconcileSAGrid(data.photos);
        })
        .catch(() => { if (saDot) saDot.classList.add('error'); });
    }

    setInterval(pollSA, 10000);
    return;
  }

  // ── Organizer-only moderation JS ─────────────────────────────

  var pSwitchSel = document.getElementById('party-switch-sel');
  if (pSwitchSel) {
    pSwitchSel.addEventListener('change', function () {
      document.getElementById('party-switch-form').submit();
    });
  }

  function adjStat(id, delta) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
  }

  function setStat(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function syncPendingUI(n) {
    setStat('stat-pending', n);
    const pill = document.getElementById('pending-pill');
    if (pill) { pill.textContent = n; pill.hidden = n <= 0; }
    document.querySelector('.stat-item')?.classList.toggle('has-pending', n > 0);
  }

  function adjPending(delta) {
    const el = document.getElementById('stat-pending');
    if (!el) return;
    syncPendingUI(Math.max(0, (parseInt(el.textContent, 10) || 0) + delta));
  }

  function syncRemovedUI(n) {
    setStat('stat-removed', n);
    const pill = document.getElementById('waste-pill');
    if (pill) { pill.textContent = n; pill.hidden = n <= 0; }
  }

  function adjRemoved(delta) {
    const el = document.getElementById('stat-removed');
    if (!el) return;
    syncRemovedUI(Math.max(0, (parseInt(el.textContent, 10) || 0) + delta));
  }

  function cldUrl(public_id, transforms) {
    return 'https://res.cloudinary.com/' + CLOUD_NAME + '/image/upload/' + transforms + '/' + public_id;
  }

  function thumbUrl(p) {
    const ext = outputExt(p.original_extension);
    if (p.status === 'pending' || (p.status === 'removed' && !p.approved_at)) {
      return 'thumb.php?uuid=' + encodeURIComponent(p.uuid) + '&party=' + encodeURIComponent(PARTY_SLUG);
    }
    if (p.cloudinary_public_id && CLOUD_NAME) {
      return cldUrl(p.cloudinary_public_id, 'w_300,h_300,c_fill,f_auto,q_auto');
    }
    return '../image.php?party=' + encodeURIComponent(PARTY_SLUG)
         + '&dir=gallery_thumbs&uuid=' + encodeURIComponent(p.uuid) + '&ext=' + encodeURIComponent(ext);
  }

  function fullUrl(p) {
    const ext = outputExt(p.original_extension);
    if (p.status === 'pending' || (p.status === 'removed' && !p.approved_at)) {
      return 'thumb.php?uuid=' + encodeURIComponent(p.uuid)
           + '&party=' + encodeURIComponent(PARTY_SLUG) + '&full=1';
    }
    if (p.cloudinary_public_id && CLOUD_NAME) {
      return cldUrl(p.cloudinary_public_id, 'f_auto,q_auto');
    }
    return '../image.php?party=' + encodeURIComponent(PARTY_SLUG)
         + '&dir=gallery&uuid=' + encodeURIComponent(p.uuid) + '&ext=' + encodeURIComponent(ext);
  }

  function dlFilename(p) {
    const raw = (p.uploaded_by || '').replace(/[^a-zA-Z0-9_-]/g, '_').substring(0, 30);
    const ts  = (p.upload_timestamp || '').replace(/[^0-9]/g, '').substring(0, 14);
    return (raw || 'photo') + '_' + ts + '.' + outputExt(p.original_extension);
  }

  const sectionLabels = { pending:'Awaiting Approval', approved:'In the Gallery', removed:'Wastebasket' };

  function buildCard(p, section) {
    const div = document.createElement('div');
    div.className = 'photo-card card-new';
    div.id = 'card-' + p.uuid;
    div.setAttribute('role', 'listitem');
    setTimeout(() => div.classList.remove('card-new'), 400);

    const displayTs = (section === 'approved' && p.approved_at) ? p.approved_at : p.upload_timestamp;
    div.dataset.fullUrl      = fullUrl(p);
    div.dataset.timestamp    = fmtDate(p.upload_timestamp);
    div.dataset.ip           = p.ip_display;
    div.dataset.name         = p.uploaded_by || '';
    div.dataset.sectionLabel = sectionLabels[section] || section;
    const rawExt = (p.original_extension || '').toLowerCase();
    div.dataset.filetype = (rawExt === 'heic' && section === 'approved') ? 'JPG' : rawExt.toUpperCase();

    let actions = '';
    const dlLink = `<a class="btn-dl-photo" href="${escHtml(fullUrl(p))}" download="${escHtml(dlFilename(p))}" aria-label="Download" title="Download this picture to your device">⬇</a>`;
    if (section === 'pending') {
      actions = `<button class="btn-approve" data-uuid="${escHtml(p.uuid)}" data-action="approve" data-section="pending" aria-label="Approve" title="Move this image into your gallery">✅</button>`
              + `<button class="btn-remove"  data-uuid="${escHtml(p.uuid)}" data-action="remove"  data-section="pending" aria-label="Move to wastebasket" title="Move this picture into the wastebasket before removal">🗑️</button>`
              + dlLink;
    } else if (section === 'approved') {
      actions = `<button class="btn-remove" data-uuid="${escHtml(p.uuid)}" data-action="remove" data-section="approved" aria-label="Move to wastebasket" title="Move this picture into the wastebasket before removal">🗑️ Remove</button>` + dlLink;
    } else {
      actions = `<button class="btn-restore" data-uuid="${escHtml(p.uuid)}" data-action="restore" data-section="removed" aria-label="Restore">↩️ Restore</button>`
              + `<button class="btn-reject"  data-uuid="${escHtml(p.uuid)}" data-action="reject"  data-section="removed" aria-label="Delete permanently">✕</button>`
              + dlLink;
    }

    const nameLine = p.uploaded_by ? `<span class="card-name">👤 ${escHtml(p.uploaded_by)}</span>` : '';
    div.innerHTML = `<img src="${escHtml(thumbUrl(p))}" alt="${section} photo" loading="lazy" onerror="this.style.display='none'">`
      + `<div class="card-meta">${nameLine}<time>${escHtml(fmtDate(displayTs))}</time>IP: ${escHtml(p.ip_display)}</div>`
      + `<div class="card-actions">${actions}</div>`;
    return div;
  }

  function reconcileGrid(gridId, photos, section, emptyText) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    const incoming = new Map(photos.map(p => [p.uuid, p]));
    const existing = new Set();
    grid.querySelectorAll('.photo-card').forEach(card => {
      const uuid = card.id.replace('card-', '');
      existing.add(uuid);
      if (!incoming.has(uuid) && !card.classList.contains('removing')) {
        card.classList.add('removing');
        setTimeout(() => card.remove(), 320);
      }
    });
    photos.filter(p => !existing.has(p.uuid)).forEach(p => {
      const card = buildCard(p, section);
      const firstCard = grid.querySelector('.photo-card');
      if (firstCard) grid.insertBefore(card, firstCard); else grid.appendChild(card);
    });
    const visible = grid.querySelectorAll('.photo-card:not(.removing)').length;
    let emptyEl = grid.querySelector('.empty-msg');
    if (visible === 0 && !emptyEl) {
      emptyEl = document.createElement('p');
      emptyEl.className = 'empty-msg';
      emptyEl.textContent = emptyText;
      grid.appendChild(emptyEl);
    } else if (visible > 0 && emptyEl) {
      emptyEl.remove();
    }
  }

  // ── Pause / Resume ───────────────────────────────────────────
  var partyIsActive = <?= json_encode($is_active) ?>;
  var pauseBtn      = document.getElementById('btn-pause-toggle');
  var statusPill    = document.getElementById('status-pill');

  function updatePauseUI(active) {
    partyIsActive = active;
    if (statusPill) {
      statusPill.textContent = active ? 'Live' : 'Paused';
      statusPill.className   = 'status-pill ' + (active ? 'pill-live' : 'pill-paused');
    }
    if (pauseBtn) {
      pauseBtn.textContent = active ? '⏸ Pause' : '▶ Resume';
      pauseBtn.className   = 'btn-pause-topbar ' + (active ? 'btn-pause-live' : 'btn-pause-paused');
      pauseBtn.title       = active
        ? 'Pause the gallery — guests will see a paused message'
        : 'Resume the gallery for guests';
    }
  }

  if (pauseBtn) {
    pauseBtn.addEventListener('click', function () {
      var newActive = partyIsActive ? 0 : 1;
      pauseBtn.disabled = true;
      fetch('toggle_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: CSRF, active: newActive }),
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        pauseBtn.disabled = false;
        if (data.ok) {
          updatePauseUI(data.active);
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(function () { pauseBtn.disabled = false; alert('Network error. Please try again.'); });
    });
  }

  // ── Polling ──────────────────────────────────────────────────
  function poll() {
    fetch('poll.php', { cache: 'no-store' })
      .then(r => {
        if (r.status === 401) { location.reload(); return null; }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(data => {
        if (!data || !data.ok) return;
        if (statusPill) statusPill.classList.remove('poll-error');
        if (typeof data.is_active !== 'undefined') updatePauseUI(!!data.is_active);
        syncPendingUI(data.counts.pending);
        setStat('stat-approved', data.counts.approved);
        syncRemovedUI(data.counts.removed);
        reconcileGrid('pending-grid',    data.pending,  'pending',  'No photos waiting for review.');
        reconcileGrid('approved-grid',   data.approved, 'approved', 'No approved photos yet.');
        reconcileGrid('wastebasket-grid',data.removed,  'removed',  'Wastebasket is empty.');
        const purgeBtn = document.getElementById('btn-purge-all');
        if (purgeBtn) purgeBtn.disabled = data.counts.removed === 0;
      })
      .catch(() => { if (statusPill) statusPill.classList.add('poll-error'); });
  }

  setInterval(poll, 10000);

  // ── Action handler ───────────────────────────────────────────
  const purgeBtn = document.getElementById('btn-purge-all');
  if (purgeBtn) {
    purgeBtn.addEventListener('click', function () {
      if (!confirm('Permanently delete all wastebasket photos? This cannot be undone.')) return;
      purgeBtn.disabled = true;
      fetch('moderate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'purge_all', csrf_token: CSRF }),
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          const grid = document.getElementById('wastebasket-grid');
          if (grid) grid.innerHTML = '<p class="empty-msg">Wastebasket is empty.</p>';
          syncRemovedUI(0);
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
          purgeBtn.disabled = false;
        }
      })
      .catch(() => { alert('Network error. Please try again.'); purgeBtn.disabled = false; });
    });
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-action]');
    if (!btn || btn.id === 'btn-purge-all') return;

    const uuid    = btn.dataset.uuid;
    const action  = btn.dataset.action;
    const section = btn.dataset.section || '';
    const card    = document.getElementById('card-' + uuid);
    if (!uuid || !card) return;

    if (action === 'reject' && section === 'removed') {
      if (!confirm('Permanently delete this photo? This cannot be undone.')) return;
    }

    card.classList.add('removing');

    fetch('moderate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ uuid, action, csrf_token: CSRF }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        setTimeout(() => {
          card.remove();
          if (action === 'approve')       { adjPending(-1); adjStat('stat-approved', 1); }
          else if (action === 'reject')   { adjRemoved(-1); }
          else if (action === 'remove')   { if (section === 'pending') { adjPending(-1); } else { adjStat('stat-approved', -1); } adjRemoved(1); if (purgeBtn) purgeBtn.disabled = false; }
          else if (action === 'restore')  { adjRemoved(-1); adjStat('stat-approved', 1); }
        }, 320);
      } else {
        card.classList.remove('removing');
        alert('Error: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(() => { card.classList.remove('removing'); alert('Network error. Please try again.'); });
  });

})();
</script>
<?php endif; ?>
</body>
</html>
