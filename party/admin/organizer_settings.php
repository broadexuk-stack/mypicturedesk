<?php
declare(strict_types=1);

// ============================================================
// admin/organizer_settings.php — Organizer edits their party.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

// ── Auth: organizer (or superadmin browsing a party) ────────
$user_id  = (int)($_SESSION['mpd_user_id'] ?? 0);
$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

if ($user_id === 0) { header('Location: index.php'); exit; }

// Superadmin can view/edit any party via ?party_id=N
if ($role === 'superadmin' && isset($_GET['party_id'])) {
    $party_id = (int)$_GET['party_id'];
}

if ($party_id === 0) { header('Location: index.php'); exit; }

$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    session_unset(); session_destroy();
    header('Location: index.php'); exit;
}
$_SESSION['admin_last_active'] = time();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$party   = mpd_get_party_by_id($party_id);
if ($party === false) { header('Location: index.php'); exit; }

$s_plat      = mpd_get_all_settings();
$ret_max     = max(1, (int)($s_plat['retention_max_days'] ?? 365));
$org_parties = mpd_get_parties_for_organizer($user_id);

// Organizer may only edit their own party
if ($role === 'organizer' && (int)$party['organizer_id'] !== $user_id) {
    header('Location: index.php'); exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submitted_csrf)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name     = mb_substr(trim($_POST['party_name']      ?? ''), 0, 200);
        $oname    = mb_substr(trim($_POST['organiser_name']  ?? ''), 0, 200);
        $edt      = trim($_POST['event_datetime'] ?? '');
        $info     = mb_substr(trim($_POST['party_info']      ?? ''), 0, 1000);
        $notify   = trim($_POST['notify_email'] ?? '');
        $ret_raw  = (int)($_POST['retention_days'] ?? (int)($party['retention_days'] ?? 30));
        $ret_days = max(1, min($ret_max, $ret_raw));

        if ($name === '') {
            $error = 'Party name is required.';
        } elseif ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            $error = 'Notification email is not a valid address.';
        } else {
            mpd_update_party($party_id, [
                'party_name'           => $name,
                'organiser_name'       => $oname !== '' ? $oname : null,
                'event_datetime'       => $edt !== '' ? $edt : null,
                'party_info'           => $info !== '' ? $info : null,
                'notify_email'         => $notify !== '' ? $notify : null,
                'retention_days'       => $ret_days,
                'timer_camera_enabled' => isset($_POST['timer_camera_enabled']) ? 1 : 0,
            ]);
            $party   = mpd_get_party_by_id($party_id); // reload
            $success = 'Settings saved.';
        }
    }
}

// Prepare datetime-local value (MySQL datetime → HTML input format)
$edt_val = '';
if (!empty($party['event_datetime'])) {
    $edt_val = date('Y-m-d\TH:i', strtotime($party['event_datetime']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Party Settings — <?= htmlspecialchars($party['party_name']) ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; }
    .topbar { position: sticky; top: 0; z-index: 50; height: 50px; background: #160f35; border-bottom: 1px solid #2d1b69; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .nav-links { display: flex; gap: 16px; }
    .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; }
    .nav-link:hover { color: #f5a623; }
    .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; }
    .signout:hover { color: #f5a623; }
    .page { max-width: 600px; margin: 0 auto; padding: 32px 20px; }
    h1 { font-size: 1.4rem; font-weight: 900; margin-bottom: 8px; }
    .sub { color: #9c7fff; font-size: 0.85rem; margin-bottom: 28px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }
    .form-row { margin-bottom: 18px; }
    label { display: block; font-size: 0.82rem; font-weight: 700; color: #c9b8ff; margin-bottom: 5px; }
    input[type=text], input[type=email], input[type=number], input[type=datetime-local], textarea {
      width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0;
      background: #160f35; color: #f0ebff; font-size: 0.9rem; font-family: inherit;
    }
    textarea { resize: vertical; min-height: 100px; }
    input:focus, textarea:focus { outline: none; border-color: #f5a623; }
    .hint { font-size: 0.74rem; color: #6b5ca5; margin-top: 4px; }
    .readonly-val { background: #160f35; border: 2px solid #2d1b69; border-radius: 8px; padding: 10px 14px; font-size: 0.9rem; color: #6b5ca5; }
    .btn-save { padding: 12px 32px; background: #f5a623; color: #1a1035; border: none; border-radius: 10px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; }
    .btn-save:hover { background: #e6941a; }
    .coming-soon { font-size: 0.78rem; color: #4a3580; background: #2d1b69; border-radius: 8px; padding: 8px 14px; margin-top: 6px; }
    .party-switch-sel { font-family: inherit; font-size: 0.82rem; padding: 5px 10px; border-radius: 8px; border: 1px solid #4b35a0; background: #2d1b69; color: #c9b8ff; cursor: pointer; max-width: 200px; }
    .checkbox-row { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#160f35; border:2px solid #4b35a0; border-radius:8px; cursor:pointer; }
    .checkbox-row input[type=checkbox] { width:16px; height:16px; accent-color:#f5a623; cursor:pointer; flex-shrink:0; }
    .checkbox-row span { font-size:0.85rem; font-weight:700; color:#c9b8ff; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">← Back to Moderation</a>
    <a class="nav-link" href="qrcode.php">📱 QR Code</a>
    <?php if (count($org_parties) > 1): ?>
    <form id="party-switch-form" method="post" action="switch_party.php" style="display:flex;align-items:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="redirect" value="organizer_settings.php">
      <select class="party-switch-sel" id="party-switch-sel" name="party_id" aria-label="Switch party">
        <?php foreach ($org_parties as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $party_id ? 'selected' : '' ?>>
            <?= $p['is_active'] ? '▶' : '⏸' ?> <?= htmlspecialchars($p['party_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <h1>⚙️ Party Settings</h1>
  <p class="sub">Changes take effect immediately on the guest page.</p>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error !== ''): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="form-row">
      <label>Party URL Slug (read-only)</label>
      <div class="readonly-val"><?= htmlspecialchars($party['slug']) ?></div>
      <p class="hint">Guest URL: <?= BASE_URL ?>/party?id=<?= htmlspecialchars($party['slug']) ?></p>
    </div>

    <div class="form-row">
      <label for="party_name">Party Name *</label>
      <input type="text" id="party_name" name="party_name" required maxlength="200"
             value="<?= htmlspecialchars($party['party_name']) ?>">
    </div>

    <div class="form-row">
      <label for="organiser_name">Organiser Display Name</label>
      <input type="text" id="organiser_name" name="organiser_name" maxlength="200"
             value="<?= htmlspecialchars($party['organiser_name'] ?? '') ?>"
             placeholder="e.g. Sarah &amp; James">
      <p class="hint">Shown to guests on the party page and if the gallery is paused.</p>
    </div>

    <div class="form-row">
      <label for="event_datetime">Event Date &amp; Time</label>
      <input type="datetime-local" id="event_datetime" name="event_datetime"
             value="<?= htmlspecialchars($edt_val) ?>">
    </div>

    <div class="form-row">
      <label for="party_info">Party Info Text</label>
      <textarea id="party_info" name="party_info" maxlength="1000"
                placeholder="Optional text shown on the guest page below the party name"><?= htmlspecialchars($party['party_info'] ?? '') ?></textarea>
    </div>

    <div class="form-row">
      <label for="notify_email">Notification Email</label>
      <input type="email" id="notify_email" name="notify_email"
             value="<?= htmlspecialchars($party['notify_email'] ?? '') ?>"
             placeholder="Receives an alert on each new upload">
      <p class="hint">Leave blank to disable upload notifications.</p>
    </div>

    <div class="form-row">
      <label for="retention_days">Photo Retention Period (days)</label>
      <input type="number" id="retention_days" name="retention_days" min="1" max="<?= $ret_max ?>"
             value="<?= (int)($party['retention_days'] ?? 30) ?>">
      <p class="hint">Photos will be flagged for removal after this many days. Maximum allowed: <?= $ret_max ?> days.</p>
    </div>

    <div class="form-row">
      <label>Colour Theme</label>
      <div class="coming-soon">🎨 Colour picker coming in a future update</div>
    </div>

    <div class="form-row">
      <label>Timer Selfie Camera</label>
      <label class="checkbox-row">
        <input type="checkbox" name="timer_camera_enabled" value="1" <?= !empty($party['timer_camera_enabled']) ? 'checked' : '' ?>>
        <span>⏱ Enable in-browser countdown selfie on the guest page</span>
      </label>
      <p class="hint">Lower resolution than the native camera — ideal for quick group selfies.</p>
    </div>

    <button type="submit" class="btn-save">Save Settings</button>
  </form>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  var sel = document.getElementById('party-switch-sel');
  if (sel) {
    sel.addEventListener('change', function () {
      document.getElementById('party-switch-form').submit();
    });
  }
}());
</script>
</body>
</html>
