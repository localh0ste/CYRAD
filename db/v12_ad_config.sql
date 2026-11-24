-- v12 — manually entered domain-controller settings.
--
-- The "Active Directory" section of the Add User page is where the domain
-- controller is typed by hand: dc host, dc ip, port, TLS, base DN and the
-- read-only bind account. One row (id=1). Anything left blank falls back to the
-- CYRAD_AD_* constants in config.php, so servers configured over SSH keep
-- working.
CREATE TABLE IF NOT EXISTS ad_config (
  id        TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  ad_domain VARCHAR(255) NOT NULL DEFAULT '',
  dc_host   VARCHAR(255) NOT NULL DEFAULT '',
  dc_ip     VARCHAR(45)  NOT NULL DEFAULT '',
  dc_port   INT UNSIGNED NOT NULL DEFAULT 389,
  use_tls   TINYINT(1)   NOT NULL DEFAULT 0,
  base_dn   VARCHAR(255) NOT NULL DEFAULT '',
  bind_dn   VARCHAR(255) NOT NULL DEFAULT '',
  bind_pass VARCHAR(255) NOT NULL DEFAULT '',
  group_dn  VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ad_config (id) VALUES (1);
