-- ============================================================================
-- CYRAD RADIUS 802.1X — user data model + demo seed
-- ============================================================================
-- In production the users ALREADY exist in the main CYRAD `radius` database
-- (created by the admin panel / CSV upload). FreeRADIUS reads that SAME database,
-- so WiFi (802.1X) login uses the student's existing college username + password.
--
-- This file is for REFERENCE and test-seeding only. Run it on the `radius` DB:
--   sudo mysql -u radius -p radius < radius-wifi/users.sql
-- ============================================================================

-- A demo student. Cleartext-Password lets PEAP-MSCHAPv2 work out of the box.
-- Replace with real users; NEVER ship real passwords in a file.
INSERT INTO radcheck (username, attribute, op, value, organization_id) VALUES
  ('student01', 'Cleartext-Password', '==', 'ChangeMe123!', 1)
ON DUPLICATE KEY UPDATE value = VALUES(value), organization_id = VALUES(organization_id);

-- Put the user in a group so group replies apply.
INSERT INTO radusergroup (username, groupname, priority) VALUES
  ('student01', 'students', 1)
ON DUPLICATE KEY UPDATE priority = VALUES(priority);

-- Example group replies: 8h daily session cap + 10m idle timeout.
-- (CYRAD's data cap / 500MB limit is enforced separately by the web panel's
--  disable_user_if_limit_exceeded.php; RADIUS Session-Timeout is an extra control.)
INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES
  ('students', 'Session-Timeout', ':=', '28800'),
  ('students', 'Idle-Timeout',    ':=', '600')
ON DUPLICATE KEY UPDATE value = VALUES(value);
