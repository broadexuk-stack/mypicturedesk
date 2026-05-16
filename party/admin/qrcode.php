<?php
declare(strict_types=1);

// ============================================================
// admin/qrcode.php — Display and download QR code for a party.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

$user_id  = (int)($_SESSION['mpd_user_id'] ?? 0);
$role     = $_SESSION['mpd_role'] ?? '';
$party_id = (int)($_SESSION['mpd_party_id'] ?? 0);

if ($user_id === 0) { header('Location: index.php'); exit; }

// Superadmin can view any party's QR via ?party=slug
if ($role === 'superadmin' && isset($_GET['party'])) {
    $p = mpd_get_party_by_slug($_GET['party']);
    if ($p !== false) $party_id = (int)$p['id'];
}

if ($party_id === 0) { header('Location: index.php'); exit; }

$lifetime_sec = SESSION_LIFETIME_MINUTES * 60;
if (isset($_SESSION['admin_last_active']) && time() - $_SESSION['admin_last_active'] > $lifetime_sec) {
    session_unset(); session_destroy(); header('Location: index.php'); exit;
}
$_SESSION['admin_last_active'] = time();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['admin_csrf'];

$party = mpd_get_party_by_id($party_id);
if ($party === false) { header('Location: index.php'); exit; }

if ($role === 'organizer' && (int)$party['organizer_id'] !== $user_id) {
    header('Location: index.php'); exit;
}

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; "
     . "connect-src 'self' https://cdn.jsdelivr.net; "
     . "object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$guest_url  = BASE_URL . '/party?id=' . urlencode($party['slug']);
$party_name = $party['party_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Code — <?= htmlspecialchars($party_name) ?></title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: #1a1035; color: #f0ebff; min-height: 100vh; }
    .topbar { position: sticky; top: 0; z-index: 50; height: 50px; background: #160f35; border-bottom: 1px solid #2d1b69; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .nav-link { color: #c9b8ff; font-size: 0.82rem; text-decoration: none; }
    .nav-link:hover { color: #f5a623; }
    .signout { color: #c9b8ff; font-size: 0.8rem; text-decoration: none; }
    .signout:hover { color: #f5a623; }
    .page { max-width: 500px; margin: 40px auto; padding: 0 20px; text-align: center; }
    h1 { font-size: 1.4rem; font-weight: 900; margin-bottom: 6px; }
    .sub { color: #9c7fff; font-size: 0.85rem; margin-bottom: 28px; word-break: break-all; }
    .qr-wrap { background: #fff; border-radius: 16px; padding: 24px; display: inline-block; margin-bottom: 24px; }
    #qr-canvas { display: block; }
    .url-box { background: #2d1b69; border-radius: 10px; padding: 12px 16px; font-size: 0.8rem; color: #9c7fff; word-break: break-all; margin-bottom: 20px; text-align: left; }
    .url-box strong { color: #f5a623; }
    .btn-dl { display: inline-block; padding: 12px 30px; background: #f5a623; color: #1a1035; border: none; border-radius: 10px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; text-decoration: none; }
    .btn-dl:hover { background: #e6941a; }
    .copy-btn { margin-left: 10px; padding: 5px 14px; background: #4b35a0; border: none; color: #f0ebff; border-radius: 7px; font-size: 0.78rem; cursor: pointer; font-family: inherit; }
    .copy-btn:hover { background: #5b45b0; }
    .spinner { color: #6b5ca5; font-size: 0.85rem; margin: 20px 0; }
    .btn-row { margin-bottom: 12px; }
    .print-btn-row { display: flex; gap: 10px; justify-content: center; margin-bottom: 16px; }
    .btn-print { padding: 11px 22px; background: #2d1b69; color: #c9b8ff; border: 2px solid #4b35a0; border-radius: 10px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; }
    .btn-print:hover:not(:disabled) { background: #3d2494; color: #f0ebff; }
    .btn-print:disabled { opacity: 0.4; cursor: not-allowed; }
    .qr-hint { font-size: 0.78rem; color: #6b5ca5; }
  </style>
</head>
<body>

<div class="topbar">
  <a class="nav-link" href="index.php">← Back to Moderation</a>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <h1>📱 Party QR Code</h1>
  <p class="sub"><?= htmlspecialchars($party_name) ?></p>

  <div class="url-box">
    <strong>Guest URL:</strong> <?= htmlspecialchars($guest_url) ?>
    <button class="copy-btn" id="btn-copy">Copy</button>
  </div>

  <div class="qr-wrap">
    <p class="spinner" id="qr-spinner">Generating QR code…</p>
    <canvas id="qr-canvas" hidden></canvas>
  </div>

  <div class="btn-row">
    <button class="btn-dl" id="btn-download" disabled>⬇ Download PNG</button>
  </div>

  <div class="print-btn-row">
    <button class="btn-print" id="btn-print-a4" disabled>🖨 Print A4</button>
    <button class="btn-print" id="btn-print-label" disabled>🖨 Print 6×4 Label</button>
  </div>

  <p class="qr-hint">
    Print or display this code at your event so guests can scan to upload photos.
  </p>
</div>

<!-- QR library from jsDelivr CDN -->
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js" nonce="<?= $nonce ?>"></script>
<script nonce="<?= $nonce ?>">
(function () {
  'use strict';

  const GUEST_URL  = <?= json_encode($guest_url) ?>;
  const PARTY_NAME = <?= json_encode($party_name) ?>;
  const SLUG       = <?= json_encode($party['slug']) ?>;

  const spinner      = document.getElementById('qr-spinner');
  const qrCanvas     = document.getElementById('qr-canvas');
  const dlBtn        = document.getElementById('btn-download');
  const copyBtn      = document.getElementById('btn-copy');
  const printA4Btn   = document.getElementById('btn-print-a4');
  const printLblBtn  = document.getElementById('btn-print-label');

  let svgString = '';

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Generate QR into an offscreen canvas, then composite with label ──
  function generate() {
    // Canvas version for on-screen display and PNG download
    QRCode.toCanvas(document.createElement('canvas'), GUEST_URL, {
      width: 320,
      margin: 2,
      color: { dark: '#000000', light: '#ffffff' },
    }, function (err, tempCanvas) {
      if (err) {
        spinner.textContent = 'QR generation failed. Try refreshing.';
        return;
      }

      const PAD   = 16;
      const LABEL = 28;
      const W     = tempCanvas.width + PAD * 2;
      const H     = tempCanvas.height + PAD * 2 + LABEL;

      qrCanvas.width  = W;
      qrCanvas.height = H;
      const ctx = qrCanvas.getContext('2d');

      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, W, H);
      ctx.drawImage(tempCanvas, PAD, PAD);

      ctx.fillStyle = '#2d1b69';
      ctx.font      = 'bold 16px Nunito, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(PARTY_NAME, W / 2, H - 8, W - PAD * 2);

      spinner.remove();
      qrCanvas.hidden = false;
      dlBtn.disabled  = false;
    });

    // SVG version for print (vector, B&W)
    QRCode.toString(GUEST_URL, { type: 'svg', margin: 1, color: { dark: '#000000', light: '#ffffff' } }, function (err, svg) {
      if (!err) {
        svgString = svg;
        printA4Btn.disabled  = false;
        printLblBtn.disabled = false;
      }
    });
  }

  // Check library loaded
  if (typeof QRCode === 'undefined') {
    spinner.textContent = 'QR library failed to load. Check your internet connection.';
  } else {
    generate();
  }

  // ── Print helpers ─────────────────────────────────────────
  function printA4() {
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(`<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>QR Code – ${escHtml(PARTY_NAME)}</title>
<style>
  @page { size: A4 portrait; margin: 15mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; text-align: center; }
  .qr-wrap { display: inline-block; width: 120mm; margin: 0 auto 8mm; }
  .qr-wrap svg { width: 100%; height: auto; display: block; }
  h1 { font-size: 22pt; font-weight: 900; margin-bottom: 4mm; }
  .party-code { font-size: 14pt; font-weight: bold; margin-bottom: 6mm; letter-spacing: 0.05em; }
  .instruction { font-size: 11pt; margin-bottom: 4mm; }
  .guest-url { font-size: 8pt; color: #333; word-break: break-all; margin-top: 8mm; border-top: 0.5pt solid #ccc; padding-top: 3mm; }
</style>
</head><body>
<h1>${escHtml(PARTY_NAME)}</h1>
<div class="qr-wrap">${svgString}</div>
<p class="party-code">Your PartyPix Code is: <strong>${escHtml(SLUG)}</strong></p>
<p class="instruction">Scan the code above to upload your photos to the gallery.</p>
<p class="guest-url">${escHtml(GUEST_URL)}</p>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
  }

  function printLabel() {
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(`<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>QR Label – ${escHtml(PARTY_NAME)}</title>
<style>
  @page { size: 6in 4in; margin: 4mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; width: 6in; height: 4in; overflow: hidden; }
  .layout { display: flex; align-items: center; height: 100%; gap: 6mm; padding: 2mm; }
  .qr-col { flex: 0 0 72mm; }
  .qr-col svg { width: 72mm; height: 72mm; display: block; }
  .text-col { flex: 1; text-align: left; }
  h1 { font-size: 16pt; font-weight: 900; margin-bottom: 3mm; line-height: 1.2; }
  .party-code { font-size: 11pt; font-weight: bold; margin-bottom: 4mm; }
  .instruction { font-size: 9pt; margin-bottom: 4mm; line-height: 1.4; }
  .guest-url { font-size: 7pt; color: #333; word-break: break-all; border-top: 0.5pt solid #ccc; padding-top: 2mm; }
</style>
</head><body>
<div class="layout">
  <div class="qr-col">${svgString}</div>
  <div class="text-col">
    <h1>${escHtml(PARTY_NAME)}</h1>
    <p class="party-code">Your PartyPix Code is:<br><strong>${escHtml(SLUG)}</strong></p>
    <p class="instruction">Scan the QR code to upload your photos to the gallery.</p>
    <p class="guest-url">${escHtml(GUEST_URL)}</p>
  </div>
</div>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
  }

  // ── Download ──────────────────────────────────────────────
  dlBtn.addEventListener('click', function () {
    const link     = document.createElement('a');
    link.download  = 'qrcode-' + SLUG + '.png';
    link.href      = qrCanvas.toDataURL('image/png');
    link.click();
  });

  printA4Btn.addEventListener('click', printA4);
  printLblBtn.addEventListener('click', printLabel);

  // ── Copy URL ──────────────────────────────────────────────
  copyBtn.addEventListener('click', function () {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(GUEST_URL).then(() => {
        copyBtn.textContent = 'Copied!';
        setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1800);
      });
    } else {
      const ta = document.createElement('textarea');
      ta.value = GUEST_URL;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      copyBtn.textContent = 'Copied!';
      setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1800);
    }
  });
})();
</script>
</body>
</html>
