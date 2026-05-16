<?php
declare(strict_types=1);

// ============================================================
// includes/db.php — Database layer for MyPictureDesk.
//
// Sections:
//   A. PDO connection
//   B. IP helpers
//   C. Photo CRUD (party-scoped)
//   D. Rate limiting (party-scoped)
//   E. mpd_users management
//   F. mpd_parties management
//   G. mpd_settings management
//   H. Directory helpers
//   I. Email helper
// ============================================================

// Load Composer autoloader (provides PHPMailer). Degrades gracefully
// if `composer install` has not been run yet.
(static function () {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
})();

// ── A. PDO connection ────────────────────────────────────────

function db_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ── B. IP helpers ────────────────────────────────────────────

function hash_ip(string $ip): string {
    return hash('sha256', $ip . IP_SALT);
}

function partial_ip(string $ip): string {
    if (str_contains($ip, ':')) {
        // IPv6 — show first 4 groups, mask the rest
        $parts = explode(':', $ip);
        $keep  = min(4, count($parts));
        return implode(':', array_slice($parts, 0, $keep)) . ':****';
    }
    // IPv4 — mask first octet
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return '*.' . implode('.', array_slice($parts, 1));
    }
    return $ip;
}

// ── C. Photo CRUD ────────────────────────────────────────────

function db_insert_photo(
    int    $party_id,
    string $uuid,
    string $ext,
    string $ip_hash,
    string $ip_display,
    string $uploaded_by,
    ?array $exif_data = null
): void {
    $sql = 'INSERT INTO photos
              (party_id, uuid, original_extension, ip_hash, ip_display, uploaded_by, exif_data)
            VALUES (:party_id, :uuid, :ext, :ip_hash, :ip_display, :uploaded_by, :exif_data)';
    $st = db_pdo()->prepare($sql);
    $st->execute([
        ':party_id'    => $party_id,
        ':uuid'        => $uuid,
        ':ext'         => $ext,
        ':ip_hash'     => $ip_hash,
        ':ip_display'  => $ip_display,
        ':uploaded_by' => $uploaded_by !== '' ? $uploaded_by : null,
        ':exif_data'   => $exif_data !== null ? json_encode($exif_data, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function db_get_photos(string $status, int $party_id): array {
    $sql = 'SELECT * FROM photos
            WHERE status = :status AND party_id = :party_id
            ORDER BY upload_timestamp DESC';
    $st = db_pdo()->prepare($sql);
    $st->execute([':status' => $status, ':party_id' => $party_id]);
    return $st->fetchAll();
}

function db_get_photo_by_uuid(string $uuid, int $party_id): array|false {
    $sql = 'SELECT * FROM photos WHERE uuid = :uuid AND party_id = :party_id LIMIT 1';
    $st = db_pdo()->prepare($sql);
    $st->execute([':uuid' => $uuid, ':party_id' => $party_id]);
    return $st->fetch();
}

function db_set_photo_cloudinary_id(string $uuid, int $party_id, string $public_id): void {
    $st = db_pdo()->prepare(
        'UPDATE photos SET cloudinary_public_id = :cid WHERE uuid = :uuid AND party_id = :party_id'
    );
    $st->execute([':cid' => $public_id, ':uuid' => $uuid, ':party_id' => $party_id]);
}

function db_set_photo_status(string $uuid, int $party_id, string $status): void {
    $col = match ($status) {
        'approved' => ', approved_at = NOW()',
        'rejected' => ', rejected_at = NOW()',
        default    => '',
    };
    $sql = "UPDATE photos SET status = :status $col
            WHERE uuid = :uuid AND party_id = :party_id";
    $st = db_pdo()->prepare($sql);
    $st->execute([':status' => $status, ':uuid' => $uuid, ':party_id' => $party_id]);
}

function db_count_pending(int $party_id): int {
    $st = db_pdo()->prepare(
        "SELECT COUNT(*) FROM photos WHERE party_id = :party_id AND status = 'pending'"
    );
    $st->execute([':party_id' => $party_id]);
    return (int)$st->fetchColumn();
}

function db_count_photos_by_status(int $party_id): array {
    $sql = 'SELECT status, COUNT(*) AS cnt FROM photos
            WHERE party_id = :party_id GROUP BY status';
    $st = db_pdo()->prepare($sql);
    $st->execute([':party_id' => $party_id]);
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'removed' => 0];
    foreach ($st->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
    return $counts;
}

// Super-admin global paginated photo view (all parties)
function db_get_photos_paginated(int $limit, int $offset, ?int $party_id = null): array {
    if ($party_id !== null) {
        $sql = "SELECT p.*, pt.party_name, pt.slug AS party_slug FROM photos p
                JOIN mpd_parties pt ON pt.id = p.party_id
                WHERE p.party_id = :party_id AND p.status != 'rejected'
                ORDER BY p.upload_timestamp DESC
                LIMIT :lim OFFSET :off";
        $st = db_pdo()->prepare($sql);
        $st->bindValue(':party_id', $party_id, PDO::PARAM_INT);
    } else {
        $sql = "SELECT p.*, pt.party_name, pt.slug AS party_slug FROM photos p
                JOIN mpd_parties pt ON pt.id = p.party_id
                WHERE p.status != 'rejected'
                ORDER BY p.upload_timestamp DESC
                LIMIT :lim OFFSET :off";
        $st = db_pdo()->prepare($sql);
    }
    $st->bindValue(':lim',  $limit,  PDO::PARAM_INT);
    $st->bindValue(':off',  $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function db_count_all_photos(?int $party_id = null): int {
    if ($party_id !== null) {
        $st = db_pdo()->prepare("SELECT COUNT(*) FROM photos WHERE party_id = :pid AND status != 'rejected'");
        $st->execute([':pid' => $party_id]);
    } else {
        $st = db_pdo()->query("SELECT COUNT(*) FROM photos WHERE status != 'rejected'");
    }
    return (int)$st->fetchColumn();
}

// ── D. Rate limiting ─────────────────────────────────────────

function db_check_rate_limit(int $party_id, string $ip_hash): bool {
    $window_seconds = RATE_LIMIT_WINDOW_HOURS * 3600;
    $sql = 'SELECT COUNT(*) FROM upload_attempts
            WHERE party_id = :party_id
              AND ip_hash   = :ip_hash
              AND attempted_at > NOW() - INTERVAL :secs SECOND';
    $st = db_pdo()->prepare($sql);
    $st->bindValue(':party_id', $party_id, PDO::PARAM_INT);
    $st->bindValue(':ip_hash',  $ip_hash);
    $st->bindValue(':secs',     $window_seconds, PDO::PARAM_INT);
    $st->execute();
    return (int)$st->fetchColumn() < RATE_LIMIT_UPLOADS;
}

function db_log_upload_attempt(int $party_id, string $ip_hash): void {
    $sql = 'INSERT INTO upload_attempts (party_id, ip_hash) VALUES (:party_id, :ip_hash)';
    $st = db_pdo()->prepare($sql);
    $st->execute([':party_id' => $party_id, ':ip_hash' => $ip_hash]);
}

// ── D2. Login brute-force protection ─────────────────────────
// Tracks failed admin login attempts per hashed email.
// Requires: CREATE TABLE mpd_login_attempts (
//   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//   email_hash VARCHAR(64) NOT NULL,
//   attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//   INDEX idx_email_time (email_hash, attempted_at)
// );

function db_is_login_locked(string $email_hash): bool {
    $st = db_pdo()->prepare(
        'SELECT COUNT(*) FROM mpd_login_attempts
         WHERE email_hash = :h AND attempted_at > NOW() - INTERVAL 15 MINUTE'
    );
    $st->execute([':h' => $email_hash]);
    return (int)$st->fetchColumn() >= 10;
}

function db_record_login_failure(string $email_hash): void {
    $st = db_pdo()->prepare(
        'INSERT INTO mpd_login_attempts (email_hash) VALUES (:h)'
    );
    $st->execute([':h' => $email_hash]);
}

function db_clear_login_failures(string $email_hash): void {
    $st = db_pdo()->prepare(
        'DELETE FROM mpd_login_attempts WHERE email_hash = :h'
    );
    $st->execute([':h' => $email_hash]);
}

// ── E. mpd_users management ──────────────────────────────────

function mpd_get_user_by_email(string $email): array|false {
    $st = db_pdo()->prepare('SELECT * FROM mpd_users WHERE email = :email LIMIT 1');
    $st->execute([':email' => $email]);
    return $st->fetch();
}

function mpd_get_user_by_id(int $id): array|false {
    $st = db_pdo()->prepare('SELECT * FROM mpd_users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    return $st->fetch();
}

function mpd_get_user_by_token(string $token): array|false {
    $st = db_pdo()->prepare(
        'SELECT * FROM mpd_users
         WHERE first_login_token = :token
           AND token_expires_at  > NOW()
           AND is_active         = 1
         LIMIT 1'
    );
    $st->execute([':token' => $token]);
    return $st->fetch();
}

function mpd_create_user(string $email, string $role = 'organizer'): int {
    $token   = bin2hex(random_bytes(32)); // 64 hex chars
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $sql = 'INSERT INTO mpd_users (email, role, first_login_token, token_expires_at)
            VALUES (:email, :role, :token, :expires)';
    $st = db_pdo()->prepare($sql);
    $st->execute([
        ':email'   => $email,
        ':role'    => $role,
        ':token'   => $token,
        ':expires' => $expires,
    ]);
    return (int)db_pdo()->lastInsertId();
}

function mpd_set_user_password(int $id, string $password_hash): void {
    $st = db_pdo()->prepare(
        'UPDATE mpd_users SET password_hash = :hash WHERE id = :id'
    );
    $st->execute([':hash' => $password_hash, ':id' => $id]);
}

function mpd_set_user_token(int $id): string {
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $st = db_pdo()->prepare(
        'UPDATE mpd_users
         SET first_login_token = :token, token_expires_at = :expires
         WHERE id = :id'
    );
    $st->execute([':token' => $token, ':expires' => $expires, ':id' => $id]);
    return $token;
}

function mpd_deactivate_token(int $id): void {
    $st = db_pdo()->prepare(
        'UPDATE mpd_users
         SET first_login_token = NULL, token_expires_at = NULL
         WHERE id = :id'
    );
    $st->execute([':id' => $id]);
}

function mpd_update_last_login(int $id): void {
    $st = db_pdo()->prepare(
        'UPDATE mpd_users SET last_login_at = NOW() WHERE id = :id'
    );
    $st->execute([':id' => $id]);
}

function mpd_set_user_active(int $id, bool $active): void {
    $st = db_pdo()->prepare(
        'UPDATE mpd_users SET is_active = :active WHERE id = :id'
    );
    $st->execute([':active' => (int)$active, ':id' => $id]);
}

function mpd_get_all_users(): array {
    return db_pdo()->query(
        "SELECT u.id, u.email, u.role, u.is_active, u.created_at, u.last_login_at,
                (u.password_hash IS NOT NULL) AS has_password,
                CASE WHEN u.first_login_token IS NOT NULL
                          AND u.token_expires_at > NOW() THEN 1 ELSE 0 END AS has_pending_invite,
                COUNT(p.id) AS party_count
         FROM mpd_users u
         LEFT JOIN mpd_parties p ON p.organizer_id = u.id
         GROUP BY u.id
         ORDER BY u.created_at DESC"
    )->fetchAll();
}

function mpd_delete_user(int $id): void {
    db_pdo()->prepare('DELETE FROM mpd_users WHERE id = :id')->execute([':id' => $id]);
}

// ── F. mpd_parties management ────────────────────────────────

function mpd_get_party_by_slug(string $slug): array|false {
    $st = db_pdo()->prepare('SELECT * FROM mpd_parties WHERE slug = :slug LIMIT 1');
    $st->execute([':slug' => $slug]);
    return $st->fetch();
}

function mpd_generate_unique_slug(int $len = 6): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $max   = strlen($chars) - 1;
    do {
        $slug = '';
        for ($i = 0; $i < $len; $i++) {
            $slug .= $chars[random_int(0, $max)];
        }
    } while (mpd_get_party_by_slug($slug) !== false);
    return $slug;
}

function mpd_get_party_by_id(int $id): array|false {
    $st = db_pdo()->prepare('SELECT * FROM mpd_parties WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    return $st->fetch();
}

function mpd_get_parties_for_organizer(int $organizer_id): array {
    $st = db_pdo()->prepare(
        'SELECT * FROM mpd_parties WHERE organizer_id = :oid ORDER BY created_at DESC'
    );
    $st->execute([':oid' => $organizer_id]);
    return $st->fetchAll();
}

function mpd_get_all_parties(): array {
    return db_pdo()->query(
        "SELECT p.*, u.email AS organizer_email,
                (SELECT COUNT(*) FROM photos ph WHERE ph.party_id = p.id AND ph.status != 'rejected') AS photo_count
         FROM mpd_parties p
         JOIN mpd_users u ON u.id = p.organizer_id
         ORDER BY p.created_at DESC"
    )->fetchAll();
}

function mpd_create_party(
    string  $slug,
    string  $party_name,
    int     $organizer_id,
    int     $created_by,
    ?string $event_datetime      = null,
    ?string $party_info          = null,
    ?string $notify_email        = null,
    int     $retention_days      = 30,
    ?string $organiser_name      = null,
    bool    $timer_camera        = false,
    bool    $cloudinary_enabled  = false,
    bool    $auto_approve        = false
): int {
    $sql = 'INSERT INTO mpd_parties
              (slug, party_name, organiser_name, organizer_id, created_by, event_datetime, party_info, notify_email, retention_days, timer_camera_enabled, cloudinary_enabled, auto_approve)
            VALUES (:slug, :name, :oname, :oid, :cby, :edt, :info, :notify, :ret, :timer, :cloud, :aa)';
    $st = db_pdo()->prepare($sql);
    $st->execute([
        ':slug'   => $slug,
        ':name'   => $party_name,
        ':oname'  => $organiser_name,
        ':oid'    => $organizer_id,
        ':cby'    => $created_by,
        ':edt'    => $event_datetime,
        ':info'   => $party_info,
        ':notify' => $notify_email,
        ':ret'    => $retention_days,
        ':timer'  => (int)$timer_camera,
        ':cloud'  => (int)$cloudinary_enabled,
        ':aa'     => (int)$auto_approve,
    ]);
    return (int)db_pdo()->lastInsertId();
}

function mpd_update_party(int $id, array $fields): void {
    $allowed = ['party_name', 'organiser_name', 'event_datetime', 'party_info', 'notify_email', 'colour_theme', 'retention_days', 'timer_camera_enabled', 'cloudinary_enabled', 'auto_approve'];
    $sets    = [];
    $params  = [':id' => $id];
    foreach ($fields as $col => $val) {
        if (!in_array($col, $allowed, true)) continue;
        $sets[]       = "`$col` = :$col";
        $params[":$col"] = $val;
    }
    if (empty($sets)) return;
    db_pdo()->prepare(
        'UPDATE mpd_parties SET ' . implode(', ', $sets) . ' WHERE id = :id'
    )->execute($params);
}

function mpd_toggle_party_active(int $id, bool $active): void {
    $st = db_pdo()->prepare(
        'UPDATE mpd_parties SET is_active = :active WHERE id = :id'
    );
    $st->execute([':active' => (int)$active, ':id' => $id]);
}

function mpd_delete_party(int $id): void {
    // Fetch slug before deleting so we can remove the upload directory
    $st = db_pdo()->prepare('SELECT slug FROM mpd_parties WHERE id = :id LIMIT 1');
    $st->execute([':id' => $id]);
    $slug = (string)($st->fetchColumn() ?: '');

    $pdo = db_pdo();
    $pdo->prepare('DELETE FROM photos          WHERE party_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM upload_attempts WHERE party_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM mpd_parties     WHERE id       = :id')->execute([':id' => $id]);

    // Remove upload directory tree
    if ($slug !== '') {
        $dir = rtrim(UPLOADS_BASE, '/') . '/' . $slug;
        if (is_dir($dir)) {
            mpd_rmdir_recursive($dir);
        }
    }
}

function mpd_rmdir_recursive(string $dir): void {
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? mpd_rmdir_recursive($path) : unlink($path);
    }
    rmdir($dir);
}

// ── G. mpd_settings management ───────────────────────────────

function mpd_get_setting(string $key): ?string {
    $st = db_pdo()->prepare(
        'SELECT setting_value FROM mpd_settings WHERE setting_key = :key LIMIT 1'
    );
    $st->execute([':key' => $key]);
    $val = $st->fetchColumn();
    return $val !== false ? (string)$val : null;
}

function mpd_get_all_settings(): array {
    $out = [];
    foreach (db_pdo()->query(
        'SELECT setting_key, setting_value FROM mpd_settings'
    )->fetchAll() as $r) {
        $out[$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

function mpd_update_setting(string $key, ?string $value): void {
    db_pdo()->prepare(
        'INSERT INTO mpd_settings (setting_key, setting_value) VALUES (:key, :val)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([':key' => $key, ':val' => $value]);
}

// ── H. Directory helpers ─────────────────────────────────────

function mpd_party_dirs(string $slug): array {
    $base = rtrim(UPLOADS_BASE, '/') . '/' . $slug;
    return [
        'quarantine'        => $base . '/quarantine',
        'quarantine_thumbs' => $base . '/quarantine/thumbs',
        'gallery'           => $base . '/gallery',
        'gallery_thumbs'    => $base . '/gallery/thumbs',
    ];
}

function mpd_ensure_party_dirs(string $slug): array {
    $dirs = mpd_party_dirs($slug);
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }
    return $dirs;
}

// ── I. Email helper ──────────────────────────────────────────

function mpd_send_email(string $to, string $subject, string $body_html): bool {
    $s = mpd_get_all_settings();

    $host     = $s['smtp_host']      ?? '';
    $port     = (int)($s['smtp_port'] ?? 587);
    $user     = $s['smtp_user']      ?? '';
    $pass     = $s['smtp_pass']      ?? '';
    $from     = $s['smtp_from']      ?? '';
    $fromName = $s['smtp_from_name'] ?? 'MyPictureDesk';
    $secure   = $s['smtp_secure']    ?? 'tls';

    // PHPMailer (recommended — install via Composer)
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && $host !== '') {
        $mailer   = new PHPMailer\PHPMailer\PHPMailer(true);
        $smtpLog  = [];
        try {
            $mailer->isSMTP();
            $mailer->Host        = $host;
            $mailer->Port        = $port;
            $mailer->Timeout     = 15;
            $mailer->SMTPDebug   = function_exists('mpd_log') ? 2 : 0;
            $mailer->Debugoutput = static function (string $str) use (&$smtpLog): void {
                $smtpLog[] = rtrim($str);
            };
            $mailer->SMTPAuth   = ($user !== '');
            $mailer->Username   = $user;
            $mailer->Password   = $pass;
            $mailer->SMTPSecure = $secure === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->setFrom($from !== '' ? $from : $user, $fromName);
            $mailer->addAddress($to);
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body    = $body_html;
            $mailer->send();
            if (function_exists('mpd_log')) {
                mpd_log('email.sent', [
                    'email.to'      => $to,
                    'email.subject' => $subject,
                    'email.via'     => 'smtp',
                ]);
            }
            return true;
        } catch (Exception $e) {
            if (function_exists('mpd_log')) {
                mpd_log('email.failed', [
                    'email.to'      => $to,
                    'email.subject' => $subject,
                    'error.message' => $e->getMessage(),
                    'smtp.log'      => implode("\n", $smtpLog),
                ]);
            }
            return false;
        }
    }

    // Fallback: PHP mail()
    if (function_exists('mail')) {
        $f        = $from !== '' ? $from : ini_get('sendmail_from');
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($f) $headers .= "From: $fromName <$f>\r\n";
        $sent = mail($to, $subject, $body_html, $headers);
        if (function_exists('mpd_log')) {
            mpd_log($sent ? 'email.sent' : 'email.failed', [
                'email.to'      => $to,
                'email.subject' => $subject,
                'email.via'     => 'php_mail',
            ]);
        }
        return $sent;
    }

    if (function_exists('mpd_log')) {
        mpd_log('email.failed', [
            'email.to'      => $to,
            'email.subject' => $subject,
            'error.message' => 'No mail transport available (PHPMailer not loaded, mail() not available)',
        ]);
    }
    return false;
}

// ── J. Email templates ───────────────────────────────────────

function mpd_default_email(string $key): string {
    return match ($key) {
        'email_welcome_body' =>
            '<div style="background:#1a1035;padding:40px 20px;margin:0;">'
          . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
          . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'

          . '<tr><td align="center" style="padding-bottom:24px;">'
          . '<span style="color:#f5a623;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">MyPictureDesk</span>'
          . '</td></tr>'

          . '<tr><td style="background:#2d1b69;border-radius:16px;padding:36px 32px;">'

          . '<h1 style="color:#f0ebff;font-family:Arial,sans-serif;font-size:22px;font-weight:900;margin:0 0 6px;">&#127881; Your party gallery is ready!</h1>'
          . '<p style="color:#9c7fff;font-family:Arial,sans-serif;font-size:14px;margin:0 0 24px;">Your party has been set up on MyPictureDesk. Here are your details.</p>'

          . '{{setpassword_block}}'

          . '<p style="color:#c9b8ff;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin:0 0 5px;">Party Name</p>'
          . '<div style="background:#160f35;border:2px solid #4b35a0;border-radius:8px;padding:10px 14px;margin-bottom:20px;">'
          . '<span style="color:#f0ebff;font-family:Arial,sans-serif;font-size:15px;">{{party_name}}</span>'
          . '</div>'

          . '<p style="color:#c9b8ff;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin:0 0 5px;">Guest URL</p>'
          . '<div style="background:#160f35;border:2px solid #4b35a0;border-radius:8px;padding:10px 14px;margin-bottom:5px;">'
          . '<a href="{{guest_url}}" style="color:#9c7fff;font-family:Arial,sans-serif;font-size:14px;word-break:break-all;text-decoration:none;">{{guest_url}}</a>'
          . '</div>'
          . '<p style="color:#6b5ca5;font-family:Arial,sans-serif;font-size:12px;margin:4px 0 20px;">Share this link with your guests so they can upload photos.</p>'

          . '<p style="color:#c9b8ff;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;margin:0 0 5px;">Admin Panel</p>'
          . '<div style="background:#160f35;border:2px solid #4b35a0;border-radius:8px;padding:10px 14px;margin-bottom:28px;">'
          . '<a href="{{admin_url}}" style="color:#9c7fff;font-family:Arial,sans-serif;font-size:14px;word-break:break-all;text-decoration:none;">{{admin_url}}</a>'
          . '</div>'

          . '<div style="text-align:center;">'
          . '<a href="{{admin_url}}" style="display:inline-block;background:#f5a623;color:#1a1035;font-family:Arial,sans-serif;font-size:16px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:10px;">Log in to Admin Panel</a>'
          . '</div>'

          . '</td></tr>'

          . '<tr><td align="center" style="padding-top:20px;">'
          . '<p style="color:#4a3580;font-family:Arial,sans-serif;font-size:12px;margin:0;">MyPictureDesk &mdash; Party Photo Sharing</p>'
          . '</td></tr>'

          . '</table></td></tr></table></div>',
        'email_notify_body' =>
            "<p>A new photo has been uploaded to <strong>{{party_name}}</strong> and is awaiting approval.</p>\n"
          . "<ul>\n"
          . "<li><strong>Name:</strong> {{uploaded_by}}</li>\n"
          . "<li><strong>IP (partial):</strong> {{ip_display}}</li>\n"
          . "<li><strong>Time:</strong> {{upload_time}} UTC</li>\n"
          . "</ul>\n"
          . "<p><a href=\"{{admin_url}}\">Review in admin panel</a></p>",
        default => '',
    };
}

function mpd_render_email(string $key, array $vars): string {
    $tpl = mpd_get_setting($key);
    if ($tpl === null || trim($tpl) === '') {
        $tpl = mpd_default_email($key);
    }
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{{' . $k . '}}', (string)$v, $tpl);
    }
    return $tpl;
}

// ── K. Print templates ───────────────────────────────────────

function mpd_default_print_template(string $key): string {
    return match ($key) {
        'print_a4_body' => <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>QR Code – {{party_name}}</title>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    background: #fff;
    display: flex;
    flex-direction: column;
  }
  .card {
    flex: 1;
    border: 3pt solid #000;
    border-radius: 8mm;
    padding: 10mm 14mm 8mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
  }
  .brand {
    font-size: 8pt;
    font-weight: bold;
    letter-spacing: 0.35em;
    text-transform: uppercase;
    color: #888;
    margin-bottom: 5mm;
  }
  h1 {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 32pt;
    font-weight: bold;
    line-height: 1.2;
    margin-bottom: 8mm;
  }
  .qr-wrap {
    display: inline-block;
    width: 108mm;
    padding: 4mm;
    border: 1pt solid #ddd;
    border-radius: 4mm;
    margin-bottom: 8mm;
  }
  .qr-wrap svg { width: 100%; height: auto; display: block; }
  .divider {
    display: flex;
    align-items: center;
    gap: 3mm;
    width: 108mm;
    margin-bottom: 8mm;
  }
  .divider-line { flex: 1; height: 0.5pt; background: #bbb; }
  .divider-dot  { width: 2mm; height: 2mm; background: #000; border-radius: 50%; flex-shrink: 0; }
  .code-label {
    font-size: 8pt;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    margin-bottom: 3mm;
  }
  .code-value {
    display: inline-block;
    font-family: 'Courier New', Courier, monospace;
    font-size: 26pt;
    font-weight: bold;
    letter-spacing: 0.3em;
    border: 2pt solid #000;
    padding: 2mm 8mm;
    border-radius: 3mm;
    margin-bottom: 8mm;
  }
  .instruction {
    font-size: 10.5pt;
    line-height: 1.7;
    color: #444;
    margin-bottom: 8mm;
  }
  .footer {
    font-size: 6.5pt;
    color: #aaa;
    word-break: break-all;
    border-top: 0.5pt solid #ddd;
    padding-top: 4mm;
    width: 100%;
  }
</style>
</head><body>
<div class="card">
  <p class="brand">PartyPix</p>
  <h1>{{party_name}}</h1>
  <div class="qr-wrap">{{qr_svg}}</div>
  <div class="divider">
    <span class="divider-line"></span>
    <span class="divider-dot"></span>
    <span class="divider-line"></span>
  </div>
  <p class="code-label">Your PartyPix Code is</p>
  <p class="code-value">{{slug}}</p>
  <p class="instruction">
    Point your phone camera at the QR code above<br>
    to upload your photos to the gallery instantly.
  </p>
  <p class="footer">{{guest_url}}</p>
</div>
</body></html>
HTML,
        'print_label_body' => <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>QR Label – {{party_name}}</title>
<style>
  @page { size: 6in 4in; margin: 4mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; overflow: hidden; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
  .layout { display: flex; align-items: center; height: 100%; gap: 5mm; padding: 1mm; overflow: hidden; }
  .qr-col { flex: 0 0 68mm; }
  .qr-col svg { width: 68mm; height: 68mm; display: block; }
  .text-col { flex: 1; text-align: left; overflow: hidden; }
  h1 { font-size: 15pt; font-weight: 900; margin-bottom: 2mm; line-height: 1.2; }
  .party-code { font-size: 10pt; font-weight: bold; margin-bottom: 3mm; line-height: 1.4; }
  .instruction { font-size: 8.5pt; margin-bottom: 3mm; line-height: 1.4; color: #333; }
  .guest-url { font-size: 6.5pt; color: #666; word-break: break-all; border-top: 0.5pt solid #ccc; padding-top: 2mm; }
</style>
</head><body>
<div class="layout">
  <div class="qr-col">{{qr_svg}}</div>
  <div class="text-col">
    <h1>{{party_name}}</h1>
    <p class="party-code">Your PartyPix Code is:<br><strong>{{slug}}</strong></p>
    <p class="instruction">Scan the QR code to upload your photos to the gallery.</p>
    <p class="guest-url">{{guest_url}}</p>
  </div>
</div>
</body></html>
HTML,
        default => '',
    };
}

function mpd_render_print_template(string $key, array $vars): string {
    $tpl = mpd_get_setting($key);
    if ($tpl === null || trim($tpl) === '') {
        $tpl = mpd_default_print_template($key);
    }
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{{' . $k . '}}', (string)$v, $tpl);
    }
    return $tpl;
}
