<?php
declare(strict_types=1);

// ============================================================
// includes/db.php
// Database abstraction layer.
// Switches transparently between MySQL (PDO) and flat JSON files
// based on the USE_DATABASE constant in config.php.
// ============================================================

// --------------- PDO connection (singleton) ---------------

function db_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// --------------- IP helpers ---------------

function hash_ip(string $ip): string
{
    return hash('sha256', $ip . IP_SALT);
}

function partial_ip(string $ip): string
{
    if (strpos($ip, ':') !== false) {
        // IPv6 — mask first four groups
        $parts = explode(':', $ip);
        $keep  = array_slice($parts, max(0, count($parts) - 4));
        return '…:' . implode(':', $keep);
    }
    // IPv4 — omit first octet
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return '*.' . implode('.', array_slice($parts, 1));
    }
    return $ip;
}

// --------------- Photo CRUD ---------------

/**
 * Insert a new photo record (status = pending).
 * Returns the uuid on success, false on failure.
 */
function db_insert_photo(
    string $uuid,
    string $extension,
    string $ip_hash,
    string $ip_display
): bool {
    if (USE_DATABASE) {
        try {
            $pdo  = db_pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO photos (uuid, original_extension, ip_hash, ip_display)
                 VALUES (:uuid, :ext, :ip_hash, :ip_display)'
            );
            return $stmt->execute([
                ':uuid'       => $uuid,
                ':ext'        => $extension,
                ':ip_hash'    => $ip_hash,
                ':ip_display' => $ip_display,
            ]);
        } catch (PDOException $e) {
            error_log('db_insert_photo: ' . $e->getMessage());
            return false;
        }
    }

    // --- Flat JSON fallback ---
    return json_photo_upsert([
        'uuid'               => $uuid,
        'original_extension' => $extension,
        'upload_timestamp'   => date('Y-m-d H:i:s'),
        'ip_hash'            => $ip_hash,
        'ip_display'         => $ip_display,
        'status'             => 'pending',
        'approved_at'        => null,
        'rejected_at'        => null,
    ]);
}

/**
 * Return an array of photos filtered by status, newest first.
 * $status: 'pending' | 'approved' | 'rejected' | 'all'
 */
function db_get_photos(string $status = 'approved'): array
{
    if (USE_DATABASE) {
        try {
            $pdo = db_pdo();
            if ($status === 'all') {
                $stmt = $pdo->query(
                    'SELECT * FROM photos ORDER BY upload_timestamp DESC'
                );
            } else {
                $stmt = $pdo->prepare(
                    'SELECT * FROM photos WHERE status = :status ORDER BY upload_timestamp DESC'
                );
                $stmt->execute([':status' => $status]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('db_get_photos: ' . $e->getMessage());
            return [];
        }
    }

    // --- Flat JSON fallback ---
    $photos = json_photos_read();
    if ($status !== 'all') {
        $photos = array_filter($photos, fn($p) => $p['status'] === $status);
    }
    usort($photos, fn($a, $b) => strcmp($b['upload_timestamp'], $a['upload_timestamp']));
    return array_values($photos);
}

/**
 * Fetch a single photo by UUID.
 */
function db_get_photo(string $uuid): ?array
{
    if (USE_DATABASE) {
        try {
            $stmt = db_pdo()->prepare('SELECT * FROM photos WHERE uuid = :uuid');
            $stmt->execute([':uuid' => $uuid]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('db_get_photo: ' . $e->getMessage());
            return null;
        }
    }

    $photos = json_photos_read();
    foreach ($photos as $p) {
        if ($p['uuid'] === $uuid) return $p;
    }
    return null;
}

/**
 * Update photo status. $status: 'approved' | 'rejected'
 */
function db_update_photo_status(string $uuid, string $status): bool
{
    $now = date('Y-m-d H:i:s');

    if (USE_DATABASE) {
        try {
            if ($status === 'approved') {
                $sql = 'UPDATE photos SET status = :s, approved_at = :now WHERE uuid = :uuid';
            } else {
                $sql = 'UPDATE photos SET status = :s, rejected_at = :now WHERE uuid = :uuid';
            }
            $stmt = db_pdo()->prepare($sql);
            return $stmt->execute([':s' => $status, ':now' => $now, ':uuid' => $uuid]);
        } catch (PDOException $e) {
            error_log('db_update_photo_status: ' . $e->getMessage());
            return false;
        }
    }

    // --- Flat JSON fallback ---
    return json_photos_update($uuid, function (array $p) use ($status, $now): array {
        $p['status'] = $status;
        if ($status === 'approved') $p['approved_at'] = $now;
        else                        $p['rejected_at']  = $now;
        return $p;
    });
}

// --------------- Dashboard counts ---------------

function db_counts(): array
{
    $today = date('Y-m-d');

    if (USE_DATABASE) {
        try {
            $pdo  = db_pdo();
            $rows = $pdo->query(
                "SELECT
                    SUM(status = 'pending')                                         AS pending,
                    SUM(status = 'approved')                                        AS approved,
                    SUM(status = 'rejected' AND DATE(rejected_at) = '$today')       AS rejected_today,
                    COUNT(*)                                                        AS total
                 FROM photos"
            )->fetch();
            return [
                'pending'        => (int)($rows['pending']        ?? 0),
                'approved'       => (int)($rows['approved']       ?? 0),
                'rejected_today' => (int)($rows['rejected_today'] ?? 0),
                'total'          => (int)($rows['total']          ?? 0),
            ];
        } catch (PDOException $e) {
            error_log('db_counts: ' . $e->getMessage());
        }
    }

    // --- Flat JSON fallback ---
    $photos = json_photos_read();
    $pending = $approved = $rejected_today = 0;
    foreach ($photos as $p) {
        if ($p['status'] === 'pending')  $pending++;
        if ($p['status'] === 'approved') $approved++;
        if ($p['status'] === 'rejected' && isset($p['rejected_at'])
            && str_starts_with($p['rejected_at'], $today)) {
            $rejected_today++;
        }
    }
    return [
        'pending'        => $pending,
        'approved'       => $approved,
        'rejected_today' => $rejected_today,
        'total'          => count($photos),
    ];
}

// --------------- Rate limiting ---------------

/**
 * Returns true if the IP is within the allowed upload limit.
 * Also records the attempt when $record = true.
 */
function db_check_rate_limit(string $ip_hash, bool $record = false): bool
{
    $window_seconds = RATE_LIMIT_WINDOW_HOURS * 3600;
    $limit          = RATE_LIMIT_UPLOADS;

    if (USE_DATABASE) {
        try {
            $pdo = db_pdo();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM upload_attempts
                 WHERE ip_hash = :h AND attempted_at > NOW() - INTERVAL :s SECOND'
            );
            $stmt->execute([':h' => $ip_hash, ':s' => $window_seconds]);
            $count = (int)$stmt->fetchColumn();

            if ($count >= $limit) return false;

            if ($record) {
                $pdo->prepare('INSERT INTO upload_attempts (ip_hash) VALUES (:h)')
                    ->execute([':h' => $ip_hash]);
            }
            return true;
        } catch (PDOException $e) {
            error_log('db_check_rate_limit: ' . $e->getMessage());
            return true; // fail open on DB error
        }
    }

    // --- Flat JSON fallback ---
    return json_rate_check($ip_hash, $window_seconds, $limit, $record);
}

// --------------- Flat-file JSON helpers ---------------

function json_photos_path(): string { return DATA_DIR . '/photos.json'; }
function json_rate_path():   string { return DATA_DIR . '/rate_limits.json'; }

function json_read(string $path): array
{
    if (!file_exists($path)) return [];
    $fp  = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($raw ?: '[]', true) ?? [];
}

function json_write(string $path, array $data): bool
{
    $fp = fopen($path, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function json_photos_read(): array { return json_read(json_photos_path()); }

function json_photo_upsert(array $photo): bool
{
    $photos   = json_photos_read();
    $photos[] = $photo;
    return json_write(json_photos_path(), $photos);
}

function json_photos_update(string $uuid, callable $fn): bool
{
    $path   = json_photos_path();
    $fp     = fopen($path, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $raw    = stream_get_contents($fp);
    $photos = json_decode($raw ?: '[]', true) ?? [];
    foreach ($photos as &$p) {
        if ($p['uuid'] === $uuid) $p = $fn($p);
    }
    unset($p);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($photos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function json_rate_check(string $ip_hash, int $window_sec, int $limit, bool $record): bool
{
    $path    = json_rate_path();
    $fp      = fopen($path, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $raw     = stream_get_contents($fp);
    $records = json_decode($raw ?: '[]', true) ?? [];
    $cutoff  = time() - $window_sec;
    // Prune old entries
    $records = array_values(array_filter($records, fn($r) => $r['ts'] >= $cutoff));
    $count   = count(array_filter($records, fn($r) => $r['ip'] === $ip_hash));
    $allowed = $count < $limit;
    if ($allowed && $record) {
        $records[] = ['ip' => $ip_hash, 'ts' => time()];
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($records));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $allowed;
}
