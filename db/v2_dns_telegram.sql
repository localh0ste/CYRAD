-- ============================================================================
-- CYRAD v2 migration: DNS monitoring, browsing history, Telegram alerting
-- Apply on the existing `radius` database:
--   sudo mysql -u root radius < db/v2_dns_telegram.sql
-- ============================================================================

-- Telegram alert configuration per organization ----------------------------
-- IF NOT EXISTS keeps the fail-fast migration runner safe on upgrades.
ALTER TABLE `organizations`
  ADD COLUMN IF NOT EXISTS `telegram_bot_token` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'BotFather token for this org''s alert bot',
  ADD COLUMN IF NOT EXISTS `telegram_admin_uids` TEXT NULL
    COMMENT 'Comma-separated Telegram user IDs allowed to receive alerts',
  ADD COLUMN IF NOT EXISTS `default_internet_limit` BIGINT(20) NULL DEFAULT NULL
    COMMENT 'Default per-user data cap in bytes (NULL = unlimited)';

-- DNS block / allow rules (per organization) --------------------------------
CREATE TABLE IF NOT EXISTS `dns_rules` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) NOT NULL,
  `domain_pattern`  VARCHAR(255) NOT NULL
    COMMENT 'Domain or wildcard, e.g. pornhub.com or *.pornhub.com',
  `action`          ENUM('block','allow') NOT NULL DEFAULT 'block',
  `description`     VARCHAR(255) DEFAULT NULL,
  `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `organization_id` (`organization_id`),
  KEY `domain_pattern` (`domain_pattern`(64)),
  CONSTRAINT `fk_dnsrules_organization`
    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;

-- Browsing history (7-day rolling retention) --------------------------------
CREATE TABLE IF NOT EXISTS `browsing_history` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) DEFAULT NULL,
  `username`        VARCHAR(64) NOT NULL DEFAULT '',
  `client_ip`       VARCHAR(45) NOT NULL DEFAULT '',
  `domain`          VARCHAR(255) NOT NULL,
  `action`          ENUM('allowed','blocked') NOT NULL DEFAULT 'allowed',
  `query_time`      TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `username` (`username`),
  KEY `client_ip` (`client_ip`),
  KEY `organization_id` (`organization_id`),
  KEY `query_time` (`query_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_uca1400_ai_ci;

-- Returns NXDOMAIN/REFUSED for blocked domains instead of answering ---------
-- The monitor (dns/dns_monitor.py) enforces this; the table above is the log.
