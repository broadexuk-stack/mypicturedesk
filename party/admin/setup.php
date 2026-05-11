<?php
declare(strict_types=1);

// ============================================================
// admin/setup.php — ONE-TIME superadmin account creation.
//
// IMPORTANT: Delete or rename this file after use.
//            Anyone who can reach this URL can create a
//            superadmin account.
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$error   = '';
$success = '';

// Check whether a superadmin already exists
$superadmin_exists = false;
try {
    $st = db_pdo()->query(
        "SELECT COUNT(*) FROM mpd_users WHERE role = 'superadmin'"
    );
    $superadmin_exists = (int)$st->fetchColumn() > 0;
} catch (PDOException $e) {
    $error = 'Database error: ' . htmlspecialchars($e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$superadmin_exists && $error === '') {
    $email = trim($_POST['email'] ?? '');
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pass1) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
            $sql  = "INSERT INTO mpd_users (email, password_hash, role, is_active)
                     VALUES (:email, :hash, 'superadmin', 1)";
            db_pdo()->prepare($sql)->execute([':email' => $email, ':hash' => $hash]);
            $success = 'Superadmin account created for ' . htmlspecialchars($email)
                     . '. <strong>Delete this file now!</strong>';
        } catch (PDOException $e) {
            $error = 'Could not create account: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MyPictureDesk — Initial Setup</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    body { background:#1a0a40; }
    .setup-card {
      max-width:440px; margin:60px auto; background:#fff;
      border-radius:16px; padding:36px 32px; box-shadow:0 8px 40px rgba(0,0,0,.4);
      font-family:Nunito,sans-serif;
    }
    h1 { font-size:1.4rem; color:#2d1b69; margin:0 0 6px; }
    .sub { color:#666; font-size:.9rem; margin:0 0 24px; }
    label { display:block; font-size:.85rem; font-weight:700; color:#2d1b69; margin:14px 0 4px; }
    input[type=email],input[type=password] {
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
    .warn { background:#fef3c7; color:#92400e; padding:12px 16px; border-radius:8px; font-size:.85rem; margin-bottom:20px; }
  </style>
</head>
<body>
<div class="setup-card">
  <h1>MyPictureDesk Setup</h1>
  <p class="sub">Create the initial superadmin account.</p>

  <?php if ($error !== ''): ?>
    <div class="msg msg-error"><?= $error ?></div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
    <div class="msg msg-success"><?= $success ?></div>
    <p style="font-size:.85rem;color:#555;">You can now <a href="index.php">log in</a>.</p>
  <?php elseif ($superadmin_exists): ?>
    <div class="msg msg-error">A superadmin account already exists. This setup page has nothing to do.</div>
  <?php else: ?>
    <div class="warn">⚠️ <strong>Delete this file after use.</strong> Anyone who can reach this URL can create a superadmin account.</div>
    <form method="post" autocomplete="off">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

      <label for="password">Password <span style="font-weight:400;color:#888;">(min 12 characters)</span></label>
      <input type="password" id="password" name="password" required minlength="12">

      <label for="password2">Confirm password</label>
      <input type="password" id="password2" name="password2" required minlength="12">

      <button type="submit" class="btn-submit">Create Superadmin Account</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
