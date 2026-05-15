<?php
declare(strict_types=1);

// ============================================================
// party/slideshow.php — Public photo slideshow.
// Standalone, unauthenticated. Access via ?id=party-slug
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/image.php';
require_once __DIR__ . '/includes/cloudinary.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['id'] ?? '')));
if ($slug === '') { http_response_code(404); exit; }

$party = mpd_get_party_by_slug($slug);
if ($party === false) { http_response_code(404); exit; }

$party_id = (int)$party['id'];

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; "
     . "img-src 'self' data: blob: https://res.cloudinary.com; "
     . "script-src 'nonce-$nonce'; "
     . "style-src 'nonce-$nonce' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; "
     . "object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

// Approved photos — reversed so default order is oldest-first
$photos_raw  = array_reverse(db_get_photos('approved', $party_id));
$photos_data = [];
foreach ($photos_raw as $p) {
    $ext = output_extension($p['original_extension']);
    $url = !empty($p['cloudinary_public_id']) && cloudinary_globally_configured()
        ? cloudinary_slideshow_url($p['cloudinary_public_id'])
        : 'image.php?party=' . urlencode($slug)
          . '&dir=gallery&uuid=' . urlencode($p['uuid'])
          . '&ext=' . urlencode($ext);
    $photos_data[] = [
        'url'         => $url,
        'uploaded_by' => (string)($p['uploaded_by'] ?? ''),
        'timestamp'   => (string)$p['upload_timestamp'],
    ];
}

$party_name_h = htmlspecialchars($party['party_name']);
$slug_js      = json_encode($slug);
$photos_js    = json_encode(array_values($photos_data));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $party_name_h ?> — Slideshow</title>
  <?php if (cloudinary_globally_configured()): ?>
  <link rel="preconnect" href="https://res.cloudinary.com" crossorigin>
  <link rel="dns-prefetch" href="https://res.cloudinary.com">
  <?php endif; ?>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <style nonce="<?= $nonce ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; overflow: hidden; background: #0a0612; font-family: 'Nunito', sans-serif; color: #f0ebff; user-select: none; }

    /* ── Slides ── */
    #stage { position: fixed; inset: 0; }
    .slide-img {
      position: absolute; inset: 0; width: 100%; height: 100%;
      object-fit: contain; opacity: 0;
      transition: opacity 0.9s ease;
    }
    .slide-img.visible { opacity: 1; }

    /* ── Progress bar ── */
    #prog {
      position: fixed; bottom: 0; left: 0; height: 3px;
      background: rgba(245,166,35,0.65); width: 0%; z-index: 10;
      pointer-events: none;
    }

    /* ── Photo info overlay ── */
    #info-overlay {
      position: fixed; bottom: 0; left: 0; right: 0; z-index: 20;
      padding: 48px 24px 28px;
      background: linear-gradient(to top, rgba(10,6,18,0.88) 0%, transparent 100%);
      pointer-events: none;
      transition: opacity 0.4s;
    }
    #info-overlay.hidden { opacity: 0; }
    #info-name { display: block; font-size: 1.05rem; font-weight: 700; color: #f5a623; line-height: 1.3; }
    #info-time { display: block; font-size: 0.82rem; color: #c9b8ff; margin-top: 2px; }

    /* ── Slide counter ── */
    #counter {
      position: fixed; top: 14px; right: 16px; z-index: 30;
      font-size: 0.78rem; color: rgba(156,127,255,0.6);
      pointer-events: none;
    }

    /* ── Prev / Next arrows (appear on hover) ── */
    .nav-arrow {
      position: fixed; top: 50%; transform: translateY(-50%); z-index: 30;
      width: 44px; height: 88px; background: rgba(22,15,53,0.45);
      border: none; border-radius: 8px; color: #c9b8ff;
      font-size: 2.2rem; line-height: 1; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      opacity: 0; transition: opacity 0.2s, background 0.2s;
    }
    body:hover .nav-arrow { opacity: 0.55; }
    .nav-arrow:hover { opacity: 1 !important; background: rgba(45,27,105,0.85); color: #f5a623; }
    #btn-prev { left: 8px; }
    #btn-next { right: 8px; }

    /* ── Bottom-left control strip ── */
    #controls {
      position: fixed; bottom: 14px; left: 14px; z-index: 40;
      display: flex; gap: 8px; align-items: center;
    }
    .ctrl-btn {
      width: 36px; height: 36px; border-radius: 50%;
      background: rgba(22,15,53,0.8); border: 1px solid rgba(75,53,160,0.55);
      color: #9c7fff; font-size: 1rem; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.2s, color 0.2s, border-color 0.2s;
    }
    .ctrl-btn:hover       { background: #2d1b69; color: #f5a623; }
    .ctrl-btn.lit         { background: #2d1b69; color: #f5a623; border-color: #f5a623; }

    /* ── Settings panel ── */
    #settings-panel {
      position: fixed; bottom: 62px; left: 14px; z-index: 50;
      width: 282px; background: #1e1248;
      border: 1px solid #4b35a0; border-radius: 14px;
      padding: 20px 22px; box-shadow: 0 10px 40px rgba(0,0,0,0.7);
      display: none;
    }
    #settings-panel.open { display: block; }
    #settings-panel h3 {
      font-size: 0.88rem; font-weight: 900; color: #c9b8ff;
      text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 18px;
    }
    .sp-field { margin-bottom: 15px; }
    .sp-field label {
      display: block; font-size: 0.78rem; font-weight: 700;
      color: #c9b8ff; margin-bottom: 5px;
    }
    .sp-field select {
      width: 100%; padding: 8px 10px; border-radius: 8px;
      border: 2px solid #4b35a0; background: #160f35; color: #f0ebff;
      font-size: 0.85rem; font-family: inherit; cursor: pointer;
    }
    .sp-field select:focus { outline: none; border-color: #f5a623; }
    .sp-toggle-row { display: flex; align-items: center; justify-content: space-between; }
    .sp-toggle-row label:first-child { margin-bottom: 0; }
    /* Toggle switch */
    .tog { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
    .tog input { opacity: 0; width: 0; height: 0; position: absolute; }
    .tog-track {
      position: absolute; inset: 0; border-radius: 24px; cursor: pointer;
      background: #2d1b69; border: 2px solid #4b35a0;
      transition: background 0.2s, border-color 0.2s;
    }
    .tog-track::before {
      content: ''; position: absolute;
      width: 14px; height: 14px; border-radius: 50%;
      top: 50%; left: 3px; transform: translateY(-50%);
      background: #6b5ca5; transition: left 0.2s, background 0.2s;
    }
    .tog input:checked ~ .tog-track { background: #27ae60; border-color: #27ae60; }
    .tog input:checked ~ .tog-track::before { left: 21px; background: #fff; }

    /* ── Empty state ── */
    #empty {
      position: fixed; inset: 0; display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 14px; text-align: center; padding: 24px;
    }
    #empty h2 { font-size: 1.3rem; font-weight: 900; color: #4a3580; }
    #empty p  { font-size: 0.9rem; color: #4a3580; }
    #empty a  { color: #9c7fff; }
  </style>
</head>
<body>

<?php if (empty($photos_data)): ?>

<div id="empty">
  <h2>No photos to show yet</h2>
  <p>Approved photos for <strong><?= $party_name_h ?></strong> will appear here once uploaded and approved.</p>
  <p style="margin-top:8px;"><a href="<?= BASE_URL ?>/party?id=<?= urlencode($slug) ?>">← Upload page</a></p>
</div>

<?php else: ?>

<div id="stage">
  <img id="slide-a" class="slide-img" alt="">
  <img id="slide-b" class="slide-img" alt="">
</div>

<div id="prog"></div>

<div id="info-overlay" class="hidden">
  <span id="info-name"></span>
  <span id="info-time"></span>
</div>

<span id="counter"></span>

<button class="nav-arrow" id="btn-prev" aria-label="Previous">&#8249;</button>
<button class="nav-arrow" id="btn-next" aria-label="Next">&#8250;</button>

<div id="controls">
  <button class="ctrl-btn" id="btn-pp"   aria-label="Play/Pause" title="Play / Pause (P)">⏸</button>
  <button class="ctrl-btn" id="btn-sett" aria-label="Settings"   title="Settings">⚙</button>
</div>

<div id="settings-panel" role="dialog" aria-label="Slideshow settings">
  <h3>Slideshow Settings</h3>

  <div class="sp-field">
    <label for="sel-order">Display order</label>
    <select id="sel-order">
      <option value="asc">Oldest upload first</option>
      <option value="desc">Newest upload first</option>
      <option value="random">Random</option>
    </select>
  </div>

  <div class="sp-field">
    <label for="sel-delay">Seconds per slide</label>
    <select id="sel-delay">
      <option value="3">3 seconds</option>
      <option value="5">5 seconds</option>
      <option value="10">10 seconds</option>
      <option value="20">20 seconds</option>
      <option value="30">30 seconds</option>
      <option value="60">60 seconds</option>
    </select>
  </div>

  <div class="sp-field">
    <div class="sp-toggle-row">
      <label for="tog-overlay">Show name &amp; upload time</label>
      <label class="tog">
        <input type="checkbox" id="tog-overlay">
        <span class="tog-track"></span>
      </label>
    </div>
  </div>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  'use strict';

  var PHOTOS    = <?= $photos_js ?>;
  var STORE_KEY = 'mpd_ss_' + <?= $slug_js ?>;

  // ── Settings ────────────────────────────────────────────────
  var cfg = { order: 'asc', delay: 5, overlay: false };
  try { Object.assign(cfg, JSON.parse(localStorage.getItem(STORE_KEY) || '{}')); } catch (e) {}

  var selOrder   = document.getElementById('sel-order');
  var selDelay   = document.getElementById('sel-delay');
  var togOverlay = document.getElementById('tog-overlay');

  selOrder.value     = cfg.order;
  selDelay.value     = String(cfg.delay);
  togOverlay.checked = !!cfg.overlay;

  function saveCfg() {
    cfg.order   = selOrder.value;
    cfg.delay   = parseInt(selDelay.value, 10) || 5;
    cfg.overlay = togOverlay.checked;
    try { localStorage.setItem(STORE_KEY, JSON.stringify(cfg)); } catch (e) {}
  }

  // ── State ────────────────────────────────────────────────────
  var queue   = [];
  var idx     = 0;
  var active  = 'a';
  var paused  = false;
  var timer   = null;

  var imgA     = document.getElementById('slide-a');
  var imgB     = document.getElementById('slide-b');
  var prog     = document.getElementById('prog');
  var infoBox  = document.getElementById('info-overlay');
  var infoName = document.getElementById('info-name');
  var infoTime = document.getElementById('info-time');
  var counter  = document.getElementById('counter');
  var panel    = document.getElementById('settings-panel');
  var btnPP    = document.getElementById('btn-pp');
  var btnSett  = document.getElementById('btn-sett');

  function img(which) { return which === 'a' ? imgA : imgB; }

  // ── Queue ─────────────────────────────────────────────────
  function buildQueue() {
    queue = PHOTOS.slice();
    if (cfg.order === 'random') {
      for (var i = queue.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = queue[i]; queue[i] = queue[j]; queue[j] = t;
      }
    } else if (cfg.order === 'desc') {
      queue.reverse();
    }
    // 'asc' is already oldest-first (PHP reversed the DESC db result)
  }

  // ── Progress bar ────────────────────────────────────────────
  function startProg() {
    prog.style.transition = 'none';
    prog.style.width = '0%';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        prog.style.transition = 'width ' + cfg.delay + 's linear';
        prog.style.width = '100%';
      });
    });
  }

  function stopProg() {
    prog.style.transition = 'none';
    prog.style.width = '0%';
  }

  // ── Overlay ──────────────────────────────────────────────────
  function fmtDate(ts) {
    if (!ts) return '';
    try {
      var d = new Date(ts.replace(' ', 'T'));
      return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
           + ' · ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    } catch (e) { return ts; }
  }

  function showOverlay(photo) {
    if (!cfg.overlay) { infoBox.classList.add('hidden'); return; }
    var name = photo.uploaded_by || '';
    var time = fmtDate(photo.timestamp);
    infoName.textContent = name;
    infoTime.textContent = time;
    infoBox.classList.toggle('hidden', !name && !time);
  }

  // ── Slide transition ────────────────────────────────────────
  function goTo(n, instant) {
    if (!queue.length) return;
    idx = ((n % queue.length) + queue.length) % queue.length;
    var photo   = queue[idx];
    var next    = active === 'a' ? 'b' : 'a';
    var imgNext = img(next);
    var imgCurr = img(active);

    counter.textContent = (idx + 1) + ' / ' + queue.length;

    function swap() {
      imgNext.onload = imgNext.onerror = null;
      if (instant) {
        imgNext.style.transition = imgCurr.style.transition = 'none';
        imgNext.classList.add('visible');
        imgCurr.classList.remove('visible');
        requestAnimationFrame(function () {
          imgNext.style.transition = imgCurr.style.transition = '';
        });
      } else {
        imgNext.classList.add('visible');
        imgCurr.classList.remove('visible');
      }
      active = next;
      showOverlay(photo);
      if (!paused) startProg();
    }

    imgNext.onload = imgNext.onerror = swap;
    imgNext.src = photo.url;
  }

  function advance() { goTo(idx + 1, false); }

  // ── Timer ────────────────────────────────────────────────────
  function startTimer() {
    clearInterval(timer);
    if (!paused) timer = setInterval(advance, cfg.delay * 1000);
  }

  function stopTimer() { clearInterval(timer); timer = null; }

  // ── Play / Pause ─────────────────────────────────────────────
  function play() {
    paused = false;
    btnPP.textContent = '⏸';
    startTimer();
    startProg();
  }

  function pause() {
    paused = true;
    btnPP.textContent = '▶';
    stopTimer();
    stopProg();
  }

  // ── Controls ─────────────────────────────────────────────────
  document.getElementById('btn-prev').addEventListener('click', function (e) {
    e.stopPropagation();
    stopTimer();
    goTo(idx - 1, false);
    if (!paused) startTimer();
  });

  document.getElementById('btn-next').addEventListener('click', function (e) {
    e.stopPropagation();
    stopTimer();
    advance();
    if (!paused) startTimer();
  });

  btnPP.addEventListener('click', function (e) {
    e.stopPropagation();
    paused ? play() : pause();
  });

  // ── Settings panel ───────────────────────────────────────────
  function openPanel()  {
    panel.classList.add('open');
    btnSett.classList.add('lit');
    pause();
  }
  function closePanel() {
    panel.classList.remove('open');
    btnSett.classList.remove('lit');
    play();
  }

  btnSett.addEventListener('click', function (e) {
    e.stopPropagation();
    panel.classList.contains('open') ? closePanel() : openPanel();
  });

  selOrder.addEventListener('change', function () {
    saveCfg();
    stopTimer();
    buildQueue();
    idx = -1;
    goTo(0, false);
    if (!paused) startTimer();
  });

  selDelay.addEventListener('change', function () {
    saveCfg();
    stopTimer();
    if (!paused) { startTimer(); startProg(); }
  });

  togOverlay.addEventListener('change', function () {
    saveCfg();
    showOverlay(queue[idx] || {});
  });

  // ── Keyboard ─────────────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (panel.classList.contains('open')) { closePanel(); return; }
    }
    if (e.key === 'ArrowRight' || e.key === ' ') {
      e.preventDefault();
      stopTimer(); advance(); if (!paused) startTimer();
    }
    if (e.key === 'ArrowLeft') {
      stopTimer(); goTo(idx - 1, false); if (!paused) startTimer();
    }
    if (e.key === 'p' || e.key === 'P') {
      paused ? play() : pause();
    }
  });

  // ── Init ─────────────────────────────────────────────────────
  buildQueue();
  goTo(0, true);
  startTimer();

}());
</script>

<?php endif; ?>
</body>
</html>
