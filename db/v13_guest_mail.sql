-- Organization-scoped SMTP credentials and a durable guest mail outbox.
CREATE TABLE IF NOT EXISTS organization_smtp (
  organization_id INT NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  host VARCHAR(255) NOT NULL DEFAULT '',
  port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  encryption VARCHAR(10) NOT NULL DEFAULT 'tls',
  auth_enabled TINYINT(1) NOT NULL DEFAULT 1,
  username VARCHAR(255) NOT NULL DEFAULT '',
  password_enc TEXT NOT NULL,
  from_address VARCHAR(254) NOT NULL DEFAULT '',
  from_name VARCHAR(100) NOT NULL DEFAULT '',
  reply_to VARCHAR(254) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL,
  template TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_smtp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guest VLANs (org-scoped 802.1Q tags handed to the AP via
-- Tunnel-Private-Group-ID). Required parent of guest_tickets / ssid_profiles.
CREATE TABLE IF NOT EXISTS `vlans` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT(11) NOT NULL,
  `name`            VARCHAR(100) NOT NULL,
  `tag`             INT(11) NOT NULL COMMENT '802.1Q VLAN tag handed to the AP via Tunnel-Private-Group-ID',
  `description`    VARCHAR(255) DEFAULT NULL,
  `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vlan_org_tag` (`organization_id`, `tag`),
  KEY `idx_vlan_org` (`organization_id`),
  CONSTRAINT `fk_vlan_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guest vouchers (time-boxed, multi-use, per-org). Parent of the mail queue.
CREATE TABLE IF NOT EXISTS `guest_tickets` (
  `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64) NOT NULL,
  `password`        VARCHAR(64) NOT NULL,
  `organization_id` INT(11) NOT NULL,
  `vlan_id`         INT(11) UNSIGNED DEFAULT NULL COMMENT 'Optional explicit VLAN; empty = the Guest SSID''s BSSID profile decides',
  `expires_at`      DATETIME DEFAULT NULL COMMENT 'NULL = never expires',
  `max_uses`        INT(11) NOT NULL DEFAULT 1,
  `used_count`      INT(11) NOT NULL DEFAULT 0,
  `status`          ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  `notes`           VARCHAR(255) DEFAULT NULL,
  `created_by`      VARCHAR(64) NOT NULL DEFAULT 'admin',
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gt_username` (`username`),
  KEY `idx_gt_org` (`organization_id`),
  KEY `idx_gt_status` (`status`),
  KEY `fk_gt_vlan` (`vlan_id`),
  CONSTRAINT `fk_gt_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gt_vlan` FOREIGN KEY (`vlan_id`) REFERENCES `vlans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_email_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  recipient VARCHAR(254) NOT NULL,
  status ENUM('queued','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY mail_due (status, available_at),
  KEY mail_ticket (ticket_id, id),
  CONSTRAINT fk_mail_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_mail_ticket FOREIGN KEY (ticket_id) REFERENCES guest_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
