<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/image.php';

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
    if (hash_equals($_SESSION['admin_csrf'], $_GET['logout'])) {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// ── Handle login POST ───────────────────────────────────────
if (!$logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_csrf'], $submitted_csrf)) {
        $error_msg = 'Invalid request. Please try again.';
    } else {
        $pw = $_POST['password'] ?? '';
        if (password_verify($pw, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in']   = true;
            $_SESSION['admin_last_active'] = time();
            $_SESSION['admin_csrf']        = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        } else {
            $error_msg = 'Login failed. Please try again.';
            usleep(500_000);
        }
    }
}

// ── Fetch data ──────────────────────────────────────────────
$counts  = [];
$pending = [];
$approved = [];
$removed  = [];
if ($logged_in) {
    $counts   = db_counts();
    $pending  = db_get_photos('pending');
    $approved = db_get_photos('approved');
    $removed  = db_get_photos('removed');
}

$csrf = $_SESSION['admin_csrf'];

function thumb_url(array $p): string {
    if ($p['status'] === 'pending') {
        return 'thumb.php?uuid=' . urlencode($p['uuid']);
    }
    $ext = output_extension($p['original_extension']);
    return '../gallery/thumbs/' . $p['uuid'] . '.' . $ext;
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

    /* ── Stats bar ── */
    .stats-bar {
      position: sticky;
      top: 0;
      z-index: 50;
      height: 50px;
      background: #160f35;
      border-bottom: 1px solid #2d1b69;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 20px;
      gap: 12px;
    }
    .stats-bar .stat-items {
      display: flex;
      align-items: center;
      gap: 24px;
    }
    .stat-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.85rem;
      color: #c9b8ff;
      white-space: nowrap;
    }
    .stat-item strong {
      color: #f5a623;
      font-size: 1rem;
      font-weight: 900;
    }
    .stat-item.has-pending strong { color: #e74c3c; }
    .stats-bar .signout {
      color: #c9b8ff;
      font-size: 0.8rem;
      text-decoration: none;
      white-space: nowrap;
    }
    .stats-bar .signout:hover { color: #f5a623; }

    /* ── Page header ── */
    .page-header {
      padding: 20px 20px 0;
      max-width: 1400px;
      margin: 0 auto;
    }
    .page-header h1 { font-size: 1.3rem; font-weight: 900; color: #f0ebff; }

    /* ── Sections ── */
    .admin-body { max-width: 1400px; margin: 0 auto; padding: 20px; }

    .section-heading {
      font-size: 1rem;
      font-weight: 900;
      color: #c9b8ff;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .section-heading .count-pill {
      background: #e74c3c;
      color: #fff;
      border-radius: 999px;
      padding: 1px 8px;
      font-size: 0.75rem;
    }

    .section-divider {
      border: none;
      border-top: 2px solid #2d1b69;
      margin: 32px 0;
    }

    /* ── Photo grid ── */
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
    .photo-card { background: #2d1b69; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
    .photo-card img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
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

    .empty-msg { color: #4a3580; font-size: 0.95rem; padding: 16px 0; }

    /* Wastebasket section */
    .wastebasket-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn-purge {
      padding: 8px 20px;
      background: #922b21;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-weight: 700;
      font-size: 0.85rem;
      cursor: pointer;
      font-family: inherit;
    }
    .btn-purge:hover { background: #7b241c; }
    .btn-purge:disabled { opacity: 0.4; cursor: not-allowed; }

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

<!-- Stats bar -->
<div class="stats-bar" role="region" aria-label="Statistics">
  <div class="stat-items">
    <div class="stat-item <?= $counts['pending'] > 0 ? 'has-pending' : '' ?>">
      ⏳ Pending <strong id="stat-pending"><?= $counts['pending'] ?></strong>
    </div>
    <div class="stat-item">
      ✅ Approved <strong id="stat-approved"><?= $counts['approved'] ?></strong>
    </div>
    <div class="stat-item">
      🗑️ Wastebasket <strong id="stat-removed"><?= $counts['removed'] ?></strong>
    </div>
    <div class="stat-item">
      📸 Total <strong><?= $counts['total'] ?></strong>
    </div>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="admin-body">

  <!-- ── Pending section ── -->
  <div class="section-heading">
    ⏳ Awaiting Approval
    <?php if ($counts['pending'] > 0): ?>
      <span class="count-pill"><?= $counts['pending'] ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($pending)): ?>
    <p class="empty-msg">No photos waiting for review.</p>
  <?php else: ?>
  <div class="photo-grid" role="list">
    <?php foreach ($pending as $p): ?>
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
        <button class="btn-approve" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="approve" data-section="pending" aria-label="Approve">✅</button>
        <button class="btn-reject"  data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="reject"  data-section="pending" aria-label="Reject">🗑️</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <hr class="section-divider">

  <!-- ── Approved section ── -->
  <div class="section-heading">
    ✅ In the Gallery
    <span style="color:#4a3580;font-weight:400;text-transform:none;letter-spacing:0;font-size:0.85rem;">(<?= $counts['approved'] ?>)</span>
  </div>

  <?php if (empty($approved)): ?>
    <p class="empty-msg">No approved photos yet.</p>
  <?php else: ?>
  <div class="photo-grid" role="list">
    <?php foreach ($approved as $p): ?>
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
        <button class="btn-remove" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="remove" data-section="approved" aria-label="Move to wastebasket">🗑️ Remove</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <hr class="section-divider">

  <!-- ── Wastebasket section ── -->
  <div class="wastebasket-bar">
    <div class="section-heading" style="margin-bottom:0;">
      🗑️ Wastebasket
      <?php if ($counts['removed'] > 0): ?>
        <span class="count-pill"><?= $counts['removed'] ?></span>
      <?php endif; ?>
    </div>
    <button class="btn-purge" id="btn-purge-all"
            <?= empty($removed) ? 'disabled' : '' ?>
            aria-label="Permanently delete all wastebasket photos">
      🗑️ Empty Wastebasket
    </button>
  </div>

  <div class="photo-grid" id="wastebasket-grid" role="list">
  <?php if (empty($removed)): ?>
    <p class="empty-msg">Wastebasket is empty.</p>
  <?php else: ?>
    <?php foreach ($removed as $p): ?>
    <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem">
      <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
           alt="Wastebasket photo"
           loading="lazy"
           onerror="this.style.display='none'">
      <div class="card-meta">
        <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
        IP: <?= htmlspecialchars($p['ip_display']) ?>
      </div>
      <div class="card-actions">
        <button class="btn-restore" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="restore" data-section="removed" aria-label="Restore to gallery">↩️ Restore</button>
        <button class="btn-reject"  data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="reject"  data-section="removed" aria-label="Delete permanently">✕</button>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

</div><!-- /admin-body -->

<script nonce="<?= $nonce ?>">
(function () {
  'use strict';
  const CSRF = <?= json_encode($csrf) ?>;

  function adjStat(id, delta) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
  }

  // Empty Wastebasket button
  const purgeBtn = document.getElementById('btn-purge-all');
  if (purgeBtn) {
    purgeBtn.addEventListener('click', function () {
      if (!confirm('Permanently delete all wastebasket photos? This cannot be undone.')) return;
      purgeBtn.disabled = true;
      fetch('moderate.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'purge_all', csrf_token: CSRF }),
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          const grid = document.getElementById('wastebasket-grid');
          if (grid) grid.innerHTML = '<p class="empty-msg">Wastebasket is empty.</p>';
          document.getElementById('stat-removed').textContent = '0';
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
          purgeBtn.disabled = false;
        }
      })
      .catch(() => {
        alert('Network error. Please try again.');
        purgeBtn.disabled = false;
      });
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

    card.classList.add('removing');

    fetch('moderate.php', {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : new URLSearchParams({ uuid, action, csrf_token: CSRF }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        setTimeout(() => {
          card.remove();
          if (action === 'approve') {
            adjStat('stat-pending', -1);
            adjStat('stat-approved', 1);
          } else if (action === 'reject') {
            adjStat(section === 'removed' ? 'stat-removed' : 'stat-pending', -1);
          } else if (action === 'remove') {
            adjStat('stat-approved', -1);
            adjStat('stat-removed', 1);
            // re-enable purge button if wastebasket now has items
            if (purgeBtn) purgeBtn.disabled = false;
          } else if (action === 'restore') {
            adjStat('stat-removed', -1);
            adjStat('stat-approved', 1);
          }
        }, 320);
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
