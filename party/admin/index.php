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
    // Photos that were never approved have their thumb only in quarantine/thumbs/
    if ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at']))) {
        return 'thumb.php?uuid=' . urlencode($p['uuid']);
    }
    $ext = output_extension($p['original_extension']);
    return '../gallery/thumbs/' . $p['uuid'] . '.' . $ext;
}

function dl_filename(array $p): string {
    $name = !empty($p['uploaded_by'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($p['uploaded_by'], 0, 30)) . '_'
        : 'photo_';
    $ts   = date('Ymd_His', strtotime($p['upload_timestamp']));
    $ext  = output_extension($p['original_extension']);
    return $name . $ts . '.' . $ext;
}

function full_url(array $p): string {
    if ($p['status'] === 'pending' || ($p['status'] === 'removed' && empty($p['approved_at']))) {
        return 'thumb.php?uuid=' . urlencode($p['uuid']) . '&full=1';
    }
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
    .card-name { display: block; color: #f5a623; font-weight: 700; margin-bottom: 2px; }

    /* Download buttons */
    .btn-dl-photo { flex: 0 0 auto; padding: 8px 10px; background: #0f2d47; color: #7fb3e8; border: none; border-radius: 8px; font-size: 0.85rem; cursor: pointer; text-decoration: none; display: flex; align-items: center; line-height: 1; }
    .btn-dl-photo:hover { background: #163d5e; color: #a8cfee; }
    .approved-heading-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
    .approved-heading-bar .section-heading { margin-bottom: 0; }
    .btn-dl-gallery { padding: 7px 14px; background: #0f2d47; color: #7fb3e8; border: none; border-radius: 8px; font-weight: 700; font-size: 0.82rem; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-dl-gallery:hover { background: #163d5e; color: #a8cfee; }
    .btn-dl-gallery[aria-disabled="true"] { opacity: 0.35; pointer-events: none; }

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

    /* Clickable card images */
    .photo-card img { cursor: zoom-in; }

    /* ── Lightbox ── */
    .lb { position: fixed; inset: 0; z-index: 200; background: rgba(0,0,0,0.93); display: flex; align-items: center; justify-content: center; padding: 16px; }
    .lb[hidden] { display: none; }
    .lb-figure { display: flex; flex-direction: column; align-items: center; max-width: min(92vw, 960px); gap: 14px; }
    .lb-img { max-width: 100%; max-height: 72vh; object-fit: contain; border-radius: 10px; display: block; }
    .lb-meta { background: rgba(255,255,255,0.07); border-radius: 10px; padding: 12px 18px; width: 100%; display: flex; flex-wrap: wrap; gap: 4px 28px; }
    .lb-meta-row { display: flex; gap: 8px; font-size: 0.85rem; color: #c9b8ff; align-items: center; }
    .lb-label { color: #f5a623; font-weight: 700; min-width: 70px; }
    .lb-close { position: fixed; top: 14px; right: 18px; background: rgba(255,255,255,0.12); border: none; color: #fff; font-size: 1.6rem; line-height: 1; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .lb-close:hover { background: rgba(255,255,255,0.25); }
    .lb-nav { position: fixed; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); border: none; color: #fff; font-size: 2.4rem; line-height: 1; width: 50px; height: 70px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s; }
    .lb-nav:hover:not(:disabled) { background: rgba(255,255,255,0.22); }
    .lb-nav:disabled { opacity: 0.18; cursor: default; }
    .lb-prev { left: 10px; }
    .lb-next { right: 10px; }
    .lb-counter { font-size: 0.78rem; color: #6b5ca5; position: fixed; bottom: 14px; left: 50%; transform: translateX(-50%); }

    /* New card pop-in */
    @keyframes card-pop { from { opacity: 0; transform: scale(0.85); } to { opacity: 1; transform: scale(1); } }
    .photo-card.card-new { animation: card-pop 0.35s ease; }

    /* Poll status dot */
    .poll-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: #27ae60; display: inline-block; margin-left: 6px;
      transition: background 0.3s;
    }
    .poll-dot.error { background: #e74c3c; }
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
    <div class="stat-item" title="Live — updates every 10 s">
      <span class="poll-dot" id="poll-dot"></span>
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

  <div class="photo-grid" id="pending-grid" role="list">
    <?php if (empty($pending)): ?>
      <p class="empty-msg">No photos waiting for review.</p>
    <?php else: ?>
      <?php foreach ($pending as $p): ?>
      <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
           data-full-url="<?= htmlspecialchars(full_url($p)) ?>"
           data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?>"
           data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
           data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
           data-section-label="Awaiting Approval">
        <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
             alt="Pending photo"
             loading="lazy"
             onerror="this.style.display='none'">
        <div class="card-meta">
          <?php if (!empty($p['uploaded_by'])): ?>
            <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
          <?php endif; ?>
          <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?></time>
          IP: <?= htmlspecialchars($p['ip_display']) ?>
        </div>
        <div class="card-actions">
          <button class="btn-approve" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="approve" data-section="pending" aria-label="Approve">✅</button>
          <button class="btn-remove"  data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="remove"  data-section="pending" aria-label="Move to wastebasket">🗑️</button>
          <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download">⬇</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <hr class="section-divider">

  <!-- ── Approved section ── -->
  <div class="approved-heading-bar">
    <div class="section-heading">
      ✅ In the Gallery
      <span style="color:#4a3580;font-weight:400;text-transform:none;letter-spacing:0;font-size:0.85rem;">(<?= $counts['approved'] ?>)</span>
    </div>
    <a href="download_gallery.php"
       class="btn-dl-gallery"
       <?= empty($approved) ? 'aria-disabled="true"' : '' ?>
       aria-label="Download all gallery photos as ZIP">
      ⬇ Download Gallery
    </a>
  </div>

  <div class="photo-grid" id="approved-grid" role="list">
    <?php if (empty($approved)): ?>
      <p class="empty-msg">No approved photos yet.</p>
    <?php else: ?>
      <?php foreach ($approved as $p): ?>
      <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
           data-full-url="<?= htmlspecialchars(full_url($p)) ?>"
           data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['approved_at'] ?? $p['upload_timestamp']))) ?>"
           data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
           data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
           data-section-label="In the Gallery">
        <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
             alt="Approved photo"
             loading="lazy"
             onerror="this.style.display='none'">
        <div class="card-meta">
          <?php if (!empty($p['uploaded_by'])): ?>
            <span class="card-name">👤 <?= htmlspecialchars($p['uploaded_by']) ?></span>
          <?php endif; ?>
          <time><?= htmlspecialchars(date('d M Y H:i', strtotime($p['approved_at'] ?? $p['upload_timestamp']))) ?></time>
          IP: <?= htmlspecialchars($p['ip_display']) ?>
        </div>
        <div class="card-actions">
          <button class="btn-remove" data-uuid="<?= htmlspecialchars($p['uuid']) ?>" data-action="remove" data-section="approved" aria-label="Move to wastebasket">🗑️ Remove</button>
          <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download">⬇</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <hr class="section-divider">

  <!-- ── Wastebasket section ── -->
  <div class="wastebasket-bar">
    <div class="section-heading" style="margin-bottom:0;">
      🗑️ Wastebasket
      <span class="count-pill" id="waste-pill"<?= $counts['removed'] === 0 ? ' hidden' : '' ?>><?= $counts['removed'] ?></span>
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
    <div class="photo-card" id="card-<?= htmlspecialchars($p['uuid']) ?>" role="listitem"
         data-full-url="<?= htmlspecialchars(full_url($p)) ?>"
         data-timestamp="<?= htmlspecialchars(date('d M Y H:i', strtotime($p['upload_timestamp']))) ?>"
         data-ip="<?= htmlspecialchars($p['ip_display']) ?>"
         data-name="<?= htmlspecialchars($p['uploaded_by'] ?? '') ?>"
         data-section-label="Wastebasket">
      <img src="<?= htmlspecialchars(thumb_url($p)) ?>"
           alt="Wastebasket photo"
           loading="lazy"
           onerror="this.style.display='none'">
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
        <a class="btn-dl-photo" href="<?= htmlspecialchars(full_url($p)) ?>" download="<?= htmlspecialchars(dl_filename($p)) ?>" aria-label="Download">⬇</a>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

</div><!-- /admin-body -->

<!-- ── Lightbox ── -->
<div id="lb" class="lb" hidden role="dialog" aria-modal="true" aria-label="Photo preview">
  <button class="lb-close" id="lb-close" aria-label="Close lightbox">×</button>
  <button class="lb-nav lb-prev" id="lb-prev" aria-label="Previous photo">&#8249;</button>
  <button class="lb-nav lb-next" id="lb-next" aria-label="Next photo">&#8250;</button>
  <figure class="lb-figure">
    <img id="lb-img" src="" alt="Full size photo" class="lb-img">
    <figcaption class="lb-meta">
      <div class="lb-meta-row" id="lb-name-row"><span class="lb-label">Name</span><span id="lb-name"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Uploaded</span><span id="lb-time"></span></div>
      <div class="lb-meta-row"><span class="lb-label">IP</span><span id="lb-ip"></span></div>
      <div class="lb-meta-row"><span class="lb-label">Section</span><span id="lb-section"></span></div>
    </figcaption>
  </figure>
  <div class="lb-counter" id="lb-counter"></div>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  'use strict';
  const CSRF = <?= json_encode($csrf) ?>;

  // ── Helpers ─────────────────────────────────────────────────
  function adjStat(id, delta) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
  }

  function setStat(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
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

  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function outputExt(ext) {
    const l = (ext || '').toLowerCase();
    return (l === 'heic' || l === 'heif') ? 'jpg' : l;
  }

  function thumbUrl(p) {
    if (p.status === 'pending' || (p.status === 'removed' && !p.approved_at)) {
      return 'thumb.php?uuid=' + encodeURIComponent(p.uuid);
    }
    return '../gallery/thumbs/' + p.uuid + '.' + outputExt(p.original_extension);
  }

  function fullUrl(p) {
    if (p.status === 'pending' || (p.status === 'removed' && !p.approved_at)) {
      return 'thumb.php?uuid=' + encodeURIComponent(p.uuid) + '&full=1';
    }
    return '../gallery/' + p.uuid + '.' + outputExt(p.original_extension);
  }

  function dlFilename(p) {
    const raw  = (p.uploaded_by || '').replace(/[^a-zA-Z0-9_-]/g, '_').substring(0, 30);
    const name = raw || 'photo';
    const ts   = (p.upload_timestamp || '').replace(/[^0-9]/g, '').substring(0, 14);
    return name + '_' + ts + '.' + outputExt(p.original_extension);
  }

  function fmtDate(ts) {
    if (!ts) return '';
    const d = new Date(ts.replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
      + ' ' + d.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit'});
  }

  const sectionLabels = { pending: 'Awaiting Approval', approved: 'In the Gallery', removed: 'Wastebasket' };

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

    let actions = '';
    const dlLink = `<a class="btn-dl-photo" href="${escHtml(fullUrl(p))}" download="${escHtml(dlFilename(p))}" aria-label="Download">⬇</a>`;
    if (section === 'pending') {
      actions = `<button class="btn-approve" data-uuid="${escHtml(p.uuid)}" data-action="approve" data-section="pending" aria-label="Approve">✅</button>`
              + `<button class="btn-remove"  data-uuid="${escHtml(p.uuid)}" data-action="remove"  data-section="pending" aria-label="Move to wastebasket">🗑️</button>`
              + dlLink;
    } else if (section === 'approved') {
      actions = `<button class="btn-remove" data-uuid="${escHtml(p.uuid)}" data-action="remove" data-section="approved" aria-label="Move to wastebasket">🗑️ Remove</button>`
              + dlLink;
    } else {
      actions = `<button class="btn-restore" data-uuid="${escHtml(p.uuid)}" data-action="restore" data-section="removed" aria-label="Restore to gallery">↩️ Restore</button>`
              + `<button class="btn-reject"  data-uuid="${escHtml(p.uuid)}" data-action="reject"  data-section="removed" aria-label="Delete permanently">✕</button>`
              + dlLink;
    }

    const nameLine = p.uploaded_by
      ? `<span class="card-name">👤 ${escHtml(p.uploaded_by)}</span>` : '';
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

    // Remove cards no longer in this section (acted on elsewhere)
    grid.querySelectorAll('.photo-card').forEach(card => {
      const uuid = card.id.replace('card-', '');
      existing.add(uuid);
      if (!incoming.has(uuid) && !card.classList.contains('removing')) {
        card.classList.add('removing');
        setTimeout(() => card.remove(), 320);
      }
    });

    // Add new cards (prepend so newest stays at top for pending)
    const toAdd = photos.filter(p => !existing.has(p.uuid));
    toAdd.forEach(p => {
      const card = buildCard(p, section);
      const firstCard = grid.querySelector('.photo-card');
      if (firstCard) grid.insertBefore(card, firstCard);
      else grid.appendChild(card);
    });

    // Toggle empty message
    const visibleCards = grid.querySelectorAll('.photo-card:not(.removing)').length;
    let emptyEl = grid.querySelector('.empty-msg');
    if (visibleCards === 0 && toAdd.length === 0) {
      if (!emptyEl) {
        emptyEl = document.createElement('p');
        emptyEl.className = 'empty-msg';
        emptyEl.textContent = emptyText;
        grid.appendChild(emptyEl);
      }
    } else if (emptyEl) {
      emptyEl.remove();
    }
  }

  // ── Polling ──────────────────────────────────────────────────
  const dot = document.getElementById('poll-dot');

  function poll() {
    fetch('poll.php', { cache: 'no-store' })
      .then(r => {
        if (r.status === 401) { location.reload(); return null; }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(data => {
        if (!data || !data.ok) return;
        if (dot) { dot.classList.remove('error'); }

        setStat('stat-pending',  data.counts.pending);
        setStat('stat-approved', data.counts.approved);
        syncRemovedUI(data.counts.removed);

        // Highlight pending stat when > 0
        const pendingStat = document.querySelector('.stat-item.has-pending, .stat-item:first-child');
        if (pendingStat) pendingStat.classList.toggle('has-pending', data.counts.pending > 0);

        reconcileGrid('pending-grid',    data.pending,  'pending',  'No photos waiting for review.');
        reconcileGrid('approved-grid',   data.approved, 'approved', 'No approved photos yet.');
        reconcileGrid('wastebasket-grid',data.removed,  'removed',  'Wastebasket is empty.');

        // Sync purge button state
        const purgeBtn = document.getElementById('btn-purge-all');
        if (purgeBtn) purgeBtn.disabled = data.counts.removed === 0;
      })
      .catch(() => { if (dot) dot.classList.add('error'); });
  }

  setInterval(poll, 10000);

  // ── Action handler ───────────────────────────────────────────
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
          syncRemovedUI(0);
        } else {
          alert('Error: ' + (data.error || 'Unknown error'));
          purgeBtn.disabled = data.counts && data.counts.removed === 0;
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

    if (action === 'reject' && section === 'removed') {
      if (!confirm('Permanently delete this photo? This cannot be undone.')) return;
    }

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
            adjRemoved(-1);
          } else if (action === 'remove') {
            adjStat(section === 'pending' ? 'stat-pending' : 'stat-approved', -1);
            adjRemoved(1);
            if (purgeBtn) purgeBtn.disabled = false;
          } else if (action === 'restore') {
            adjRemoved(-1);
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

  // ── Lightbox ─────────────────────────────────────────────────
  const lb        = document.getElementById('lb');
  const lbImg     = document.getElementById('lb-img');
  const lbTime    = document.getElementById('lb-time');
  const lbIp      = document.getElementById('lb-ip');
  const lbSection = document.getElementById('lb-section');
  const lbCloseBtn= document.getElementById('lb-close');
  const lbPrev    = document.getElementById('lb-prev');
  const lbNext    = document.getElementById('lb-next');
  const lbCounter = document.getElementById('lb-counter');
  const lbName    = document.getElementById('lb-name');
  const lbNameRow = document.getElementById('lb-name-row');

  let lbCards = [];
  let lbIdx   = 0;

  function lbGetCards() {
    return [...document.querySelectorAll('.photo-card:not(.removing)')];
  }

  function lbRefresh() {
    const card = lbCards[lbIdx];
    if (!card) return;
    lbImg.src              = card.dataset.fullUrl || '';
    lbTime.textContent     = card.dataset.timestamp || '';
    lbIp.textContent       = card.dataset.ip || '';
    lbSection.textContent  = card.dataset.sectionLabel || '';
    const name = card.dataset.name || '';
    if (lbName)    lbName.textContent = name;
    if (lbNameRow) lbNameRow.hidden   = !name;
    lbPrev.disabled        = lbIdx === 0;
    lbNext.disabled        = lbIdx === lbCards.length - 1;
    lbCounter.textContent  = (lbIdx + 1) + ' / ' + lbCards.length;
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
})();
</script>

<?php endif; ?>
</body>
</html>
