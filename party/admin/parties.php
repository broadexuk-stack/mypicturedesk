<?php
declare(strict_types=1);

// ============================================================
// admin/parties.php — Super admin: manage parties and organiser accounts.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/cloudinary.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── Auth: superadmin only ────────────────────────────────────
if (empty($_SESSION['mpd_user_id']) || ($_SESSION['mpd_role'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit;
}

$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    session_unset(); session_destroy();
    header('Location: index.php');
    exit;
}
$_SESSION['admin_last_active'] = time();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];

// ── Security headers ─────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$success          = '';
$error            = '';
$party_modal_open = false;
$generated_slug   = '';

$s_plat      = mpd_get_all_settings();
$ret_max     = max(1, (int)($s_plat['retention_max_days']     ?? 365));
$ret_default = max(1, (int)($s_plat['retention_default_days'] ?? 30));

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submitted_csrf)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ─ Create party ─
        if ($action === 'create_party') {
            // Accept client-generated slug if valid; silently regenerate otherwise
            $slug_raw    = preg_replace('/[^a-z0-9]/', '', strtolower(trim($_POST['slug'] ?? '')));
            $slug        = (strlen($slug_raw) === 6 && mpd_get_party_by_slug($slug_raw) === false)
                           ? $slug_raw
                           : mpd_generate_unique_slug();
            $name        = trim($_POST['party_name'] ?? '');
            $oname       = mb_substr(trim($_POST['organiser_name'] ?? ''), 0, 200);
            $org_id_raw  = trim($_POST['organiser_id'] ?? '');
            $new_email   = trim($_POST['new_organiser_email'] ?? '');
            $edt         = trim($_POST['event_datetime'] ?? '');
            $info        = trim($_POST['party_info'] ?? '');
            $notify      = trim($_POST['notify_email'] ?? '');
            $ret_raw     = (int)($_POST['retention_days'] ?? $ret_default);
            $party_ret   = max(1, min($ret_max, $ret_raw));
            $timer_camera = !empty($_POST['timer_camera_enabled']);
            $me          = (int)$_SESSION['mpd_user_id'];
            $org_id      = 0;

            if ($org_id_raw === 'new') {
                if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address for the new organiser.';
                    $party_modal_open = true;
                } else {
                    $existing = mpd_get_user_by_email($new_email);
                    if ($existing !== false && !empty($existing['password_hash'])) {
                        $error = 'An active account already exists for that email. Select them from the organiser list.';
                        $party_modal_open = true;
                    } elseif ($existing !== false) {
                        $org_id = (int)$existing['id'];
                    } else {
                        mpd_create_user($new_email, 'organizer');
                        $created = mpd_get_user_by_email($new_email);
                        if ($created !== false) {
                            $org_id = (int)$created['id'];
                        }
                    }
                }
            } else {
                $org_id = (int)$org_id_raw;
            }

            if ($error === '') {
                if ($name === '') {
                    $error = 'Party name is required.';
                    $party_modal_open = true;
                } elseif ($org_id === 0) {
                    $error = 'Please select or create an organiser.';
                    $party_modal_open = true;
                } else {
                    $cloudinary_on  = cloudinary_globally_configured() && !empty($_POST['cloudinary_enabled']);
                    $auto_approve   = !empty($_POST['auto_approve']);
                    $new_party_id   = mpd_create_party(
                        $slug, $name, $org_id, $me,
                        $edt    !== '' ? $edt    : null,
                        $info   !== '' ? $info   : null,
                        $notify !== '' ? $notify : null,
                        $party_ret,
                        $oname  !== '' ? $oname  : null,
                        $timer_camera,
                        $cloudinary_on,
                        $auto_approve
                    );
                    mpd_ensure_party_dirs($slug);
                    mpd_log('party.created', [
                        'party.id'          => $new_party_id,
                        'party.name'        => $name,
                        'party.slug'        => $slug,
                        'organiser.id'      => $org_id,
                        'party.auto_approve'  => $auto_approve,
                        'party.cloudinary'    => $cloudinary_on,
                        'party.retention_days' => $party_ret,
                        'admin.id'          => $me,
                    ]);

                    $org = mpd_get_user_by_id($org_id);
                    if ($org) {
                        $guest_url = BASE_URL . '/party?id=' . urlencode($slug);
                        $admin_url = BASE_URL . '/party/admin/index.php';
                        $setpassword_block = '';
                        if (empty($org['password_hash'])) {
                            $inv_token = mpd_set_user_token($org_id);
                            $inv_link  = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($inv_token);
                            $setpassword_block =
                                '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;"><tr><td style="background:#2a1500;border:2px solid #f5a623;border-radius:10px;padding:18px 20px;">'
                              . '<p style="color:#f5a623;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin:0 0 10px;">&#9888; Action Required &mdash; Set Your Password</p>'
                              . '<p style="color:#f0ebff;font-family:Arial,sans-serif;font-size:14px;margin:0 0 16px;">Before you can log in to the admin panel you\'ll need to create a password for your account. Use the button below to get started.</p>'
                              . "<a href=\"$inv_link\" style=\"display:inline-block;background:#f5a623;color:#1a1035;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:11px 26px;border-radius:8px;\">Set My Password &rarr;</a>"
                              . '<p style="color:#9c7fff;font-family:Arial,sans-serif;font-size:11px;margin:12px 0 0;">This link expires in 7&nbsp;days.</p>'
                              . '</td></tr></table>';
                        }
                        $body = mpd_render_email('email_welcome_body', [
                            'party_name'        => htmlspecialchars($name),
                            'guest_url'         => $guest_url,
                            'admin_url'         => $admin_url,
                            'setpassword_block' => $setpassword_block,
                        ]);
                        $sent = mpd_send_email($org['email'], "Your party gallery is ready: $name", $body);
                        if (!$sent) {
                            $success = "Party '$name' created (ID: $slug). Warning: welcome email could not be sent — check SMTP settings.";
                        }
                    }
                    if ($success === '') $success = "Party '$name' created (ID: $slug).";
                }
            }
            if ($party_modal_open) {
                $generated_slug = $slug;
            }
        }

        // ─ Toggle party active ─
        if ($action === 'toggle_party') {
            $pid    = (int)($_POST['party_id'] ?? 0);
            $active = (bool)(int)($_POST['active'] ?? 0);
            if ($pid > 0) {
                $pt = mpd_get_party_by_id($pid);
                mpd_toggle_party_active($pid, $active);
                mpd_log('party.toggled', [
                    'party.id'   => $pid,
                    'party.name' => $pt['party_name'] ?? '(unknown)',
                    'party.slug' => $pt['slug']        ?? '(unknown)',
                    'party.active' => $active,
                    'admin.id'   => (int)$_SESSION['mpd_user_id'],
                ]);
                $success = 'Party status updated.';
            }
        }

        // ─ Delete party ─
        if ($action === 'delete_party') {
            $pid = (int)($_POST['party_id'] ?? 0);
            if ($pid > 0) {
                $pt             = mpd_get_party_by_id($pid);
                $photo_count    = db_count_all_photos($pid);
                $photo_filenames = db_get_party_photo_filenames($pid);
                mpd_delete_party($pid);
                mpd_log('party.deleted', [
                    'party.id'          => $pid,
                    'party.name'        => $pt['party_name']   ?? '(unknown)',
                    'party.slug'        => $pt['slug']          ?? '(unknown)',
                    'party.photo_count' => $photo_count,
                    'party.photos'      => $photo_filenames,
                    'organiser.id'      => $pt['organizer_id'] ?? null,
                    'admin.id'          => (int)$_SESSION['mpd_user_id'],
                ]);
                $success = $pt ? "Party '{$pt['party_name']}' deleted." : 'Party deleted.';
            }
        }

        // ─ Toggle Cloudinary ─
        if ($action === 'toggle_cloudinary') {
            $pid = (int)($_POST['party_id'] ?? 0);
            if ($pid > 0 && cloudinary_globally_configured()) {
                $enabled = (bool)(int)($_POST['cloudinary_enabled'] ?? 0);
                mpd_update_party($pid, ['cloudinary_enabled' => $enabled ? 1 : 0]);
            }
        }

        // ─ Toggle auto-approve ─
        if ($action === 'toggle_auto_approve') {
            $pid = (int)($_POST['party_id'] ?? 0);
            if ($pid > 0) {
                $enabled = (bool)(int)($_POST['auto_approve'] ?? 0);
                mpd_update_party($pid, ['auto_approve' => $enabled ? 1 : 0]);
            }
        }

        // ─ Resend party invite ─
        if ($action === 'resend_invite') {
            $pid = (int)($_POST['party_id'] ?? 0);
            $pt  = $pid > 0 ? mpd_get_party_by_id($pid) : false;
            if ($pt === false) {
                $error = 'Party not found.';
            } else {
                $org = mpd_get_user_by_id((int)$pt['organizer_id']);
                if ($org === false) {
                    $error = 'Organiser not found.';
                } else {
                    $guest_url = BASE_URL . '/party?id=' . urlencode($pt['slug']);
                    $admin_url = BASE_URL . '/party/admin/index.php';
                    $setpassword_block = '';
                    if (empty($org['password_hash'])) {
                        $inv_token = mpd_set_user_token((int)$org['id']);
                        $inv_link  = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($inv_token);
                        $setpassword_block =
                            '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;"><tr><td style="background:#2a1500;border:2px solid #f5a623;border-radius:10px;padding:18px 20px;">'
                          . '<p style="color:#f5a623;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin:0 0 10px;">&#9888; Action Required &mdash; Set Your Password</p>'
                          . '<p style="color:#f0ebff;font-family:Arial,sans-serif;font-size:14px;margin:0 0 16px;">Before you can log in to the admin panel you\'ll need to create a password for your account. Use the button below to get started.</p>'
                          . "<a href=\"$inv_link\" style=\"display:inline-block;background:#f5a623;color:#1a1035;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:11px 26px;border-radius:8px;\">Set My Password &rarr;</a>"
                          . '<p style="color:#9c7fff;font-family:Arial,sans-serif;font-size:11px;margin:12px 0 0;">This link expires in 7&nbsp;days.</p>'
                          . '</td></tr></table>';
                    }
                    $body = mpd_render_email('email_welcome_body', [
                        'party_name'        => htmlspecialchars($pt['party_name']),
                        'guest_url'         => $guest_url,
                        'admin_url'         => $admin_url,
                        'setpassword_block' => $setpassword_block,
                    ]);
                    $sent = mpd_send_email($org['email'], "Your party gallery: " . $pt['party_name'], $body);
                    if ($sent) {
                        $success = 'Invite resent to ' . htmlspecialchars($org['email']) . '.';
                    } else {
                        $error = 'Failed to send email to ' . htmlspecialchars($org['email']) . '. Check SMTP settings in Superadmin → Settings.';
                    }
                }
            }
        }
    }
}

$parties    = mpd_get_all_parties();
$organisers = array_filter(mpd_get_all_users(), fn($u) => $u['role'] === 'organizer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Parties — MyPictureDesk Admin</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; }

    /* Topbar */
    .topbar { position: sticky; top: 0; z-index: 50; height: 50px; background: #160f35; border-bottom: 1px solid #2d1b69; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .topbar .nav-links { display: flex; gap: 16px; align-items: center; }
    .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; }
    .nav-link:hover { color: #f5a623; }
    .nav-link.active { color: #f5a623; font-weight: 700; }
    .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; }
    .signout:hover { color: #f5a623; }

    /* Page */
    .page { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }
    .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    h1 { font-size: 1.5rem; font-weight: 900; }
    h2 { font-size: 1.05rem; font-weight: 900; color: #c9b8ff; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }
    hr { border: none; border-top: 2px solid #2d1b69; margin: 32px 0; }

    /* Buttons */
    .btn { padding: 10px 22px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-primary   { background: #f5a623; color: #1a1035; }
    .btn-primary:hover { background: #e6941a; }
    .btn-sm { padding: 4px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-toggle-enable  { background: #27ae60; color: #fff; }
    .btn-toggle-enable:hover { background: #219150; }
    .btn-toggle-disable { background: #4a3580; color: #c9b8ff; }
    .btn-toggle-disable:hover { background: #5a4590; color: #fff; }
    .btn-ghost { background: #2d1b69; color: #c9b8ff; border: 1px solid #4b35a0; }
    .btn-ghost:hover { background: #3d2494; color: #f0ebff; }
    .btn-danger { background: #7a1a1a; color: #f87171; }
    .btn-danger:hover { background: #9a2a2a; color: #fca5a5; }

    /* Party table */
    .party-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .party-table th { text-align: left; padding: 10px 14px; background: #2d1b69; color: #c9b8ff; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .party-table td { padding: 10px 14px; border-bottom: 1px solid #2d1b69; vertical-align: middle; }
    .party-table tr:hover td { background: rgba(255,255,255,0.03); }
    .slug-badge { background: #160f35; color: #9c7fff; font-size: 0.72rem; padding: 2px 7px; border-radius: 6px; font-weight: 700; }
    .active-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
    .pill-active   { background: #1a4a2e; color: #6ee7a0; }
    .pill-inactive { background: #4a1a1a; color: #f87171; }
    .pill-remove   { background: #7a1a1a; color: #f87171; }
    .party-table td + td, .party-table th + th { border-left: 1px solid rgba(100,80,170,0.35); }
    .guest-link { color: #9c7fff; font-size: 0.75rem; }
    .action-cell { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; }
    .inline-form { display: inline; }

    /* Modal */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,6,30,0.85); z-index: 200; overflow-y: auto; }
    .modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
    .modal { background: #1e1248; border: 1px solid #4b35a0; border-radius: 16px; padding: 32px 36px; width: 100%; max-width: 560px; position: relative; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .modal h2 { margin-bottom: 0; text-transform: none; font-size: 1.1rem; letter-spacing: 0; color: #f0ebff; }
    .modal-close { background: none; border: none; color: #6b5ca5; font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0 4px; }
    .modal-close:hover { color: #f0ebff; }

    /* Form */
    .form-row { margin-bottom: 16px; }
    label { display: block; font-size: 0.82rem; font-weight: 700; color: #c9b8ff; margin-bottom: 5px; }
    input[type=text], input[type=email], input[type=number], input[type=datetime-local], select, textarea {
      width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0;
      background: #160f35; color: #f0ebff; font-size: 0.9rem; font-family: inherit;
    }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #f5a623; }
    .hint { font-size: 0.74rem; color: #6b5ca5; margin-top: 4px; }
    .hidden { display: none; }
    .checkbox-row { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#160f35; border:2px solid #4b35a0; border-radius:8px; cursor:pointer; }
    .checkbox-row input[type=checkbox] { width:16px; height:16px; accent-color:#f5a623; cursor:pointer; flex-shrink:0; }
    .checkbox-row span { font-size:0.85rem; font-weight:700; color:#c9b8ff; }
    .slug-id-row { display: flex; gap: 8px; align-items: center; }
    .slug-id-box { flex: 1; font-family: inherit; font-size: 0.9rem; background: #160f35; border: 2px solid #4b35a0; border-radius: 8px; padding: 10px 14px; color: #f0ebff; }
    .slug-hint-val { font-weight: 700; color: #9c7fff; }

    /* Column header tooltips */
    .has-tip { position: relative; cursor: help; }
    .has-tip::after {
      content: attr(data-tip);
      position: absolute;
      bottom: calc(100% + 6px);
      left: 50%;
      transform: translateX(-50%);
      background: #160f35;
      color: #c9b8ff;
      font-size: 0.72rem;
      font-weight: 400;
      white-space: nowrap;
      padding: 5px 10px;
      border-radius: 6px;
      border: 1px solid #4b35a0;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.15s;
      text-transform: none;
      letter-spacing: 0;
      z-index: 100;
    }
    .has-tip:hover::after { opacity: 1; }

    /* Auto-approve confirm modal */
    .modal-sm { max-width: 400px; text-align: center; }
    .modal-icon { font-size: 2.5rem; margin-bottom: 14px; }
    .modal-confirm-text { color: #c9b8ff; font-size: 0.92rem; line-height: 1.6; margin-bottom: 24px; }
    .modal-actions { display: flex; gap: 12px; justify-content: center; }
    .btn-yes { background: #e67e22; color: #fff; }
    .btn-yes:hover { background: #d35400; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">📸 Dashboard</a>
    <a class="nav-link active" href="parties.php">🎉 Parties</a>
    <a class="nav-link" href="users.php">👥 Users</a>
    <a class="nav-link" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <div class="page-header">
    <h1>🎉 Party Management</h1>
    <button class="btn btn-primary" id="btn-new-party">+ New Party</button>
  </div>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error !== '' && !$party_modal_open): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── Party list ── -->
  <?php if (empty($parties)): ?>
    <p style="color:#4a3580;font-size:.9rem;">No parties yet. Click <strong>New Party</strong> to create one.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="party-table">
      <thead>
        <tr>
          <th>Party Name</th>
          <th>Slug / URL</th>
          <th>Organiser</th>
          <th>Event Date</th>
          <th>Photos</th>
          <th>Status</th>
          <?php if (cloudinary_globally_configured()): ?><th class="has-tip" data-tip="Store approved photos on Cloudinary CDN — served globally, local copies removed">☁️</th><?php endif; ?>
          <th class="has-tip" data-tip="Auto-approve: uploaded photos go live immediately without moderation">⚡</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parties as $pt):
            $is_expired = (int)$pt['retention_days'] > 0
                && time() > strtotime($pt['created_at']) + (int)$pt['retention_days'] * 86400;
        ?>
        <tr>
          <td><?= htmlspecialchars($pt['party_name']) ?></td>
          <td>
            <span class="slug-badge"><?= htmlspecialchars($pt['slug']) ?></span><br>
            <a class="guest-link" href="<?= BASE_URL ?>/party?id=<?= urlencode($pt['slug']) ?>" target="_blank">Guest page ↗</a>
          </td>
          <td><?= htmlspecialchars($pt['organizer_email']) ?></td>
          <td><?= $pt['event_datetime'] ? htmlspecialchars(date('d M Y H:i', strtotime($pt['event_datetime']))) : '—' ?></td>
          <td style="text-align:center;"><?= (int)$pt['photo_count'] ?></td>
          <td>
            <?php if ($is_expired): ?>
              <span class="active-pill pill-remove" title="Retention period of <?= (int)$pt['retention_days'] ?> days exceeded">Remove</span>
            <?php else: ?>
              <span class="active-pill <?= $pt['is_active'] ? 'pill-active' : 'pill-inactive' ?>">
                <?= $pt['is_active'] ? 'Live' : 'Paused' ?>
              </span>
            <?php endif; ?>
          </td>
          <?php if (cloudinary_globally_configured()): ?>
          <td style="text-align:center;">
            <form class="inline-form cloud-toggle-form" method="post">
              <input type="hidden" name="csrf_token"         value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"             value="toggle_cloudinary">
              <input type="hidden" name="party_id"           value="<?= (int)$pt['id'] ?>">
              <input type="hidden" name="cloudinary_enabled" value="0">
              <input type="checkbox" name="cloudinary_enabled" value="1"
                     title="Store approved photos on Cloudinary"
                     <?= !empty($pt['cloudinary_enabled']) ? 'checked' : '' ?>>
            </form>
          </td>
          <?php endif; ?>
          <td style="text-align:center;">
            <form class="inline-form cloud-toggle-form" method="post">
              <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"      value="toggle_auto_approve">
              <input type="hidden" name="party_id"    value="<?= (int)$pt['id'] ?>">
              <input type="hidden" name="auto_approve" value="0">
              <input type="checkbox" name="auto_approve" value="1"
                     title="Auto-approve uploads — photos go live immediately without moderation"
                     <?= !empty($pt['auto_approve']) ? 'checked' : '' ?>>
            </form>
          </td>
          <td>
            <div class="action-cell">
              <!-- Toggle active -->
              <form class="inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="toggle_party">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <input type="hidden" name="active"   value="<?= $pt['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn-sm <?= $pt['is_active'] ? 'btn-toggle-disable' : 'btn-toggle-enable' ?>">
                  <?= $pt['is_active'] ? 'Pause' : 'Enable' ?>
                </button>
              </form>

              <!-- QR code -->
              <a class="btn-sm btn-ghost" href="qrcode.php?party=<?= urlencode($pt['slug']) ?>">QR</a>

              <!-- Resend invite -->
              <form class="inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="resend_invite">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <button type="submit" class="btn-sm btn-ghost" title="Resend party invite email to organiser">Resend invite</button>
              </form>

              <!-- Impersonate organiser -->
              <form class="inline-form" method="post" action="impersonate.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="organiser_id" value="<?= (int)$pt['organizer_id'] ?>">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <button type="submit" class="btn-sm btn-ghost" title="Log in as this party's organiser">👁 View as</button>
              </form>

              <!-- Delete -->
              <form class="inline-form" method="post" data-confirm="Delete party '<?= htmlspecialchars(addslashes($pt['party_name'])) ?>'? This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="delete_party">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <button type="submit" class="btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── New Party Modal ── -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header">
      <h2 id="modal-title">🎉 Create New Party</h2>
      <button class="modal-close" id="btn-close-modal" aria-label="Close">&times;</button>
    </div>

    <?php if ($party_modal_open && $error !== ''): ?>
      <div class="msg msg-err" style="margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create_party">

      <div class="form-row">
        <label for="party_name">Party Name *</label>
        <input type="text" id="party_name" name="party_name" required maxlength="200"
               value="<?= htmlspecialchars($_POST['party_name'] ?? '') ?>">
      </div>

      <div class="form-row">
        <label for="organiser_name">Organiser Display Name</label>
        <input type="text" id="organiser_name" name="organiser_name" maxlength="200"
               value="<?= htmlspecialchars($_POST['organiser_name'] ?? '') ?>"
               placeholder="e.g. Sarah &amp; James">
        <p class="hint">Shown to guests on the party page and in any paused-gallery message.</p>
      </div>

      <div class="form-row">
        <label for="organiser_id">Organiser *</label>
        <select id="organiser_id" name="organiser_id" required>
          <option value="">— Select organiser —</option>
          <?php foreach ($organisers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
                    <?= (($_POST['organiser_id'] ?? '') === (string)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['email']) ?>
            </option>
          <?php endforeach; ?>
          <option value="new" <?= (($_POST['organiser_id'] ?? '') === 'new') ? 'selected' : '' ?>>+ New organiser...</option>
        </select>
      </div>

      <div class="form-row <?= (($_POST['organiser_id'] ?? '') !== 'new') ? 'hidden' : '' ?>" id="new-organiser-row">
        <label for="new_organiser_email">New Organiser Email *</label>
        <input type="email" id="new_organiser_email" name="new_organiser_email"
               value="<?= htmlspecialchars($_POST['new_organiser_email'] ?? '') ?>"
               placeholder="organiser@example.com">
        <p class="hint">An account will be created and the welcome email will include a link for them to set their password.</p>
      </div>

      <div class="form-row">
        <label for="event_datetime">Event Date &amp; Time</label>
        <input type="datetime-local" id="event_datetime" name="event_datetime"
               value="<?= htmlspecialchars($_POST['event_datetime'] ?? '') ?>">
      </div>

      <div class="form-row">
        <label for="party_info">Party Info Text</label>
        <textarea id="party_info" name="party_info" maxlength="1000"
                  placeholder="Shown on the guest page below the party name"><?= htmlspecialchars($_POST['party_info'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <label for="notify_email">Notification Email</label>
        <input type="email" id="notify_email" name="notify_email"
               value="<?= htmlspecialchars($_POST['notify_email'] ?? '') ?>"
               placeholder="Receives an email on each new upload">
      </div>

      <div class="form-row">
        <label for="retention_days">Retention Period (days)</label>
        <input type="number" id="retention_days" name="retention_days" min="1" max="<?= $ret_max ?>"
               value="<?= (int)($_POST['retention_days'] ?? $ret_default) ?>">
        <p class="hint">Photos will be flagged for removal after this many days. Platform maximum: <?= $ret_max ?> days.</p>
      </div>

      <div class="form-row">
        <label>Timer Selfie Camera</label>
        <label class="checkbox-row">
          <input type="checkbox" name="timer_camera_enabled" value="1" <?= !empty($_POST['timer_camera_enabled']) ? 'checked' : '' ?>>
          <span>⏱ Enable in-browser countdown selfie on the guest page</span>
        </label>
        <p class="hint">Lower resolution than the native camera — ideal for quick group selfies.</p>
      </div>

      <?php if (cloudinary_globally_configured()): ?>
      <div class="form-row">
        <label>Cloud Storage</label>
        <label class="checkbox-row">
          <input type="checkbox" name="cloudinary_enabled" value="1" <?= !empty($_POST['cloudinary_enabled']) ? 'checked' : '' ?>>
          <span>☁️ Store approved photos on Cloudinary CDN</span>
        </label>
        <p class="hint">Approved photos are uploaded to Cloudinary and served via their global CDN. Local copies are removed after upload.</p>
      </div>
      <?php endif; ?>

      <div class="form-row">
        <label>Auto-Approve Uploads</label>
        <label class="checkbox-row">
          <input type="checkbox" name="auto_approve" value="1" <?= !empty($_POST['auto_approve']) ? 'checked' : '' ?>>
          <span>⚡ Photos go live immediately without moderation</span>
        </label>
        <p class="hint">Only enable for trusted, controlled audiences. Uploaded photos skip the approval queue and appear in the gallery instantly.</p>
      </div>

      <div class="form-row">
        <label>Party ID</label>
        <div class="slug-id-row">
          <div id="slug-display" class="slug-id-box">——————</div>
          <button type="button" id="btn-regen-slug" class="btn-sm btn-ghost" title="Generate a new Party ID">↻ New ID</button>
        </div>
        <p class="hint">Auto-generated unique ID. Guest URL: <?= BASE_URL ?>/party?id=<span id="slug-hint-val" class="slug-hint-val">……</span></p>
        <input type="hidden" name="slug" id="slug-hidden" value="<?= htmlspecialchars($generated_slug) ?>">
      </div>

      <button type="submit" class="btn btn-primary">Create Party</button>
    </form>
  </div>
</div>

<!-- ── Auto-approve confirmation modal ── -->
<div class="modal-overlay" id="aa-confirm-overlay">
  <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="aa-confirm-title">
    <div class="modal-icon">⚠️</div>
    <h2 id="aa-confirm-title">Enable Auto-Approve?</h2>
    <p class="modal-confirm-text">
      Warning — all pictures will go live immediately without moderation.<br>
      Do you agree to this?
    </p>
    <div class="modal-actions">
      <button class="btn btn-ghost" id="aa-confirm-no">No, keep off</button>
      <button class="btn btn-yes"   id="aa-confirm-yes">Yes, enable it</button>
    </div>
  </div>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  var overlay  = document.getElementById('modal-overlay');

  // ── Party ID generation ───────────────────────────────────────
  var SERVER_SLUG = <?= json_encode($generated_slug) ?>;

  function generatePartyId() {
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    var arr   = new Uint8Array(6);
    crypto.getRandomValues(arr);
    return Array.from(arr).map(function(b) { return chars[b % chars.length]; }).join('');
  }

  function applyPartyId(id) {
    var disp = document.getElementById('slug-display');
    var hint = document.getElementById('slug-hint-val');
    var inp  = document.getElementById('slug-hidden');
    if (disp) disp.textContent = id;
    if (hint) hint.textContent = id;
    if (inp)  inp.value        = id;
  }

  function openModal() {
    applyPartyId(SERVER_SLUG || generatePartyId());
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  document.getElementById('btn-new-party').addEventListener('click', openModal);
  document.getElementById('btn-close-modal').addEventListener('click', closeModal);

  var regenBtn = document.getElementById('btn-regen-slug');
  if (regenBtn) regenBtn.addEventListener('click', function() { applyPartyId(generatePartyId()); });

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  var organiserSelect = document.getElementById('organiser_id');
  organiserSelect.addEventListener('change', function () {
    var row = document.getElementById('new-organiser-row');
    var inp = document.getElementById('new_organiser_email');
    if (this.value === 'new') {
      row.classList.remove('hidden');
      inp.required = true;
    } else {
      row.classList.add('hidden');
      inp.required = false;
    }
  });

  // ── Auto-approve confirm modal ────────────────────────────────
  var aaOverlay   = document.getElementById('aa-confirm-overlay');
  var aaBtnYes    = document.getElementById('aa-confirm-yes');
  var aaBtnNo     = document.getElementById('aa-confirm-no');
  var aaPendingForm = null;
  var aaPendingCb   = null;

  function closeAaModal() {
    aaOverlay.classList.remove('open');
    document.body.style.overflow = '';
    if (aaPendingCb) aaPendingCb.checked = false;
    aaPendingForm = aaPendingCb = null;
  }

  aaBtnYes.addEventListener('click', function () {
    aaOverlay.classList.remove('open');
    document.body.style.overflow = '';
    if (aaPendingForm) aaPendingForm.submit();
    aaPendingForm = aaPendingCb = null;
  });
  aaBtnNo.addEventListener('click', closeAaModal);
  aaOverlay.addEventListener('click', function (e) {
    if (e.target === aaOverlay) closeAaModal();
  });

  document.querySelectorAll('.cloud-toggle-form input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (cb.name === 'auto_approve' && cb.checked) {
        aaPendingForm = cb.closest('form');
        aaPendingCb   = cb;
        aaOverlay.classList.add('open');
        document.body.style.overflow = 'hidden';
      } else {
        cb.closest('form').submit();
      }
    });
  });

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  <?php if ($party_modal_open): ?>
  openModal();
  <?php endif; ?>
}());
</script>

</body>
</html>
