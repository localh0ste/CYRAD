#!/usr/bin/env bash
#
# setup-cyrad.sh — One-command bootstrap of a fresh Debian/Ubuntu server into a
# fully working CYRAD deployment:
#   * LEMP stack          (nginx + PHP 8.3-FPM + MariaDB)
#   * FreeRADIUS          (802.1X auth + accounting) wired to MariaDB
#   * CYRAD web panel     (admin control panel, optional captive portal)
#   * DNS monitor         (per-org block + browsing-history logging) as a systemd service
#   * DNS-over-TLS (DoT)  optional: dnsproxy + Let's Encrypt so stock routers get
#                         filtered, logged, encrypted DNS (bypasses ISP :53 blocking)
#
# Idempotent for the common case (fresh box). Re-running on an already-installed
# host aborts unless FORCE=1 (re-install regenerates config.php + RADIUS secret).
#
# Override any default with an environment variable before invoking:
#   CYRAD_DEPLOY_DIR      web root                       (default: /var/www/html/cyrad)
#   CYRAD_DB_USER         MariaDB radius user            (default: radius)
#   CYRAD_DB_PASS         MariaDB radius password        (default: random 24-char)
#   CYRAD_DB_NAME         MariaDB database               (default: radius)
#   CYRAD_DOMAIN          FQDN for DoT cert, e.g. dns.example.com
#                          (omit + CYRAD_SKIP_DOT=1 to skip DoT entirely)
#   CYRAD_ADMIN_EMAIL     certbot contact email          (default: admin@<DOMAIN>)
#   CYRAD_RADIUS_SECRET   shared secret for 'home_router' RADIUS client
#                          (default: random 24-char)
#   CYRAD_ADMIN_PASS      set web admin 'admin' password after import
#   CYRAD_DEFAULT_ORG     org id used when client IP can't be mapped (NAT)
#                          (default: 0 = off)
#   CYRAD_BLOCK_MODE      'nxdomain' (default) | 'page'
#   CYRAD_SKIP_DOT         set to 1 to skip certbot + dnsproxy
#   CYRAD_API_TOKEN        shared token for router APIs (openwrt/cyrad-router-setup.sh).
#                          auto-generated (16-byte hex) if omitted, so endpoints are token-gated by default.
#   CYRAD_OPENWRT_IP       if set, also register a FreeRADIUS client for this OpenWrt router IP
#   CYRAD_OPENWRT_SECRET   RADIUS secret for that client (default: same as CYRAD_RADIUS_SECRET)
#   CYRAD_DAS_TARGET_IP    router WireGuard tunnel IP (e.g. 10.9.0.2) for NATed NAS;
#                          empty = send DAE directly to the NAS public IP
#                          (see wireguard/README.md / INSTALLATION.md)
#   CYRAD_INSTALL_WIREGUARD 1 (default) | 0 — set up VPS wg0 + router bootstrap
#   CYRAD_WG_PORT         WireGuard UDP port (default 51820)
#   CYRAD_SERVER_WG_IP    VPS tunnel IP (default 10.9.0.1)
#   CYRAD_ROUTER_WG_IP    router tunnel IP (default 10.9.0.2)
#   CYRAD_HTTP_PORT       router-bootstrap HTTP port (default 8000)
#   CYRAD_DNS_ORG_ID      org id for router DNS blocklist (default 0 = all rules)
#   FORCE=1               re-run even if CYRAD is already installed
#
set -euo pipefail

# ---- auto-elevate to root ------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    echo "==> re-executing as root via sudo ..." >&2
    exec sudo bash "$0" "$@"
  fi
  echo "ERROR: run as root:  sudo bash $0" >&2
  exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="${CYRAD_DEPLOY_DIR:-/var/www/html/cyrad}"
DB_USER="${CYRAD_DB_USER:-radius}"
DB_NAME="${CYRAD_DB_NAME:-radius}"
[[ $DB_NAME =~ ^[a-zA-Z0-9_]+$ && $DB_USER =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Invalid DB name/user' >&2; exit 1; }
[[ $DEPLOY_DIR =~ ^/[a-zA-Z0-9_./-]+$ && $DEPLOY_DIR != / ]] || { echo 'Invalid deploy path' >&2; exit 1; }
DB_PASS="${CYRAD_DB_PASS:-$(openssl rand -base64 18 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 24)}"
RADIUS_SECRET="${CYRAD_RADIUS_SECRET:-$(openssl rand -base64 18 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 24)}"
ADMIN_PASS="${CYRAD_ADMIN_PASS:-$(openssl rand -base64 24 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 20)}"
DOMAIN="${CYRAD_DOMAIN:-}"
ADMIN_EMAIL="${CYRAD_ADMIN_EMAIL:-admin@${DOMAIN}}"
BLOCK_MODE="${CYRAD_BLOCK_MODE:-nxdomain}"
DEFAULT_ORG="${CYRAD_DEFAULT_ORG:-0}"
if [ -n "${CYRAD_SKIP_DOT:-}" ]; then
  SKIP_DOT="$CYRAD_SKIP_DOT"
elif [ -n "$DOMAIN" ]; then
  SKIP_DOT=0
else
  SKIP_DOT=1
fi
DNS_UPSTREAM="8.8.8.8:53"
WEB_IP="${CYRAD_WEB_IP:-127.0.0.1}"
SERVER_IP="${CYRAD_SERVER_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
CREDENTIALS_FILE="${CYRAD_CREDENTIALS_FILE:-/root/CYRAD_CREDENTIALS.txt}"
CREDENTIALS_ALT="${CYRAD_CREDENTIALS_ALT:-/root/cred.txt}"
# OpenWrt "light" router observer support
API_TOKEN="${CYRAD_API_TOKEN:-$(openssl rand -hex 16)}"
OPENWRT_IP="${CYRAD_OPENWRT_IP:-}"
OPENWRT_SECRET="${CYRAD_OPENWRT_SECRET:-$RADIUS_SECRET}"
DAS_TARGET_IP="${CYRAD_DAS_TARGET_IP:-}"
INSTALL_WG="${CYRAD_INSTALL_WIREGUARD:-1}"
WG_PORT="${CYRAD_WG_PORT:-51820}"
SERVER_WG_IP="${CYRAD_SERVER_WG_IP:-10.9.0.1}"
ROUTER_WG_IP="${CYRAD_ROUTER_WG_IP:-10.9.0.2}"
WG_HTTP_PORT="${CYRAD_HTTP_PORT:-8000}"
WG_BOOTSTRAP="/root/cyrad-router-bootstrap.sh"

# ---- helpers -------------------------------------------------------------
info()  { echo -e "\033[1;32m==>\033[0m $*"; }
warn()  { echo -e "\033[1;33m[!]\033[0m $*" >&2; }
die()   { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; exit 1; }

# ---- update mode (no secret regeneration) ----------------------------------
# UPDATE=1 refreshes an existing install: applies migrations, re-deploys web
# files, re-writes crons and restarts services — WITHOUT generating new
# DB / RADIUS / API secrets, so config.php and the routers keep working.
# Secrets that matter are taken from the live config.php / clients.conf unless
# the matching CYRAD_* env var was given explicitly (env wins, then live file,
# then fresh random).
UPDATE="${UPDATE:-0}"
if [ "$UPDATE" = "1" ]; then
  if [ ! -f "$DEPLOY_DIR/config.php" ]; then
    die "UPDATE=1 needs an existing install ($DEPLOY_DIR/config.php present). Run a fresh install instead."
  fi
  info "UPDATE mode — refreshing CYRAD in place (migrations + files + services)."
  info "Keeping existing secrets (no regeneration); pass CYRAD_* env vars to override."
  _cy_from_cfg() { # $1 = constant name -> echo its value
    php -r "require '$DEPLOY_DIR/config.php'; echo defined('$1') ? constant('$1') : '';" 2>/dev/null
  }
  _cfg_or_env() { # $1 = constant, $2 = current env-derived value
    local ev="${1//CYRAD_/CYRAD_}" want="$2"
    # prefer an explicitly-set env var
    case "$1" in
      CYRAD_DB_PASS) [ -n "${CYRAD_DB_PASS:-}" ] && { echo "$CYRAD_DB_PASS"; return; } ;;
      CYRAD_RADIUS_SECRET) [ -n "${CYRAD_RADIUS_SECRET:-}" ] && { echo "$CYRAD_RADIUS_SECRET"; return; } ;;
      CYRAD_API_TOKEN) [ -n "${CYRAD_API_TOKEN:-}" ] && { echo "$CYRAD_API_TOKEN"; return; } ;;
      CYRAD_WEB_IP) [ -n "${CYRAD_WEB_IP:-}" ] && { echo "$CYRAD_WEB_IP"; return; } ;;
      CYRAD_DEFAULT_ORG) [ -n "${CYRAD_DEFAULT_ORG:-}" ] && { echo "$CYRAD_DEFAULT_ORG"; return; } ;;
      CYRAD_DAS_TARGET_IP) [ -n "${CYRAD_DAS_TARGET_IP:-}" ] && { echo "$CYRAD_DAS_TARGET_IP"; return; } ;;
    esac
    local v; v="$(_cy_from_cfg "$1")"
    [ -n "$v" ] && { echo "$v"; return; }
    echo "$want"
  }
  DB_PASS="$(      _cfg_or_env CYRAD_DB_PASS      "$DB_PASS")"
  API_TOKEN="$(    _cfg_or_env CYRAD_API_TOKEN    "$API_TOKEN")"
  WEB_IP="$(       _cfg_or_env CYRAD_WEB_IP      "$WEB_IP")"
  DEFAULT_ORG="$(  _cfg_or_env CYRAD_DEFAULT_ORG  "$DEFAULT_ORG")"
  DAS_TARGET_IP="$(_cfg_or_env CYRAD_DAS_TARGET_IP "$DAS_TARGET_IP")"
  if [ -f /etc/freeradius/3.0/clients.conf ]; then
    RS="$(sed -n 's/^[[:space:]]*secret[[:space:]]*=[[:space:]]*//p' /etc/freeradius/3.0/clients.conf 2>/dev/null | head -1 | tr -d '"')"
    [ -n "${CYRAD_RADIUS_SECRET:-}" ] && RS="${CYRAD_RADIUS_SECRET}"
    [ -n "$RS" ] && RADIUS_SECRET="$RS"
  fi
  OPENWRT_SECRET="${CYRAD_OPENWRT_SECRET:-$RADIUS_SECRET}"
fi

export DEBIAN_FRONTEND=noninteractive

# ---- os check ------------------------------------------------------------
if ! grep -qiE 'debian|ubuntu' /etc/os-release 2>/dev/null; then
  die "This installer supports Debian/Ubuntu only (detected: $(. /etc/os-release 2>/dev/null; echo ${PRETTY_NAME:-unknown}))."
fi

# ---- already installed? -------------------------------------------------
if [ -f "$DEPLOY_DIR/config.php" ] && [ "${FORCE:-0}" != "1" ] && [ "${UPDATE:-0}" != "1" ]; then
  die "CYRAD already installed at $DEPLOY_DIR (config.php present).
       Use 'sudo bash install.sh UPDATE=1' to update in place (keeps all secrets),
       'sudo bash deploy.sh' to push code updates, or re-run with FORCE=1 to reinstall
       (this regenerates config.php and the RADIUS 'home_router' secret)."
fi

ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64)  DP_ARCH="amd64" ;;
  aarch64|arm64) DP_ARCH="arm64" ;;
  *) die "Unsupported architecture: $ARCH" ;;
esac

# ==========================================================================
info "CYRAD auto-installer"
echo "    source:        $SRC"
echo "    deploy dir:    $DEPLOY_DIR"
echo "    php:           distro default (detected after package install)"
echo "    db/user/pass:  $DB_NAME / $DB_USER / [hidden]"
echo "    radius secret: [hidden]"
echo "    dot domain:    ${DOMAIN:-<none — DoT skipped>}"
echo

# ---- 1. packages ---------------------------------------------------------
info "Installing packages ..."
apt-get update -y
apt-get install -y \
  nginx php-fpm php-mysql php-cli php-mbstring php-xml php-curl \
  mariadb-server unzip git curl rsync freeradius freeradius-mysql \
  freeradius-utils ssl-cert certbot openssl dnsutils libphp-phpmailer cron

# Detect the PHP-FPM socket after apt selected the release-appropriate PHP
# version (for example 8.1 on Ubuntu 22.04 and 8.3 on Ubuntu 24.04).
PHP_VER="$(php -v 2>/dev/null | head -1 | grep -oE '8\.[0-9]+' | head -1)"
[ -n "$PHP_VER" ] || die "PHP was installed but its version could not be detected."
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [ ! -S "$PHP_SOCK" ]; then
  ALT="/run/php/php-fpm.sock"
  [ -S "$ALT" ] && PHP_SOCK="$ALT"
fi
[ -S "$PHP_SOCK" ] || die "PHP-FPM socket not found: $PHP_SOCK"
info "Using PHP $PHP_VER (socket $PHP_SOCK)"

# ---- 2. database ---------------------------------------------------------
info "Configuring MariaDB + radius database ..."
systemctl enable --now mariadb
DB_PASS_SQL="$(php -r 'echo str_replace(["\\", "\x27"], ["\\\\", "\x27\x27"], $argv[1]);' -- "$DB_PASS")"
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
-- Re-assert the password on every run: CREATE USER IF NOT EXISTS is a no-op
-- for an already-existing user, which silently broke config.php/sql module
-- sync after the previous install generated a different random password.
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# Import the base schema only on a fresh database.
TABLES="$(mysql -u root "$DB_NAME" -N -e "SHOW TABLES" 2>/dev/null | wc -l)"
if [ "$TABLES" -eq 0 ]; then
  info "Importing base schema (admin/radius.sql) ..."
  [ -f "$SRC/admin/radius.sql" ] || die 'Base schema missing.'
  sed 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' "$SRC/admin/radius.sql" | mysql -u root "$DB_NAME"
  if [ -n "$ADMIN_PASS" ]; then
    info "Setting web admin 'admin' password ..."
    HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$ADMIN_PASS")"
    mysql -u root "$DB_NAME" -e "UPDATE admin_users SET password='$HASH' WHERE username='admin';" 2>/dev/null \
      || warn "could not set admin password (admin_users table/row missing?)"
  fi
else
  warn "Database '$DB_NAME' already has $TABLES tables — keeping existing data."
fi

# Run idempotent migrations in numeric order; stop on any SQL error.
info "Applying all numbered database migrations ..."
CYRAD_DB_HOST=localhost CYRAD_DB_USER=root CYRAD_DB_PASS='' CYRAD_DB_NAME="$DB_NAME" php "$SRC/admin/apply_migrations.php"

# ---- 3. FreeRADIUS -------------------------------------------------------
info "Configuring FreeRADIUS (auth + accounting -> MariaDB) ..."
FRC="/etc/freeradius/3.0"
# clients.conf: localhost + a permissive 'home_router' (lock down ipaddr as needed)
cat > "$FRC/clients.conf" <<'RADCLIENTS'
client localhost {
    ipaddr = 127.0.0.1
    secret = testing123
    shortname = localhost
}
client home_router {
    ipaddr = 0.0.0.0/0
    secret = __RADIUS_SECRET__
    acct_interim_interval = 300
    shortname = home_router
}
RADCLIENTS
sed -i "s/__RADIUS_SECRET__/$RADIUS_SECRET/" "$FRC/clients.conf"

# --------------------------------------------------------------------------
# enable + configure the sql module
#
# Ubuntu 24.04's freeradius-config ships mods-available/sql with
#   dialect = "sqlite", driver = "rlm_sql_null"  and the login/password
# lines commented out, and a mysql{ tls{ } } block pointing at cert files
# that do not exist. Edit only the keys with sed and you get a server that
# cannot start ("${authcheck_table} not found", "Failed initializing SSL",
# "Unknown module tolower", ...). We therefore drive it with Python that
# is idempotent and robust to the package layout.
# --------------------------------------------------------------------------
ln -sf "$FRC/mods-available/sql" "$FRC/mods-enabled/sql"

if ! grep -q 'queries.conf' "$FRC/mods-available/sql" 2>/dev/null; then
  warn "FreeRADIUS sql module appears truncated; restoring package default ..."
  dpkg --force-confmiss -i "$(dpkg -S "$FRC/mods-config/sql/main/mysql/queries.conf" | cut -d: -f1)" >/dev/null 2>&1 \
    || apt-get install --reinstall -o Dpkg::Options::="--force-confmiss" -y freeradius-config >/dev/null 2>&1 \
    || true
fi
# Some images ship the module with the invalid `${mods-config}` variable (not
# defined on FreeRADIUS 3.x); normalize it to `${modconfdir}` before parsing.
sed -i 's/${mods-config}/${modconfdir}/g' "$FRC/mods-available/sql"

python3 - "$FRC/mods-enabled/sql" "$DB_USER" "$DB_PASS" "$DB_NAME" <<'PYEOF'
import re, sys
p, db_user, db_pass, db_name = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
lines = open(p, encoding='utf-8').read().split('\n')
out, in_mysql_tls = [], 0
for line in lines:
    ls = line.lstrip()
    if in_mysql_tls:
        # mysql{ tls{ }: the stock cert paths do not exist on a fresh box
        # and FreeRADIUS refuses to instantiate the module when they don't.
        if ls.startswith('tls_required'):
            out.append(re.sub(r'=\s*\S+', '= no', line)); continue
        if any(frag in ls for frag in ('ca_file','ca_path','certificate_file','private_key_file','cipher')):
            out.append(line if ls.startswith('#') else '#' + line.lstrip(' \t')); continue
    if re.search(r'^[ \t]*#?[ \t]*dialect\s*=', line):
        # dialect = sqlite | "sqlite" | ...  ->  "mysql"
        out.append(re.sub(r'(dialect\s*=\s*).*', r'\1"mysql"', line));    continue
    if re.search(r'^[ \t]*#?[ \t]*driver\s*=', line) and 'rlm_sql_${' not in line:
        out.append(re.sub(r'(driver\s*=\s*).*', r'\1"rlm_sql_mysql"', line)); continue
    if ls.startswith('server ') and not line.lstrip().startswith('#') and '"' in line:
        out.append(re.sub(r'(server\s*=\s*).*', r'\1"localhost"', line)); continue
    if ls.startswith('port ') and not '#' in line.split('=',1)[0]:
        out.append(re.sub(r'(port\s*=\s*).*', r'\1 3306', line));         continue
    if re.match(r'^[ \t]*#?[ \t]*login\s*=', line):
        out.append(f'\tlogin = "{db_user}"');                             continue
    if re.match(r'^[ \t]*#?[ \t]*password\s*=', line):
        out.append(f'\tpassword = "{db_pass}"'); continue
    if ls.startswith('radius_db ') :
        out.append(re.sub(r'=\s*"[^"]*"', f'= "{db_name}"', line)); continue
    if ls.startswith('mysql {') and in_mysql_tls == 0:
        in_mysql_tls = 1
    elif in_mysql_tls and ls.startswith('}') and not ls.startswith('}}'):
        in_mysql_tls = 0
    out.append(line)
open(p, 'w', encoding='utf-8').write('\n'.join(out))
print(f'sql module: dialect=mysql, driver=rlm_sql_mysql, login={db_user}@/{db_name}')
PYEOF

# Auth-time enforcement: the SQL authorize query rejects over-limit users and
# blacklisted MACs on every authentication (idempotent; validates the config).
bash "$SRC/freeradius/auth-limit-check.sh" \
  || warn "auth-time limit/blacklist patch failed — caps still enforced by the cron"

# Ensure the standard module and site set is present. Some images ship a
# previously-tweaked /etc/freeradius where symlinks vanished or are broken
# (e.g. mods-enabled/eap or expr missing, sites-enabled/inner-tunnel gone),
# which FreeRADIUS reports as "module not found" / a broken default site.
FRC_MODS="always attr_filter chap detail detail.log digest dynamic_clients eap \
echo exec expiration expr files linelog logintime mschap ntlm_auth pap passwd \
preprocess radutmp realm replicate soh sradutmp unix unpack utf8 sql"
for m in $FRC_MODS; do
  if [ -f "$FRC/mods-available/$m" ] || [ -L "$FRC/mods-available/$m" ]; then
    ln -sf "../mods-available/$m" "$FRC/mods-enabled/$m"
  else
    warn "freeradius module '$m' not present in mods-available (skipping)"
  fi
done
ln -sf ../mods-available/sql "$FRC/mods-enabled/sql"
ln -sf ../sites-available/default "$FRC/sites-enabled/default" 2>/dev/null || true
[ -f "$FRC/sites-available/inner-tunnel" ] && \
  ln -sf ../sites-available/inner-tunnel "$FRC/sites-enabled/inner-tunnel" 2>/dev/null || true

# uncomment the 'sql' references in the default virtual server (if still commented)
sed -i -E 's|^[[:space:]]*#[[:space:]]*sql[[:space:]]*$|sql|' "$FRC/sites-available/default"

# Ask the NAS for interim accounting every 2 minutes. Without it a live
# session reports 0 bytes, so the data-cap cron never sees current usage and
# cannot disconnect an over-limit user. Idempotent.
python3 - "$FRC/sites-available/default" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
block = ("\t# CYRAD: interim accounting so live data-cap usage is visible.\n"
         "\tupdate reply {\n"
         "\t\tAcct-Interim-Interval := 120\n"
         "\t}\n\n")
if 'Acct-Interim-Interval' not in s:
    s = s.replace('post-auth {\n', 'post-auth {\n' + block, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('default site: Acct-Interim-Interval=300 added to post-auth')
PYEOF
# also wire sql into inner-tunnel (used by EAP / 802.1X WPA2-Enterprise)
[ -f "$FRC/sites-available/inner-tunnel" ] && \
  sed -i -E 's|^[[:space:]]*#[[:space:]]*sql[[:space:]]*$|sql|' "$FRC/sites-available/inner-tunnel"

# Force PEAP / MS-CHAPv2 only (username+password, NOT EAP-TLS / TTLS)
if [ -f "$FRC/mods-enabled/eap" ]; then
  EAP="$FRC/mods-enabled/eap"
  python3 - "$EAP" <<'PYEOF'
import sys
p=sys.argv[1]
s=open(p).read()
def cb(t,m):
    out=[];i=0;L=t.split("\n")
    while i<len(L):
        if L[i].strip()==m:
            d=0
            while i<len(L):
                l=L[i]
                if "{" in l: d+=1
                if "}" in l: d-=1
                out.append(("# "+l) if l.strip() else l); i+=1
                if d==0: break
        else:
            out.append(L[i]); i+=1
    return "\n".join(out)
s=cb(s,"tls {"); s=cb(s,"ttls {"); s=cb(s,"md5 {"); s=cb(s,"gtc {")
s=s.replace("default_eap_type = md5","default_eap_type = peap",1)
open(p,"w").write(s)
print("eap: PEAP-only enforced (tls/ttls disabled, default_eap_type=peap)")
PYEOF
else
  warn "eap module missing from mods-enabled; WPA2-Enterprise will not work"
fi

# enable the default site (accounting/auth) and restart
ln -sf "$FRC/sites-available/default" "$FRC/sites-enabled/default" 2>/dev/null || true
# Enable at boot, but validate before attempting to start the service.
systemctl enable freeradius
# Validate before restart and keep the parser output visible; suppressing it made
# real deployment failures impossible to diagnose from the installer output.
if ! freeradius -XC; then
  die "FreeRADIUS configuration validation failed; see the parser output above."
fi
systemctl restart freeradius

# ---- 3b. OpenWrt router observer client (optional) ----------------------
if [ -n "$OPENWRT_IP" ]; then
  info "Registering FreeRADIUS client for OpenWrt router ($OPENWRT_IP) ..."
  cat >> "$FRC/clients.conf" <<RADOPENWRT

client openwrt_router {
    ipaddr = $OPENWRT_IP
    secret = $OPENWRT_SECRET
    acct_interim_interval = 300
    shortname = openwrt_router
}
RADOPENWRT
  systemctl restart freeradius
  info "OpenWrt router RADIUS secret: $OPENWRT_SECRET  (use as RADIUS_SECRET on the router)"
  info "Backend API token for the router's /etc/cyrad.conf: $API_TOKEN"
else
  warn "No CYRAD_OPENWRT_IP set — skipping OpenWrt router RADIUS client. Set it to auto-register the router."
fi

# ---- 3c. WireGuard server + one-line router bootstrap -------------------
if [ "$INSTALL_WG" = "1" ]; then
  info "Setting up WireGuard server + router bootstrap (tunnel $SERVER_WG_IP <-> $ROUTER_WG_IP) ..."
  [ -n "$DAS_TARGET_IP" ] || DAS_TARGET_IP="$ROUTER_WG_IP"

  # DNS Rules are org-scoped in the panel, but a router normally serves the
  # whole household. org=0 exports ALL enabled block rules (api_dns_rules.php),
  # so rules added by any admin — including the super admin — take effect.
  # Set CYRAD_DNS_ORG_ID=<id> for a strictly single-tenant router.
  BOOT_ORG_ID="${CYRAD_DNS_ORG_ID:-0}"

  SERVER_ENDPOINT="$SERVER_IP" RADIUS_SECRET="$RADIUS_SECRET" API_TOKEN="$API_TOKEN" \
  ORG_ID="$BOOT_ORG_ID" WG_PORT="$WG_PORT" SERVER_WG_IP="$SERVER_WG_IP" \
  ROUTER_WG_IP="$ROUTER_WG_IP" CYRAD_HTTP_PORT="$WG_HTTP_PORT" \
    bash "$SRC/wireguard/server/setup-wireguard-server.sh" \
    || warn "WireGuard setup reported an error — check /etc/wireguard and systemd before using the router bootstrap"

  [ -f "$WG_BOOTSTRAP" ] && echo "    [ok] router bootstrap -> $WG_BOOTSTRAP"
else
  warn "WireGuard setup skipped (CYRAD_INSTALL_WIREGUARD=0). Live disconnect needs another reachable NAS path."
fi

# ---- 4. deploy web files -------------------------------------------------
info "Deploying CYRAD web files -> $DEPLOY_DIR ..."
mkdir -p "$DEPLOY_DIR"
rsync -a \
  --exclude='.git' \
  --exclude='/config.php' \
  --exclude='CYRAD_CREDENTIALS.txt' \
  --exclude='info.php' \
  --exclude='*.log' \
  --exclude='.env' \
  --exclude='.env.*' \
  "$SRC/" "$DEPLOY_DIR/"

# ---- 5. generate config.php ----------------------------------------------
info "Writing config.php ..."
if [ "$UPDATE" != "1" ]; then
cat > "$DEPLOY_DIR/config.php" <<'CFG'
<?php
/**
 * CYRAD central configuration — generated by setup-cyrad.sh
 */
define('CYRAD_DB_HOST', '__DB_HOST__');
define('CYRAD_DB_USER', '__DB_USER__');
define('CYRAD_DB_PASS', '__DB_PASS__');
define('CYRAD_DB_NAME', '__DB_NAME__');

define('CYRAD_DNS_LISTEN_HOST', '0.0.0.0');
define('CYRAD_DNS_LISTEN_PORT', 53);
define('CYRAD_DNS_UPSTREAM',    '__DNS_UPSTREAM__');
define('CYRAD_DNS_ENFORCE', 1);
define('CYRAD_BLOCK_MODE', '__BLOCK_MODE__');
define('CYRAD_WEB_IP', '__WEB_IP__');
define('CYRAD_VPN_BLOCK_ENABLED', 1);
define('CYRAD_DEFAULT_ORG', __DEFAULT_ORG__);
define('CYRAD_API_TOKEN', '__API_TOKEN__');

// RADIUS + DAE/DAS (RFC 5176 PoD/CoA): the backend sends Disconnect-Messages
// to the NAS on UDP/3799 with the same secret used for 1812/1813.
define('CYRAD_RADIUS_SECRET', '__RADIUS_SECRET__');
define('CYRAD_DAS_SECRET',    '__RADIUS_SECRET__');
define('CYRAD_DAS_PORT',      3799);
// Set to the router's WireGuard tunnel IP if the NAS is behind NAT/CGNAT.
define('CYRAD_DAS_TARGET_IP', '__DAS_TARGET_IP__');
define('CYRAD_RADCLIENT_BIN', '/usr/bin/radclient');
define('CYRAD_CLIENTS_CONF',  '/etc/freeradius/3.0/clients.conf');

// Data cap: 24 h rolling window, 24 h ban on breach (auto-lifted at expiry or
// when the admin raises/removes the limit).
define('CYRAD_WINDOW_HOURS', 24);
define('CYRAD_BAN_HOURS',    24);
define('CYRAD_RESET_KICK',   1);
CFG
php -r '
$path=$argv[1]; $text=file_get_contents($path);
$names=["DB_HOST","DB_USER","DB_PASS","DB_NAME","DNS_UPSTREAM","BLOCK_MODE","WEB_IP","API_TOKEN","RADIUS_SECRET","DAS_TARGET_IP"];
foreach ($names as $i=>$name) $text=str_replace("\x27__".$name."__\x27",var_export($argv[$i+2],true),$text);
$text=str_replace("__DEFAULT_ORG__",(string)(int)$argv[12],$text);
file_put_contents($path,$text);
' -- "$DEPLOY_DIR/config.php" localhost "$DB_USER" "$DB_PASS" "$DB_NAME" "$DNS_UPSTREAM" "$BLOCK_MODE" "$WEB_IP" "$API_TOKEN" "$RADIUS_SECRET" "$DAS_TARGET_IP" "$DEFAULT_ORG"
fi

chown -R www-data:www-data "$DEPLOY_DIR"
find "$DEPLOY_DIR" -type d -exec chmod 755 {} +
find "$DEPLOY_DIR" -type f -exec chmod 644 {} +
chmod 640 "$DEPLOY_DIR/config.php"


# Keep generated credentials outside the web root so nginx cannot serve them.
if [ "$UPDATE" != "1" ]; then
cat > "$CREDENTIALS_FILE" <<CREDS
CYRAD deployment credentials
Panel URL: http://${SERVER_IP}:8080/admin/login.php
Admin username: admin
Admin password: $ADMIN_PASS
RADIUS shared secret: $RADIUS_SECRET
OpenWrt API token: $API_TOKEN
Deployment directory: $DEPLOY_DIR
WireGuard router IP: $ROUTER_WG_IP
WireGuard UDP port: $WG_PORT
Router bootstrap script: $WG_BOOTSTRAP
DNS rules org id: ${BOOT_ORG_ID:-$DEFAULT_ORG}
CREDS
chown root:root "$CREDENTIALS_FILE"
chmod 600 "$CREDENTIALS_FILE"
cp -f "$CREDENTIALS_FILE" "$CREDENTIALS_ALT" 2>/dev/null || true
chmod 600 "$CREDENTIALS_ALT" 2>/dev/null || true
echo "    [ok] credentials -> $CREDENTIALS_FILE (+ $CREDENTIALS_ALT)"
fi

# ---- 6. DNS monitor service ----------------------------------------------
info "Installing cyrad-dnsmon systemd service ..."
# The DNS monitor binds UDP 53, but systemd-resolved holds 127.0.0.53:53 and
# ghost-shadowing the resolver breaks "Address already in use" debugging on a
# fresh Ubuntu/Debian box. Free the port first, then point the host at public
# resolvers directly.
if systemctl is-active --quiet systemd-resolved; then
  info "Disabling systemd-resolved (frees UDP/TCP 53 for the DNS monitor) ..."
  systemctl disable --now systemd-resolved >/dev/null 2>&1 || true
  [ -L /etc/resolv.conf ] && rm -f /etc/resolv.conf
  : > /etc/resolv.conf
  for NS in 8.8.8.8 1.1.1.1; do echo "nameserver $NS" >> /etc/resolv.conf; done
  echo "options edns0 trust-ad" >> /etc/resolv.conf
  warn "/etc/resolv.conf now points straight at 8.8.8.8/1.1.1.1 (systemd-resolved disabled)."
fi
cat > /etc/systemd/system/cyrad-dnsmon.service <<'DNSMON'
[Unit]
Description=CYRAD DNS Monitor (PHP) — query logging + per-org block rules + Telegram alerts
After=network.target mariadb.service
Requires=mariadb.service

[Service]
Type=simple
ExecStart=/usr/bin/php /var/www/html/cyrad/dns/dns_monitor.php
WorkingDirectory=/var/www/html/cyrad
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
DNSMON
sed -i "s#/var/www/html/cyrad#$DEPLOY_DIR#g" /etc/systemd/system/cyrad-dnsmon.service
systemctl daemon-reload
systemctl enable --now cyrad-dnsmon

# ---- 6b. data-limit enforcement (auto-disconnect on cap) -----------------
info "Scheduling data-limit enforcement (auto PoD-kick when a user hits their cap) ..."
cat > /etc/cron.d/cyrad-limit <<CRON
*/2 * * * * root /usr/bin/php $DEPLOY_DIR/admin/disable_user_if_limit_exceeded.php >> /var/log/cyrad-limit.log 2>&1
CRON
echo "    [ok] /etc/cron.d/cyrad-limit (every 2 min)"

# ---- 6c. guest voucher expiry -------------------------------------------------
info "Scheduling guest-voucher expiry ..."
cat > /etc/cron.d/cyrad-guest <<CRON
*/5 * * * * root /usr/bin/php $DEPLOY_DIR/admin/expire_guest_tickets.php >> /var/log/cyrad-guest.log 2>&1
CRON
echo "    [ok] /etc/cron.d/cyrad-guest (every 5 min)"
CYRAD_DEPLOY_DIR="$DEPLOY_DIR" bash "$SRC/setup-mail.sh" --skip-packages

# ---- 7. nginx ------------------------------------------------------------
info "Configuring nginx (admin :8080 + block-page :80/:443) ..."
cat > /etc/nginx/sites-available/cyrad <<'NGINX'
server {
    listen 8080;
    server_name _;

    root __DEPLOY_DIR__;
    index index.php;

    location = / { return 301 /admin/login.php; }
    location = /admin { return 404; }
    location ~ ^/(tests|db|\.git)/ { deny all; }
    location ~ \.(sql|sh|log|key|zip|bak)$ { deny all; }
    location /admin/ { try_files $uri =404; }
    location / { try_files $uri =404; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:__PHP_SOCK__;
    }

    location ~ /\.ht { deny all; }
}

# Branded "Access Blocked" page for blocked domains (CYRAD_BLOCK_MODE = 'page').
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    root __DEPLOY_DIR__;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        default_type "text/plain";
        try_files $uri =404;
    }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/block.php;
        fastcgi_param SCRIPT_NAME /block.php;
        fastcgi_pass unix:__PHP_SOCK__;
    }

    location ~ /\.ht { deny all; }
}
NGINX
sed -i "s#__DEPLOY_DIR__#$DEPLOY_DIR#g" /etc/nginx/sites-available/cyrad
sed -i "s#__PHP_SOCK__#$PHP_SOCK#g"     /etc/nginx/sites-available/cyrad

# Optional TLS block-page + DoT cert wiring (only if a domain was supplied)
if [ -n "$DOMAIN" ] && [ "$SKIP_DOT" != "1" ]; then
cat >> /etc/nginx/sites-available/cyrad <<'NGINXTLS'

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;

    ssl_certificate     /etc/letsencrypt/live/__DOMAIN__/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/__DOMAIN__/privkey.pem;

    root __DEPLOY_DIR__;

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/block.php;
        fastcgi_param SCRIPT_NAME /block.php;
        fastcgi_pass unix:__PHP_SOCK__;
    }

    location ~ /\.ht { deny all; }
}
NGINXTLS
sed -i "s#__DEPLOY_DIR__#$DEPLOY_DIR#g" /etc/nginx/sites-available/cyrad
sed -i "s#__PHP_SOCK__#$PHP_SOCK#g"     /etc/nginx/sites-available/cyrad
sed -i "s#__DOMAIN__#$DOMAIN#g"         /etc/nginx/sites-available/cyrad
fi

ln -sf /etc/nginx/sites-available/cyrad /etc/nginx/sites-enabled/cyrad
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# ---- 8. DNS-over-TLS (optional) ------------------------------------------
if [ -n "$DOMAIN" ] && [ "$SKIP_DOT" != "1" ]; then
  info "Setting up DNS-over-TLS (dnsproxy + Let's Encrypt for $DOMAIN) ..."
  DP_VER="0.83.0"
  DP_URL="https://github.com/AdguardTeam/dnsproxy/releases/download/v${DP_VER}/dnsproxy-linux-${DP_ARCH}-v${DP_VER}.tar.gz"
  mkdir -p /opt/dnsproxy /tmp/dpinstall
  curl -fsSL "$DP_URL" -o /tmp/dpinstall/dnsproxy.tar.gz \
    || die "failed to download dnsproxy from $DP_URL"
  tar xzf /tmp/dpinstall/dnsproxy.tar.gz -C /tmp/dpinstall
  find /tmp/dpinstall -name dnsproxy -type f -exec mv {} /opt/dnsproxy/dnsproxy \;
  chmod +x /opt/dnsproxy/dnsproxy
  rm -rf /tmp/dpinstall

  # obtain cert via webroot (needs :80 reachable + A record pointing here)
  certbot certonly --webroot -w /var/www/html -d "$DOMAIN" \
    --non-interactive --agree-tos -m "$ADMIN_EMAIL" --keep-until-expiring \
    || warn "certbot failed — DoT cert not issued. Fix DNS/A-record and re-run, or set CYRAD_SKIP_DOT=1."

  if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    cat > /etc/systemd/system/dnsproxy.service <<DNSPROXY
[Unit]
Description=CYRAD DoT proxy (dnsproxy -> local DNS monitor :53)
After=network.target cyrad-dnsmon.service

[Service]
Type=simple
ExecStart=/opt/dnsproxy/dnsproxy --upstream=127.0.0.1:53 --tls-port=853 --port=0 --tls-crt=/etc/letsencrypt/live/$DOMAIN/fullchain.pem --tls-key=/etc/letsencrypt/live/$DOMAIN/privkey.pem
Restart=on-failure
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
DNSPROXY
    # restart dnsproxy automatically when certbot renews the cert
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/restart-dnsproxy.sh <<'DPHOOK'
#!/bin/bash
systemctl restart dnsproxy
DPHOOK
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/restart-dnsproxy.sh
    systemctl daemon-reload
    systemctl enable --now dnsproxy
  fi
else
  warn "Skipping DoT (no CYRAD_DOMAIN set or CYRAD_SKIP_DOT=1). DNS monitor still serves plain :53."
fi

# ---- 9. firewall ---------------------------------------------------------
if command -v ufw >/dev/null 2>&1; then
  info "Opening firewall ports (ufw) ..."
  ufw allow 22/tcp      >/dev/null 2>&1 || true
  ufw allow 80/tcp      >/dev/null 2>&1 || true
  ufw allow 443/tcp     >/dev/null 2>&1 || true
  ufw allow 8080/tcp    >/dev/null 2>&1 || true
  ufw allow 53/udp      >/dev/null 2>&1 || true
  ufw allow 853/tcp     >/dev/null 2>&1 || true
  ufw allow 1812/udp    >/dev/null 2>&1 || true
  ufw allow 1813/udp    >/dev/null 2>&1 || true
  ufw --force enable    >/dev/null 2>&1 || true
fi

# ---- 10. verify ----------------------------------------------------------
info "Verifying installation ..."
bad=0
while IFS= read -r f; do
  if ! php -l "$f" >/dev/null 2>&1; then echo "    SYNTAX ERROR: $f"; bad=1; fi
done < <(find "$DEPLOY_DIR" -name '*.php')
[ "$bad" -eq 0 ] && echo "    [ok] all PHP files parse"

CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/admin/login.php 2>/dev/null || echo 000)"
echo "    admin login page :8080/admin/login.php -> HTTP $CODE"

if systemctl is-active --quiet cyrad-dnsmon; then
  echo "    [ok] cyrad-dnsmon active"
  if dig +short @127.0.0.1 google.com >/dev/null 2>&1; then
    echo "    [ok] DNS monitor resolves google.com"
  else
    warn "DNS monitor did not answer (check journalctl -u cyrad-dnsmon)"
  fi
else
  warn "cyrad-dnsmon not active"
fi

systemctl is-active --quiet freeradius && echo "    [ok] freeradius active" || warn "freeradius not active"
systemctl is-active --quiet nginx      && echo "    [ok] nginx active"      || warn "nginx not active"
systemctl is-active --quiet mariadb    && echo "    [ok] mariadb active"    || warn "mariadb not active"
[ "$SKIP_DOT" = "1" ] || { systemctl is-active --quiet dnsproxy && echo "    [ok] dnsproxy (DoT) active" || warn "dnsproxy not active"; }

# ---- 11. done ------------------------------------------------------------
echo
echo "============================================================"
echo " CYRAD installed at $DEPLOY_DIR"
echo " Panel:  http://${SERVER_IP}:8080/admin/login.php"
echo " Admin:  admin / $ADMIN_PASS   (change after first login)"
echo " RADIUS home_router secret: $RADIUS_SECRET"
echo " DB:     $DB_USER @ $DB_NAME  (pass hidden in $DEPLOY_DIR/config.php)"
echo " Credentials saved securely to: $CREDENTIALS_FILE"
if [ -n "$DOMAIN" ] && [ "$SKIP_DOT" != "1" ]; then
echo " DoT:    point devices' Private DNS to $DOMAIN (port 853)"
fi
if [ "$INSTALL_WG" = "1" ]; then
echo " Router: copy $WG_BOOTSTRAP to the OpenWrt router and run it"
echo "         (WireGuard + ubus disconnect + DNS block, one wget-based script)"
fi
echo "============================================================"
echo
warn "SECURITY: clients.conf allows 'home_router' from 0.0.0.0/0 — restrict ipaddr to your router's IP. Change the admin password and RADIUS secret."
