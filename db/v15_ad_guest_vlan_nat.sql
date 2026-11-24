-- v15 — restore AD/guest/VLAN/NAT feature tables lost when the v9 migration
-- set was folded into later numbers. Idempotent (IF NOT EXISTS) so existing
-- installs (which already carry these tables) are unaffected.
-- ---------------------------------------------------------------------------

-- Per-SSID RADIUS policy profiles (AP BSSID -> VLAN + auth source + enable flag).
CREATE TABLE IF NOT EXISTS `ssid_profiles` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) NOT NULL,
  `ssid`            VARCHAR(64) NOT NULL,
  `bssid`           VARCHAR(17) NOT NULL COMMENT 'AP MAC, normalized AA:BB:CC:DD:EE:FF (uppercase). Matches RADIUS Called-Station-Id.',
  `vlan_id`         INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = no VLAN assignment',
  `auth_source`     ENUM('local','ad','guest') NOT NULL DEFAULT 'local' COMMENT 'How users that associate to this SSID are expected to authenticate',
  `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
  `description`    VARCHAR(255) DEFAULT NULL,
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssid_bssid` (`bssid`),
  KEY `idx_ssid_org` (`organization_id`),
  KEY `fk_ssid_vlan` (`vlan_id`),
  CONSTRAINT `fk_ssid_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssid_vlan` FOREIGN KEY (`vlan_id`) REFERENCES `vlans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observed NAT/DHCP client -> VLAN mapping (router NAT board -> DNS monitor).
CREATE TABLE IF NOT EXISTS `nat_mappings` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) NOT NULL,
  `client_ip`       VARCHAR(45) NOT NULL,
  `mac`             VARCHAR(32) NOT NULL DEFAULT 'unknown' COMMENT 'callingstationid-style, e.g. aa:bb:cc:dd:ee:ff (lowercase)',
  `hostname`        VARCHAR(128) NOT NULL DEFAULT '',
  `iface`           VARCHAR(32) NOT NULL DEFAULT '',
  `vlan_id`         INT(11) UNSIGNED DEFAULT NULL COMMENT 'VLAN the router observed for this client',
  `ts`              TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nat_org_ip` (`organization_id`, `client_ip`),
  KEY `idx_nat_ip` (`client_ip`),
  KEY `idx_nat_updated` (`updated_at`),
  CONSTRAINT `fk_nat_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
