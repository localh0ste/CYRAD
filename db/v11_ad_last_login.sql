-- v11: track when each user last connected, so AD shadows auto-updated by the
-- RADIUS post-auth upsert can be surfaced in the panel (and for local users the
-- column simply stays NULL until the panel wires a write).
ALTER TABLE user_profiles
  ADD COLUMN IF NOT EXISTS `last_login` DATETIME DEFAULT NULL AFTER `auth_source`;