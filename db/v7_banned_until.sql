-- v7: 24-hour data-cap ban support.
-- When a limited user hits their cap, the data-limit cron disables their
-- account AND stamps banned_until = NOW() + INTERVAL 24 HOUR. A per-run
-- expiry in the same cron re-enables them automatically after 24h.
-- Admins raising a user's limit also clear this (auto-unban).
-- NULL = not currently banned.
ALTER TABLE data_limits
  ADD COLUMN IF NOT EXISTS banned_until datetime DEFAULT NULL;
