<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ── Session & CSRF ──────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Security headers ────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; "
     . "img-src 'self' data: blob:; "
     . "script-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; "
     . "connect-src 'self'; "
     . "object-src 'none'; "
     . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#2d1b69">
  <title><?= htmlspecialchars(PARTY_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;900&display=swap" nonce="<?= $nonce ?>">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <span class="version-badge" aria-hidden="true">v1.3</span>

  <!-- ── Header ─────────────────────────────────────── -->
  <header class="site-header">
    <div class="header-inner">
      <span class="header-emoji" aria-hidden="true">🎉</span>
      <h1><?= htmlspecialchars(PARTY_NAME) ?></h1>
      <span class="header-emoji" aria-hidden="true">📸</span>
    </div>
  </header>

  <!-- ── Camera section ─────────────────────────────── -->
  <main id="main">
    <section class="camera-section" id="camera-section">

      <!-- Hidden file inputs — never visible, triggered by labels -->
      <!--
        capture="environment" = ask the OS for the rear camera.
        On Android/Chrome this opens the camera app directly.
        On iOS/Safari it shows a sheet with "Take Photo" as the first option.
      -->
      <input type="file"
             id="camera-input"
             accept="image/*"
             capture="environment"
             aria-label="Take a photo with your camera"
             hidden>

      <!-- Secondary input WITHOUT capture — opens photo library -->
      <input type="file"
             id="library-input"
             accept="image/*"
             aria-label="Choose a photo from your library"
             hidden>

      <!-- ── Camera button (default state) ── -->
      <div id="upload-ui">
        <label for="camera-input" class="btn-camera" id="camera-label" role="button" tabindex="0">
          <span class="btn-camera-emoji" aria-hidden="true">📸</span>
          <span class="btn-camera-text">Take a Photo!</span>
        </label>

        <p class="library-hint">
          or <label for="library-input" class="link-label" role="button" tabindex="0">choose an existing photo</label>
        </p>

        <p class="name-byline" id="name-byline" hidden>
          Uploading as <strong id="name-byline-text"></strong> &middot;
          <button type="button" id="btn-change-name" class="name-change-btn">change</button>
        </p>

      </div>

      <!-- ── Preview state (hidden until photo selected) ── -->
      <div id="preview-ui" hidden>
        <div class="preview-wrap">
          <img id="preview-img" src="" alt="Your photo preview" class="preview-img">
        </div>

        <div class="preview-actions">
          <button id="btn-upload" class="btn btn-confirm" type="button">
            ✅ Upload this photo
          </button>
          <button id="btn-retake" class="btn btn-retake" type="button">
            🔄 Retake
          </button>
        </div>

        <!-- Progress bar (hidden until upload starts) -->
        <div id="progress-wrap" hidden>
          <div class="progress-bar" role="progressbar" aria-label="Uploading…">
            <div class="progress-fill" id="progress-fill"></div>
          </div>
          <p class="progress-label">Uploading… please wait ✨</p>
        </div>
      </div>

      <!-- ── Success overlay ── -->
      <div id="success-ui" hidden class="result-ui result-success">
        <div class="result-emoji" aria-hidden="true">🎉</div>
        <p class="result-text">Your photo is in!</p>
        <p class="result-sub">It'll appear in the gallery once approved.</p>
        <p class="result-dismiss">Returning to camera in <span id="countdown">4</span>s…</p>
      </div>

      <!-- ── Error message ── -->
      <div id="error-ui" hidden class="result-ui result-error">
        <div class="result-emoji" aria-hidden="true">😬</div>
        <p class="result-text" id="error-msg">Something went wrong.</p>
        <button class="btn btn-confirm" id="btn-retry" type="button">🔄 Try Again</button>
      </div>

    </section>

    <!-- ── Public gallery ─────────────────────────────── -->
    <section class="gallery-section" id="gallery-section" aria-label="Approved photo gallery">
      <h2 class="gallery-heading">
        <span aria-hidden="true">🖼️</span> The Gallery
      </h2>

      <div id="gallery-grid" class="gallery-grid" role="list" aria-live="polite" aria-label="Party photos">
        <p class="gallery-empty" id="gallery-empty">Loading photos…</p>
      </div>
    </section>

    <p class="privacy-notice">
      By uploading, you agree that your photos will be visible in a public gallery and will be deleted after 30 days.
      <button type="button" id="btn-privacy" class="name-change-btn">About &amp; Privacy</button>
    </p>
  </main>

  <!-- ── Lightbox ───────────────────────────────────── -->
  <div id="lightbox" class="lightbox" hidden role="dialog" aria-modal="true" aria-label="Photo lightbox">
    <button class="lightbox-close" id="lightbox-close" aria-label="Close photo">&times;</button>
    <button class="lightbox-prev" id="lightbox-prev" aria-label="Previous photo" disabled>&#8249;</button>
    <button class="lightbox-next" id="lightbox-next" aria-label="Next photo"     disabled>&#8250;</button>
    <img id="lightbox-img" src="" alt="Full size photo" class="lightbox-img">
  </div>

  <!-- About & Privacy modal -->
  <div id="privacy-modal" class="privacy-modal" hidden role="dialog" aria-modal="true" aria-labelledby="privacy-modal-title">
    <div class="privacy-modal-card">
      <button type="button" class="privacy-close" id="btn-privacy-close" aria-label="Close">&times;</button>
      <h2 class="privacy-modal-title" id="privacy-modal-title">About This Gallery</h2>

      <div class="privacy-section">
        <p>Welcome! Our goal is to capture every moment of today&rsquo;s event through your eyes. To ensure everyone has a great experience, please take a moment to read how this service works.</p>
      </div>

      <div class="privacy-section">
        <h3>Public Visibility</h3>
        <p>Please be aware that this is a public gallery. Any images you upload will be visible to other guests and anyone with access to the party link.</p>
        <p><strong>Think before you upload:</strong> Do not post any images you would not want seen by the public or other event attendees.</p>
        <p><strong>Privacy:</strong> By uploading, you acknowledge that your photos are part of a shared experience.</p>
      </div>

      <div class="privacy-section">
        <h3>Content Moderation</h3>
        <p>To keep this environment fun and safe for everyone, we reserve the right to monitor uploads.</p>
        <p><strong>Removal:</strong> Any images deemed offensive, inappropriate, or illegal will be removed immediately without notice.</p>
        <p><strong>Reporting:</strong> If you see a photo that concerns you, please notify the party organizer.</p>
      </div>

      <div class="privacy-section">
        <h3>Data Retention (30-Day Rule)</h3>
        <p>We believe in &ldquo;capturing the moment, not keeping it forever.&rdquo;</p>
        <p>All images are hosted temporarily for 30 days following the event.</p>
        <p>After this period, all files are permanently deleted from the MyPictureDesk system to protect your privacy. Please ensure you save any photos you wish to keep before this window closes.</p>
      </div>

      <div class="privacy-section">
        <h3>Questions?</h3>
        <p>The MyPictureDesk platform provides the technology for this gallery, but the Party Organizer manages the specific event. For questions regarding how the photos will be used, shared, or distributed beyond this 30-day window, please contact the event organizer directly.</p>
      </div>
    </div>
  </div>

  <!-- Name prompt modal — shown on first visit or when "change" is clicked -->
  <div id="name-modal" class="name-modal" hidden role="dialog" aria-modal="true" aria-labelledby="name-modal-title">
    <div class="name-modal-card">
      <p class="name-modal-emoji" aria-hidden="true">👋</p>
      <h2 class="name-modal-title" id="name-modal-title">What's your name?</h2>
      <p class="name-modal-sub">So we know who took which photo!</p>
      <input type="text" id="name-input" class="name-input"
             placeholder="Your name" maxlength="50"
             autocomplete="given-name" autocorrect="off">
      <button type="button" id="btn-name-submit" class="btn btn-confirm">Let's go! 🎉</button>
      <button type="button" id="btn-name-skip" class="name-skip">Stay anonymous</button>
    </div>
  </div>

  <!-- Pass CSRF token and config to JS -->
  <script nonce="<?= $nonce ?>">
    window.PARTY_CONFIG = {
      csrfToken:   <?= json_encode($csrf) ?>,
      uploadUrl:   'upload.php',
      galleryUrl:  'gallery.php?json=1',
      refreshMs:   30000
    };
  </script>
  <script src="assets/app.js"></script>
</body>
</html>
