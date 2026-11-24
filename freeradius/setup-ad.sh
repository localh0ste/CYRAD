#!/usr/bin/env bash
#
# setup-ad.sh — plug CYRAD FreeRADIUS into an Active Directory domain.
# =============================================================================
# Two things happen:
#
#  1. PEAP-MSCHAPv2 Wi-Fi authentication against AD (true 802.1X, every client
#     type just enters their domain username + password on the CYRAD SSID):
#        * Samba winbind is joined to the domain (`security = ads`).
#        * FreeRADIUS `mschap` is pointed at winbind via `ntlm_auth`, so the
#          MSCHAPv2 challenge/response is validated by the DC, not locally.
#        * `rlm_ldap` (freeradius-ldap) runs FIRST in authorize: every login is
#          checked against AD. Only when Active Directory does not know the
#          username (or the DC is unreachable) does the request fall back to
#          the local radcheck database. AD wins over local when both exist.
#        * Each successful AD login ALSO updates the panel database in the same
#          authentication pass: the billing shadow is upserted (Unlimited),
#          the Unlimited data limit is enforced, and `last_login` is refreshed,
#          so AD users appear in the panel automatically without any manual add.
#
#  2. BSSID -> VLAN assignment (also works without AD). Applied for every
#     successful authentication from a mapped AP: Called-Station-Id (the AP
#     BSSID, e.g. "AA:BB:CC:DD:EE:01:CYRAD") is matched against the panel's
#     "WiFi / BSSID" table and the client is put on that VLAN via
#     Tunnel-Type/Tunnel-Medium-Type/Tunnel-Private-Group-ID. This is what
#     gives every SSID its own VLAN and every VLAN its own DNS rules.
#
# Run as root on the CYRAD server (after setup-cyrad.sh). Required env:
#
#   CYRAD_AD_DOMAIN     the AD domain, e.g.        corp.local
#   CYRAD_AD_HOST       a domain controller, e.g.  dc01.corp.local
#   CYRAD_AD_BASE_DN    base DN, e.g.              DC=corp,DC=local
#   CYRAD_AD_BIND_DN    service account, e.g.      CN=radius,OU=svc,DC=corp,DC=local
#   CYRAD_AD_BIND_PASS  service account password
#   CYRAD_AD_JOIN_USER  privileged user for `net ads join`   (default: Administrator)
#   CYRAD_AD_JOIN_PASS  its password
#
# Optional:
#   CYRAD_AD_TLS=1      use ldaps:// (default 0 -> plain ldap://, LAN-internal)
#   CYRAD_AD_PORT=389   (or 636 when TLS=1)
#   CYRAD_AD_GROUP_DN   optional: only members of this AD group may use the
#                       Wi-Fi (the panel's "Restrict to AD group" field).
#                       Blank = every account in the domain is allowed.
#
# Anything not set in the environment is filled in from the domain controller
# saved in the panel (Users -> Add User -> Active Directory), and finally from
# the CYRAD_AD_* constants in config.php.  Check the result with:
#   sudo bash freeradius/setup-ad.sh --print-effective
#
# Re-run anytime: every edit is idempotent and marker-commented.
# Test after joining:  radtest <aduser> '<adpassword>' 127.0.0.1 0 testing123
# =============================================================================
set -euo pipefail

# -----------------------------------------------------------------------------
# Where the domain-controller settings come from, in order of precedence:
#   1. environment variables (what the panel prints as a one-shot command)
#   2. the panel's own ad_config row (Users -> Add User -> Active Directory)
#   3. nothing -> a clear error, since the script cannot guess a DC
# -----------------------------------------------------------------------------
AD_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AD_WEB_ROOT="${CYRAD_WEB_ROOT:-$(cd "$AD_SELF_DIR/.." && pwd)}"
AD_CONFIG_PHP="${CYRAD_CONFIG:-$AD_WEB_ROOT/config.php}"
AD_EXPORT_PHP="$AD_WEB_ROOT/admin/ad_config_export.php"
AD_ENV_FILE=""
# The panel values are copied into a 0600 temp file (it holds the bind secret);
# drop it on every exit path, including --print-effective.
trap '[ -n "$AD_ENV_FILE" ] && rm -f "$AD_ENV_FILE"' EXIT

ad_load_panel_config() {
  # Pulls CYRAD_AD_* out of the panel's ad_config table into this shell. Only
  # fills variables that are not already set, so an explicit env var always wins.
  [ -n "$AD_ENV_FILE" ] && [ -f "$AD_ENV_FILE" ] && return 0
  command -v php >/dev/null 2>&1 || return 1
  [ -f "$AD_CONFIG_PHP" ] || return 1
  [ -f "$AD_EXPORT_PHP" ] || return 1

  local tmp rc=1
  tmp="$(mktemp "${TMPDIR:-/tmp}/cyrad-ad-env.XXXXXX")"
  chmod 600 "$tmp"
  if php "$AD_EXPORT_PHP" --env-file "$tmp" >/dev/null 2>&1; then
    local k
    while IFS= read -r line; do
      case "$line" in
        CYRAD_AD_*=*)
          k="${line%%=*}"
          case "$k" in
            CYRAD_AD_JOIN_USER|CYRAD_AD_JOIN_PASS) continue ;;
          esac
          if [ -z "${!k:-}" ]; then
            eval "$line"
          fi
          ;;
      esac
    done < "$tmp"
    rc=0
    AD_ENV_FILE="$tmp"
  else
    rm -f "$tmp"
  fi
  return $rc
}

ad_print_effective() {
  # Read-only: show what this run would use, then stop. Needs no root, no DC.
  local had_env=0
  [ -n "${CYRAD_AD_HOST:-}" ] || [ -n "${CYRAD_AD_DOMAIN:-}" ] && had_env=1
  ad_load_panel_config || true
  local tls="${CYRAD_AD_TLS:-0}" port="${CYRAD_AD_PORT:-0}"
  [ "$tls" = "1" ] && [ "$port" = "0" ] && port=636
  [ "$tls" != "1" ] && [ "$port" = "0" ] && port=389
  local scheme="ldap://"; [ "$tls" = "1" ] && scheme="ldaps://"
  local src="nothing configured"
  if [ -n "${CYRAD_AD_HOST:-}" ]; then
    if [ -n "$AD_ENV_FILE" ]; then
      [ "$had_env" = "1" ] && src="environment variables (panel filled the gaps)" \
                         || src="panel: Users -> Add User -> Active Directory"
    else
      src="environment variables"
    fi
  fi
  echo "    source:     $src"
  echo "    domain:     ${CYRAD_AD_DOMAIN:-<not set>}"
  echo "    dc:         ${CYRAD_AD_HOST:-<not set>}"
  echo "    ldap url:   ${CYRAD_AD_HOST:+${scheme}${CYRAD_AD_HOST}:${port}}"
  echo "    base dn:    ${CYRAD_AD_BASE_DN:-<not set>}"
  echo "    bind dn:    ${CYRAD_AD_BIND_DN:-<not set>}"
  echo "    bind pass:  $([ -n "${CYRAD_AD_BIND_PASS:-}" ] && echo 'set' || echo '<not set>')"
  echo "    ldaps:      ${CYRAD_AD_TLS:-0}   port: ${port}"
  echo "    ad group:   ${CYRAD_AD_GROUP_DN:-<any domain account allowed>}"
  local missing=""
  for v in CYRAD_AD_DOMAIN CYRAD_AD_HOST CYRAD_AD_BASE_DN CYRAD_AD_BIND_DN CYRAD_AD_BIND_PASS; do
    [ -z "${!v:-}" ] && missing="$missing $v"
  done
  if [ -n "$missing" ]; then
    echo "    missing:$missing"
    return 1
  fi
  return 0
}

case "${1:-}" in
  --print-effective|-n)
    ad_print_effective; exit $? ;;
  --help|-h)
    # Print the whole header comment block, whatever its length.
    awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "${BASH_SOURCE[0]}"
    echo
    echo "Options:"
    echo "  --print-effective   print the resolved domain-controller settings and exit"
    echo "  --help              this text"
    exit 0 ;;
esac

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run as root:  sudo bash $0" >&2; exit 1
fi

# Fill anything still unset from the panel's ad_config row.
ad_load_panel_config || true

if [ -z "${CYRAD_AD_DOMAIN:-}" ] || [ -z "${CYRAD_AD_HOST:-}" ] || [ -z "${CYRAD_AD_BASE_DN:-}" ] \
   || [ -z "${CYRAD_AD_BIND_DN:-}" ] || [ -z "${CYRAD_AD_BIND_PASS:-}" ]; then
  cat >&2 <<'MSG'
ERROR: no domain controller configured.
  Set it in the panel:  Users -> Add User -> Authentication Source = Active Directory
  then press "Save domain controller", or pass the CYRAD_AD_* variables:
    CYRAD_AD_DOMAIN=corp.local CYRAD_AD_HOST=dc01.corp.local \
      CYRAD_AD_BASE_DN='DC=corp,DC=local' \
      CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
      bash freeradius/setup-ad.sh
  Check what this run would use:  bash freeradius/setup-ad.sh --print-effective
MSG
  exit 1
fi

CYRAD_AD_JOIN_USER="${CYRAD_AD_JOIN_USER:-Administrator}"
CYRAD_AD_JOIN_PASS="${CYRAD_AD_JOIN_PASS:-}"
CYRAD_AD_TLS="${CYRAD_AD_TLS:-0}"
CYRAD_AD_PORT="${CYRAD_AD_PORT:-0}"
[ "$CYRAD_AD_TLS" = "1" ] && [ "$CYRAD_AD_PORT" = "0" ] && CYRAD_AD_PORT=636
[ "$CYRAD_AD_TLS" != "1" ] && [ "$CYRAD_AD_PORT" = "0" ] && CYRAD_AD_PORT=389
# 636 is the LDAPS port, so it implies TLS even if the flag was left off. This
# matches admin/lib/ad_config.php, so the panel and this script always agree on
# the resulting ldaps:// URL.
[ "$CYRAD_AD_PORT" = "636" ] && CYRAD_AD_TLS=1
CYRAD_AD_AUTO_REGISTER="${CYRAD_AD_AUTO_REGISTER:-1}"
CYRAD_AD_DEFAULT_ORG="${CYRAD_AD_DEFAULT_ORG:-0}"
AD_ORG_SQL="NULL"
[ "$CYRAD_AD_DEFAULT_ORG" != "0" ] && AD_ORG_SQL="$CYRAD_AD_DEFAULT_ORG"

FRC="/etc/freeradius/3.0"
NETBIOS="$(printf '%s' "${CYRAD_AD_DOMAIN%%.*}" | tr '[:lower:]' '[:upper:]')"
SCHEME="ldap://"; [ "$CYRAD_AD_TLS" = "1" ] && SCHEME="ldaps://"
LDAP_URL="${SCHEME}${CYRAD_AD_HOST}"
[ "$SCHEME" = "ldap://" ] && [ "$CYRAD_AD_PORT" != "389" ] && LDAP_URL="${SCHEME}${CYRAD_AD_HOST}:${CYRAD_AD_PORT}"
[ "$SCHEME" = "ldaps://" ] && [ "$CYRAD_AD_PORT" != "636" ] && LDAP_URL="${SCHEME}${CYRAD_AD_HOST}:${CYRAD_AD_PORT}"

echo "==> CYRAD Active Directory integration"
echo "    domain:     $CYRAD_AD_DOMAIN"
echo "    dc:         $LDAP_URL"
echo "    base dn:    $CYRAD_AD_BASE_DN"

# ---- packages --------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
echo "==> Installing winbind + rlm_ldap ..."
apt-get update -y
apt-get install -y samba-common-bin winbind freeradius-ldap ldap-utils 2>/dev/null || \
  apt-get install -y samba-common winbind freeradius-ldap

# ---- /etc/samba/smb.conf (join the domain for ntlm_auth / machine account) --
echo "==> Writing /etc/samba/smb.conf"
mkdir -p /etc/samba
cp -a /etc/samba/smb.conf /root/smb.conf.cyrad.bak 2>/dev/null || true
cat > /etc/samba/smb.conf <<EOF
[global]
   workgroup = $NETBIOS
   realm = $CYRAD_AD_DOMAIN
   security = ads
   client signing = yes
   client use spnego = yes
   winbind use default domain = yes
   winbind offline logon = no
   winbind refresh tickets = yes
   dedicated keytab file = /etc/krb5.keytab
   kerberos method = secrets and keytab
   idmap config * : backend = tdb
   idmap config * : range = 3000-7999
   server string = CYRAD RADIUS
EOF

# ---- join the domain --------------------------------------------------------
if [ -n "$CYRAD_AD_JOIN_PASS" ]; then
  echo "==> Joining domain $CYRAD_AD_DOMAIN ..."
  if ! net ads join -U "${CYRAD_AD_JOIN_USER}%${CYRAD_AD_JOIN_PASS}"; then
    echo "ERROR: net ads join failed (join user/password, DNS, reachability?)." >&2
    exit 1
  fi
else
  echo "==> CYRAD_AD_JOIN_PASS not set — skipping domain join."
  echo "    Run manually:  net ads join -U ${CYRAD_AD_JOIN_USER}%<pass>"
fi
systemctl enable --now winbind 2>/dev/null || true
echo "    [ok] winbind enabled"
wbinfo -t 2>/dev/null | sed 's/^/    /' || echo "    [!] wbinfo -t could not verify trust (ensure 'net ads join' succeeded)"

# ============================================================================
#  FreeRADIUS edits (all idempotent, marker-commented)
# ============================================================================
PY() { python3 - "$@"; }

# ---- 1. mschap -> winbind (ntlm_auth) ---------------------------------------
echo "==> Pointing mschap at winbind (ntlm_auth) ..."
cp -a "$FRC/mods-available/mschap" "$FRC/mods-available/mschap.cyrad.bak" 2>/dev/null || true
PY "$FRC/mods-available/mschap" <<'PYEOF'
import sys, re
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
ntlm = '/usr/bin/ntlm_auth --request-nt-key --username=%{mschap:User-Name} --challenge=%{mschap:Challenge} --nt-response=%{mschap:NT-Response}'
if 'cyrad-ntlm' in s:
    s = re.sub(r'^[ \t]*ntlm_auth\s*=\s*.*? # cyrad-ntlm.*$', '        ntlm_auth = "%s"    # cyrad-ntlm' % ntlm, s, flags=re.M)
else:
    lines = s.split('\n')
    for i, line in enumerate(lines):
        if re.match(r'^[ \t]*#?\s*mschap\s*\{', line):
            lines.insert(i + 1, '        ntlm_auth = "%s"    # cyrad-ntlm' % ntlm)
            break
    s = '\n'.join(lines)
open(p, 'w', encoding='utf-8').write(s)
print("    [ok] mschap.ntlm_auth configured")
PYEOF

# ---- 2. ldap module -> AD ---------------------------------------------------
echo "==> Configuring rlm_ldap for AD ($LDAP_URL) ..."
cp -a "$FRC/mods-available/ldap" "$FRC/mods-available/ldap.cyrad.bak" 2>/dev/null || true
PY "$FRC/mods-available/ldap" "$LDAP_URL" "$CYRAD_AD_BIND_DN" "$CYRAD_AD_BIND_PASS" "$CYRAD_AD_BASE_DN" <<'PYEOF'
import sys, re
p, url, ident, pw, base = sys.argv[1:6]
lines = open(p, encoding='utf-8').read().split('\n')
out, in_user, user_done = [], 0, False
for line in lines:
    if re.match(r'^#?\s*server\s*=', line):
        out.append(line if 'cyrad-dc' in line else "        server = '%s'    # cyrad-dc" % url)
        continue
    if re.match(r'^#?\s*identity\s*=', line):
        out.append(line if 'cyrad-identity' in line else "        identity = '%s'    # cyrad-identity" % ident)
        continue
    if re.match(r'^#?\s*password\s*=', line):
        out.append(line if 'cyrad-password' in line else "        password = '%s'    # cyrad-password" % pw)
        continue
    if re.match(r'^#?\s*base_dn\s*=', line):
        out.append(line if 'cyrad-base' in line else "        base_dn = '%s'    # cyrad-base" % base)
        continue
    if re.match(r'^#?\s*tls_required\s*=', line):
        out.append("        tls_required = no    # cyrad-tls")
        continue
    if re.match(r'^#?\s*user\s*\{', line):
        in_user = 1
    if in_user and not user_done and re.match(r'^#?\s*filter\s*=', line):
        out.append('            filter = "(sAMAccountName=%{%{Stripped-User-Name}:-%{User-Name}})"    # cyrad-user-filter')
        user_done = True
        in_user = None
        continue
    if in_user and line.strip() == '}':
        in_user = 0
    out.append(line)
open(p, 'w', encoding='utf-8').write('\n'.join(out))
print("    [ok] rlm_ldap points at %s (%s)" % (url, base))
PYEOF
ln -sf ../mods-available/ldap "$FRC/mods-enabled/ldap"

# ---- 3. authorize: AD first, local radcheck fallback (default + inner-tunnel) -->
echo "==> Wiring AD-first authorize (ldap first, local sql fallback)..."
for site in default inner-tunnel; do
  [ -f "$FRC/sites-available/$site" ] || { echo "    skip $site (missing)"; continue; }
  cp -a "$FRC/sites-available/$site" "$FRC/sites-available/$site.cyrad.bak" 2>/dev/null || true
  PY "$FRC/sites-available/$site" "authorize" <<'PYEOF'
import sys, re, textwrap
p, target = sys.argv[1], sys.argv[2]
s = open(p, encoding='utf-8').read()
# Upgrade: drop the old local-first gate ("cyrad-ldap-gate") if present.
s = re.sub(r'if \(!&control:Cleartext-Password.*?# cyrad-ldap-gate\n', '', s, flags=re.S)
if 'cyrad-ad-first-begin' in s:
    print("    [ok] %s already AD-first (idempotent)" % p)
    sys.exit(0)
adfirst = textwrap.dedent('''
    # ==== cyrad-ad-first-begin =====================================
    # AD first: try the domain (LDAP/winbind) for every login. Only
    # when Active Directory does not know the username (or the DC is
    # unreachable) do we fall back to the local radcheck database.
    # A wrong AD password never falls through to local.
    ldap
    if (fail || notfound) {
        sql
    }
    # ==== cyrad-ad-first-end ========================================
''')
lines = s.split('\n')
# Locate the target section and the `authorize { ... }` span.
i_sec = next((i for i, ln in enumerate(lines) if re.match(r'^\s*' + target + r'\s*\{', ln)), None)
if i_sec is None:
    print("    [skip] %s not found in %s" % (target, p))
    sys.exit(0)
depth = 0
sec_end = len(lines) - 1
for k in range(i_sec, len(lines)):
    depth += lines[k].count('{') - lines[k].count('}')
    if depth == 0:
        sec_end = k
        break
# Insert right after the first `eap { ... }` block inside the section
# (EAP handshake packets short-circuit at "eap { ok = return }", so the
# AD check only runs once the EAP framing is done).
ins = None
for k in range(i_sec + 1, sec_end):
    if re.match(r'^\s*eap\s*\{', lines[k]):
        d = 0
        for h in range(k, sec_end + 1):
            d += lines[h].count('{') - lines[h].count('}')
            if d <= 0:
                ins = h
                break
        break
if ins is None:
    ins = i_sec  # fallback: top of the section
block = adfirst.rstrip('\n').split('\n')
out = lines[:ins + 1] + block + lines[ins + 1:]
open(p, 'w', encoding='utf-8').write('\n'.join(out))
print("    [ok] AD-first block in %s (ldap => local fallback)" % p)
PYEOF
done

# ---- 3b. optional: restrict AD logins to one group ---------------------------
# Comes from the panel's "Restrict to AD group" field (CYRAD_AD_GROUP_DN); blank
# means every account in the domain is allowed. Written as its own marker block so
# clearing the field in the panel really removes the restriction on the next run.
if [ -n "${CYRAD_AD_GROUP_DN:-}" ]; then
  echo "==> Restricting AD logins to group: $CYRAD_AD_GROUP_DN"
else
  echo "==> No AD group restriction (blank = any domain account allowed)"
fi
for site in default inner-tunnel; do
  [ -f "$FRC/sites-available/$site" ] || continue
  # Start from the pre-run copy so a removed restriction cannot survive.
  [ -f "$FRC/sites-available/$site.cyrad.bak" ] && \
    cp -a "$FRC/sites-available/$site.cyrad.bak" "$FRC/sites-available/$site"
  PY "$FRC/sites-available/$site" "${CYRAD_AD_GROUP_DN:-}" <<'PYEOF'
import sys, re, textwrap
p = sys.argv[1]
group_dn = (sys.argv[2] if len(sys.argv) > 2 else '').strip()
s = open(p, encoding='utf-8').read()
# Always drop a previously written restriction first, then re-add it when asked:
# one code path, so setting and clearing the field both behave.
s = re.sub(r'\n?[ \t]*# ==== cyrad-ad-group-begin.*?# ==== cyrad-ad-group-end[^\n]*\n', '', s, flags=re.S)
if not group_dn:
    open(p, 'w', encoding='utf-8').write(s)
    print("    [ok] %s: no group restriction" % p)
    sys.exit(0)
if 'cyrad-ad-first-begin' not in s:
    print("    [skip] %s: AD-first block not present, group restriction needs it" % p)
    open(p, 'w', encoding='utf-8').write(s)
    sys.exit(0)
body = textwrap.dedent('''
    # ==== cyrad-ad-group-begin ====================================
    # Restrict Wi-Fi to one AD group ("Restrict to AD group" in the
    # panel). Accounts outside it are rejected. Local logins are
    # untouched: they have no LDAP-Group, so the guard never fires.
    if (&LDAP-Group) {
        if (!(&LDAP-Group =~ /''' + re.escape(group_dn) + r'''(,|$)/)) {
            update reply { Reply-Message := "Account not in the allowed AD group" }
            reject
        }
    }
    # ==== cyrad-ad-group-end ======================================
''')
anchor = '    # ==== cyrad-ad-first-end ========================================'
if anchor not in s:
    print("    [skip] %s: ad-first end marker not found" % p)
    open(p, 'w', encoding='utf-8').write(s)
    sys.exit(0)
s = s.replace(anchor, anchor + '\n' + body.rstrip('\n'), 1)
open(p, 'w', encoding='utf-8').write(s)
print("    [ok] %s restricts AD logins to %s" % (p, group_dn))
PYEOF
done

# ---- 4. post-auth policy (VLAN disabled) --------------------------------------

# ---- 4b. post-auth: upsert AD shadow + Unlimited plan + last-login ------------
if [ "$CYRAD_AD_AUTO_REGISTER" != "0" ]; then
  echo "==> Auto-register: AD logins upsert shadow + Unlimited + last_login in the same pass ..."
  cp -a "$FRC/sites-available/default" "$FRC/sites-available/default.cyrad-round3.bak" 2>/dev/null || true
  CYRAD_AD_DEFAULT_ORG="$AD_ORG_SQL" PY "$FRC/sites-available/default" <<'PYEOF'
import sys, re, textwrap, os
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
org_sql = os.environ.get('CYRAD_AD_DEFAULT_ORG', 'NULL')
if org_sql in ('', '0'):
    org_sql = 'NULL'
body = textwrap.dedent('''
    # ==== cyrad-auto-reg-begin ==========================================
    # Successful non-local (AD) login: the panel DB is written to in the
    # same authentication pass.
    #   * billing shadow upserted (created on first login, org untouched
    #     on later refreshes),
    #   * Unlimited data limit enforced,
    #   * last_login refreshed = the panel can show when each AD user last
    #     connected. Local radcheck users are skipped entirely.
    if ("%{sql:SELECT COUNT(*) FROM radcheck WHERE username='%{User-Name}'}" == "0") {
        %{sql:INSERT INTO user_profiles (username, organization_id, user_info, auth_source, last_login) VALUES ('%{User-Name}', ORG, 'auto-registered via LDAP', 'ad', NOW()) ON DUPLICATE KEY UPDATE last_login=NOW()}
        %{sql:INSERT INTO data_limits (username, internet_type, internet_limit, organization_id, usage_reset_ts) VALUES ('%{User-Name}', 'unlimited', 0, ORG, NOW()) ON DUPLICATE KEY UPDATE internet_type='unlimited', internet_limit=0}
    }
    # ==== cyrad-auto-reg-end ============================================
''').replace('ORG', org_sql)
# Drop any previous auto-register block (old insert-only or new upsert) so
# upgrades always replace it cleanly.
s = re.sub(r'# ==== cyrad-auto-reg-begin.*?# ==== cyrad-auto-reg-end =+\n?', '', s, flags=re.S)
if 'ON DUPLICATE KEY UPDATE last_login=NOW()' in s:
    print('    [ok] auto-register (upsert + last-login refresh) already present (idempotent)')
    sys.exit(0)
out, depth, insec, done = [], 0, False, False
for line in s.split('\n'):
    if not done and re.match(r'^\s*post-auth\s*\{', line):
        insec = True
        out.append(line)
        out.extend(body.split('\n'))
        done = True
        continue
    out.append(line)
open(p, 'w', encoding='utf-8').write('\n'.join(out))
print('    [ok] auto-register upsert added to post-auth of %s (default org %s)' % (p, org_sql))
PYEOF
else
  echo "==> Auto-register disabled (CYRAD_AD_AUTO_REGISTER=0)."
fi

# ---- 5. validate + restart FreeRADIUS ---------------------------------------
echo "==> Validating FreeRADIUS config ..."
freeradius -XC || { echo "ERROR: FreeRADIUS validation failed — see the parse output above." >&2; exit 1; }
systemctl restart freeradius
systemctl enable freeradius

echo
echo "================================================================"
echo " Active Directory integration installed."
echo "   winbind:     domain $CYRAD_AD_DOMAIN (verify: wbinfo -t)"
echo "   mschap:      ntlm_auth -> winbind -> AD  (PEAP-MSCHAPv2)"
echo "   authorize:   AD first (LDAP), local radcheck fallback"
echo "   DB updates:  AD login upserts shadow + Unlimited + last_login"
echo
echo " Next steps:"
echo "   * AD users appear automatically on first WiFi login (auto-register,"
echo "     Unlimited plan, last_login refreshed) — nothing to add manually."
echo "   * Test login:  radtest DOMAINUSER 'pass' 127.0.0.1 0 testing123"
echo "================================================================"