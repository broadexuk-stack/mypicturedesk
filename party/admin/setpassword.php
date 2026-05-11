<?php
declare(strict_types=1);

// ============================================================
// admin/setpassword.php — Token-based first-login / password reset.
// Linked from the invitation email:
//   https://mypicturedesk.com/party/admin/setpassword.php?token=...
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;

// Validate token
$user = ($token !== '') ? mpd_get_user_by_token($token) : false;

if ($token === '' || $user === false) {
    $error = 'This link is invalid or has expired. Please ask your administrator to resend an invitation.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== false && $error === '') {
    $pass1 = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass1) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
        mpd_set_user_password((int)$user['id'], $hash);
        mpd_deactivate_token((int)$user['id']);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set Your Password — MyPictureDesk</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    body { background:#1a0a40; }
    .card {
      max-width:420px; margin:60px auto; background:#fff;
      border-radius:16px; padding:36px 32px; box-shadow:0 8px 40px rgba(0,0,0,.4);
      font-family:Nunito,sans-serif;
    }
    h1 { font-size:1.4rem; color:#2d1b69; margin:0 0 6px; }
    .sub { color:#666; font-size:.9rem; margin:0 0 24px; }
    label { display:block; font-size:.85rem; font-weight:700; color:#2d1b69; margin:14px 0 4px; }
    input[type=password] {
      width:100%; box-sizing:border-box; padding:10px 14px;
      border:2px solid #d4c9f0; border-radius:8px; font-size:1rem;
    }
    input:focus { outline:none; border-color:#7c3aed; }
    .btn-submit {
      display:block; width:100%; margin-top:24px; padding:12px;
      background:#7c3aed; color:#fff; border:none; border-radius:10px;
      font-size:1rem; font-weight:700; cursor:pointer;
    }
    .btn-submit:hover { background:#5b21b6; }
    .msg { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:.9rem; }
    .msg-error   { background:#fee2e2; color:#991b1b; }
    .msg-success { background:#d1fae5; color:#065f46; }
  </style>
</head>
<body>
<div class="card">
  <h1>Set Your Password</h1>

  <?php if ($done): ?>
    <div class="msg msg-success">Password set! You can now <a href="index.php">log in</a>.</div>

  <?php elseif ($error !== ''): ?>
    <div class="msg msg-error"><?= htmlspecialchars($error) ?></div>

  <?php else: ?>
    <p class="sub">Welcome, <?= htmlspecialchars($user['email']) ?>. Choose a password to activate your account.</p>

    <?php if (isset($_POST['password']) && $error !== ''): ?>
      <div class="msg msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <label for="password">New password <span style="font-weight:400;color:#888;">(min 12 characters)</span></label>
      <input type="password" id="password" name="password" required minlength="12" autofocus>

      <label for="password2">Confirm password</label>
      <input type="password" id="password2" name="password2" required minlength="12">

      <button type="submit" class="btn-submit">Set Password &amp; Log In</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
