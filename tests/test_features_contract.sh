#!/usr/bin/env bash
# Static regression checks for the AD / guest / NAT-trace / tunnel feature
# set. Grep-level: keeps the cross-file wiring honest without a live DB/FreeRADIUS.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fails=0
note() {
  if ! grep -q -- "$2" "$1"; then
    printf 'FAIL: %s (%s)\n' "$3" "$1" >&2
    fails=$((fails+1))
  fi
}
# Fixed-string match: for patterns containing [, ], $ or ( that grep would
# otherwise treat as a regex.
note_lit() {
  if ! grep -qF -- "$2" "$1"; then
    printf 'FAIL: %s (%s)\n' "$3" "$1" >&2
    fails=$((fails+1))
  fi
}
note_not() {
  if grep -q -- "$2" "$1"; then
    printf 'FAIL: %s (should be ABSENT) (%s)\n' "$3" "$1" >&2
    fails=$((fails+1))
  fi
}

# --- DB migration must not exist -------------------------------
f="$ROOT/db/v9_ad_guest_vlan_nat.sql"
[ -f "$f" ] && { printf 'FAIL: v9 migration should be removed\n' >&2; fails=$((fails+1)); } || true

# --- FreeRADIUS AD policy installer -----------------------------------------
f="$ROOT/freeradius/setup-ad.sh"
note "$f" 'ntlm_auth --request-nt-key' 'mschap -> winbind ntlm_auth'
note "$f" 'sAMAccountName' 'ldap user filter sAMAccountName'
note "$f" 'net ads join' 'domain join step'
note "$f" 'freeradius -XC' 'config validation step'
note "$f" 'cyrad-ldap-gate' 'idempotent ldap gate marker'
note "$f" 'idempotent' 'file-edit idempotency is documented'

# --- Cloudflare tunnel ---------------------------------------------------------
f="$ROOT/freeradius/cloudflared-tunnel.sh"
note "$f" '127.0.0.1:8080' 'tunnel -> panel 8080'
note "$f" '127.0.0.1:80' 'tunnel -> block page 80'
note "$f" 'NOT tunneled' 'documented UDP-not-tunnelable caveat'

# --- One router script: WireGuard + ubus + DNS block -----------------------------
f="$ROOT/openwrt/cyrad-router-setup.sh"
note "$f" 'cyrad-wg-up' 'persistent WireGuard tunnel'
note "$f" 'del_client' 'ubus disconnect helper'
note "$f" 'api_dns_rules.php' 'DNS blocklist sync'
note "$f" 'cyrad-force-dns.nft' 'forced LAN DNS'
note "$f" 'cyrad-sync-blocklist' '5-minute blocklist refresh'

# --- Backend endpoints ----------------------------------------------------------
f="$ROOT/admin/api_nat_table.php"
note "$f" 'POST' 'upsert endpoint'
note "$f" 'hostname' 'parses hostname field'

f="$ROOT/admin/api_dns_rules.php"
note "$f" 'domain_pattern' 'org-wide rules export'

f="$ROOT/dns/dns_monitor.php"
note "$f" 'nat_mappings' 'NAT-aware resolution'
note "$f" 'user_profiles' 'AD shadow org attribution'

# --- Panel pages -----------------------------------------------------------------
f="$ROOT/admin/add_user.php"
note "$f" "auth_source" 'auth-source selector'
note "$f" "Active Directory" 'AD shadow mode label'
note "$f" 'No user is added here' 'AD: no manual creds note'
note "$f" 'usernameGroup' 'AD mode hides username field'
note_not "$f" 'pre-add its billing shadow' 'manual AD pre-registration removed'
note_not "$f" 'AD shadow' 'manual AD insert path removed'

f="$ROOT/freeradius/setup-ad.sh"
note "$f" 'cyrad-ad-first-begin' 'authorize: AD first, local fallback'
note "$f" 'if (fail || notfound)' 'fallback only when AD does not know the user'
note "$f" 'cyrad-auto-reg-begin' 'RADIUS upserts AD shadows on login'
note "$f" 'last_login' 'upsert refreshes last_login each login'
note "$f" 'ON DUPLICATE KEY UPDATE last_login=NOW()' 'shadow upsert semantics'
note "$f" 'unlimited' 'auto-registered plan is Unlimited'

f="$ROOT/db/v11_ad_last_login.sql"
[ -f "$f" ] || { printf 'FAIL: v11 migration missing\n' >&2; fails=$((fails+1)); }
note "$f" 'ADD COLUMN IF NOT EXISTS `last_login`' 'last_login column (idempotent)'

f="$ROOT/admin/guest_tickets.php"
note "$f" "guest_tickets" 'backed by guest_tickets'
note_lit "$f" "? (int)(\$_POST['internet_limit'] ?? 250) : 0;" 'unlimited tickets store internet_limit = 0, never a cap'
note "$f" 'guest_internet_type' 'the internet type select is toggleable'
note "$f" 'guestLimitWrap' 'the MB box is hidden while unlimited'
note "$f" 'mb.disabled = unlimited' 'a hidden cap is not submitted'

# CSRF must gate every state-changing action on this page (mint/revoke/delete/purge).
note "$f" 'hash_equals' 'POST actions are CSRF-checked'
note "$f" 'csrf_token' 'forms carry a CSRF token'
note_not "$f" "?revoke=" 'revoke is not a bare GET link (CSRF)'
note_not "$f" 'purge_inactive' 'no bulk delete action (one delete per ticket, by choice)'

# Delete must clear the ticket AND its account/billing/pin rows, but keep history.
f="$ROOT/admin/lib/guest_ticket_ops.php"
note "$f" 'function guest_ticket_delete' 'ticket delete helper exists'
note_lit "$f" "['radcheck', 'radreply', 'data_limits', 'user_profiles', 'mac_blacklist']" 'delete removes account, billing, pin, profile and blacklist rows'
note "$f" 'history (radacct) is deliberately kept' 'accounting history (radacct) survives a delete'
f="$ROOT/admin/guest_tickets.php"
note "$f" '?filter=dead' 'dead rows can be filtered out of the ticket list'

f="$ROOT/admin/add_user.php"
note "$f" 'Unlimited means no cap at all' 'unlimited local users store 0 bytes, not an unset variable'

f="$ROOT/admin/check_data_limit_users.php"
note "$f" "LOWER(d.internet_type) <> 'unlimited'" 'the "Data Left" list only shows users that really have a cap'
note "$f" 'd.internet_limit > 0' 'a 0-byte (unlimited) row is not listed as 0 MB remaining'

f="$ROOT/admin/expire_guest_tickets.php"
note "$f" 'disabled_' 'disables via radcheck prefix'
note "$f" 'guest_tickets' 'marks tickets expired'

f="$ROOT/admin/api_disabled_users.php"
note "$f" 'SELECT username FROM radcheck WHERE attribute' 'disabled-user bug fixed (usernames, not passwords)'

# --- Hand-entered domain controller (Add User -> Active Directory) ---------------
f="$ROOT/db/v12_ad_config.sql"
[ -f "$f" ] || { printf 'FAIL: v12 ad_config migration missing\n' >&2; fails=$((fails+1)); }
note "$f" 'CREATE TABLE IF NOT EXISTS ad_config' 'DC settings table (idempotent)'
note "$f" 'dc_host' 'DC host is a hand-entered field'
note "$f" 'dc_ip' 'DC IP is a hand-entered field'
note "$f" 'bind_dn' 'bind DN is a hand-entered field'

f="$ROOT/admin/lib/ad_config.php"
note "$f" 'function ad_config_load' 'loads saved DC values, falling back to config.php'
note "$f" 'function ad_config_save' 'persists the DC values'
note "$f" 'function ad_config_validate' 'validates the typed DC values'
note "$f" 'function ad_config_uri' 'builds ldap:// or ldaps://'
note "$f" "CYRAD_AD_BIND_PASS' => trim" 'masks the bind secret in the printed command'
note "$f" 'CYRAD_AD_HOST' 'maps to setup-ad.sh env vars'

f="$ROOT/admin/add_user.php"
note "$f" 'name="dc_host"' 'DC host input in the AD section'
note "$f" 'name="dc_ip"' 'DC IP input in the AD section'
note "$f" 'name="dc_port"' 'DC port input in the AD section'
note "$f" 'name="use_tls"' 'LDAPS toggle'
note "$f" 'name="base_dn"' 'base DN input'
note "$f" 'name="bind_dn"' 'bind DN input'
note "$f" 'name="bind_pass"' 'bind password input'
note "$f" 'name="save_ad"' 'Save domain controller submit'
note "$f" 'ad_config_save' 'saves the DC values from the AD section'
note "$f" "el.required = !isAd" 'hidden local fields stop being required so AD can submit'

f="$ROOT/admin/ad_config_export.php"
[ -f "$f" ] || { printf 'FAIL: ad_config exporter missing\n' >&2; fails=$((fails+1)); }
note "$f" "PHP_SAPI !== 'cli'" 'CLI only: refuses to run over HTTP'
note "$f" 'lib/ad_config.php' 'shares the panel loader, not a second copy of the logic'
note "$f" 'CYRAD_AD_BIND_PASS' 'exports the bind secret for setup-ad.sh'
note "$f" "'***'" 'masks the secret on stdout'
note "$f" 'chmod($envFile, 0600)' 'the env file holding the secret is 0600'

f="$ROOT/freeradius/setup-ad.sh"
note "$f" 'ad_config_export.php' 'falls back to the settings saved in the panel'
note "$f" '--print-effective' 'can print the resolved DC settings and exit'
note "$f" 'CYRAD_AD_PORT" = "636"' 'port 636 implies LDAPS, same as the panel'
note "$f" 'rm -f "$AD_ENV_FILE"' 'the temp file with the secret is removed on exit'
note "$f" 'no domain controller configured' 'clear error when AD is unconfigured'
note "$f" 'CYRAD_AD_GROUP_DN' 'the panel group restriction reaches FreeRADIUS'
note "$f" 'cyrad-ad-group-begin' 'marker-commented, removable restriction block'
note "$f" 'ad group:' '--print-effective shows the group restriction'

f="$ROOT/admin/api_disabled_users.php"
note_not "$f" "FROM user_profiles WHERE auth_source" 'AD users are not blanket-disabled'

f="$ROOT/tests/test_ad_config.php"
[ -f "$f" ] || { printf 'FAIL: ad_config behaviour tests missing\n' >&2; fails=$((fails+1)); }
note "$f" 'ad_config_validate' 'exercises the validation matrix'
note "$f" 'blank password keeps the stored secret' 'covers the secret-retention path'
note "$f" 'print-effective' 'covers the panel -> backend handoff'

# --- Installer cron + contract intact --------------------------------------------
f="$ROOT/setup-cyrad.sh"
note "$f" '/etc/cron.d/cyrad-guest' 'guest expiry cron installed'
note "$f" 'admin/expire_guest_tickets.php' 'points at the expiry script'


# Guest ticket email: per-org SMTP, queue, worker, shared settings card.
f="$ROOT/db/v13_guest_mail.sql"
note "$f" 'CREATE TABLE IF NOT EXISTS organization_smtp' 'per-organization SMTP table'
note "$f" 'CREATE TABLE IF NOT EXISTS guest_email_queue' 'durable guest mail outbox'
f="$ROOT/admin/lib/guest_mail.php"
note "$f" 'aes-256-gcm' 'SMTP password is encrypted at rest'
note "$f" 'GET_LOCK' 'worker runs are serialised'
note_not "$f" 'Password=' 'no plaintext password logging'
f="$ROOT/admin/guest_tickets.php"
note "$f" 'recipient_emails' 'mint form has optional recipient emails'
note "$f" 'send_ticket' 'existing tickets can be emailed'
note "$f" 'smtp_settings_form' 'org admins get the SMTP card on the guest page'
note_lit "$f" "Location: guest_tickets.php?filter=" 'POST redirects so a refresh cannot mint twice'
f="$ROOT/admin/superUserFilesContainer/edit_organizations.php"
note "$f" 'smtp_settings_form' 'super admin org editor shares the SMTP card'
f="$ROOT/admin/send_guest_ticket_emails.php"
note "$f" "PHP_SAPI !== 'cli'" 'mail worker is CLI only'
f="$ROOT/setup-mail.sh"
note "$f" '/etc/cron.d/cyrad-guest-mail' 'worker cron installed'
note "$f" '/etc/cyrad/mail.key' 'persistent encryption key created'


# Router policy: cap reset, auth-time check, MAC blacklist, guest purge.
f="$ROOT/db/v14_mac_blacklist.sql"
note "$f" 'CREATE TABLE IF NOT EXISTS `mac_blacklist`' 'mac_blacklist table'
f="$ROOT/admin/lib/router_policy.php"
note "$f" 'function cyrad_block_user' 'shared block helper'
note "$f" 'function cyrad_reset_user' 'shared reset helper'
note "$f" 'function cyrad_blacklist_add' 'shared blacklist helper'
note "$f" 'function cyrad_guest_ticket_dead' 'dead guest tickets are respected'
f="$ROOT/admin/lib/guest_ticket_ops.php"
note "$f" 'function cyrad_purge_revoked_guests' 'revoked ticket purge helper'
f="$ROOT/admin/disable_user_if_limit_exceeded.php"
note "$f" 'CYRAD_RESET_KICK' 'daily reset can kick the live session'
note "$f" 'cyrad_block_user' 'cap hit blocks via the shared helper'
note "$f" 'cyrad_guest_ticket_dead' 'cron never resurrects dead guest tickets'
f="$ROOT/admin/expire_guest_tickets.php"
note "$f" 'cyrad_purge_revoked_guests' 'guest cron purges revoked tickets'
note "$f" 'CYRAD_GUEST_PURGE_DAYS' 'purge retention is configurable (7d default)'
f="$ROOT/freeradius/auth-limit-check.sh"
note "$f" 'CYRAD_AUTH_POLICY' 'auth-time check is marker-patched'
note "$f" "Auth-Type', 'Reject'" 'over-limit auth returns Reject'
note "$f" 'mac_blacklist' 'blacklisted MACs are rejected at auth time'
note "$f" 'freeradius -XC' 'config validated before restart'
f="$ROOT/admin/api_blocked_macs.php"
note "$f" 'format=text' 'router-friendly text format'
f="$ROOT/admin/users.php"
note "$f" 'reset_user' 'panel can reset usage per user'
note "$f" 'bulk_reset' 'panel can bulk reset'
f="$ROOT/admin/mac_blacklist.php"
note "$f" 'remove_mac' 'panel can unblock a MAC'
f="$ROOT/openwrt/cyrad-router-setup.sh"
note "$f" '/usr/bin/cyrad-kick' 'ubus kick helper installed on router'
note "$f" 'authorized_keys' 'passwordless VPS-to-router SSH installed'
f="$ROOT/setup-cyrad.sh"
note "$f" 'freeradius/auth-limit-check.sh' 'installer applies the auth-time check'
f="$ROOT/MANUAL.md"
note "$f" 'NAT/DHCP board' 'manual documents NAT forwarding'

# Guest-ticket collation fix: live installs have mixed utf8mb4_general_ci /
# utf8mb4_unicode_ci tables, so username joins need an explicit collation.
[ -f "$ROOT/db/v16_guest_ticket_collation.sql" ] || {
  printf 'FAIL: v16 guest-ticket collation migration missing\n' >&2
  fails=$((fails+1))
}
f="$ROOT/admin/lib/role_guard.php"
note "$f" "guest_tickets.php" 'super admins can open Guest Tickets'
f="$ROOT/admin/guest_tickets.php"
note "$f" 'COLLATE utf8mb4_unicode_ci' 'guest list join is collation-safe'
f="$ROOT/admin/expire_guest_tickets.php"
note "$f" 'COLLATE utf8mb4_unicode_ci' 'guest expiry cron joins are collation-safe'
f="$ROOT/admin/lib/guest_mail.php"
note "$f" 'COLLATE utf8mb4_unicode_ci' 'guest mail worker joins are collation-safe'

if [ "$fails" -gt 0 ]; then
  printf 'FAIL: %d feature-contract check(s) failed\n' "$fails" >&2
  exit 1
fi
printf 'PASS: feature contract checks\n'