<?php
declare(strict_types=1);

// ============================================================
// admin/parties.php — Super admin: manage parties and organizer accounts.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── Auth: superadmin only ────────────────────────────────────
if (empty($_SESSION['mpd_user_id']) || ($_SESSION['mpd_role'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit;
}

// Session timeout
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

$success = '';
$error   = '';

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submitted_csrf)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ─ Create organizer account ─
        if ($action === 'create_organizer') {
            $email = trim($_POST['organizer_email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (mpd_get_user_by_email($email) !== false) {
                $error = 'An account with that email already exists.';
            } else {
                $user_id = mpd_create_user($email, 'organizer');
                $user    = mpd_get_user_by_id($user_id);
                $token   = $user['first_login_token'];
                $link    = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($token);

                $subject = 'Your MyPictureDesk organizer account';
                $body    = "<p>Hi,</p>"
                         . "<p>An organizer account has been created for you on MyPictureDesk.</p>"
                         . "<p>Click the link below to set your password (valid for 48 hours):</p>"
                         . "<p><a href='$link'>$link</a></p>"
                         . "<p>If you did not expect this email, please ignore it.</p>";
                mpd_send_email($email, $subject, $body);
                $success = "Account created for $email. Invitation email sent.";
            }
        }

        // ─ Create party ─
        if ($action === 'create_party') {
            $slug       = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
            $name       = trim($_POST['party_name'] ?? '');
            $org_id     = (int)($_POST['organizer_id'] ?? 0);
            $edt        = trim($_POST['event_datetime'] ?? '');
            $info       = trim($_POST['party_info'] ?? '');
            $notify     = trim($_POST['notify_email'] ?? '');
            $me         = (int)$_SESSION['mpd_user_id'];

            if ($slug === '' || strlen($slug) < 3) {
                $error = 'Party URL slug must be at least 3 characters (lowercase letters, numbers, hyphens only).';
            } elseif ($name === '') {
                $error = 'Party name is required.';
            } elseif ($org_id === 0) {
                $error = 'Please select an organizer.';
            } elseif (mpd_get_party_by_slug($slug) !== false) {
                $error = "The slug '$slug' is already taken. Please choose another.";
            } else {
                $party_id = mpd_create_party(
                    $slug, $name, $org_id, $me,
                    $edt !== '' ? $edt : null,
                    $info !== '' ? $info : null,
                    $notify !== '' ? $notify : null
                );
                mpd_ensure_party_dirs($slug);

                // Send notification to organizer
                $org = mpd_get_user_by_id($org_id);
                if ($org) {
                    $guest_url = BASE_URL . '/party?id=' . urlencode($slug);
                    $subject   = "Your party gallery is ready: $name";
                    $body      = "<p>Your party gallery has been created.</p>"
                               . "<ul><li><strong>Party name:</strong> $name</li>"
                               . "<li><strong>Guest URL:</strong> <a href='$guest_url'>$guest_url</a></li>"
                               . "<li><strong>Admin panel:</strong> <a href='" . BASE_URL . "/party/admin/index.php'>Log in to moderate photos</a></li></ul>";
                    mpd_send_email($org['email'], $subject, $body);
                }
                $success = "Party '$name' created with slug '$slug'. Directories provisioned.";
            }
        }

        // ─ Toggle party active ─
        if ($action === 'toggle_party') {
            $pid    = (int)($_POST['party_id'] ?? 0);
            $active = (bool)(int)($_POST['active'] ?? 0);
            if ($pid > 0) {
                mpd_toggle_party_active($pid, $active);
                $success = 'Party status updated.';
            }
        }
    }
}

$parties    = mpd_get_all_parties();
$organizers = array_filter(mpd_get_all_users(), fn($u) => $u['role'] === 'organizer');
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
    .topbar { position: sticky; top: 0; z-index: 50; height: 50px; background: #160f35; border-bottom: 1px solid #2d1b69; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .topbar .nav-links { display: flex; gap: 16px; align-items: center; }
    .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; }
    .nav-link:hover { color: #f5a623; }
    .nav-link.active { color: #f5a623; font-weight: 700; }
    .topbar .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; }
    .topbar .signout:hover { color: #f5a623; }
    .page { max-width: 1200px; margin: 0 auto; padding: 28px 20px; }
    h1 { font-size: 1.5rem; font-weight: 900; margin-bottom: 24px; }
    h2 { font-size: 1.05rem; font-weight: 900; color: #c9b8ff; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }
    hr { border: none; border-top: 2px solid #2d1b69; margin: 32px 0; }

    /* Forms */
    .form-card { background: #2d1b69; border-radius: 12px; padding: 24px 28px; max-width: 560px; }
    .form-row { margin-bottom: 16px; }
    label { display: block; font-size: 0.82rem; font-weight: 700; color: #c9b8ff; margin-bottom: 5px; }
    input[type=text], input[type=email], input[type=datetime-local], select, textarea {
      width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0;
      background: #160f35; color: #f0ebff; font-size: 0.9rem; font-family: inherit;
    }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #f5a623; }
    .hint { font-size: 0.74rem; color: #6b5ca5; margin-top: 4px; }
    .btn { padding: 10px 22px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-primary { background: #f5a623; color: #1a1035; }
    .btn-primary:hover { background: #e6941a; }

    /* Party table */
    .party-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .party-table th { text-align: left; padding: 10px 14px; background: #2d1b69; color: #c9b8ff; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .party-table td { padding: 10px 14px; border-bottom: 1px solid #2d1b69; vertical-align: middle; }
    .party-table tr:hover td { background: rgba(255,255,255,0.03); }
    .slug-badge { background: #160f35; color: #9c7fff; font-size: 0.72rem; padding: 2px 7px; border-radius: 6px; font-weight: 700; }
    .active-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
    .pill-active   { background: #1a4a2e; color: #6ee7a0; }
    .pill-inactive { background: #4a1a1a; color: #f87171; }
    .toggle-form { display: inline; }
    .btn-toggle { padding: 4px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; font-family: inherit; }
    .btn-enable  { background: #27ae60; color: #fff; }
    .btn-enable:hover { background: #219150; }
    .btn-disable { background: #4a3580; color: #c9b8ff; }
    .btn-disable:hover { background: #5a4590; color: #fff; }
    .guest-link { color: #9c7fff; font-size: 0.75rem; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">📸 Dashboard</a>
    <a class="nav-link active" href="parties.php">🎉 Parties</a>
    <a class="nav-link" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <h1>🎉 Party Management</h1>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error !== ''): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── Party list ── -->
  <h2>Active Parties</h2>
  <?php if (empty($parties)): ?>
    <p style="color:#4a3580;font-size:.9rem;margin-bottom:24px;">No parties yet. Create one below.</p>
  <?php else: ?>
  <div style="overflow-x:auto;margin-bottom:32px;">
    <table class="party-table">
      <thead>
        <tr>
          <th>Party Name</th>
          <th>Slug / URL</th>
          <th>Organizer</th>
          <th>Event Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parties as $pt): ?>
        <tr>
          <td><?= htmlspecialchars($pt['party_name']) ?></td>
          <td>
            <span class="slug-badge"><?= htmlspecialchars($pt['slug']) ?></span><br>
            <a class="guest-link" href="<?= BASE_URL ?>/party?id=<?= urlencode($pt['slug']) ?>" target="_blank">Guest page ↗</a>
          </td>
          <td><?= htmlspecialchars($pt['organizer_email']) ?></td>
          <td><?= $pt['event_datetime'] ? htmlspecialchars(date('d M Y H:i', strtotime($pt['event_datetime']))) : '—' ?></td>
          <td>
            <span class="active-pill <?= $pt['is_active'] ? 'pill-active' : 'pill-inactive' ?>">
              <?= $pt['is_active'] ? 'Live' : 'Paused' ?>
            </span>
          </td>
          <td>
            <form class="toggle-form" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="toggle_party">
              <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
              <input type="hidden" name="active" value="<?= $pt['is_active'] ? '0' : '1' ?>">
              <button type="submit" class="btn-toggle <?= $pt['is_active'] ? 'btn-disable' : 'btn-enable' ?>">
                <?= $pt['is_active'] ? 'Pause' : 'Enable' ?>
              </button>
            </form>
            &nbsp;
            <a class="btn btn-toggle btn-disable" href="qrcode.php?party=<?= urlencode($pt['slug']) ?>" style="text-decoration:none;display:inline-block;">QR</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <hr>

  <!-- ── Create party ── -->
  <h2>Create New Party</h2>
  <div class="form-card" style="margin-bottom:32px;">
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create_party">

      <div class="form-row">
        <label for="party_name">Party Name *</label>
        <input type="text" id="party_name" name="party_name" required maxlength="200"
               value="<?= htmlspecialchars($_POST['party_name'] ?? '') ?>">
      </div>

      <div class="form-row">
        <label for="slug">URL Slug *</label>
        <input type="text" id="slug" name="slug" required maxlength="60" pattern="[a-z0-9\-]+"
               value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>"
               placeholder="e.g. smith-wedding-2026">
        <p class="hint">Lowercase letters, numbers, hyphens only. Guest URL: <?= BASE_URL ?>/party?id=<em>your-slug</em></p>
      </div>

      <div class="form-row">
        <label for="organizer_id">Organizer *</label>
        <select id="organizer_id" name="organizer_id" required>
          <option value="">— Select organizer —</option>
          <?php foreach ($organizers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
                    <?= ((int)($_POST['organizer_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['email']) ?>
            </option>
          <?php endforeach; ?>
        </select>
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

      <button type="submit" class="btn btn-primary">Create Party</button>
    </form>
  </div>

  <hr>

  <!-- ── Create organizer account ── -->
  <h2>Invite New Organizer</h2>
  <div class="form-card">
    <p style="font-size:.85rem;color:#9c7fff;margin-bottom:16px;">
      Creates an organizer account and emails a password-set link (valid 48 hours).
    </p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create_organizer">
      <div class="form-row">
        <label for="organizer_email">Organizer Email *</label>
        <input type="email" id="organizer_email" name="organizer_email" required
               value="<?= htmlspecialchars($_POST['organizer_email'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-primary">Send Invitation</button>
    </form>
  </div>

</div>
</body>
</html>
