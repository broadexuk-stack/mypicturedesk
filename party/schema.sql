-- schema.sql — MyPictureDesk multi-tenant schema
-- MySQL 5.7+ / MariaDB 10.3+
--
-- Usage (fresh install):
--   mysql -u your_user -p your_database < schema.sql
-- or paste into phpMyAdmin > SQL tab.
--
-- For upgrades from v1.x single-party installations, run only the
-- sections marked "MIGRATION v2.0" at the bottom of this file.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ================================================================
-- PART 1 — Multi-tenant tables (new in v2.0)
-- ================================================================

-- ---------------------------------------------------------------
-- mpd_users — platform accounts (superadmin + organizers)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mpd_users` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `email`               VARCHAR(254)  NOT NULL,
  `password_hash`       VARCHAR(255)           DEFAULT NULL,
  `role`                ENUM('superadmin','organizer') NOT NULL DEFAULT 'organizer',
  `is_active`           TINYINT(1)    NOT NULL DEFAULT 1,
  -- 64-char hex token for first-login / password-reset link
  `first_login_token`   VARCHAR(64)            DEFAULT NULL,
  `token_expires_at`    DATETIME               DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at`       DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- mpd_parties — one row per event / party
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mpd_parties` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  -- URL-safe slug used in guest URL: /party?id=slug
  `slug`            VARCHAR(60)   NOT NULL,
  `party_name`      VARCHAR(200)  NOT NULL,
  `event_datetime`  DATETIME               DEFAULT NULL,
  -- Free-text shown on the guest page below the party name
  `party_info`      TEXT                   DEFAULT NULL,
  -- Organizer who owns this party
  `organizer_id`    INT UNSIGNED  NOT NULL,
  -- Optional notification email (overrides organizer email for upload alerts)
  `notify_email`    VARCHAR(254)           DEFAULT NULL,
  -- Reserved for future colour picker; store CSS colour string e.g. '#7c3aed'
  `colour_theme`    VARCHAR(50)            DEFAULT NULL,
  -- Soft-disable: 0 = paused (guests see "not active" page), 1 = live
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`      INT UNSIGNED  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `organizer_id` (`organizer_id`),
  CONSTRAINT `fk_parties_organizer` FOREIGN KEY (`organizer_id`) REFERENCES `mpd_users` (`id`),
  CONSTRAINT `fk_parties_created_by` FOREIGN KEY (`created_by`)  REFERENCES `mpd_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- mpd_settings — global key/value config (e.g. SMTP settings)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mpd_settings` (
  `setting_key`   VARCHAR(100)  NOT NULL,
  `setting_value` TEXT                   DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings rows (INSERT IGNORE so re-running is safe)
INSERT IGNORE INTO `mpd_settings` (`setting_key`, `setting_value`) VALUES
  ('smtp_host',     NULL),
  ('smtp_port',     '587'),
  ('smtp_user',     NULL),
  ('smtp_pass',     NULL),
  ('smtp_from',     NULL),
  ('smtp_from_name','MyPictureDesk'),
  ('smtp_secure',   'tls');

-- ================================================================
-- PART 2 — Core photo tables (unchanged from v1.x, CREATE IF NOT EXISTS)
-- ================================================================

-- ---------------------------------------------------------------
-- photos — one row per uploaded file
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `photos` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `party_id`           INT UNSIGNED  NOT NULL DEFAULT 0,
  -- bin2hex(random_bytes(16)) — 32 hex chars, used as filename stem
  `uuid`               CHAR(32)      NOT NULL,
  -- Derived from validated magic bytes, never from client-supplied name
  `original_extension` VARCHAR(10)   NOT NULL,
  `upload_timestamp`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- SHA-256(IP + salt) stored for rate-limiting lookups
  `ip_hash`            VARCHAR(64)   NOT NULL,
  -- Partial IP shown in admin UI (e.g. "*.2.3.4") — no first octet
  `ip_display`         VARCHAR(50)   NOT NULL,
  -- Optional display name submitted by the guest at upload time
  `uploaded_by`        VARCHAR(100)           DEFAULT NULL,
  `status`             ENUM('pending','approved','rejected','removed') NOT NULL DEFAULT 'pending',
  `approved_at`        DATETIME               DEFAULT NULL,
  `rejected_at`        DATETIME               DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `party_status_time` (`party_id`, `status`, `upload_timestamp`),
  KEY `ip_hash`           (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- upload_attempts — rolling-window rate limit tracking
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `upload_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `ip_hash`      VARCHAR(64)  NOT NULL,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `party_ip_time` (`party_id`, `ip_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ================================================================
-- MIGRATION v2.0 — run on existing v1.x installations ONLY
-- (Skip if doing a fresh install — the CREATE TABLE IF NOT EXISTS
--  above already includes party_id.)
-- ================================================================

-- Add party_id to existing photos table (run once, skip if column exists)
-- ALTER TABLE `photos`
--   ADD COLUMN `party_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `uuid`,
--   ADD INDEX `party_status_time` (`party_id`, `status`, `upload_timestamp`);

-- Add party_id to existing upload_attempts table (run once, skip if column exists)
-- ALTER TABLE `upload_attempts`
--   ADD COLUMN `party_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ip_hash`,
--   ADD INDEX `party_ip_time` (`party_id`, `ip_hash`, `attempted_at`);

-- Drop the old narrower index that is now superseded
-- ALTER TABLE `photos`          DROP INDEX IF EXISTS `status_time`;
-- ALTER TABLE `upload_attempts` DROP INDEX IF EXISTS `ip_time`;

-- Optional: prune old rate-limit rows nightly via cron
-- DELETE FROM upload_attempts WHERE attempted_at < NOW() - INTERVAL 48 HOUR;
