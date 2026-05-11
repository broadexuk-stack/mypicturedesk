<?php
declare(strict_types=1);

// ============================================================
// admin/superadmin_settings.php — Global SMTP / email config.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (empty($_SESSION['mpd_user_id']) || ($_SESSION['mpd_role'] ?? '') !== 'superadmin') {
    header('Location: index.php'); exit;
}

$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    session_unset(); session_destroy(); header('Location: index.php'); exit;
}
$_SESSION['admin_last_active'] = time();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['admin_csrf'];

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submitted_csrf)) {
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_templates') {
            mpd_update_setting('email_welcome_body', trim($_POST['email_welcome_body'] ?? '') ?: null);
            mpd_update_setting('email_notify_body',  trim($_POST['email_notify_body']  ?? '') ?: null);
            $success = 'Email templates saved.';
        }

        if ($action === 'save_smtp') {
            $keys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_secure'];
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                // Never blank out an existing password if field left empty
                if ($k === 'smtp_pass' && $val === '') continue;
                mpd_update_setting($k, $val !== '' ? $val : null);
            }
            $success = 'SMTP settings saved.';
        }

        if ($action === 'send_test') {
            $to = trim($_POST['test_email'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid test email address.';
            } else {
                $sent = mpd_send_email(
                    $to,
                    'MyPictureDesk — SMTP test',
                    '<p>This is a test email from your MyPictureDesk installation.</p><p>If you received this, your SMTP settings are working correctly.</p>'
                );
                $success = $sent ? "Test email sent to $to." : 'Email sending failed. Check your SMTP settings and server logs.';
            }
        }
    }
}

$s = mpd_get_all_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings — MyPictureDesk Admin</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; }
    .topbar { position: sticky; top: 0; z-index: 50; height: 50px; background: #160f35; border-bottom: 1px solid #2d1b69; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .nav-links { display: flex; gap: 16px; }
    .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; }
    .nav-link:hover, .nav-link.active { color: #f5a623; }
    .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; }
    .signout:hover { color: #f5a623; }
    .page { max-width: 620px; margin: 0 auto; padding: 32px 20px; }
    h1 { font-size: 1.4rem; font-weight: 900; margin-bottom: 24px; }
    h2 { font-size: 1rem; font-weight: 900; color: #c9b8ff; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
    hr { border: none; border-top: 2px solid #2d1b69; margin: 28px 0; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }
    .form-row { margin-bottom: 16px; }
    label { display: block; font-size: 0.82rem; font-weight: 700; color: #c9b8ff; margin-bottom: 5px; }
    input[type=text], input[type=email], input[type=number], input[type=password], select {
      width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0;
      background: #160f35; color: #f0ebff; font-size: 0.9rem; font-family: inherit;
    }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #f5a623; }
    textarea { width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0; background: #160f35; color: #f0ebff; font-size: 0.85rem; font-family: monospace; resize: vertical; }
    code { background: #160f35; color: #9c7fff; font-size: 0.78rem; padding: 1px 5px; border-radius: 4px; }
    .hint { font-size: 0.74rem; color: #6b5ca5; margin-top: 4px; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .btn { padding: 10px 24px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-primary { background: #f5a623; color: #1a1035; }
    .btn-primary:hover { background: #e6941a; }
    .btn-secondary { background: #2d1b69; color: #c9b8ff; border: 1px solid #4b35a0; }
    .btn-secondary:hover { background: #3d2494; color: #f0ebff; }
    .test-row { display: flex; gap: 10px; align-items: flex-end; }
    .test-row .form-row { flex: 1; margin-bottom: 0; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">📸 Dashboard</a>
    <a class="nav-link" href="parties.php">🎉 Parties</a>
    <a class="nav-link active" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <h1>⚙️ Platform Settings</h1>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error !== ''): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── SMTP ── -->
  <h2>Email / SMTP</h2>
  <p style="font-size:.82rem;color:#6b5ca5;margin-bottom:20px;">
    Used for organizer invitation emails and upload notifications.
    If left blank, the server's <code>mail()</code> function is used as fallback.
  </p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="save_smtp">

    <div class="row2">
      <div class="form-row">
        <label for="smtp_host">SMTP Host</label>
        <input type="text" id="smtp_host" name="smtp_host"
               value="<?= htmlspecialchars($s['smtp_host'] ?? '') ?>"
               placeholder="mail.yourdomain.com">
      </div>
      <div class="form-row">
        <label for="smtp_port">SMTP Port</label>
        <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
               value="<?= htmlspecialchars($s['smtp_port'] ?? '587') ?>">
      </div>
    </div>

    <div class="row2">
      <div class="form-row">
        <label for="smtp_user">SMTP Username</label>
        <input type="text" id="smtp_user" name="smtp_user"
               value="<?= htmlspecialchars($s['smtp_user'] ?? '') ?>"
               autocomplete="username">
      </div>
      <div class="form-row">
        <label for="smtp_pass">SMTP Password</label>
        <input type="password" id="smtp_pass" name="smtp_pass"
               placeholder="Leave blank to keep existing"
               autocomplete="current-password">
        <?php if (!empty($s['smtp_pass'])): ?>
          <p class="hint">Password is set (not shown for security).</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row">
      <label for="smtp_secure">Encryption</label>
      <select id="smtp_secure" name="smtp_secure">
        <option value="tls" <?= ($s['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
        <option value="ssl" <?= ($s['smtp_secure'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (port 465)</option>
        <option value=""    <?= ($s['smtp_secure'] ?? '') === ''     ? 'selected' : '' ?>>None</option>
      </select>
    </div>

    <div class="row2">
      <div class="form-row">
        <label for="smtp_from">From Address</label>
        <input type="email" id="smtp_from" name="smtp_from"
               value="<?= htmlspecialchars($s['smtp_from'] ?? '') ?>"
               placeholder="noreply@yourdomain.com">
      </div>
      <div class="form-row">
        <label for="smtp_from_name">From Name</label>
        <input type="text" id="smtp_from_name" name="smtp_from_name"
               value="<?= htmlspecialchars($s['smtp_from_name'] ?? 'MyPictureDesk') ?>">
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save SMTP Settings</button>
  </form>

  <hr>

  <hr>

  <!-- ── Email templates ── -->
  <h2>Email Templates</h2>
  <p style="font-size:.82rem;color:#6b5ca5;margin-bottom:20px;">
    HTML is supported. Leave blank to restore the default. Placeholders are replaced when emails are sent.
  </p>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="save_templates">

    <div class="form-row">
      <label for="email_welcome_body">Welcome Email <span style="font-weight:400;color:#6b5ca5;">(sent to organizer when their party is created)</span></label>
      <p style="font-size:.74rem;color:#6b5ca5;margin:4px 0 8px;">
        Placeholders: <code>{{party_name}}</code> &nbsp; <code>{{guest_url}}</code> &nbsp; <code>{{admin_url}}</code> &nbsp; <code>{{setpassword_block}}</code>
      </p>
      <textarea id="email_welcome_body" name="email_welcome_body" rows="10"
                placeholder="Leave blank to use the default template"><?= htmlspecialchars($s['email_welcome_body'] ?? '') ?></textarea>
    </div>

    <div class="form-row" style="margin-top:20px;">
      <label for="email_notify_body">Upload Notification Email <span style="font-weight:400;color:#6b5ca5;">(sent when a guest uploads a photo)</span></label>
      <p style="font-size:.74rem;color:#6b5ca5;margin:4px 0 8px;">
        Placeholders: <code>{{party_name}}</code> &nbsp; <code>{{uploaded_by}}</code> &nbsp; <code>{{ip_display}}</code> &nbsp; <code>{{upload_time}}</code> &nbsp; <code>{{admin_url}}</code>
      </p>
      <textarea id="email_notify_body" name="email_notify_body" rows="10"
                placeholder="Leave blank to use the default template"><?= htmlspecialchars($s['email_notify_body'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Save Templates</button>
  </form>

  <hr>

  <!-- ── Test email ── -->
  <h2>Send Test Email</h2>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="send_test">
    <div class="test-row">
      <div class="form-row">
        <label for="test_email">Send test to</label>
        <input type="email" id="test_email" name="test_email"
               value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>"
               placeholder="you@example.com">
      </div>
      <button type="submit" class="btn btn-secondary" style="white-space:nowrap;">Send Test ✉️</button>
    </div>
  </form>
</div>
</body>
</html>
