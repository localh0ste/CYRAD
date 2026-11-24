-- ============================================================================
-- CYRAD v10 migration: Active Directory users are always Unlimited
-- ----------------------------------------------------------------------------
-- Every AD/LDAP billing shadow (user_profiles.auth_source = 'ad') is forced to
-- the 'unlimited' plan by materialising (or upgrading) its data_limits row:
--   * missing row  -> created as internet_type='unlimited', internet_limit=0
--   * limited row  -> flipped to 'unlimited', internet_limit=0
-- Idempotent: running it again is a no-op.
-- ============================================================================

INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts)
SELECT p.username, 'unlimited', 0, p.organization_id, NOW()
FROM user_profiles p
WHERE p.auth_source = 'ad'
ON DUPLICATE KEY UPDATE
  internet_type = 'unlimited',
  internet_limit = 0;