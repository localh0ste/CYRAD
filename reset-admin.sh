#!/usr/bin/env bash
#
# reset-admin.sh — set (or reset) the CYRAD panel admin username + password.
#
# Reads the live DB credentials from /var/www/html/cyrad/config.php so it
# works regardless of the DB name. Idempotent: re-running just updates the
# password. Also prints a verification line for every admin row.
#
# Usage:
#   sudo bash reset-admin.sh --list          # show all panel accounts (no change)
#   sudo bash reset-admin.sh                 # -> cyrad / Cyrad
#   sudo bash reset-admin.sh myuser MyPass   # custom username/password
#
set -euo pipefail

CFG="${CFG:-/var/www/html/cyrad/config.php}"
[ -r "$CFG" ] || { echo "ERROR: cannot read $CFG — run with: sudo bash $0" >&2; exit 1; }

if [ "${1:-}" = "--list" ]; then
  php -r '
  require $argv[1];
  $m = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
  if ($m->connect_error) { fwrite(STDERR, "DB connect failed: ".$m->connect_error."\n"); exit(1); }
  echo "== admin_users (all panel accounts) ==\n";
  $rows = $m->query("SELECT id, username, role, organization_id, full_name, position FROM admin_users ORDER BY id");
  if (!$rows || $rows->num_rows === 0) { echo "  (no admin accounts found)\n"; exit(0); }
  while ($r = $rows->fetch_assoc()) {
      printf("  id=%d  user=%-16s role=%-12s org=%d  name=%-12s position=%s\n",
          $r["id"], $r["username"], $r["role"], $r["organization_id"],
          $r["full_name"] ?: "-", $r["position"] ?: "-");
  }
  echo "\nRoles: super_admin = full control | admin = created by a super admin\n";
  ' "$CFG"
  exit 0
fi

USERNAME="${1:-cyrad}"
PASSWORD="${2:-Cyrad}"

php -r '
require $argv[1];
$u = $argv[2];
$p = $argv[3];
$m = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
if ($m->connect_error) { fwrite(STDERR, "DB connect failed: ".$m->connect_error."\n"); exit(1); }
if (strlen($p) < 4) { fwrite(STDERR, "password too short\n"); exit(1); }

$h   = password_hash($p, PASSWORD_DEFAULT);
$esc = function ($s) use ($m) { return $m->real_escape_string($s); };
$uh  = $esc($u); $ph = $esc($h);

// 1) password on an existing <user>, 2) rename the default admin, 3) insert if absent
$m->query("UPDATE admin_users SET password=\"$ph\" WHERE username=\"$uh\"");
$m->query("UPDATE admin_users SET username=\"$uh\", password=\"$ph\" WHERE username=\"admin\" AND username<>\"$uh\"");
$m->query("INSERT INTO admin_users (username, password, full_name, organization_id, role)
          SELECT \"$uh\", \"$ph\", \"cyrad admin\", 1, \"super_admin\"
          FROM DUAL
          WHERE NOT EXISTS (SELECT 1 FROM admin_users WHERE username=\"$uh\")");

echo "== admin_users (current) ==\n";
$rows = $m->query("SELECT id, username, role, organization_id, password FROM admin_users ORDER BY id");
while ($r = $rows->fetch_assoc()) {
    $ok = password_verify($p, $r["password"]) ? "PASSWORD_OK" : "other-password";
    printf("  id=%d  user=%-16s role=%-12s org=%d  %s\n",
        $r["id"], $r["username"], $r["role"], $r["organization_id"], $ok);
}
' "$CFG" "$USERNAME" "$PASSWORD"

echo
echo "Panel login:  username=$USERNAME  password=$PASSWORD"
echo "URL:          http://<server-ip>:8080/admin/login.php"