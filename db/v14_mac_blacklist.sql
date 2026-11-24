-- ============================================================================
-- v14 — router policy: instant MAC blacklist.
-- ----------------------------------------------------------------------------
-- A MAC lands here when a user exceeds their cap, when a guest ticket is
-- revoked/expired, or when an admin blocks it by hand. Three consumers:
--   * FreeRADIUS authorize_check_query rejects at auth time (freeradius/
--     auth-limit-check.sh patches it) so a blocked device cannot even start
--     802.1X, and a raised limit lets it rejoin immediately;
--   * the backend kicks the station via WireGuard -> /usr/bin/cyrad-kick
--     (ubus del_client) on the router;
--   * the guest-ticket purge (7 days) removes rows tied to deleted tickets.
-- expires_at NULL = blocked until an admin removes it.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `mac_blacklist` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) NOT NULL,
  `mac`             VARCHAR(17) NOT NULL COMMENT 'normalized lowercase aa:bb:cc:dd:ee:ff',
  `reason`          VARCHAR(255) NOT NULL DEFAULT '',
  `username`        VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'last known owner, for cleanup',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME NULL COMMENT 'NULL = until manually removed',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_macblk_org_mac` (`organization_id`, `mac`),
  KEY `idx_macblk_mac` (`mac`),
  KEY `idx_macblk_user` (`username`),
  CONSTRAINT `fk_macblk_org`
    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
