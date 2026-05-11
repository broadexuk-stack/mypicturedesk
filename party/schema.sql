-- schema.sql
-- Run once to initialise the database.
-- Compatible with MySQL 5.7+ and MariaDB 10.3+
--
-- Usage:
--   mysql -u your_user -p your_database < schema.sql
-- or paste into phpMyAdmin > SQL tab.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------
-- photos — one row per uploaded file
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `photos` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  -- bin2hex(random_bytes(16)) — 32 hex chars, used as filename stem
  `uuid`               CHAR(32)      NOT NULL,
  -- Derived from validated magic bytes, never from client-supplied name
  `original_extension` VARCHAR(10)   NOT NULL,
  `upload_timestamp`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- SHA-256(IP + salt) stored for rate-limiting lookups
  `ip_hash`            VARCHAR(64)   NOT NULL,
  -- Partial IP shown in admin UI (e.g. "*.2.3.4") — no first octet
  `ip_display`         VARCHAR(50)   NOT NULL,
  `status`             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_at`        DATETIME               DEFAULT NULL,
  `rejected_at`        DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `status_time`    (`status`, `upload_timestamp`),
  KEY `ip_hash`        (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- upload_attempts — rolling-window rate limit tracking.
-- Each accepted upload is logged here; old rows can be
-- pruned periodically (or just left; the table stays small).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `upload_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`      VARCHAR(64)  NOT NULL,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ip_time` (`ip_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: prune old rate-limit rows nightly via cron
-- DELETE FROM upload_attempts WHERE attempted_at < NOW() - INTERVAL 48 HOUR;
