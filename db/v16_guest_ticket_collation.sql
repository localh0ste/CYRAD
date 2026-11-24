-- Fix "Illegal mix of collations" on guest tickets, guest mail and blacklist
-- joins. Older installs created these tables with the server default
-- (utf8mb4_general_ci), while the RADIUS tables use utf8mb4_unicode_ci.
-- Normalize them to the RADIUS collation. This runs on every migration pass
-- and is safe to re-apply; all foreign keys here are integer columns.
ALTER TABLE `ad_config`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `guest_email_queue`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `guest_tickets`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `mac_blacklist`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `nat_mappings`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `organization_smtp`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `ssid_profiles`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `vlans`               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
