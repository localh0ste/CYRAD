-- CYRAD v4 migration: per-user profile notes + RBAC enforcement support
-- Apply on the `radius` database as root:
--   sudo mysql -u root radius < db/v4_rbac_userinfo.sql

CREATE TABLE IF NOT EXISTS `user_profiles` (
  `username`        VARCHAR(64)  NOT NULL,
  `organization_id` INT(11)      DEFAULT NULL,
  `user_info`       TEXT         DEFAULT NULL
    COMMENT 'Free-text note captured when the user is created; shown to the org admin in Telegram violation alerts',
  `auth_source`     ENUM('local','ad','guest') NOT NULL DEFAULT 'local',
  `created_at`      TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`username`),
  KEY `organization_id` (`organization_id`),
  CONSTRAINT `fk_userprofiles_organization`
    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
