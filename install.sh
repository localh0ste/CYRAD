#!/usr/bin/env bash
# ============================================================================
#  CYRAD — one-command PRODUCTION installer / updater
# ============================================================================
#  Sets up the whole CYRAD stack on a fresh Debian/Ubuntu 22.04+ server in one
#  shot, then — when the matching env vars are provided — instantly adds the
#  optional production pieces on the same run:
#
#    core    setup-cyrad.sh
#            LEMP (nginx + PHP-FPM + MariaDB) + FreeRADIUS + web panel +
#            DNS monitor (systemd) + per-org block rules + data-cap kicks +
#            guest-voucher cron
#    AD      freeradius/setup-ad.sh            (env CYRAD_AD_DOMAIN+ ... )
#            Active Directory join + winbind ntlm_auth + rlm_ldap
#    CF      freeradius/cloudflared-tunnel.sh  (env CYRAD_CF_DOMAIN or TOKEN)
#            public https://panel.<domain> + block.<domain> via Cloudflare
#    WG      wireguard/server/setup-wireguard-server.sh (default ON)
#            VPS WireGuard server + one wget-based router script that sets up
#            the tunnel, ubus disconnect helper and DNS blocking
#
#  Usage
#    Fresh install (everything detected from env):
#      sudo bash install.sh
#      CYRAD_DOMAIN=net.local sudo bash install.sh          # also DoT
#
#    Full auto (panel + AD + tunnel) in one command:
#      CYRAD_AD_DOMAIN=corp.local CYRAD_AD_HOST=dc01 \
#        CYRAD_AD_BASE_DN='DC=corp,DC=local' \
#        CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
#        CYRAD_AD_JOIN_USER=Administrator CYRAD_AD_JOIN_PASS='***' \
#        CYRAD_CF_DOMAIN=cyrad.example.com CYRAD_CF_TOKEN='***' \
#        sudo bash install.sh
#
#  Update an existing server IN PLACE (applies SQL migrations, re-deploys
#  files, refreshes crons/services; keeps DB/RADIUS/API secrets so routers
#  and config.php keep working):
#      sudo bash install.sh UPDATE=1
#
#  Opt-outs / controls
#      INSTALL_AD=0|1   override AD auto-detect        (default: auto)
#      INSTALL_CF=0|1   override Cloudflare auto-detect(default: auto)
#      CYRAD_INSTALL_WIREGUARD=0|1  skip/enable WireGuard (default: 1)
#      FORCE=1          full reinstall (regenerates secrets)
#      DEPLOY_HOST=...  after install, rsync a copy to another box (see deploy.sh)
#
#  See setup-cyrad.sh / setup-ad.sh / cloudflared-tunnel.sh headers for the
#  full list of CYRAD_* env knobs (all pass through automatically).
# ============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
  cat <<'HELP'
Usage: sudo bash install.sh [OPTIONS] [KEY=VALUE ...]
Run from the complete extracted CYRAD directory (not a standalone script).
  --help, -h          Show help without modifying the system
  --non-interactive   Unattended core installation (default)
  --update            Update existing install; preserve live config and credentials
  --check             Check package layout, supported OS and shell syntax only
  --config FILE       Read KEY=VALUE configuration; no shell evaluation
  --server-ip IP      Public server IP advertised in credentials
  --domain FQDN       DNS-over-TLS domain (DNS must point to this host)
  --skip-dot          Skip DNS-over-TLS/certificate setup
  --deploy-dir PATH   Web deployment directory
  --no-ad            Skip Active Directory setup
  --no-cloudflare    Skip Cloudflare setup
  --no-wireguard     Skip VPS WireGuard + router bootstrap setup
Environment / KEY=VALUE options:
  CYRAD_ADMIN_PASS, CYRAD_DB_NAME, CYRAD_DB_USER, CYRAD_DB_PASS,
  CYRAD_RADIUS_SECRET, CYRAD_API_TOKEN, CYRAD_SERVER_IP, CYRAD_WEB_IP,
  CYRAD_DOMAIN, CYRAD_ADMIN_EMAIL, CYRAD_SKIP_DOT, CYRAD_DEFAULT_ORG,
  CYRAD_OPENWRT_IP, CYRAD_OPENWRT_SECRET, CYRAD_DEPLOY_DIR,
  CYRAD_INSTALL_WIREGUARD=0|1, CYRAD_WG_PORT, CYRAD_SERVER_WG_IP,
  CYRAD_ROUTER_WG_IP, CYRAD_HTTP_PORT, CYRAD_DNS_ORG_ID,
  CYRAD_AD_*, CYRAD_CF_*, INSTALL_AD=0|1, INSTALL_CF=0|1, UPDATE=1, FORCE=1.
Secrets are generated if omitted. Prefer a root-readable config file for secrets.
SMTP is configured per organization in the panel after installation.
AD requires join credentials; unattended Cloudflare requires CYRAD_CF_TOKEN.
Example: sudo bash install.sh --non-interactive --skip-dot --server-ip 192.0.2.10
Update:  sudo bash install.sh --update --non-interactive
HELP
}
set_option() {
  local key=${1%%=*} value=${1#*=}
  [[ $key =~ ^CYRAD_[A-Z0-9_]+$ || $key =~ ^(UPDATE|FORCE|INSTALL_AD|INSTALL_CF|DEPLOY_HOST)$ ]] || { echo "Unknown configuration key: $key" >&2; exit 2; }
  export "$key=$value"
}
CHECK=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h) usage; exit 0 ;;
    --check) CHECK=1 ;;
    --update) export UPDATE=1 ;;
    --non-interactive) export DEBIAN_FRONTEND=noninteractive ;;
    --skip-dot) export CYRAD_SKIP_DOT=1 ;;
    --no-ad) export INSTALL_AD=0 ;;
    --no-cloudflare) export INSTALL_CF=0 ;;
    --no-wireguard) export CYRAD_INSTALL_WIREGUARD=0 ;;
    --config)
      [[ $# -ge 2 && -r $2 ]] || { echo 'Readable --config FILE required' >&2; exit 2; }
      while IFS= read -r line || [[ -n $line ]]; do
        line=${line%$'\r'}
        [[ -z $line || $line == \#* ]] && continue
        [[ $line == *=* ]] || { echo 'Config lines must be KEY=VALUE' >&2; exit 2; }
        set_option "$line"
      done < "$2"
      shift ;;
    --server-ip|--domain|--deploy-dir)
      [[ $# -ge 2 && $2 != --* ]] || { echo "Value required for $1" >&2; exit 2; }
      case $1 in --server-ip) export CYRAD_SERVER_IP=$2;; --domain) export CYRAD_DOMAIN=$2;; --deploy-dir) export CYRAD_DEPLOY_DIR=$2;; esac
      shift ;;
    *=*) set_option "$1" ;;
    *) echo "Unknown argument: $1 (see --help)" >&2; exit 2 ;;
  esac
  shift
done

for file in setup-cyrad.sh setup-mail.sh admin/radius.sql admin/apply_migrations.php freeradius/auth-limit-check.sh wireguard/server/setup-wireguard-server.sh wireguard/server/bootstrap-http-server.sh openwrt/cyrad-router-setup.sh; do
  [[ -f $SCRIPT_DIR/$file ]] || { echo "Incomplete package: missing $file" >&2; exit 1; }
done
if [[ $CHECK == 1 ]]; then
  source /etc/os-release
  [[ ${ID:-} == ubuntu || ${ID:-} == debian ]] || { echo 'Debian/Ubuntu required' >&2; exit 1; }
  for f in setup-cyrad.sh setup-mail.sh install.sh; do
    bash -n "$SCRIPT_DIR/$f" || { echo "Syntax error in $f" >&2; exit 1; }
  done
  echo 'PASS: package layout, supported OS and installer syntax (not an installation test)'
  exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "[ERROR] run as root:  sudo bash $0" >&2; exit 1
fi

UPDATE="${UPDATE:-0}"
echo "================================================================"
echo " CYRAD production installer"
if [ "$UPDATE" = "1" ]; then
  echo "   mode:  UPDATE - in-place refresh, secrets preserved"
  AD_STATE="skipped-in-update"; CF_STATE="skipped-in-update"
else
  echo "   mode:  INSTALL"
  [ -n "${CYRAD_AD_DOMAIN:-}" ] && AD_STATE="yes" || AD_STATE="no"
  { [ -n "${CYRAD_CF_DOMAIN:-}" ] || [ -n "${CYRAD_CF_TOKEN:-}" ]; } && CF_STATE="yes" || CF_STATE="no"
fi
echo "   AD:    ${AD_STATE:-no}"
echo "   CF:    ${CF_STATE:-no}"
[ "${CYRAD_INSTALL_WIREGUARD:-1}" = "1" ] && WG_STATE="yes" || WG_STATE="no"
echo "   WG:    $WG_STATE"
echo "================================================================"

# ---- 1. core (always) -------------------------------------------------------
echo
echo "==> [1/3] Core platform: panel + FreeRADIUS + DNS monitor + crons"
bash "$SCRIPT_DIR/setup-cyrad.sh"

# ---- 2. Active Directory (auto-detected from env) ---------------------------
INSTALL_AD="${INSTALL_AD:-}"
if [ "$UPDATE" = "1" ]; then
  :
elif [ "$INSTALL_AD" = "1" ] || { [ -z "$INSTALL_AD" ] && [ -n "${CYRAD_AD_DOMAIN:-}" ]; }; then
  echo
  echo "==> [2/3] Active Directory integration (winbind + rlm_ldap)"
  bash "$SCRIPT_DIR/freeradius/setup-ad.sh" \
    || { echo "[install.sh] AD setup failed. Re-run it alone for details:" >&2
         echo "    sudo bash $SCRIPT_DIR/freeradius/setup-ad.sh" >&2; exit 1; }
else
  echo "==> [2/3] Active Directory: skipped (set CYRAD_AD_DOMAIN=... to enable,"
  echo "        or INSTALL_AD=1 to force). Local RADIUS users work without it."
fi

# ---- 3. Cloudflare Tunnel (auto-detected from env) --------------------------
INSTALL_CF="${INSTALL_CF:-}"
if [ "$UPDATE" = "1" ]; then
  :
elif [ "$INSTALL_CF" = "1" ] || { [ -z "$INSTALL_CF" ] && { [ -n "${CYRAD_CF_DOMAIN:-}" ] || [ -n "${CYRAD_CF_TOKEN:-}" ]; }; }; then
  echo
  echo "==> [3/3] Cloudflare Tunnel (public panel + block page)"
  bash "$SCRIPT_DIR/freeradius/cloudflared-tunnel.sh" \
    || { echo "[install.sh] Cloudflare tunnel setup failed. Re-run it alone:" >&2
         echo "    sudo bash $SCRIPT_DIR/freeradius/cloudflared-tunnel.sh" >&2; exit 1; }
else
  echo "==> [3/3] Cloudflare Tunnel: skipped (set CYRAD_CF_DOMAIN=... to enable,"
  echo "        or use freeradius/cloudflared-tunnel.sh later)."
fi

# ---- done --------------------------------------------------------------------
echo
echo "================================================================"
if [ "$UPDATE" = "1" ]; then
  echo " CYRAD updated in place. Secrets untouched; routers unchanged."
else
  echo " CYRAD production install complete."
  echo "   Panel:          http://$(hostname -I 2>/dev/null | awk '{print $1}'):8080/admin/login.php"
  if [ -n "${CYRAD_AD_DOMAIN:-}" ]; then
    echo "   Active Directory:$CYRAD_AD_DOMAIN (winbind + rlm_ldap active)"
  fi
  if [ -n "${CYRAD_CF_DOMAIN:-}" ]; then
    echo "   Public panel:   https://panel.$CYRAD_CF_DOMAIN/admin/login.php"
    echo "   Public block:   https://block.$CYRAD_CF_DOMAIN"
  fi
fi
echo "   Credentials:     /root/CYRAD_CREDENTIALS.txt"
echo "   Next router step: copy /root/cyrad-router-bootstrap.sh to the router and run it"
echo "                    (WireGuard + ubus kick + DNS block in one wget-based script)"
echo "================================================================"
