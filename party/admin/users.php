<?php
declare(strict_types=1);

// ============================================================
// admin/users.php — Super admin: manage organiser accounts.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/logger.php';

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
$csrf    = $_SESSION['admin_csrf'];
$self_id = (int)$_SESSION['mpd_user_id'];

// ── Security headers ─────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; object-src 'none'; base-uri 'self';");
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
        $uid    = (int)($_POST['user_id'] ?? 0);

        // ─ Reset password (resend invite) ─
        if ($action === 'reset_password' && $uid > 0) {
            $user = mpd_get_user_by_id($uid);
            if ($user === false) {
                $error = 'User not found.';
            } elseif ($user['role'] === 'superadmin' && $uid !== $self_id) {
                $error = 'Cannot reset another superadmin\'s password from here.';
            } else {
                $inv_token = mpd_set_user_token($uid);
                $inv_link  = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($inv_token);
                $body = "<p>Hi,</p>\n"
                      . "<p>A password reset has been requested for your MyPictureDesk account.</p>\n"
                      . "<p><a href=\"{$inv_link}\">Click here to set your password</a>"
                      . " (link valid for 48 hours).</p>\n"
                      . "<p>If you did not request this, you can ignore this email.</p>";
                $sent = mpd_send_email($user['email'], 'MyPictureDesk — Set your password', $body);
                mpd_log('user.password_reset', [
                    'target.user_id' => $uid,
                    'email.sent'     => $sent,
                    'user.id'        => $self_id,
                    'user.role'      => 'superadmin',
                    'client.address' => partial_ip($_SERVER['REMOTE_ADDR'] ?? ''),
                ]);
                $success = $sent
                    ? 'Password reset email sent to ' . htmlspecialchars($user['email']) . '.'
                    : 'Token generated but email could not be sent. '
                      . 'Set-password link: <a href="' . htmlspecialchars($inv_link) . '">' . htmlspecialchars($inv_link) . '</a>';
            }
        }

        // ─ Delete user ─
        if ($action === 'delete_user' && $uid > 0) {
            if ($uid === $self_id) {
                $error = 'You cannot delete your own account.';
            } else {
                $user = mpd_get_user_by_id($uid);
                if ($user === false) {
                    $error = 'User not found.';
                } elseif ($user['role'] === 'superadmin') {
                    $error = 'Superadmin accounts cannot be deleted here.';
                } else {
                    $parties = mpd_get_parties_for_organizer($uid);
                    if (!empty($parties)) {
                        $error = 'Cannot delete ' . htmlspecialchars($user['email'])
                               . ' — they still have ' . count($parties)
                               . ' party/parties assigned. Delete or reassign the parties first.';
                    } else {
                        mpd_delete_user($uid);
                        $success = 'User ' . htmlspecialchars($user['email']) . ' deleted.';
                    }
                }
            }
        }
    }
}

$users = mpd_get_all_users();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Users — MyPictureDesk Admin</title>
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
    h1 { font-size: 1.5rem; font-weight: 900; margin-bottom: 24px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-ok  a { color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }

    /* User table */
    .user-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .user-table th { text-align: left; padding: 10px 14px; background: #2d1b69; color: #c9b8ff; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .user-table td { padding: 10px 14px; border-bottom: 1px solid #2d1b69; vertical-align: middle; }
    .user-table tr:hover td { background: rgba(255,255,255,0.03); }
    .user-table td + td, .user-table th + th { border-left: 1px solid rgba(100,80,170,0.35); }

    /* Buttons */
    .btn-sm { padding: 4px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-ghost  { background: #2d1b69; color: #c9b8ff; border: 1px solid #4b35a0; }
    .btn-ghost:hover  { background: #3d2494; color: #f0ebff; }
    .btn-danger { background: #7a1a1a; color: #f87171; border: none; }
    .btn-danger:hover { background: #9a2a2a; color: #fca5a5; }
    .btn-danger:disabled { opacity: 0.3; cursor: not-allowed; }
    .btn-email  { background: #0f2d47; color: #7fb3e8; border: none; border-radius: 6px; padding: 4px 10px; font-size: 0.75rem; font-weight: 700; text-decoration: none; display: inline-block; }
    .btn-email:hover { background: #163d5e; color: #a8cfee; }
    .action-cell { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; }
    .inline-form { display: inline; }

    /* Pills */
    .role-pill   { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; }
    .pill-super  { background: #3d1a69; color: #c9b8ff; }
    .pill-org    { background: #1a2e4a; color: #7fb3e8; }
    .active-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; }
    .pill-active   { background: #1a4a2e; color: #6ee7a0; }
    .pill-inactive { background: #4a1a1a; color: #f87171; }
    .pill-invite   { background: #3a2a00; color: #f5a623; font-size: 0.68rem; }

    .muted { color: #4a3580; font-size: 0.8rem; }
    .party-count { font-weight: 700; color: #c9b8ff; }
    .party-count.zero { color: #4a3580; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">📸 Dashboard</a>
    <a class="nav-link" href="parties.php">🎉 Parties</a>
    <a class="nav-link active" href="users.php">👥 Users</a>
    <a class="nav-link" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <h1>👥 User Management</h1>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= $success /* may contain a safe link — constructed server-side */ ?></div>
  <?php elseif ($error !== ''): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (empty($users)): ?>
    <p class="muted">No users found.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="user-table">
      <thead>
        <tr>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Parties</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $is_self       = (int)$u['id'] === $self_id;
            $party_count   = (int)$u['party_count'];
            $can_delete    = !$is_self && $u['role'] !== 'superadmin' && $party_count === 0;
            $last_login    = $u['last_login_at']
                ? htmlspecialchars(date('d M Y H:i', strtotime($u['last_login_at'])))
                : '<span class="muted">Never</span>';
        ?>
        <tr>
          <td>
            <?= htmlspecialchars($u['email']) ?>
            <?php if ($is_self): ?>
              <span class="muted">(you)</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="role-pill <?= $u['role'] === 'superadmin' ? 'pill-super' : 'pill-org' ?>">
              <?= $u['role'] === 'superadmin' ? 'Superadmin' : 'Organiser' ?>
            </span>
          </td>
          <td>
            <span class="active-pill <?= $u['is_active'] ? 'pill-active' : 'pill-inactive' ?>">
              <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
            <?php if ($u['has_pending_invite']): ?>
              <span class="active-pill pill-invite">Invite pending</span>
            <?php elseif (!$u['has_password']): ?>
              <span class="active-pill pill-invite">No password</span>
            <?php endif; ?>
          </td>
          <td><?= $last_login ?></td>
          <td>
            <span class="party-count <?= $party_count === 0 ? 'zero' : '' ?>">
              <?= $party_count ?>
            </span>
          </td>
          <td>
            <div class="action-cell">
              <!-- Email -->
              <a class="btn-email" href="mailto:<?= htmlspecialchars($u['email']) ?>"
                 title="Send email to <?= htmlspecialchars($u['email']) ?>">✉️ Email</a>

              <!-- Reset password -->
              <form class="inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"  value="reset_password">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn-sm btn-ghost"
                        title="Generate a new set-password link and email it to this user">
                  🔑 Reset password
                </button>
              </form>

              <!-- Delete -->
              <?php if ($u['role'] !== 'superadmin' && !$is_self): ?>
              <form class="inline-form" method="post"
                    <?= $can_delete ? 'data-confirm="Delete user ' . htmlspecialchars(addslashes($u['email'])) . '? This cannot be undone."' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"  value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn-sm btn-danger"
                        <?= !$can_delete ? 'disabled title="User still has ' . $party_count . ' party/parties — delete or reassign first"' : 'title="Permanently delete this user account"' ?>>
                  Delete
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
}());
</script>
</body>
</html>
