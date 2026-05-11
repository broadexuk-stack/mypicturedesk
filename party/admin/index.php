<?php
declare(strict_types=1);

// ============================================================
// admin/index.php — Login page + moderation panel.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

// ── Session setup ───────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

// Regenerate CSRF token for admin if not present
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

// ── Security headers ────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; "
     . "img-src 'self' data: blob:; "
     . "script-src 'self' 'nonce-$nonce'; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; "
     . "connect-src 'self'; "
     . "object-src 'none'; "
     . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// ── Session lifetime ────────────────────────────────────────
$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active'])) {
    if (time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
}
if (isset($_SESSION['admin_logged_in'])) {
    $_SESSION['admin_last_active'] = time();
}

$logged_in = !empty($_SESSION['admin_logged_in']);
$error_msg = '';

// ── Handle logout ───────────────────────────────────────────
if ($logged_in && isset($_GET['logout'])) {
    $tok = $_GET['logout'];
    if (hash_equals($_SESSION['admin_csrf'], $tok)) {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// ── Handle login POST ───────────────────────────────────────
if (!$logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    // CSRF check
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_csrf'], $submitted_csrf)) {
        $error_msg = 'Invalid request. Please try again.';
    } else {
        $pw = $_POST['password'] ?? '';
        // password_verify is timing-safe
        if (password_verify($pw, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in']   = true;
            $_SESSION['admin_last_active'] = time();
            $_SESSION['admin_csrf']        = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        } else {
            // Generic message — no enumeration hint
            $error_msg = 'Login failed. Please try again.';
            // Brief artificial delay to slow brute force
            usleep(500_000);
        }
    }
}

// ── Active tab ──────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'approved', 'dashboard'], true)) {
    $tab = 'pending';
}

// ── Fetch data (only when logged in) ───────────────────────
$counts  = [];
$photos  = [];
if ($logged_in) {
    $counts = db_counts();
    if ($tab === 'pending') {
        $photos = db_get_photos('pending');
    } elseif ($tab === 'approved') {
        $photos = db_get_photos('approved');
    }
}

$csrf    = $_SESSION['admin_csrf'];
$baseUrl = '../'; // relative URL back to the gallery/ folder

function thumb_url(array $p): string {
    if ($p['status'] === 'pending') {
        // Quarantine files are not directly accessible — proxy through thumb.php
        return 'thumb.php?uuid=' . urlencode($p['uuid']);
    }
    $ext = output_extension($p['original_extension']);
    return '../gallery/thumbs/' . $p['uuid'] . '.' . $ext;
}
function full_url(array $p): string {
    $ext = output_extension($p['original_extension']);
    return '../gallery/' . $p['uuid'] . '.' . $ext;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin — <?= htmlspecialchars(PARTY_NAME) ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; font-size: 1rem; }

    /* ── Login ── */
    .login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
    .login-card { background: #2d1b69; border-radius: 16px; padding: 40px 36px; width: 100%; max-width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.4); }
    .login-card h1 { font-size: 1.6rem; font-weight: 900; margin-bottom: 24px; text-align: center; }
    .login-card label { display: block; font-size: 0.9rem; margin-bottom: 6px; color: #c9b8ff; }
    .login-card input[type=password] { width: 100%; padding: 12px 14px; border-radius: 8px; border: 2px solid #4b35a0; background: #160f35; color: #f0ebff; font-size: 1rem; font-family: inherit; }
    .login-card input[type=password]:focus { outline: none; border-color: #f5a623; }
    .btn-login { margin-top: 20px; width: 100%; padding: 14px; background: #f5a623; color: #1a1035; font-weight: 900; font-size: 1.1rem; border: none; border-radius: 10px; cursor: pointer; font-family: inherit; }
    .btn-login:hover { background: #e6941a; }
    .login-error { background: #c0392b; color: #fff; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.95rem; }

    /* ── Layout ── */
    .admin-wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .admin-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 0 24px; gap: 12px; flex-wrap: wrap; }
    .admin-header h1 { font-size: 1.4rem; font-weight: 900; }
    .admin-header a { color: #c9b8ff; font-size: 0.9rem; text-decoration: none; }
    .admin-header a:hover { color: #f5a623; }

    /* ── Tabs ── */
    .tab-bar { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
    .tab-btn { padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.95rem; color: #c9b8ff; background: #2d1b69; border: 2px solid transparent; }
    .tab-btn.active, .tab-btn:hover { background: #f5a623; color: #1a1035; }
    .badge { display: inline-block; background: #e74c3c; color: #fff; border-radius: 12px; padding: 1px 7px; font-size: 0.75rem; margin-left: 4px; }

    /* ── Dashboard ── */
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 32px; }
    .stat-card { background: #2d1b69; border-radius: 12px; padding: 20px; text-align: center; }
    .stat-card .stat-num { font-size: 2.5rem; font-weight: 900; color: #f5a623; }
    .stat-card .stat-label { font-size: 0.85rem; color: #c9b8ff; margin-top: 4px; }

    /* ── Photo grid ── */
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
    .photo-card { background: #2d1b69; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
    .photo-card img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
    .photo-card .card-meta { padding: 10px 12px; font-size: 0.8rem; color: #c9b8ff; flex: 1; }
    .photo-card .card-meta time { display: block; margin-bottom: 4px; }
    .photo-card .card-actions { display: flex; gap: 8px; padding: 10px 12px; }
    .btn-approve { flex: 1; padding: 10px 0; background: #27ae60; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-approve:hover { background: #219150; }
    .btn-reject  { flex: 1; padding: 10px 0; background: #e74c3c; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-reject:hover { background: #c0392b; }
    .btn-remove  { flex: 1; padding: 10px 0; background: #7f8c8d; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-remove:hover { background: #636e72; }

    .empty-msg { color: #c9b8ff; padding: 32px; text-align: center; font-size: 1.1rem; }

    /* Card fade-out on action */
    .photo-card.removing { opacity: 0; transform: scale(0.9); transition: all 0.3s ease; pointer-events: none; }
  </style>
</head>
<body>

<?php if (!$logged_in): ?>
<!-- ══════════════════ LOGIN PAGE ══════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <h1>🔐 Admin Login</h1>
    <?php if ($error_msg): ?>
      <div class="login-error" role="alert"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <form method="post" action="index.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label for="pw">Password</label>
      <input type="password" id="pw" name="password" required autofocus autocomplete="current-password">
      <button class="btn-login" type="submit">Sign In</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ ADMIN PANEL ══════════════════ -->
<div class="admin-wrap">

  <div class="admin-header">
    <h1>🛠️ <?= htmlspecialchars(PARTY_NAME) ?> — Admin</h1>
    <a href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
  </div>

  <nav class="tab-bar" aria-label="Admin sections">
    <a href="index.php?tab=dashboard" class="tab-btn <?= $tab === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
    <a href="index.php?tab=pending"   class="tab-btn <?= $tab === 'pending'   ? 'active' : '' ?>">
      ⏳ Pending
      <?php if ($counts['pending'] > 0): ?>
        <span class="badge"><?= $counts['pending'] ?></span>
      <?php endif; ?>
    </a>
    <a href="index.php?tab=approved"  class="tab-btn <?= $tab === 'approved'  ? 'active' : '' ?>">✅ Approved (<?= $counts['approved'] ?>)</a>
  </nav>

  <!-- ── Dashboard tab ── -->
  <?php if ($tab === 'dashboard'): ?>
  <div class="stat-grid" role="region" aria-label="Statistics">
    <div class="stat-card">
      <div class="stat-num"><?= $counts['pending'] ?></div>
      <div class="stat-label">Awaiting Review</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $counts['approved'] ?></div>
      <div class="stat-label">In Gallery</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $counts['rejected_today'] ?></div>
      <div class="stat-label">Rejected Today</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $counts['total'] ?></div>
      <div class="stat-label">Total Uploads</div>
    </div>
  </div>
  <p style="color:#c9b8ff;font-size:0.9rem;">
    Switch to the <strong>Pending</strong> tab to review and approve photos.
  </p>

  <!-- ── Pending tab ── -->
  <?php elseif ($tab === 'pending'): ?>
  <?php if (empty($photos)): ?>
    <p class="empty-msg">🎉 No photos waiting for review.</p>
  <?php else: ?>
  <div class="photo-grid" id="photo-grid" role="list">
    <?php foreach ($photos as $p): ?>
    <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem">
      <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
           alt="Pending photo uploaded <?= htmlspecialchars($p['upload_timestamp']) ?>"
           loading="lazy"
           onerror="this.style.display='none'">
      <div class="card-meta">
        <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
        IP: <?= htmlspecialchars($p['ip_display']) ?>
      </div>
      <div class="card-actions">
        <button class="btn-approve"
                data-uuid="<?= htmlspecialchars($p['uuid']) ?>"
                data-action="approve"
                aria-label="Approve this photo">
          ✅ Approve
        </button>
        <button class="btn-reject"
                data-uuid="<?= htmlspecialchars($p['uuid']) ?>"
                data-action="reject"
                aria-label="Reject this photo">
          🗑️ Reject
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Approved tab ── -->
  <?php elseif ($tab === 'approved'): ?>
  <?php if (empty($photos)): ?>
    <p class="empty-msg">No approved photos yet.</p>
  <?php else: ?>
  <div class="photo-grid" id="photo-grid" role="list">
    <?php foreach ($photos as $p): ?>
    <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem">
      <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
           alt="Approved photo"
           loading="lazy"
           onerror="this.style.display='none'">
      <div class="card-meta">
        <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['approved_at'] ?? $p['upload_timestamp']))) ?></time>
        IP: <?= htmlspecialchars($p['ip_display']) ?>
      </div>
      <div class="card-actions">
        <button class="btn-remove"
                data-uuid="<?= htmlspecialchars($p['uuid']) ?>"
                data-action="reject"
                aria-label="Remove this photo from the gallery">
          🗑️ Remove
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div><!-- /admin-wrap -->

<script nonce="<?= $nonce ?>">
(function () {
  'use strict';

  const CSRF  = <?= json_encode($csrf) ?>;

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;

    const uuid   = btn.dataset.uuid;
    const action = btn.dataset.action;
    const card   = document.getElementById('card-' + uuid);
    if (!uuid || !card) return;

    // Optimistic UI — fade card immediately
    card.classList.add('removing');

    fetch('moderate.php', {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : new URLSearchParams({ uuid, action, csrf_token: CSRF }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        // Remove card from DOM after CSS transition
        setTimeout(() => card.remove(), 320);
      } else {
        card.classList.remove('removing');
        alert('Error: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(() => {
      card.classList.remove('removing');
      alert('Network error. Please try again.');
    });
  });
})();
</script>
<?php endif; ?>

</body>
</html>
