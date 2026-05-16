<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
$user_id = (int)($_SESSION['mpd_user_id'] ?? 0);
$role    = $_SESSION['mpd_role'] ?? '';
if ($user_id === 0 || $role !== 'superadmin') { header('Location: index.php'); exit; }
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; font-src 'none'; object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Technical Overview — MyPictureDesk</title>
<style nonce="<?= $nonce ?>">
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:       #0f0a28;
    --surface:  #1a1035;
    --card:     #211545;
    --border:   #2d1b69;
    --accent:   #4b35a0;
    --gold:     #f5a623;
    --purple:   #9c7fff;
    --text:     #f0ebff;
    --muted:    #9c7fff;
    --dim:      #6b5ca5;
    --code-bg:  #160f35;
    --green:    #34d399;
    --red:      #f87171;
    --nav-w:    270px;
  }
  html { scroll-behavior: smooth; }
  body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.65; font-size: 0.95rem; }

  /* ── Layout ── */
  .layout { display: flex; min-height: 100vh; }
  nav { width: var(--nav-w); flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 24px 0 40px; }
  main { flex: 1; min-width: 0; padding: 48px 56px 80px; max-width: 1000px; }

  /* ── Nav ── */
  .nav-brand { padding: 0 20px 20px; border-bottom: 1px solid var(--border); margin-bottom: 16px; }
  .nav-brand h2 { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--gold); }
  .nav-brand p { font-size: 0.72rem; color: var(--dim); margin-top: 3px; }
  nav a.back { display: block; padding: 6px 20px; font-size: 0.78rem; color: var(--dim); text-decoration: none; margin-bottom: 8px; }
  nav a.back:hover { color: var(--gold); }
  .nav-group { margin-bottom: 4px; }
  .nav-group-title { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--dim); padding: 8px 20px 4px; }
  nav a.nav-item { display: block; padding: 5px 20px 5px 28px; font-size: 0.8rem; color: var(--muted); text-decoration: none; border-left: 2px solid transparent; transition: color 0.15s, border-color 0.15s; }
  nav a.nav-item:hover, nav a.nav-item.active { color: var(--text); border-left-color: var(--gold); background: rgba(245,166,35,0.06); }

  /* ── Headings ── */
  h1 { font-size: 2rem; font-weight: 900; color: var(--text); margin-bottom: 8px; }
  .lead { color: var(--muted); font-size: 1rem; margin-bottom: 48px; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
  h2 { font-size: 1.3rem; font-weight: 800; color: var(--gold); margin: 52px 0 16px; padding-top: 8px; }
  h2:first-of-type { margin-top: 0; }
  h3 { font-size: 1rem; font-weight: 700; color: var(--purple); margin: 28px 0 10px; }
  h4 { font-size: 0.88rem; font-weight: 700; color: var(--text); margin: 18px 0 6px; text-transform: uppercase; letter-spacing: 0.06em; }

  /* ── Prose ── */
  p { margin-bottom: 12px; color: var(--text); }
  ul, ol { padding-left: 20px; margin-bottom: 12px; }
  li { margin-bottom: 4px; }
  strong { color: var(--text); font-weight: 700; }
  a { color: var(--purple); }
  a:hover { color: var(--gold); }
  hr { border: none; border-top: 1px solid var(--border); margin: 40px 0; }

  /* ── Code ── */
  code { font-family: 'Courier New', monospace; font-size: 0.82em; background: var(--code-bg); color: #c9b8ff; padding: 2px 6px; border-radius: 4px; }
  pre { background: var(--code-bg); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; overflow-x: auto; margin: 12px 0 20px; }
  pre code { background: none; padding: 0; font-size: 0.83rem; color: #c9b8ff; line-height: 1.7; }
  .kw  { color: #a78bfa; }
  .str { color: #6ee7b7; }
  .cmt { color: var(--dim); }
  .val { color: var(--gold); }

  /* ── Tables ── */
  .tbl-wrap { overflow-x: auto; margin: 12px 0 24px; }
  table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
  th { background: var(--accent); color: var(--text); font-weight: 700; padding: 8px 12px; text-align: left; }
  td { padding: 7px 12px; border-bottom: 1px solid var(--border); vertical-align: top; color: var(--text); }
  tr:nth-child(even) td { background: rgba(75,53,160,0.12); }
  td code { font-size: 0.8em; }

  /* ── Badges ── */
  .badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; }
  .badge-gold    { background: rgba(245,166,35,0.15); color: var(--gold); border: 1px solid rgba(245,166,35,0.35); }
  .badge-purple  { background: rgba(156,127,255,0.15); color: var(--purple); border: 1px solid rgba(156,127,255,0.3); }
  .badge-green   { background: rgba(52,211,153,0.12); color: var(--green); border: 1px solid rgba(52,211,153,0.3); }
  .badge-red     { background: rgba(248,113,113,0.12); color: var(--red); border: 1px solid rgba(248,113,113,0.3); }

  /* ── Cards ── */
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; margin: 16px 0; }
  .card h4 { margin-top: 0; }
  .flow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
  .flow-step { background: var(--accent); color: var(--text); border-radius: 8px; padding: 6px 14px; font-size: 0.8rem; font-weight: 700; }
  .flow-arrow { color: var(--dim); font-size: 1.1rem; }

  /* ── Callouts ── */
  .callout { border-left: 3px solid var(--gold); background: rgba(245,166,35,0.06); border-radius: 0 8px 8px 0; padding: 12px 16px; margin: 16px 0; font-size: 0.88rem; }
  .callout.info { border-color: var(--purple); background: rgba(156,127,255,0.07); }
  .callout.warn { border-color: var(--red); background: rgba(248,113,113,0.07); }

  /* ── Section anchors ── */
  section { scroll-margin-top: 24px; }

  /* ── Responsive ── */
  @media (max-width: 800px) {
    .layout { flex-direction: column; }
    nav { width: 100%; height: auto; position: static; }
    main { padding: 24px 20px 60px; }
  }
</style>
</head>
<body>
<div class="layout">

<!-- ══════════════════════ SIDEBAR NAV ══════════════════════ -->
<nav>
  <div class="nav-brand">
    <h2>MyPictureDesk</h2>
    <p>Technical Overview v2.0</p>
  </div>
  <a class="back" href="index.php">← Back to Admin</a>

  <div class="nav-group">
    <div class="nav-group-title">Architecture</div>
    <a class="nav-item" href="#overview">System Overview</a>
    <a class="nav-item" href="#stack">Tech Stack</a>
    <a class="nav-item" href="#directory">Directory Structure</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Data</div>
    <a class="nav-item" href="#database">Database Schema</a>
    <a class="nav-item" href="#config">Configuration</a>
    <a class="nav-item" href="#settings">Platform Settings</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Auth &amp; Security</div>
    <a class="nav-item" href="#auth">Authentication &amp; Roles</a>
    <a class="nav-item" href="#security">Security Controls</a>
    <a class="nav-item" href="#impersonate">Impersonation</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Core Flows</div>
    <a class="nav-item" href="#guest-upload">Guest Upload</a>
    <a class="nav-item" href="#moderation">Photo Moderation</a>
    <a class="nav-item" href="#image-proc">Image Processing</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Admin Pages</div>
    <a class="nav-item" href="#parties">Party Management</a>
    <a class="nav-item" href="#users">User Management</a>
    <a class="nav-item" href="#qrcode">QR Code &amp; Print</a>
    <a class="nav-item" href="#superadmin-settings">Global Settings</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Services</div>
    <a class="nav-item" href="#email">Email System</a>
    <a class="nav-item" href="#cloudinary">Cloudinary</a>
    <a class="nav-item" href="#logging">Logging (Axiom)</a>
  </div>
  <div class="nav-group">
    <div class="nav-group-title">Database Layer</div>
    <a class="nav-item" href="#db-functions">db.php Functions</a>
  </div>
</nav>

<!-- ══════════════════════ MAIN CONTENT ══════════════════════ -->
<main>

<h1>Technical Overview</h1>
<p class="lead">MyPictureDesk v2.0 — Multi-tenant party photo sharing platform. This document covers architecture, data model, all code flows, security controls, and service integrations. Superadmin access only.</p>

<!-- ─── OVERVIEW ─── -->
<section id="overview">
<h2>System Overview</h2>
<p>MyPictureDesk is a <strong>multi-tenant, event-scoped photo sharing platform</strong>. Each party (event) gets an isolated gallery with a unique 6-character slug. Guests upload photos via a public URL; an organiser moderates them through an admin panel before they appear in the gallery.</p>

<div class="flow">
  <span class="flow-step">Guest scans QR / visits URL</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Uploads photo</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Quarantine</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Organiser moderates</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Gallery (local or Cloudinary)</span>
</div>

<h3>Roles</h3>
<div class="tbl-wrap"><table>
  <tr><th>Role</th><th>Scope</th><th>Key Capabilities</th></tr>
  <tr><td><span class="badge badge-red">Superadmin</span></td><td>Platform-wide</td><td>All parties, all users, SMTP settings, templates, impersonate organizers</td></tr>
  <tr><td><span class="badge badge-purple">Organizer</span></td><td>Own party/parties</td><td>Moderate photos, view gallery, download ZIP, edit party settings, view QR</td></tr>
  <tr><td><span class="badge badge-green">Guest</span></td><td>One party (via slug)</td><td>Upload photos, view approved gallery, slideshow</td></tr>
</table></div>
</section>

<!-- ─── TECH STACK ─── -->
<section id="stack">
<h2>Tech Stack</h2>
<div class="tbl-wrap"><table>
  <tr><th>Layer</th><th>Technology</th><th>Notes</th></tr>
  <tr><td>Backend</td><td>PHP 8+ (strict types)</td><td><code>declare(strict_types=1)</code> on all files</td></tr>
  <tr><td>Database</td><td>MySQL 5.7+ / MariaDB 10.3+</td><td>PDO with FETCH_ASSOC, prepared statements throughout</td></tr>
  <tr><td>Frontend</td><td>Vanilla JS, CSS Grid/Flexbox</td><td>No frameworks; mobile-first, 48px touch targets</td></tr>
  <tr><td>Image processing</td><td>Imagick (preferred), GD fallback</td><td>Auto-orient, EXIF strip, resize, thumbnail</td></tr>
  <tr><td>Email</td><td>PHPMailer ^6.9 (Composer)</td><td>SMTP with STARTTLS/SSL; <code>mail()</code> fallback</td></tr>
  <tr><td>Object storage</td><td>Cloudinary (optional)</td><td>Per-party toggle; signed API calls</td></tr>
  <tr><td>Logging</td><td>Axiom</td><td>Async, post-response flush via shutdown function</td></tr>
  <tr><td>QR generation</td><td>qrcode.js (CDN)</td><td>Client-side; canvas + SVG outputs</td></tr>
  <tr><td>Dependencies</td><td><code>party/composer.json</code></td><td>Run <code>composer install --no-dev</code> on deploy</td></tr>
</table></div>

<div class="callout info">
  <strong>Deployment note:</strong> After <code>git pull</code>, run <code>composer install --no-dev --optimize-autoloader</code> inside <code>party/</code> if <code>composer.json</code> or <code>composer.lock</code> changed. The <code>vendor/</code> directory is gitignored and must exist on the server for PHPMailer to load.
</div>
</section>

<!-- ─── DIRECTORY ─── -->
<section id="directory">
<h2>Directory Structure</h2>
<pre><code>mypicturedesk.com/
└── party/
    ├── config.php               <span class="cmt"># Constants (DB, paths, limits) — gitignored, set up manually</span>
    ├── schema.sql               <span class="cmt"># Full DDL — run once on new installs</span>
    ├── composer.json            <span class="cmt"># PHPMailer dependency</span>
    ├── composer.lock            <span class="cmt"># Pinned versions — commit this</span>
    ├── vendor/                  <span class="cmt"># Composer packages — gitignored, install on server</span>
    ├── .htaccess
    │
    ├── index.php                <span class="cmt"># Guest landing: party lookup, upload UI, gallery</span>
    ├── upload.php               <span class="cmt"># Upload handler — JSON endpoint</span>
    ├── image.php                <span class="cmt"># Serve local gallery/quarantine images</span>
    ├── slideshow.php            <span class="cmt"># Auto-advancing photo slideshow</span>
    ├── poll.php                 <span class="cmt"># Organizer live-poll endpoint (counts + new photos)</span>
    ├── log_event.php            <span class="cmt"># Client-side event logging endpoint</span>
    │
    ├── includes/
    │   ├── db.php               <span class="cmt"># All database functions (PDO layer)</span>
    │   ├── image.php            <span class="cmt"># Magic-byte validation + Imagick/GD processing</span>
    │   ├── logger.php           <span class="cmt"># Axiom async event logger</span>
    │   └── cloudinary.php       <span class="cmt"># Cloudinary upload/delete/URL helpers</span>
    │
    ├── admin/
    │   ├── index.php            <span class="cmt"># Login + main dashboard (superadmin grid / organizer moderation)</span>
    │   ├── parties.php          <span class="cmt"># Superadmin: create/manage/delete parties</span>
    │   ├── users.php            <span class="cmt"># Superadmin: user list, reset password, delete</span>
    │   ├── moderate.php         <span class="cmt"># AJAX: approve/reject/remove/restore/purge photos</span>
    │   ├── superadmin_settings.php <span class="cmt"># SMTP, retention, email + print templates</span>
    │   ├── organizer_settings.php  <span class="cmt"># Organizer: edit own party settings</span>
    │   ├── qrcode.php           <span class="cmt"># QR code display + A4/label print</span>
    │   ├── setpassword.php      <span class="cmt"># Token-based first-login / password reset</span>
    │   ├── impersonate.php      <span class="cmt"># Superadmin → organizer session swap</span>
    │   ├── switch_party.php     <span class="cmt"># Organizer: switch between own parties</span>
    │   ├── toggle_status.php    <span class="cmt"># AJAX: pause/resume party</span>
    │   ├── download_gallery.php <span class="cmt"># Stream ZIP of approved photos</span>
    │   ├── sa_poll.php          <span class="cmt"># Superadmin live-poll endpoint</span>
    │   ├── thumb.php            <span class="cmt"># Serve quarantine thumbnails (auth-gated)</span>
    │   └── tech-overview.php    <span class="cmt"># This document</span>
    │
    ├── assets/
    │   ├── style.css            <span class="cmt"># Global styles (purple/gold theme, mobile-first)</span>
    │   └── app.js               <span class="cmt"># Guest JS: camera, upload, gallery, lightbox, timer</span>
    │
    └── <span class="cmt"># Upload storage — per-party directories created automatically</span>
        <span class="cmt"># UPLOADS_BASE/{slug}/quarantine/       — pending approval</span>
        <span class="cmt"># UPLOADS_BASE/{slug}/quarantine/thumbs/ — 400px thumbnails</span>
        <span class="cmt"># UPLOADS_BASE/{slug}/gallery/           — approved (or Cloudinary)</span>
        <span class="cmt"># UPLOADS_BASE/{slug}/gallery/thumbs/    — gallery thumbnails</span></code></pre>

<div class="callout warn">
  <strong>Storage location:</strong> <code>UPLOADS_BASE</code> should be outside <code>public_html</code> (e.g., <code>/home/user/uploads/</code>). Files are served via <code>image.php</code> which enforces auth/party scoping — never served directly.
</div>
</section>

<!-- ─── DATABASE ─── -->
<section id="database">
<h2>Database Schema</h2>
<p>Six tables. Run <code>party/schema.sql</code> once on a new install. All foreign keys reference <code>mpd_users.id</code>.</p>

<h3>mpd_users — Platform Accounts</h3>
<div class="tbl-wrap"><table>
  <tr><th>Column</th><th>Type</th><th>Notes</th></tr>
  <tr><td><code>id</code></td><td>INT PK AI</td><td></td></tr>
  <tr><td><code>email</code></td><td>VARCHAR(254) UNIQUE</td><td>Login identifier</td></tr>
  <tr><td><code>password_hash</code></td><td>VARCHAR(255) NULL</td><td>bcrypt cost=12; NULL until set via invitation link</td></tr>
  <tr><td><code>role</code></td><td>ENUM('superadmin','organizer')</td><td></td></tr>
  <tr><td><code>is_active</code></td><td>TINYINT(1) DEFAULT 1</td><td>Soft-disable; login blocked when 0</td></tr>
  <tr><td><code>first_login_token</code></td><td>VARCHAR(64) NULL</td><td>64-char hex (32 random bytes); cleared after use</td></tr>
  <tr><td><code>token_expires_at</code></td><td>DATETIME NULL</td><td>7-day window from generation</td></tr>
  <tr><td><code>created_at</code></td><td>DATETIME</td><td></td></tr>
  <tr><td><code>last_login_at</code></td><td>DATETIME NULL</td><td>Updated on each successful login</td></tr>
</table></div>

<h3>mpd_parties — Events / Galleries</h3>
<div class="tbl-wrap"><table>
  <tr><th>Column</th><th>Type</th><th>Notes</th></tr>
  <tr><td><code>id</code></td><td>INT PK AI</td><td></td></tr>
  <tr><td><code>slug</code></td><td>VARCHAR(60) UNIQUE</td><td>6-char alphanumeric; used in guest URL and QR code</td></tr>
  <tr><td><code>party_name</code></td><td>VARCHAR(200)</td><td>Display name</td></tr>
  <tr><td><code>organizer_id</code></td><td>INT FK → mpd_users</td><td>Primary owner; login scoped to this party</td></tr>
  <tr><td><code>created_by</code></td><td>INT FK → mpd_users</td><td>Superadmin who created it</td></tr>
  <tr><td><code>is_active</code></td><td>TINYINT(1) DEFAULT 1</td><td>0 = paused; guests see "Gallery Paused" page</td></tr>
  <tr><td><code>auto_approve</code></td><td>TINYINT(1) DEFAULT 0</td><td>Skip moderation queue; photos go straight to gallery</td></tr>
  <tr><td><code>cloudinary_enabled</code></td><td>TINYINT(1) DEFAULT 0</td><td>Use Cloudinary CDN for approved photos</td></tr>
  <tr><td><code>timer_camera_enabled</code></td><td>TINYINT(1) DEFAULT 0</td><td>Show in-browser countdown selfie camera</td></tr>
  <tr><td><code>retention_days</code></td><td>INT DEFAULT 30</td><td>Days before photos are flagged for removal</td></tr>
  <tr><td><code>event_datetime</code></td><td>DATETIME NULL</td><td>Optional; shown on guest page</td></tr>
  <tr><td><code>party_info</code></td><td>TEXT NULL</td><td>Optional description shown on guest page</td></tr>
  <tr><td><code>organiser_name</code></td><td>VARCHAR(200) NULL</td><td>Display name shown to guests</td></tr>
  <tr><td><code>notify_email</code></td><td>VARCHAR(254) NULL</td><td>Override email for upload notifications</td></tr>
  <tr><td><code>colour_theme</code></td><td>VARCHAR(50) NULL</td><td>Reserved for future theme support</td></tr>
  <tr><td><code>created_at</code></td><td>DATETIME</td><td></td></tr>
</table></div>

<h3>photos — Uploaded Images</h3>
<div class="tbl-wrap"><table>
  <tr><th>Column</th><th>Type</th><th>Notes</th></tr>
  <tr><td><code>id</code></td><td>INT PK AI</td><td></td></tr>
  <tr><td><code>party_id</code></td><td>INT FK → mpd_parties</td><td></td></tr>
  <tr><td><code>uuid</code></td><td>CHAR(32) UNIQUE</td><td><code>bin2hex(random_bytes(16))</code>; used as filename stem</td></tr>
  <tr><td><code>original_extension</code></td><td>VARCHAR(10)</td><td>Validated: jpg, png, webp, heic</td></tr>
  <tr><td><code>status</code></td><td>ENUM('pending','approved','rejected','removed')</td><td>State machine; see Moderation section</td></tr>
  <tr><td><code>ip_hash</code></td><td>VARCHAR(64)</td><td>SHA256(IP + IP_SALT) for rate-limiting</td></tr>
  <tr><td><code>ip_display</code></td><td>VARCHAR(50)</td><td>Masked: *.2.3.4 for IPv4; first 4 groups for IPv6</td></tr>
  <tr><td><code>uploaded_by</code></td><td>VARCHAR(100) NULL</td><td>Optional guest name; stripped of control chars and HTML</td></tr>
  <tr><td><code>exif_data</code></td><td>JSON NULL</td><td>Full image metadata captured before stripping; NULL when <code>CAPTURE_EXIF_DATA</code> is false or file has no metadata. Imagick captures EXIF/IPTC/XMP + dimensions; GD captures EXIF sections for JPEG only.</td></tr>
  <tr><td><code>cloudinary_public_id</code></td><td>VARCHAR NULL</td><td>Set when image is pushed to Cloudinary on approval</td></tr>
  <tr><td><code>upload_timestamp</code></td><td>DATETIME DEFAULT NOW()</td><td></td></tr>
  <tr><td><code>approved_at</code></td><td>DATETIME NULL</td><td></td></tr>
  <tr><td><code>rejected_at</code></td><td>DATETIME NULL</td><td></td></tr>
</table></div>

<h3>upload_attempts — Rate Limiting</h3>
<div class="tbl-wrap"><table>
  <tr><th>Column</th><th>Type</th><th>Notes</th></tr>
  <tr><td><code>party_id</code></td><td>INT</td><td>Scoped per-party (not platform-wide)</td></tr>
  <tr><td><code>ip_hash</code></td><td>VARCHAR(64)</td><td>Same hash as photos table</td></tr>
  <tr><td><code>attempted_at</code></td><td>DATETIME DEFAULT NOW()</td><td>Rolling window; old rows can be pruned</td></tr>
</table></div>

<h3>mpd_settings — Global Key/Value Config</h3>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Default</th><th>Purpose</th></tr>
  <tr><td><code>smtp_host</code></td><td>—</td><td>SMTP relay hostname</td></tr>
  <tr><td><code>smtp_port</code></td><td>587</td><td></td></tr>
  <tr><td><code>smtp_user</code></td><td>—</td><td>SMTP auth username</td></tr>
  <tr><td><code>smtp_pass</code></td><td>—</td><td>Stored in DB; never blank-overwritten from UI</td></tr>
  <tr><td><code>smtp_from</code></td><td>—</td><td>Envelope From address</td></tr>
  <tr><td><code>smtp_from_name</code></td><td>MyPictureDesk</td><td>Friendly sender name</td></tr>
  <tr><td><code>smtp_secure</code></td><td>tls</td><td><code>tls</code> = STARTTLS on 587, <code>ssl</code> = SMTPS on 465</td></tr>
  <tr><td><code>retention_max_days</code></td><td>365</td><td>Platform ceiling for party retention setting</td></tr>
  <tr><td><code>retention_default_days</code></td><td>30</td><td>Pre-fill for new parties</td></tr>
  <tr><td><code>email_welcome_body</code></td><td>(styled HTML)</td><td>Welcome email template; NULL = use code default</td></tr>
  <tr><td><code>email_notify_body</code></td><td>(styled HTML)</td><td>Upload notification template; NULL = use code default</td></tr>
  <tr><td><code>print_a4_body</code></td><td>(HTML)</td><td>A4 print template; NULL = use code default</td></tr>
  <tr><td><code>print_label_body</code></td><td>(HTML)</td><td>6×4" label print template; NULL = use code default</td></tr>
</table></div>

<h3>mpd_login_attempts — Brute-Force Log</h3>
<p>Stores hashed email + timestamp. If 10+ rows exist within 15 minutes for a hash, login is locked until the window passes. Cleared on successful login.</p>
</section>

<!-- ─── CONFIG ─── -->
<section id="config">
<h2>Configuration (config.php)</h2>
<div class="callout warn"><strong>config.php is gitignored.</strong> It must be created manually on each server. Never commit credentials.</div>
<pre><code><span class="cmt">// Database</span>
<span class="kw">define</span>(<span class="str">'DB_HOST'</span>, <span class="str">'localhost'</span>);
<span class="kw">define</span>(<span class="str">'DB_NAME'</span>, <span class="str">'your_db'</span>);
<span class="kw">define</span>(<span class="str">'DB_USER'</span>, <span class="str">'your_user'</span>);
<span class="kw">define</span>(<span class="str">'DB_PASS'</span>, <span class="str">'your_pass'</span>);

<span class="cmt">// File storage (outside public_html)</span>
<span class="kw">define</span>(<span class="str">'UPLOADS_BASE'</span>, <span class="str">'/home/user/uploads'</span>);

<span class="cmt">// Upload limits</span>
<span class="kw">define</span>(<span class="str">'MAX_FILE_SIZE_MB'</span>,    <span class="val">10</span>);
<span class="kw">define</span>(<span class="str">'MAX_FILE_SIZE_BYTES'</span>, MAX_FILE_SIZE_MB * <span class="val">1024</span> * <span class="val">1024</span>);

<span class="cmt">// Rate limiting (per IP, per party)</span>
<span class="kw">define</span>(<span class="str">'RATE_LIMIT_UPLOADS'</span>,      <span class="val">20</span>);
<span class="kw">define</span>(<span class="str">'RATE_LIMIT_WINDOW_HOURS'</span>, <span class="val">24</span>);

<span class="cmt">// Image processing</span>
<span class="kw">define</span>(<span class="str">'ENABLE_RESIZE'</span>,     <span class="val">true</span>);
<span class="kw">define</span>(<span class="str">'MAX_DISPLAY_PX'</span>,    <span class="val">3840</span>);   <span class="cmt">// longest edge (4K)</span>
<span class="kw">define</span>(<span class="str">'THUMB_SIZE'</span>,         <span class="val">400</span>);    <span class="cmt">// square centre-crop</span>
<span class="kw">define</span>(<span class="str">'CAPTURE_EXIF_DATA'</span>, <span class="val">true</span>);   <span class="cmt">// store metadata in photos.exif_data before stripping</span>

<span class="cmt">// Session</span>
<span class="kw">define</span>(<span class="str">'SESSION_LIFETIME_MINUTES'</span>, <span class="val">120</span>);

<span class="cmt">// App</span>
<span class="kw">define</span>(<span class="str">'BASE_URL'</span>, <span class="str">'https://mypicturedesk.com'</span>);
<span class="kw">define</span>(<span class="str">'IP_SALT'</span>,  <span class="str">'random-string-here'</span>);

<span class="cmt">// Optional — Cloudinary (all three required to enable)</span>
<span class="kw">define</span>(<span class="str">'CLOUDINARY_CLOUD_NAME'</span>, <span class="str">'...'</span>);
<span class="kw">define</span>(<span class="str">'CLOUDINARY_API_KEY'</span>,    <span class="str">'...'</span>);
<span class="kw">define</span>(<span class="str">'CLOUDINARY_API_SECRET'</span>, <span class="str">'...'</span>);

<span class="cmt">// Optional — Axiom logging</span>
<span class="kw">define</span>(<span class="str">'AXIOM_API_KEY'</span>, <span class="str">'...'</span>);
<span class="kw">define</span>(<span class="str">'AXIOM_DATASET'</span>, <span class="str">'...'</span>);</code></pre>
</section>

<!-- ─── AUTH ─── -->
<section id="auth">
<h2>Authentication &amp; Roles</h2>

<h3>Login Flow</h3>
<div class="flow">
  <span class="flow-step">POST email + password</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Brute-force check (10 fails / 15 min)</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">password_verify()</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">session_regenerate_id()</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Set session vars</span>
  <span class="flow-arrow">→</span>
  <span class="flow-step">Redirect to dashboard</span>
</div>

<h3>Session Variables</h3>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Type</th><th>Notes</th></tr>
  <tr><td><code>mpd_user_id</code></td><td>int</td><td>Authenticated user ID</td></tr>
  <tr><td><code>mpd_role</code></td><td>string</td><td>'superadmin' or 'organizer'</td></tr>
  <tr><td><code>mpd_party_id</code></td><td>int</td><td>Active party (organizer only; 0 for superadmin)</td></tr>
  <tr><td><code>mpd_party_slug</code></td><td>string</td><td>Active party slug</td></tr>
  <tr><td><code>admin_csrf</code></td><td>string</td><td>64-char hex CSRF token; regenerated on login</td></tr>
  <tr><td><code>admin_last_active</code></td><td>int</td><td>Unix timestamp; checked against SESSION_LIFETIME_MINUTES</td></tr>
  <tr><td><code>mpd_real_user_id</code></td><td>int</td><td>Superadmin's real ID during impersonation</td></tr>
  <tr><td><code>mpd_real_role</code></td><td>string</td><td>Always 'superadmin' during impersonation</td></tr>
</table></div>

<h3>Password Reset / Invitation</h3>
<p>When a new organiser is created (or when reset is requested), <code>mpd_set_user_token()</code> generates a 64-char hex token stored in <code>mpd_users.first_login_token</code> with a 7-day expiry. The link <code>/party/admin/setpassword.php?token=...</code> is emailed. On use, <code>mpd_deactivate_token()</code> clears both fields.</p>
</section>

<!-- ─── SECURITY ─── -->
<section id="security">
<h2>Security Controls</h2>

<h3>CSRF Protection</h3>
<p>Every state-changing POST requires a <code>csrf_token</code> field validated via <code>hash_equals($session_token, $submitted_token)</code>. The token lives in <code>$_SESSION['admin_csrf']</code> and is regenerated on login.</p>

<h3>Content Security Policy</h3>
<p>Every page sets a per-request nonce (<code>base64_encode(random_bytes(16))</code>) and emits a strict CSP header. All <code>&lt;script&gt;</code> and <code>&lt;style&gt;</code> tags carry <code>nonce="..."</code>. Inline styles without a nonce are blocked.</p>
<pre><code>Content-Security-Policy:
  default-src 'self'
  script-src  'self' 'nonce-{random}'
  style-src   'self' 'nonce-{random}' https://fonts.googleapis.com
  img-src     'self' data: blob: https://res.cloudinary.com
  font-src    https://fonts.gstatic.com
  object-src  'none'
  base-uri    'self'</code></pre>

<div class="callout info"><strong>Blob URL CSP:</strong> Print preview windows are opened as <code>blob:</code> URLs via <code>window.open()</code>. Chrome/Edge inherit the opener's CSP, so the blob's <code>&lt;style&gt;</code> tags must carry the same nonce — this is injected by JS at print time.</div>

<h3>Upload Security</h3>
<ul>
  <li><strong>Magic-byte validation</strong> — file headers checked against JPEG (FF D8 FF), PNG (8-byte signature), WebP (RIFF/WEBP), HEIC (ftyp) — MIME type from the browser is ignored</li>
  <li><strong>UUID filenames</strong> — <code>bin2hex(random_bytes(16))</code>; no user-supplied names ever used on disk</li>
  <li><strong>Quarantine first</strong> — files land in a non-public directory; only moved to gallery on approval</li>
  <li><strong>EXIF capture</strong> — when <code>CAPTURE_EXIF_DATA = true</code>, all metadata is read from the quarantine file and stored as JSON in <code>photos.exif_data</code> before processing begins</li>
  <li><strong>EXIF stripping</strong> — Imagick strips all metadata on processing; GD re-encodes (inherently strips). Stripping happens regardless of <code>CAPTURE_EXIF_DATA</code>.</li>
  <li><strong>Rate limiting</strong> — 20 uploads per IP per party per 24-hour rolling window (hashed IP)</li>
</ul>

<h3>Other Headers</h3>
<pre><code>X-Content-Type-Options: nosniff
X-Frame-Options: DENY          <span class="cmt"># admin pages</span>
Referrer-Policy: same-origin</code></pre>
</section>

<!-- ─── IMPERSONATION ─── -->
<section id="impersonate">
<h2>Impersonation</h2>
<p>Superadmins can take on an organizer's session to view their dashboard exactly as they see it. Real identity is preserved in separate session keys and restored on stop.</p>
<div class="card">
  <h4>Start (POST action=start to impersonate.php)</h4>
  <ul>
    <li>Stores <code>mpd_real_user_id</code>, <code>mpd_real_role</code>, <code>mpd_real_csrf</code> in session</li>
    <li>Overwrites <code>mpd_user_id</code>, <code>mpd_role='organizer'</code>, <code>mpd_party_id</code>, new CSRF token</li>
    <li>Logs <code>admin.impersonate.start</code> to Axiom</li>
  </ul>
  <h4>Stop (POST action=stop)</h4>
  <ul>
    <li>Restores all real session variables, clears impersonation keys</li>
    <li>Logs <code>admin.impersonate.stop</code></li>
  </ul>
  <h4>Banner</h4>
  <p>A yellow banner on the organizer dashboard shows during impersonation: <em>"Viewing as organiser: email — Return to superadmin"</em>.</p>
</div>
</section>

<!-- ─── GUEST UPLOAD ─── -->
<section id="guest-upload">
<h2>Guest Upload Flow</h2>

<h3>Landing Page (index.php)</h3>
<p>Resolves party by <code>?id={slug}</code>. Three states:</p>
<ul>
  <li><strong>Active</strong> — Upload UI, gallery grid, slideshow link</li>
  <li><strong>Paused</strong> — "Gallery Paused" message only</li>
  <li><strong>Not found</strong> — 6-char code entry form (auto-submits at length 6)</li>
</ul>

<h3>Upload Handler (upload.php) — Validation Chain</h3>
<div class="flow" style="flex-direction:column; align-items:flex-start; gap:6px;">
  <div><span class="flow-step">1</span> &nbsp;POST-only; JSON response throughout</div>
  <div><span class="flow-step">2</span> &nbsp;Detect post_max_size overflow (empty <code>$_POST</code> with <code>CONTENT_LENGTH</code> &gt; limit)</div>
  <div><span class="flow-step">3</span> &nbsp;CSRF token (session-scoped per guest)</div>
  <div><span class="flow-step">4</span> &nbsp;Party exists and <code>is_active = 1</code></div>
  <div><span class="flow-step">5</span> &nbsp;IP rate limit (20 / 24h rolling window)</div>
  <div><span class="flow-step">6</span> &nbsp;File present, no PHP upload error</div>
  <div><span class="flow-step">7</span> &nbsp;File size ≤ MAX_FILE_SIZE_BYTES</div>
  <div><span class="flow-step">8</span> &nbsp;Magic-byte check (JPEG / PNG / WebP / HEIC only)</div>
  <div><span class="flow-step">9</span> &nbsp;Move to quarantine/{uuid}.{ext}, chmod 0644</div>
  <div><span class="flow-step">10</span> &nbsp;Generate quarantine thumbnail (400×400 centre-crop)</div>
  <div><span class="flow-step">11</span> &nbsp;Insert <code>photos</code> row (status=pending or approved if auto_approve)</div>
  <div><span class="flow-step">12</span> &nbsp;If auto_approve: run full image processing immediately</div>
  <div><span class="flow-step">13</span> &nbsp;Log rate-limit attempt; optionally send notification email</div>
</div>

<h3>Error Responses</h3>
<div class="tbl-wrap"><table>
  <tr><th>HTTP</th><th>Reason</th></tr>
  <tr><td>400</td><td>CSRF fail, missing file, invalid type, bad party</td></tr>
  <tr><td>405</td><td>Non-POST request</td></tr>
  <tr><td>413</td><td>File exceeds post_max_size</td></tr>
  <tr><td>429</td><td>Rate limit reached</td></tr>
  <tr><td>503</td><td>Party paused</td></tr>
  <tr><td>500</td><td>Storage / processing failure</td></tr>
</table></div>
</section>

<!-- ─── MODERATION ─── -->
<section id="moderation">
<h2>Photo Moderation</h2>
<p>All moderation calls hit <code>moderate.php</code> as AJAX JSON POSTs. The photo status is a state machine:</p>

<div class="card">
<pre><code>pending ──approve──→ approved ──remove──→ removed ──restore──→ approved
        ──remove──→ removed  ──reject──→ [deleted from disk + DB status=rejected]
        ──reject──→ [deleted]
approved ──remove──→ removed
removed  ──purge_all──→ [all removed photos deleted]</code></pre>
</div>

<h3>Approve Action</h3>
<ol>
  <li>Move <code>quarantine/{uuid}.ext</code> → <code>gallery/{uuid}.ext</code></li>
  <li>Process: auto-orient, EXIF strip, resize (if ENABLE_RESIZE), thumbnail</li>
  <li>Set <code>status='approved'</code>, <code>approved_at=NOW()</code></li>
  <li>If Cloudinary enabled: upload to <code>mypicturedesk/{slug}/{uuid}</code>, store <code>cloudinary_public_id</code>, delete local gallery copy</li>
</ol>

<h3>Reject / Purge</h3>
<p>Permanent deletion: disk files (quarantine + gallery, local + Cloudinary) are removed. DB row remains for audit (status=rejected).</p>

<h3>Wastebasket</h3>
<p><code>status='removed'</code> is a soft-delete. Files remain on disk. Organiser can restore or permanently reject. Purge All deletes all removed photos at once.</p>
</section>

<!-- ─── IMAGE PROCESSING ─── -->
<section id="image-proc">
<h2>Image Processing</h2>
<p>Handled by <code>includes/image.php</code>. Imagick is used if available; GD is the fallback.</p>

<h3>Magic-Byte Validation</h3>
<div class="tbl-wrap"><table>
  <tr><th>Format</th><th>Header bytes</th><th>Output ext</th></tr>
  <tr><td>JPEG</td><td><code>FF D8 FF</code></td><td>jpg</td></tr>
  <tr><td>PNG</td><td><code>89 50 4E 47 0D 0A 1A 0A</code> (8 bytes)</td><td>png</td></tr>
  <tr><td>WebP</td><td><code>RIFF????WEBP</code></td><td>webp</td></tr>
  <tr><td>HEIC</td><td>Bytes 4–7 = <code>ftyp</code></td><td>jpg (converted on processing)</td></tr>
</table></div>

<h3>Processing Pipeline (Imagick)</h3>
<ol>
  <li>Flatten animated GIF / HEIC to first frame</li>
  <li><strong>Extract metadata</strong> — if <code>CAPTURE_EXIF_DATA = true</code>, <code>extract_image_metadata()</code> reads all EXIF/IPTC/XMP properties via <code>getImageProperties()</code> and stores them in <code>photos.exif_data</code> as JSON (this happens in upload.php before <code>process_image()</code> is called)</li>
  <li>Auto-orient from EXIF data</li>
  <li>Strip all metadata (<code>stripImage()</code>)</li>
  <li>Resize: longest edge capped at <code>MAX_DISPLAY_PX</code> (3840px), aspect preserved</li>
  <li>Quality 88; convert HEIC → JPEG</li>
  <li>Save display image to gallery</li>
  <li>Centre-crop to <code>THUMB_SIZE × THUMB_SIZE</code> (400px), quality 82</li>
  <li>Save thumbnail</li>
</ol>

<h3>Captured Metadata — Sample (iPhone 15 Pro Max, GD path)</h3>
<p>Real <code>photos.exif_data</code> payload from a JPEG uploaded on the GD fallback path (<code>exif_read_data</code>). Imagick captures a superset of these fields plus XMP and ICC profile data.</p>

<h4 style="margin:18px 0 8px;color:var(--purple);">FILE — File-level facts</h4>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Raw value</th><th>Meaning</th></tr>
  <tr><td><code>FILE.FileName</code></td><td>d4811b…324.jpg</td><td>UUID filename on disk</td></tr>
  <tr><td><code>FILE.FileDateTime</code></td><td>1778946327</td><td>Unix timestamp of quarantine file write</td></tr>
  <tr><td><code>FILE.FileSize</code></td><td>5 796 884</td><td>~5.5 MB original file size</td></tr>
  <tr><td><code>FILE.MimeType</code></td><td>image/jpeg</td><td></td></tr>
  <tr><td><code>FILE.SectionsFound</code></td><td>ANY_TAG, IFD0, EXIF, GPS</td><td>Sections present in this file</td></tr>
</table></div>

<h4 style="margin:18px 0 8px;color:var(--purple);">COMPUTED — Values derived by exif_read_data</h4>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Raw value</th><th>Meaning</th></tr>
  <tr><td><code>COMPUTED.Width</code></td><td>4032</td><td>Full-resolution width (px)</td></tr>
  <tr><td><code>COMPUTED.Height</code></td><td>3024</td><td>Full-resolution height (px)</td></tr>
  <tr><td><code>COMPUTED.ApertureFNumber</code></td><td>f/2.8</td><td>Human-readable aperture</td></tr>
  <tr><td><code>COMPUTED.IsColor</code></td><td>1</td><td>Colour image (not greyscale)</td></tr>
</table></div>

<h4 style="margin:18px 0 8px;color:var(--purple);">IFD0 — Primary image directory</h4>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Raw value</th><th>Meaning</th></tr>
  <tr><td><code>IFD0.Make</code></td><td>Apple</td><td>Camera manufacturer</td></tr>
  <tr><td><code>IFD0.Model</code></td><td>iPhone 15 Pro Max</td><td>Camera model</td></tr>
  <tr><td><code>IFD0.Orientation</code></td><td>1</td><td>Normal (no rotation needed)</td></tr>
  <tr><td><code>IFD0.Software</code></td><td>26.4.2</td><td>iOS version at time of capture</td></tr>
  <tr><td><code>IFD0.DateTime</code></td><td>2026:05:16 12:12:24</td><td>File modification timestamp (local)</td></tr>
  <tr><td><code>IFD0.XResolution / YResolution</code></td><td>72/1</td><td>72 DPI (display metadata only, not print)</td></tr>
</table></div>

<h4 style="margin:18px 0 8px;color:var(--purple);">EXIF — Exposure &amp; optics</h4>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Raw value</th><th>Decoded</th></tr>
  <tr><td><code>EXIF.DateTimeOriginal</code></td><td>2026:05:16 12:12:24</td><td>Shutter moment (local time)</td></tr>
  <tr><td><code>EXIF.UndefinedTag:0x9010</code></td><td>+01:00</td><td>UTC offset (OffsetTime) — BST</td></tr>
  <tr><td><code>EXIF.ExposureTime</code></td><td>1/313</td><td>1/313 s shutter speed</td></tr>
  <tr><td><code>EXIF.FNumber</code></td><td>14/5</td><td>f/2.8 aperture</td></tr>
  <tr><td><code>EXIF.ISOSpeedRatings</code></td><td>50</td><td>ISO 50 (bright conditions)</td></tr>
  <tr><td><code>EXIF.FocalLength</code></td><td>2052196/131047</td><td>≈ 15.66 mm physical focal length</td></tr>
  <tr><td><code>EXIF.FocalLengthIn35mmFilm</code></td><td>120</td><td>120 mm equivalent (telephoto lens)</td></tr>
  <tr><td><code>EXIF.ExposureProgram</code></td><td>2</td><td>Normal program (auto)</td></tr>
  <tr><td><code>EXIF.MeteringMode</code></td><td>5</td><td>Pattern / multi-segment</td></tr>
  <tr><td><code>EXIF.Flash</code></td><td>16</td><td>Flash did not fire</td></tr>
  <tr><td><code>EXIF.ExposureMode</code></td><td>0</td><td>Auto exposure</td></tr>
  <tr><td><code>EXIF.WhiteBalance</code></td><td>0</td><td>Auto white balance</td></tr>
  <tr><td><code>EXIF.UndefinedTag:0xA434</code></td><td>iPhone 15 Pro Max back triple camera 15.66mm f/2.8</td><td>Lens model string (LensModel tag)</td></tr>
  <tr><td><code>EXIF.SubjectLocation</code></td><td>[2001, 1508, 2218, 1328]</td><td>Subject rectangle: centre x, centre y, width, height (px)</td></tr>
  <tr><td><code>EXIF.ExifImageWidth / Length</code></td><td>4032 × 3024</td><td>Image dimensions as recorded in EXIF</td></tr>
</table></div>

<h4 style="margin:18px 0 8px;color:var(--purple);">GPS — Location</h4>
<div class="tbl-wrap"><table>
  <tr><th>Key</th><th>Raw value</th><th>Decoded</th></tr>
  <tr><td><code>GPS.GPSLatitudeRef</code></td><td>N</td><td>Northern hemisphere</td></tr>
  <tr><td><code>GPS.GPSLatitude</code></td><td>["51/1","33/1","5412/100"]</td><td>51° 33′ 54.12″ N</td></tr>
  <tr><td><code>GPS.GPSLongitudeRef</code></td><td>E</td><td>Eastern hemisphere</td></tr>
  <tr><td><code>GPS.GPSLongitude</code></td><td>["0/1","38/1","1100/100"]</td><td>0° 38′ 11.00″ E</td></tr>
  <tr><td><code>GPS.GPSAltitudeRef</code></td><td>0x00</td><td>Above sea level</td></tr>
  <tr><td><code>GPS.GPSAltitude</code></td><td>242883/4025</td><td>≈ 60.3 m ASL</td></tr>
  <tr><td><code>GPS.GPSTimeStamp</code></td><td>["11/1","12/1","23/1"]</td><td>11:12:23 UTC</td></tr>
  <tr><td><code>GPS.GPSDateStamp</code></td><td>2026:05:16</td><td>UTC date</td></tr>
  <tr><td><code>GPS.GPSSpeed</code></td><td>31607/39972</td><td>≈ 0.79 km/h (effectively stationary)</td></tr>
  <tr><td><code>GPS.GPSImgDirection</code></td><td>413963/1425</td><td>≈ 290.5° true (WNW)</td></tr>
  <tr><td><code>GPS.UndefinedTag:0x001F</code></td><td>51103/8543</td><td>≈ 5.98 m horizontal positioning error (HDOP equivalent)</td></tr>
</table></div>
<div class="callout info"><strong>Note:</strong> GPS rational values (DMS fractions) are stored as-is from EXIF. To convert latitude to decimal degrees: <code>51 + 33/60 + 54.12/3600 ≈ 51.5650°</code>. Longitude: <code>0 + 38/60 + 11.00/3600 ≈ 0.6364°E</code>.</div>
</section>

<!-- ─── PARTIES ─── -->
<section id="parties">
<h2>Party Management (parties.php)</h2>
<p>Superadmin only. Handles all lifecycle operations for parties.</p>

<h3>Create Party</h3>
<p>Client-side generates a 6-char alphanumeric slug (validated unique or auto-regenerated). Organizer can be selected from existing users or created as a new account by email. On creation:</p>
<ul>
  <li>Party + directory tree created</li>
  <li>Welcome email sent to organiser (with set-password link if no password yet)</li>
  <li>Success/failure shown inline</li>
</ul>

<h3>Per-Party Toggles</h3>
<div class="tbl-wrap"><table>
  <tr><th>Toggle</th><th>Effect</th></tr>
  <tr><td>Live / Paused</td><td>Sets <code>is_active</code>; guests see "paused" page when 0</td></tr>
  <tr><td>Cloudinary</td><td>Sets <code>cloudinary_enabled</code>; applies to future approvals</td></tr>
  <tr><td>Auto-approve</td><td>Sets <code>auto_approve</code>; requires confirmation modal (bypasses moderation)</td></tr>
</table></div>

<h3>Delete Party</h3>
<p>Cascades: deletes <code>photos</code>, <code>upload_attempts</code>, the <code>mpd_parties</code> row, and the full directory tree under <code>UPLOADS_BASE/{slug}/</code>. Irreversible — confirmation required.</p>
</section>

<!-- ─── USERS ─── -->
<section id="users">
<h2>User Management (users.php)</h2>
<p>Superadmin only.</p>
<div class="tbl-wrap"><table>
  <tr><th>Action</th><th>Behaviour</th></tr>
  <tr><td>Reset Password</td><td>Generates 7-day token → emails set-password link; logs to Axiom</td></tr>
  <tr><td>Delete User</td><td>Blocked if user has any party assignments; hard-deletes the row</td></tr>
</table></div>
<p>The user list shows: email, role, active status, created date, last login, party count, whether a pending invite link is outstanding.</p>
</section>

<!-- ─── QR CODE ─── -->
<section id="qrcode">
<h2>QR Code &amp; Print (qrcode.php)</h2>
<p>Generates a QR code encoding the guest URL (<code>BASE_URL/party?id={slug}</code>) in the browser using the <code>qrcode</code> JS library from jsDelivr CDN.</p>

<h3>Outputs</h3>
<ul>
  <li><strong>On-screen canvas</strong> — with party name label, downloadable as PNG</li>
  <li><strong>A4 print card</strong> — SVG QR, party name, slug, guest URL; blob URL popup → browser print</li>
  <li><strong>6×4" label</strong> — SVG QR left, text right; landscape layout for sticker printers</li>
</ul>

<h3>Print Template Mechanism</h3>
<p>Templates are stored in <code>mpd_settings</code> (<code>print_a4_body</code>, <code>print_label_body</code>). PHP renders them via <code>mpd_render_print_template()</code> before page load, substituting <code>{{party_name}}</code>, <code>{{slug}}</code>, <code>{{guest_url}}</code>, <code>{{qr_svg}}</code>. The rendered HTML is passed to JS as a JSON constant.</p>

<p>On print, JS strips any existing print-trigger <code>&lt;script&gt;</code>, injects <code>&lt;style nonce="..."&gt;</code> and <code>&lt;script nonce="..."&gt;</code> (required due to CSP inheritance on blob URLs), then opens a <code>blob:</code> URL which auto-prints after 150ms.</p>

<p>Templates are editable in <strong>Settings → Print Templates</strong>. Clearing the textarea resets to the code default.</p>
</section>

<!-- ─── SETTINGS ─── -->
<section id="superadmin-settings">
<h2>Global Settings (superadmin_settings.php)</h2>
<div class="tbl-wrap"><table>
  <tr><th>Section</th><th>Settings</th></tr>
  <tr><td>SMTP</td><td>Host, Port, User, Password, From, From Name, Secure mode + test email</td></tr>
  <tr><td>Retention</td><td>Platform maximum days, default days for new parties</td></tr>
  <tr><td>Email Templates</td><td>Welcome email body, upload notification body (HTML with <code>{{placeholders}}</code>)</td></tr>
  <tr><td>Print Templates</td><td>A4 card HTML, 6×4" label HTML (with <code>{{placeholders}}</code>)</td></tr>
</table></div>

<h3>Email Template Variables</h3>
<div class="tbl-wrap"><table>
  <tr><th>Placeholder</th><th>Available in</th><th>Value</th></tr>
  <tr><td><code>{{party_name}}</code></td><td>Both</td><td>Party display name</td></tr>
  <tr><td><code>{{guest_url}}</code></td><td>Welcome</td><td>Full guest URL with slug</td></tr>
  <tr><td><code>{{admin_url}}</code></td><td>Welcome</td><td>Admin panel URL</td></tr>
  <tr><td><code>{{setpassword_block}}</code></td><td>Welcome</td><td>Amber alert box with set-password link (empty string if organiser already has a password)</td></tr>
  <tr><td><code>{{uploaded_by}}</code></td><td>Notify</td><td>Guest's submitted name</td></tr>
  <tr><td><code>{{ip_display}}</code></td><td>Notify</td><td>Masked IP (*.x.x.x)</td></tr>
  <tr><td><code>{{upload_time}}</code></td><td>Notify</td><td>UTC timestamp</td></tr>
</table></div>
</section>

<!-- ─── EMAIL ─── -->
<section id="email">
<h2>Email System</h2>

<h3>Transport</h3>
<p>All email is sent via <code>mpd_send_email(to, subject, body_html)</code> in <code>db.php</code>. Transport priority:</p>
<ol>
  <li><strong>PHPMailer via SMTP</strong> — if <code>PHPMailer\PHPMailer\PHPMailer</code> class exists (Composer installed) <em>and</em> <code>smtp_host</code> is non-empty in settings. 15-second timeout. STARTTLS on port 587, SMTPS on 465.</li>
  <li><strong>PHP <code>mail()</code> fallback</strong> — if PHPMailer unavailable or SMTP not configured. Delivers via server's local MTA; no DKIM — may trigger spam filters for HTML emails.</li>
</ol>
<div class="callout warn"><strong>Important:</strong> Always ensure <code>composer install</code> has been run and SMTP credentials are configured in Settings. The <code>mail()</code> fallback can deliver simple plain-text emails but complex HTML templates will be silently dropped by Gmail without DKIM.</div>

<h3>Emails Sent</h3>
<div class="tbl-wrap"><table>
  <tr><th>Trigger</th><th>Recipient</th><th>Subject</th><th>Template</th></tr>
  <tr><td>Party created</td><td>Organiser</td><td>Your party gallery is ready: {name}</td><td>email_welcome_body</td></tr>
  <tr><td>Resend invite</td><td>Organiser</td><td>Your party gallery: {name}</td><td>email_welcome_body</td></tr>
  <tr><td>Photo uploaded</td><td>notify_email or organiser</td><td>New photo awaiting approval</td><td>email_notify_body</td></tr>
  <tr><td>Password reset</td><td>User</td><td>MyPictureDesk — Set your password</td><td>Inline (not customisable)</td></tr>
  <tr><td>SMTP test</td><td>Entered by superadmin</td><td>MyPictureDesk — SMTP test</td><td>Inline</td></tr>
</table></div>

<h3>Logging</h3>
<p>Every send attempt logs to Axiom: <code>email.sent</code> or <code>email.failed</code>. On failure, the full SMTP conversation (server responses, rejection codes) is included in the <code>smtp.log</code> field. The <code>email.via</code> field shows <code>smtp</code> or <code>php_mail</code>.</p>
</section>

<!-- ─── CLOUDINARY ─── -->
<section id="cloudinary">
<h2>Cloudinary Integration</h2>
<p>Optional per-party CDN storage. Requires <code>CLOUDINARY_CLOUD_NAME</code>, <code>CLOUDINARY_API_KEY</code>, <code>CLOUDINARY_API_SECRET</code> in <code>config.php</code>. Toggle per party in the party list.</p>

<h3>How It Works</h3>
<ol>
  <li>On photo <strong>approve</strong>: processed gallery image is uploaded to Cloudinary at path <code>mypicturedesk/{slug}/{uuid}</code></li>
  <li><code>cloudinary_public_id</code> stored in the <code>photos</code> DB row</li>
  <li>Local gallery copy is deleted</li>
  <li>Gallery and admin grid serve Cloudinary transformation URLs (auto format, quality, resize)</li>
</ol>

<h3>URL Transforms</h3>
<div class="tbl-wrap"><table>
  <tr><th>Use</th><th>Transform</th></tr>
  <tr><td>Admin grid thumb</td><td><code>w_300,h_300,c_fill,f_auto,q_auto</code></td></tr>
  <tr><td>Guest gallery thumb</td><td><code>w_600,h_600,c_fill,f_auto,q_auto</code></td></tr>
  <tr><td>Full view</td><td><code>f_auto,q_auto</code></td></tr>
  <tr><td>Slideshow</td><td><code>w_2560,f_auto,q_auto</code></td></tr>
</table></div>

<h3>Deletion</h3>
<p>On reject or purge, <code>cloudinary_delete(public_id)</code> sends a signed DELETE request. Success/failure logged to Axiom as <code>cloudinary.delete</code>.</p>
</section>

<!-- ─── LOGGING ─── -->
<section id="logging">
<h2>Logging (Axiom)</h2>
<p>All events are queued in memory during the request and flushed to Axiom <strong>after</strong> the HTTP response is sent — adding zero latency for the user.</p>

<h3>Mechanism</h3>
<ol>
  <li><code>mpd_log(event, attrs)</code> called anywhere in the app (requires <code>logger.php</code> loaded)</li>
  <li>If <code>AXIOM_API_KEY</code> is defined: event queued in <code>MpdLogger::$queue[]</code></li>
  <li>On first queue, <code>register_shutdown_function(flush)</code> registered</li>
  <li>At shutdown: <code>fastcgi_finish_request()</code> (PHP-FPM) sends response first</li>
  <li>JSON payload POSTed to Axiom ingest API (5s timeout)</li>
</ol>

<h3>Standard Fields</h3>
<pre><code>{
  "_time":        "2026-05-16T14:23:00.000Z",
  "service.name": "mypicturedesk",
  "event.name":   "user.login",
  ...event-specific attributes
}</code></pre>

<h3>Event Reference</h3>
<div class="tbl-wrap"><table>
  <tr><th>Event</th><th>Source</th><th>Key Fields</th></tr>
  <tr><td><code>party.created</code></td><td>parties.php</td><td>party.id, party.name, party.slug, organiser.id, party.auto_approve, party.cloudinary, party.retention_days, admin.id</td></tr>
  <tr><td><code>party.toggled</code></td><td>parties.php</td><td>party.id, party.name, party.slug, party.active (bool), admin.id</td></tr>
  <tr><td><code>party.deleted</code></td><td>parties.php</td><td>party.id, party.name, party.slug, party.photo_count (non-rejected at time of delete), organiser.id, admin.id</td></tr>
  <tr><td><code>user.login</code></td><td>index.php</td><td>event.outcome (success|failure|locked), user.id, user.role, client.address, http.user_agent</td></tr>
  <tr><td><code>user.password_reset</code></td><td>index.php</td><td>target.user_id, email.sent (bool)</td></tr>
  <tr><td><code>email.sent</code></td><td>db.php</td><td>email.to, email.subject, email.via (smtp|php_mail)</td></tr>
  <tr><td><code>email.failed</code></td><td>db.php</td><td>email.to, email.subject, error.message, smtp.log</td></tr>
  <tr><td><code>photo.upload</code></td><td>upload.php</td><td>party.id, party.slug, file.name, file.size, file.type, upload.source, uploader.name, client.address</td></tr>
  <tr><td><code>photo.upload.error</code></td><td>upload.php</td><td>error.type, party.id, file.name, file.size, client.address</td></tr>
  <tr><td><code>photo.auto_approved</code></td><td>upload.php</td><td>photo.uuid, party.id, party.slug, cloudinary.stored, cloudinary.public_id, client.address</td></tr>
  <tr><td><code>photo.wastebasket_emptied</code></td><td>moderate.php</td><td>party.id, photos.count, user.id</td></tr>
  <tr><td><code>cloudinary.delete</code></td><td>moderate.php / db.php</td><td>event.outcome, photo.uuid, cloudinary.public_id, trigger</td></tr>
  <tr><td><code>admin.impersonate.start</code></td><td>impersonate.php</td><td>admin.user_id, target.user_id, target.party_id</td></tr>
  <tr><td><code>admin.impersonate.stop</code></td><td>impersonate.php</td><td>admin.user_id, target.user_id</td></tr>
  <tr><td><code>page.view</code></td><td>index.php (guest)</td><td>url.full, party.slug, party.active, http.user_agent</td></tr>
</table></div>

<div class="callout info"><strong>Note:</strong> <code>logger.php</code> must be included before <code>mpd_log()</code> is called. Not all admin pages include it — check with <code>function_exists('mpd_log')</code> if calling from shared code.</div>
</section>

<!-- ─── DB FUNCTIONS ─── -->
<section id="db-functions">
<h2>db.php Function Reference</h2>

<h3>Connection</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th><th>Notes</th></tr>
  <tr><td><code>db_pdo()</code></td><td>PDO</td><td>Singleton; exceptions enabled; FETCH_ASSOC</td></tr>
</table></div>

<h3>IP Helpers</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th><th>Notes</th></tr>
  <tr><td><code>hash_ip(ip)</code></td><td>string</td><td>SHA256(ip + IP_SALT) for rate-limit keys</td></tr>
  <tr><td><code>partial_ip(ip)</code></td><td>string</td><td>Masks first octet IPv4 (*.x.x.x) or first 4 groups IPv6</td></tr>
</table></div>

<h3>Photo CRUD</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>db_insert_photo(party_id, uuid, ext, ip_hash, ip_display, uploaded_by, exif_data=null)</code></td><td>void</td></tr>
  <tr><td><code>db_get_photos(status, party_id)</code></td><td>array</td></tr>
  <tr><td><code>db_get_photo_by_uuid(uuid, party_id)</code></td><td>array|false</td></tr>
  <tr><td><code>db_set_photo_cloudinary_id(uuid, party_id, public_id)</code></td><td>void</td></tr>
  <tr><td><code>db_set_photo_status(uuid, party_id, status)</code></td><td>void</td></tr>
  <tr><td><code>db_count_pending(party_id)</code></td><td>int</td></tr>
  <tr><td><code>db_count_photos_by_status(party_id)</code></td><td>array</td></tr>
  <tr><td><code>db_get_photos_paginated(limit, offset, party_id|null)</code></td><td>array</td></tr>
  <tr><td><code>db_count_all_photos(party_id|null)</code></td><td>int</td></tr>
</table></div>

<h3>Rate Limiting</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>db_check_rate_limit(party_id, ip_hash)</code></td><td>bool — true if quota remains</td></tr>
  <tr><td><code>db_log_upload_attempt(party_id, ip_hash)</code></td><td>void</td></tr>
</table></div>

<h3>Login Protection</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>db_is_login_locked(email_hash)</code></td><td>bool</td></tr>
  <tr><td><code>db_record_login_failure(email_hash)</code></td><td>void</td></tr>
  <tr><td><code>db_clear_login_failures(email_hash)</code></td><td>void</td></tr>
</table></div>

<h3>User Management</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>mpd_get_user_by_email(email)</code></td><td>array|false</td></tr>
  <tr><td><code>mpd_get_user_by_id(id)</code></td><td>array|false</td></tr>
  <tr><td><code>mpd_get_user_by_token(token)</code></td><td>array|false — checks expiry + is_active</td></tr>
  <tr><td><code>mpd_create_user(email, role)</code></td><td>int — new user ID</td></tr>
  <tr><td><code>mpd_set_user_password(id, hash)</code></td><td>void</td></tr>
  <tr><td><code>mpd_set_user_token(id)</code></td><td>string — the new token</td></tr>
  <tr><td><code>mpd_deactivate_token(id)</code></td><td>void</td></tr>
  <tr><td><code>mpd_update_last_login(id)</code></td><td>void</td></tr>
  <tr><td><code>mpd_set_user_active(id, bool)</code></td><td>void</td></tr>
  <tr><td><code>mpd_get_all_users()</code></td><td>array — with party_count + has_pending_invite</td></tr>
  <tr><td><code>mpd_delete_user(id)</code></td><td>void</td></tr>
</table></div>

<h3>Party Management</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>mpd_get_party_by_slug(slug)</code></td><td>array|false</td></tr>
  <tr><td><code>mpd_get_party_by_id(id)</code></td><td>array|false</td></tr>
  <tr><td><code>mpd_generate_unique_slug(len=6)</code></td><td>string</td></tr>
  <tr><td><code>mpd_get_parties_for_organizer(organizer_id)</code></td><td>array</td></tr>
  <tr><td><code>mpd_get_all_parties()</code></td><td>array — with organiser email + photo counts</td></tr>
  <tr><td><code>mpd_create_party(slug, name, organizer_id, ...)</code></td><td>void</td></tr>
  <tr><td><code>mpd_update_party(id, fields[])</code></td><td>void — whitelisted fields only</td></tr>
  <tr><td><code>mpd_toggle_party_active(id, bool)</code></td><td>void</td></tr>
  <tr><td><code>mpd_delete_party(id)</code></td><td>void — cascades photos, dirs</td></tr>
</table></div>

<h3>Settings</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>mpd_get_setting(key)</code></td><td>string|null</td></tr>
  <tr><td><code>mpd_get_all_settings()</code></td><td>array — all key/value pairs</td></tr>
  <tr><td><code>mpd_update_setting(key, value|null)</code></td><td>void — null deletes the row (resets to default)</td></tr>
</table></div>

<h3>Email &amp; Templates</h3>
<div class="tbl-wrap"><table>
  <tr><th>Function</th><th>Returns</th></tr>
  <tr><td><code>mpd_send_email(to, subject, body_html)</code></td><td>bool</td></tr>
  <tr><td><code>mpd_default_email(key)</code></td><td>string — fallback HTML template</td></tr>
  <tr><td><code>mpd_render_email(key, vars[])</code></td><td>string — DB template or default with substitutions</td></tr>
  <tr><td><code>mpd_default_print_template(key)</code></td><td>string — fallback print HTML</td></tr>
  <tr><td><code>mpd_render_print_template(key, vars[])</code></td><td>string — DB template or default with substitutions</td></tr>
</table></div>

</section>

<hr>
<p style="color:var(--dim); font-size:0.78rem; text-align:center;">
  MyPictureDesk Technical Overview &mdash; Generated <?= date('j F Y') ?> &mdash; Superadmin access only
</p>

</main>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  // Highlight active nav item based on scroll position
  var sections = document.querySelectorAll('section[id]');
  var navLinks  = document.querySelectorAll('a.nav-item');
  function onScroll() {
    var scrollY = window.scrollY + 80;
    var current = '';
    sections.forEach(function (s) {
      if (s.offsetTop <= scrollY) current = s.id;
    });
    navLinks.forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('href') === '#' + current);
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}());
</script>
</body>
</html>
