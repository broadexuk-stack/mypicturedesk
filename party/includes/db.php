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
    string $uploaded_by
): void {
    $sql = 'INSERT INTO photos
              (party_id, uuid, original_extension, ip_hash, ip_display, uploaded_by)
            VALUES (:party_id, :uuid, :ext, :ip_hash, :ip_display, :uploaded_by)';
    $st = db_pdo()->prepare($sql);
    $st->execute([
        ':party_id'    => $party_id,
        ':uuid'        => $uuid,
        ':ext'         => $ext,
        ':ip_hash'     => $ip_hash,
        ':ip_display'  => $ip_display,
        ':uploaded_by' => $uploaded_by !== '' ? $uploaded_by : null,
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
        $sql = 'SELECT p.*, pt.party_name FROM photos p
                JOIN mpd_parties pt ON pt.id = p.party_id
                WHERE p.party_id = :party_id
                ORDER BY p.upload_timestamp DESC
                LIMIT :lim OFFSET :off';
        $st = db_pdo()->prepare($sql);
        $st->bindValue(':party_id', $party_id, PDO::PARAM_INT);
    } else {
        $sql = 'SELECT p.*, pt.party_name FROM photos p
                JOIN mpd_parties pt ON pt.id = p.party_id
                ORDER BY p.upload_timestamp DESC
                LIMIT :lim OFFSET :off';
        $st = db_pdo()->prepare($sql);
    }
    $st->bindValue(':lim',  $limit,  PDO::PARAM_INT);
    $st->bindValue(':off',  $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function db_count_all_photos(?int $party_id = null): int {
    if ($party_id !== null) {
        $st = db_pdo()->prepare('SELECT COUNT(*) FROM photos WHERE party_id = :pid');
        $st->execute([':pid' => $party_id]);
    } else {
        $st = db_pdo()->query('SELECT COUNT(*) FROM photos');
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
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
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
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
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
        'SELECT id, email, role, is_active, created_at, last_login_at
         FROM mpd_users ORDER BY created_at DESC'
    )->fetchAll();
}

// ── F. mpd_parties management ────────────────────────────────

function mpd_get_party_by_slug(string $slug): array|false {
    $st = db_pdo()->prepare('SELECT * FROM mpd_parties WHERE slug = :slug LIMIT 1');
    $st->execute([':slug' => $slug]);
    return $st->fetch();
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
        'SELECT p.*, u.email AS organizer_email
         FROM mpd_parties p
         JOIN mpd_users u ON u.id = p.organizer_id
         ORDER BY p.created_at DESC'
    )->fetchAll();
}

function mpd_create_party(
    string  $slug,
    string  $party_name,
    int     $organizer_id,
    int     $created_by,
    ?string $event_datetime = null,
    ?string $party_info     = null,
    ?string $notify_email   = null
): int {
    $sql = 'INSERT INTO mpd_parties
              (slug, party_name, organizer_id, created_by, event_datetime, party_info, notify_email)
            VALUES (:slug, :name, :oid, :cby, :edt, :info, :notify)';
    $st = db_pdo()->prepare($sql);
    $st->execute([
        ':slug'   => $slug,
        ':name'   => $party_name,
        ':oid'    => $organizer_id,
        ':cby'    => $created_by,
        ':edt'    => $event_datetime,
        ':info'   => $party_info,
        ':notify' => $notify_email,
    ]);
    return (int)db_pdo()->lastInsertId();
}

function mpd_update_party(int $id, array $fields): void {
    $allowed = ['party_name', 'event_datetime', 'party_info', 'notify_email', 'colour_theme'];
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
    $pdo = db_pdo();
    $pdo->prepare('DELETE FROM photos          WHERE party_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM upload_attempts WHERE party_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM mpd_parties     WHERE id       = :id')->execute([':id' => $id]);
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
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host       = $host;
            $mailer->Port       = $port;
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
            return true;
        } catch (Exception) {
            return false;
        }
    }

    // Fallback: PHP mail()
    if (function_exists('mail')) {
        $f        = $from !== '' ? $from : ini_get('sendmail_from');
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        if ($f) $headers .= "From: $fromName <$f>\r\n";
        return mail($to, $subject, $body_html, $headers);
    }

    return false;
}

// ── J. Email templates ───────────────────────────────────────

function mpd_default_email(string $key): string {
    return match ($key) {
        'email_welcome_body' =>
            "<p>Hi,</p>\n"
          . "<p>Your party gallery has been set up on MyPictureDesk.</p>\n"
          . "<ul>\n"
          . "<li><strong>Party name:</strong> {{party_name}}</li>\n"
          . "<li><strong>Guest URL:</strong> <a href=\"{{guest_url}}\">{{guest_url}}</a></li>\n"
          . "<li><strong>Admin panel:</strong> <a href=\"{{admin_url}}\">Log in to moderate photos</a></li>\n"
          . "</ul>\n"
          . "{{setpassword_block}}",
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
