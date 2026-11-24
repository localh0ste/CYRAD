-- v8: add the usage_reset_ts column that the data-limit cron relies on.
-- The rolling 24h window per user is anchored on this timestamp:
--   usage is summed from usage_reset_ts .. NOW(), and the cron bumps it
--   forward by 24h every time a user's window expires (hard 24h reset).
-- Without this column the cron's UPDATE/SELECT on usage_reset_ts silently
-- failed, so caps never reset and the panel showed stale / 0 usage.
--
-- Idempotent: only adds the column + index if they do not already exist,
-- so re-running this file (or applying it on a DB that already has it) is safe.

SET @exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'data_limits'
    AND COLUMN_NAME  = 'usage_reset_ts'
);

SET @sql = IF(@exists = 0,
  'ALTER TABLE data_limits
     ADD COLUMN usage_reset_ts datetime DEFAULT NULL
       COMMENT ''Start of the current 24h data window; NULL = not yet initialized''',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'data_limits'
    AND INDEX_NAME   = 'idx_dl_usage_reset'
);

SET @sql2 = IF(@idx = 0,
  'CREATE INDEX idx_dl_usage_reset ON data_limits (usage_reset_ts)',
  'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
