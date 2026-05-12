<?php
declare(strict_types=1);

// ============================================================
// admin/parties.php — Super admin: manage parties and organiser accounts.
// ============================================================

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

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
$csrf = $_SESSION['admin_csrf'];

// ── Security headers ─────────────────────────────────────────
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; object-src 'none'; base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$success          = '';
$error            = '';
$party_modal_open = false;

$s_plat      = mpd_get_all_settings();
$ret_max     = max(1, (int)($s_plat['retention_max_days']     ?? 365));
$ret_default = max(1, (int)($s_plat['retention_default_days'] ?? 30));

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $submitted_csrf)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ─ Create party ─
        if ($action === 'create_party') {
            $slug        = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
            $name        = trim($_POST['party_name'] ?? '');
            $org_id_raw  = trim($_POST['organiser_id'] ?? '');
            $new_email   = trim($_POST['new_organiser_email'] ?? '');
            $edt         = trim($_POST['event_datetime'] ?? '');
            $info        = trim($_POST['party_info'] ?? '');
            $notify      = trim($_POST['notify_email'] ?? '');
            $ret_raw     = (int)($_POST['retention_days'] ?? $ret_default);
            $party_ret   = max(1, min($ret_max, $ret_raw));
            $me          = (int)$_SESSION['mpd_user_id'];
            $org_id      = 0;

            if ($org_id_raw === 'new') {
                if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address for the new organiser.';
                    $party_modal_open = true;
                } else {
                    $existing = mpd_get_user_by_email($new_email);
                    if ($existing !== false && !empty($existing['password_hash'])) {
                        $error = 'An active account already exists for that email. Select them from the organiser list.';
                        $party_modal_open = true;
                    } elseif ($existing !== false) {
                        $org_id = (int)$existing['id'];
                    } else {
                        mpd_create_user($new_email, 'organizer');
                        $created = mpd_get_user_by_email($new_email);
                        if ($created !== false) {
                            $org_id = (int)$created['id'];
                        }
                    }
                }
            } else {
                $org_id = (int)$org_id_raw;
            }

            if ($error === '') {
                if ($slug === '' || strlen($slug) < 3) {
                    $error = 'Party URL slug must be at least 3 characters (lowercase letters, numbers, hyphens only).';
                    $party_modal_open = true;
                } elseif ($name === '') {
                    $error = 'Party name is required.';
                    $party_modal_open = true;
                } elseif ($org_id === 0) {
                    $error = 'Please select or create an organiser.';
                    $party_modal_open = true;
                } elseif (mpd_get_party_by_slug($slug) !== false) {
                    $error = "The slug '$slug' is already taken. Please choose another.";
                    $party_modal_open = true;
                } else {
                    mpd_create_party(
                        $slug, $name, $org_id, $me,
                        $edt    !== '' ? $edt    : null,
                        $info   !== '' ? $info   : null,
                        $notify !== '' ? $notify : null,
                        $party_ret
                    );
                    mpd_ensure_party_dirs($slug);

                    $org = mpd_get_user_by_id($org_id);
                    if ($org) {
                        $guest_url = BASE_URL . '/party?id=' . urlencode($slug);
                        $admin_url = BASE_URL . '/party/admin/index.php';
                        $setpassword_block = '';
                        if (empty($org['password_hash'])) {
                            $inv_token = mpd_set_user_token($org_id);
                            $inv_link  = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($inv_token);
                            $setpassword_block = "<p>To access the admin panel you'll need to set your password first &mdash; "
                                               . "<a href=\"$inv_link\">click here to set your password</a> (link valid for 48 hours).</p>";
                        }
                        $body = mpd_render_email('email_welcome_body', [
                            'party_name'        => htmlspecialchars($name),
                            'guest_url'         => $guest_url,
                            'admin_url'         => $admin_url,
                            'setpassword_block' => $setpassword_block,
                        ]);
                        mpd_send_email($org['email'], "Your party gallery is ready: $name", $body);
                    }
                    $success = "Party '$name' created with slug '$slug'.";
                }
            }
        }

        // ─ Toggle party active ─
        if ($action === 'toggle_party') {
            $pid    = (int)($_POST['party_id'] ?? 0);
            $active = (bool)(int)($_POST['active'] ?? 0);
            if ($pid > 0) {
                mpd_toggle_party_active($pid, $active);
                $success = 'Party status updated.';
            }
        }

        // ─ Delete party ─
        if ($action === 'delete_party') {
            $pid = (int)($_POST['party_id'] ?? 0);
            if ($pid > 0) {
                $pt = mpd_get_party_by_id($pid);
                mpd_delete_party($pid);
                $success = $pt ? "Party '{$pt['party_name']}' deleted." : 'Party deleted.';
            }
        }

        // ─ Resend party invite ─
        if ($action === 'resend_invite') {
            $pid = (int)($_POST['party_id'] ?? 0);
            $pt  = $pid > 0 ? mpd_get_party_by_id($pid) : false;
            if ($pt === false) {
                $error = 'Party not found.';
            } else {
                $org = mpd_get_user_by_id((int)$pt['organizer_id']);
                if ($org === false) {
                    $error = 'Organiser not found.';
                } else {
                    $guest_url = BASE_URL . '/party?id=' . urlencode($pt['slug']);
                    $admin_url = BASE_URL . '/party/admin/index.php';
                    $setpassword_block = '';
                    if (empty($org['password_hash'])) {
                        $inv_token = mpd_set_user_token((int)$org['id']);
                        $inv_link  = BASE_URL . '/party/admin/setpassword.php?token=' . urlencode($inv_token);
                        $setpassword_block = "<p>To access the admin panel you'll need to set your password first &mdash; "
                                           . "<a href=\"$inv_link\">click here to set your password</a> (link valid for 48 hours).</p>";
                    }
                    $body = mpd_render_email('email_welcome_body', [
                        'party_name'        => htmlspecialchars($pt['party_name']),
                        'guest_url'         => $guest_url,
                        'admin_url'         => $admin_url,
                        'setpassword_block' => $setpassword_block,
                    ]);
                    mpd_send_email($org['email'], "Your party gallery: " . $pt['party_name'], $body);
                    $success = 'Invite resent to ' . htmlspecialchars($org['email']) . '.';
                }
            }
        }
    }
}

$parties    = mpd_get_all_parties();
$organisers = array_filter(mpd_get_all_users(), fn($u) => $u['role'] === 'organizer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Parties — MyPictureDesk Admin</title>
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
    .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    h1 { font-size: 1.5rem; font-weight: 900; }
    h2 { font-size: 1.05rem; font-weight: 900; color: #c9b8ff; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
    .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
    .msg-ok  { background: #1a4a2e; color: #6ee7a0; }
    .msg-err { background: #4a1a1a; color: #f87171; }
    hr { border: none; border-top: 2px solid #2d1b69; margin: 32px 0; }

    /* Buttons */
    .btn { padding: 10px 22px; border: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-primary   { background: #f5a623; color: #1a1035; }
    .btn-primary:hover { background: #e6941a; }
    .btn-sm { padding: 4px 12px; border: none; border-radius: 6px; font-size: 0.75rem; font-weight: 700; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-toggle-enable  { background: #27ae60; color: #fff; }
    .btn-toggle-enable:hover { background: #219150; }
    .btn-toggle-disable { background: #4a3580; color: #c9b8ff; }
    .btn-toggle-disable:hover { background: #5a4590; color: #fff; }
    .btn-ghost { background: #2d1b69; color: #c9b8ff; border: 1px solid #4b35a0; }
    .btn-ghost:hover { background: #3d2494; color: #f0ebff; }
    .btn-danger { background: #7a1a1a; color: #f87171; }
    .btn-danger:hover { background: #9a2a2a; color: #fca5a5; }

    /* Party table */
    .party-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .party-table th { text-align: left; padding: 10px 14px; background: #2d1b69; color: #c9b8ff; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .party-table td { padding: 10px 14px; border-bottom: 1px solid #2d1b69; vertical-align: middle; }
    .party-table tr:hover td { background: rgba(255,255,255,0.03); }
    .slug-badge { background: #160f35; color: #9c7fff; font-size: 0.72rem; padding: 2px 7px; border-radius: 6px; font-weight: 700; }
    .active-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
    .pill-active   { background: #1a4a2e; color: #6ee7a0; }
    .pill-inactive { background: #4a1a1a; color: #f87171; }
    .pill-remove   { background: #7a1a1a; color: #f87171; }
    .party-table td + td, .party-table th + th { border-left: 1px solid rgba(100,80,170,0.35); }
    .guest-link { color: #9c7fff; font-size: 0.75rem; }
    .action-cell { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .inline-form { display: inline; }

    /* Modal */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,6,30,0.85); z-index: 200; overflow-y: auto; }
    .modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
    .modal { background: #1e1248; border: 1px solid #4b35a0; border-radius: 16px; padding: 32px 36px; width: 100%; max-width: 560px; position: relative; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .modal h2 { margin-bottom: 0; text-transform: none; font-size: 1.1rem; letter-spacing: 0; color: #f0ebff; }
    .modal-close { background: none; border: none; color: #6b5ca5; font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0 4px; }
    .modal-close:hover { color: #f0ebff; }

    /* Form */
    .form-row { margin-bottom: 16px; }
    label { display: block; font-size: 0.82rem; font-weight: 700; color: #c9b8ff; margin-bottom: 5px; }
    input[type=text], input[type=email], input[type=datetime-local], select, textarea {
      width: 100%; padding: 10px 14px; border-radius: 8px; border: 2px solid #4b35a0;
      background: #160f35; color: #f0ebff; font-size: 0.9rem; font-family: inherit;
    }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #f5a623; }
    .hint { font-size: 0.74rem; color: #6b5ca5; margin-top: 4px; }
    .hidden { display: none; }
  </style>
</head>
<body>

<div class="topbar">
  <div class="nav-links">
    <a class="nav-link" href="index.php">📸 Dashboard</a>
    <a class="nav-link active" href="parties.php">🎉 Parties</a>
    <a class="nav-link" href="superadmin_settings.php">⚙️ Settings</a>
  </div>
  <a class="signout" href="index.php?logout=<?= urlencode($csrf) ?>">Sign out</a>
</div>

<div class="page">
  <div class="page-header">
    <h1>🎉 Party Management</h1>
    <button class="btn btn-primary" id="btn-new-party">+ New Party</button>
  </div>

  <?php if ($success !== ''): ?>
    <div class="msg msg-ok"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error !== '' && !$party_modal_open): ?>
    <div class="msg msg-err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── Party list ── -->
  <?php if (empty($parties)): ?>
    <p style="color:#4a3580;font-size:.9rem;">No parties yet. Click <strong>New Party</strong> to create one.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="party-table">
      <thead>
        <tr>
          <th>Party Name</th>
          <th>Slug / URL</th>
          <th>Organiser</th>
          <th>Event Date</th>
          <th>Photos</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parties as $pt):
            $is_expired = (int)$pt['retention_days'] > 0
                && time() > strtotime($pt['created_at']) + (int)$pt['retention_days'] * 86400;
        ?>
        <tr>
          <td><?= htmlspecialchars($pt['party_name']) ?></td>
          <td>
            <span class="slug-badge"><?= htmlspecialchars($pt['slug']) ?></span><br>
            <a class="guest-link" href="<?= BASE_URL ?>/party?id=<?= urlencode($pt['slug']) ?>" target="_blank">Guest page ↗</a>
          </td>
          <td><?= htmlspecialchars($pt['organizer_email']) ?></td>
          <td><?= $pt['event_datetime'] ? htmlspecialchars(date('d M Y H:i', strtotime($pt['event_datetime']))) : '—' ?></td>
          <td style="text-align:center;"><?= (int)$pt['photo_count'] ?></td>
          <td>
            <?php if ($is_expired): ?>
              <span class="active-pill pill-remove" title="Retention period of <?= (int)$pt['retention_days'] ?> days exceeded">Remove</span>
            <?php else: ?>
              <span class="active-pill <?= $pt['is_active'] ? 'pill-active' : 'pill-inactive' ?>">
                <?= $pt['is_active'] ? 'Live' : 'Paused' ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <div class="action-cell">
              <!-- Toggle active -->
              <form class="inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="toggle_party">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <input type="hidden" name="active"   value="<?= $pt['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn-sm <?= $pt['is_active'] ? 'btn-toggle-disable' : 'btn-toggle-enable' ?>">
                  <?= $pt['is_active'] ? 'Pause' : 'Enable' ?>
                </button>
              </form>

              <!-- QR code -->
              <a class="btn-sm btn-ghost" href="qrcode.php?party=<?= urlencode($pt['slug']) ?>">QR</a>

              <!-- Resend invite -->
              <form class="inline-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="resend_invite">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <button type="submit" class="btn-sm btn-ghost" title="Resend party invite email to organiser">Resend invite</button>
              </form>

              <!-- Impersonate organiser -->
              <form class="inline-form" method="post" action="impersonate.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="organiser_id" value="<?= (int)$pt['organizer_id'] ?>">
                <button type="submit" class="btn-sm btn-ghost" title="Log in as this party's organiser">👁 View as</button>
              </form>

              <!-- Delete -->
              <form class="inline-form" method="post" data-confirm="Delete party '<?= htmlspecialchars(addslashes($pt['party_name'])) ?>'? This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"   value="delete_party">
                <input type="hidden" name="party_id" value="<?= (int)$pt['id'] ?>">
                <button type="submit" class="btn-sm btn-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── New Party Modal ── -->
<div class="modal-overlay" id="modal-overlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header">
      <h2 id="modal-title">🎉 Create New Party</h2>
      <button class="modal-close" id="btn-close-modal" aria-label="Close">&times;</button>
    </div>

    <?php if ($party_modal_open && $error !== ''): ?>
      <div class="msg msg-err" style="margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create_party">

      <div class="form-row">
        <label for="party_name">Party Name *</label>
        <input type="text" id="party_name" name="party_name" required maxlength="200"
               value="<?= htmlspecialchars($_POST['party_name'] ?? '') ?>">
      </div>

      <div class="form-row">
        <label for="slug">URL Slug *</label>
        <input type="text" id="slug" name="slug" required maxlength="60" pattern="[a-z0-9\-]+"
               value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>"
               placeholder="e.g. smith-wedding-2026">
        <p class="hint">Lowercase letters, numbers, hyphens only. Guest URL: <?= BASE_URL ?>/party?id=<em>your-slug</em></p>
      </div>

      <div class="form-row">
        <label for="organiser_id">Organiser *</label>
        <select id="organiser_id" name="organiser_id" required>
          <option value="">— Select organiser —</option>
          <?php foreach ($organisers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
                    <?= (($_POST['organiser_id'] ?? '') === (string)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['email']) ?>
            </option>
          <?php endforeach; ?>
          <option value="new" <?= (($_POST['organiser_id'] ?? '') === 'new') ? 'selected' : '' ?>>+ New organiser...</option>
        </select>
      </div>

      <div class="form-row <?= (($_POST['organiser_id'] ?? '') !== 'new') ? 'hidden' : '' ?>" id="new-organiser-row">
        <label for="new_organiser_email">New Organiser Email *</label>
        <input type="email" id="new_organiser_email" name="new_organiser_email"
               value="<?= htmlspecialchars($_POST['new_organiser_email'] ?? '') ?>"
               placeholder="organiser@example.com">
        <p class="hint">An account will be created and the welcome email will include a link for them to set their password.</p>
      </div>

      <div class="form-row">
        <label for="event_datetime">Event Date &amp; Time</label>
        <input type="datetime-local" id="event_datetime" name="event_datetime"
               value="<?= htmlspecialchars($_POST['event_datetime'] ?? '') ?>">
      </div>

      <div class="form-row">
        <label for="party_info">Party Info Text</label>
        <textarea id="party_info" name="party_info" maxlength="1000"
                  placeholder="Shown on the guest page below the party name"><?= htmlspecialchars($_POST['party_info'] ?? '') ?></textarea>
      </div>

      <div class="form-row">
        <label for="notify_email">Notification Email</label>
        <input type="email" id="notify_email" name="notify_email"
               value="<?= htmlspecialchars($_POST['notify_email'] ?? '') ?>"
               placeholder="Receives an email on each new upload">
      </div>

      <div class="form-row">
        <label for="retention_days">Retention Period (days)</label>
        <input type="number" id="retention_days" name="retention_days" min="1" max="<?= $ret_max ?>"
               value="<?= (int)($_POST['retention_days'] ?? $ret_default) ?>">
        <p class="hint">Photos will be flagged for removal after this many days. Platform maximum: <?= $ret_max ?> days.</p>
      </div>

      <button type="submit" class="btn btn-primary">Create Party</button>
    </form>
  </div>
</div>

<script nonce="<?= $nonce ?>">
(function () {
  var overlay  = document.getElementById('modal-overlay');

  function openModal() {
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  document.getElementById('btn-new-party').addEventListener('click', openModal);
  document.getElementById('btn-close-modal').addEventListener('click', closeModal);

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  var organiserSelect = document.getElementById('organiser_id');
  organiserSelect.addEventListener('change', function () {
    var row = document.getElementById('new-organiser-row');
    var inp = document.getElementById('new_organiser_email');
    if (this.value === 'new') {
      row.classList.remove('hidden');
      inp.required = true;
    } else {
      row.classList.add('hidden');
      inp.required = false;
    }
  });

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  <?php if ($party_modal_open): ?>
  openModal();
  <?php endif; ?>
}());
</script>

</body>
</html>
