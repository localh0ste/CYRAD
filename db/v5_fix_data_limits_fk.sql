-- v5: fix erroneous data_limits FK
-- Wi-Fi users live in `radcheck`, not `admin_users`. The FK
-- data_limits_ibfk_1 (username -> admin_users.username) is wrong and
-- blocks adding a limited-data Wi-Fi user (FK violation on insert).
-- Drop it; keep the organization_id FK (data_limits_ibfk_2 -> organizations.id).
-- Guarded so re-running on a database where it is already gone is a no-op.
SET @fk = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'data_limits'
    AND CONSTRAINT_NAME = 'data_limits_ibfk_1'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk > 0,
  'ALTER TABLE data_limits DROP FOREIGN KEY data_limits_ibfk_1',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
