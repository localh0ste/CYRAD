#!/usr/bin/env bash
#
# deploy.sh — push the local CYRAD working tree to the production web root.
# ---------------------------------------------------------------------------
# Safe for repeated use: it NEVER overwrites the live prod config.php or the
# credentials file, and it reloads nginx + php-fpm when done.
#
#   sudo bash deploy.sh                      # local sync + perms + reload + verify
#   sudo bash deploy.sh --install            # FRESH VPS: delegate to install.sh
#                                           #   (panel + RADIUS + WireGuard + cron)
#   sudo bash deploy.sh --clean              # also delete prod files absent from source
#   DEPLOY_DIR=/srv/cyrad bash deploy.sh     # custom web root (local)
#   DEPLOY_HOST=cyrad-prod bash deploy.sh    # push to remote host over SSH
#                                           #   + mirror /tmp/CYRAD on the remote
#                                           #   + reload services on the remote
#
# A non-web-served mirror is ALWAYS synced to /tmp/CYRAD (or $MIRROR_DIR) with
# --delete, so there is always a clean snapshot of the tree outside the docroot.
#
# First-time install on a fresh box: `deploy.sh --install` (delegates to
# install.sh -> setup-cyrad.sh, including WireGuard + router bootstrap).
# If prod has no config.php the normal code-push path tells you to do that.
#
set -euo pipefail

# ---- remote vs local ------------------------------------------------------
DEPLOY_HOST="${DEPLOY_HOST:-}"
MIRROR_DIR="${MIRROR_DIR:-/tmp/CYRAD}"

# ---- auto-elevate to root (local deploys only) ----------------------------
if [ -z "$DEPLOY_HOST" ] && [ "$(id -u)" -ne 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    echo "==> re-executing as root via sudo ..."
    exec sudo bash "$0" "$@"
  else
    echo "ERROR: run this as root:  sudo bash $0" >&2
    exit 1
  fi
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Fresh-VPS convenience: "deploy.sh --install" is the single command users run
# on a new server. Everything else in this file is the code-push path.
if [ "${1:-}" = "--install" ]; then
  shift
  exec bash "$SRC/install.sh" "$@"
fi

DEPLOY_DIR="${DEPLOY_DIR:-/var/www/html/cyrad}"
CLEAN=0
for a in "$@"; do [ "$a" = "--clean" ] && CLEAN=1; done

echo "==> Deploying:  $SRC  ->  ${DEPLOY_HOST:+$DEPLOY_HOST:}$DEPLOY_DIR"

# ---- guard: first-time install? -------------------------------------------
if [ -n "$DEPLOY_HOST" ]; then
  if ! ssh "$DEPLOY_HOST" "[ -f '$DEPLOY_DIR/config.php' ]" 2>/dev/null; then
    echo "    remote has no config.php — looks like a fresh box."
    echo "    Run setup-cyrad.sh on the host first."
    exit 1
  fi
else
  if [ ! -f "$DEPLOY_DIR/config.php" ]; then
    echo "    prod has no config.php — this looks like a fresh box."
    echo "    Run the one-shot installer first:"
    echo "        sudo bash $SRC/setup-cyrad.sh"
    exit 1
  fi
fi

# ---- sync (preserve live config + credentials + debug/secret files) -------
RSYNC_EXTRA=()
[ "$CLEAN" -eq 1 ] && RSYNC_EXTRA=(--delete)
COMMON_EXCLUDES=(
  --exclude='.git'
  --exclude='/config.php'
  --exclude='CYRAD_CREDENTIALS.txt'
  --exclude='info.php'
  --exclude='*.log'
  --exclude='.env'
  --exclude='.env.*'
)
# Router/VPS WireGuard scripts belong in the mirror, not the web root.
WEB_EXCLUDES=("${COMMON_EXCLUDES[@]}" --exclude='/wireguard')
DST="$DEPLOY_DIR"
[ -n "$DEPLOY_HOST" ] && DST="$DEPLOY_HOST:$DEPLOY_DIR"
rsync -a "${RSYNC_EXTRA[@]}" "${WEB_EXCLUDES[@]}" "$SRC/" "$DST/"

# Remove stale router/WireGuard files left in the docroot by an older deploy.
# Only ONE router script is supported now: openwrt/cyrad-router-setup.sh.
STALE_ROUTER_FILES='openWRT.sh setup-openwrt.sh deploy-dns-block.sh setup-cyrad-dns.sh cyrad-enforcer.sh cyrad-natsync.sh cyrad-sync-blocklist'
if [ -n "$DEPLOY_HOST" ]; then
  ssh "$DEPLOY_HOST" "rm -rf '$DEPLOY_DIR/wireguard'; cd '$DEPLOY_DIR' 2>/dev/null || exit 0; rm -f $STALE_ROUTER_FILES; cd openwrt 2>/dev/null || exit 0; rm -f $STALE_ROUTER_FILES" 2>/dev/null || true
else
  rm -rf "$DEPLOY_DIR/wireguard"
  for f in $STALE_ROUTER_FILES; do
    rm -f "$DEPLOY_DIR/$f" "$DEPLOY_DIR/openwrt/$f"
  done
fi

# ---- normalize docroot ownership/permissions ------------------------------
# rsync -a preserves the SOURCE owner/group/mode. A working tree from a normal
# user checkout is usually owned by that user with a restrictive umask
# (e.g. kali:kali 0770), which nginx/php-fpm (www-data) cannot read — every
# page then fails with 403/404 right after an otherwise "successful" deploy.
# Re-assert the same ownership/modes setup-cyrad.sh applies so redeploys keep
# working. config.php is excluded from the sync and stays 640 www-data.
WEB_USER="${WEB_USER:-www-data}"
if [ -n "$DEPLOY_HOST" ]; then
  echo "==> Fixing ownership/permissions on $DEPLOY_HOST:$DEPLOY_DIR"
  ssh "$DEPLOY_HOST" "chown -R '$WEB_USER:$WEB_USER' '$DEPLOY_DIR' 2>/dev/null || true
    find '$DEPLOY_DIR' -type d -exec chmod 755 {} +
    find '$DEPLOY_DIR' -type f -exec chmod 644 {} +
    [ -f '$DEPLOY_DIR/config.php' ] && chmod 640 '$DEPLOY_DIR/config.php' || true"
elif [ "$(id -u)" -eq 0 ]; then
  echo "==> Fixing ownership/permissions on $DEPLOY_DIR"
  chown -R "$WEB_USER:$WEB_USER" "$DEPLOY_DIR"
  find "$DEPLOY_DIR" -type d -exec chmod 755 {} +
  find "$DEPLOY_DIR" -type f -exec chmod 644 {} +
  [ -f "$DEPLOY_DIR/config.php" ] && chmod 640 "$DEPLOY_DIR/config.php" || true
fi

# ---- mirror to /tmp (non-web-served, always --delete) ---------------------
MIRROR_DST="$MIRROR_DIR"
[ -n "$DEPLOY_HOST" ] && MIRROR_DST="$DEPLOY_HOST:$MIRROR_DIR"
echo "==> Mirroring to:  $MIRROR_DST (--delete)"
rsync -a --delete "${COMMON_EXCLUDES[@]}" "$SRC/" "$MIRROR_DST/"

# ---- reload web services ---------------------------------------------------
if [ -n "$DEPLOY_HOST" ]; then
  echo "==> Reloading services on $DEPLOY_HOST"
  ssh "$DEPLOY_HOST" 'systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
PHPVER=$(php -v 2>/dev/null | head -1 | grep -oE "8\.[0-9]+" | head -1)
if [ -n "$PHPVER" ]; then systemctl reload "php${PHPVER}-fpm" 2>/dev/null || systemctl restart "php${PHPVER}-fpm" 2>/dev/null || true; echo "    php-fpm: php${PHPVER}-fpm"; fi'
else
  systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
  # NB: keep filters pipe-safe. `grep -m1`/`head` exit early which can SIGPIPE
  # the upstream (systemctl) mid-write; under `set -euo pipefail` that aborts the
  # deploy with a silent exit 141 right here. Read the full output, then take
  # the first matching line.
  FPMSVC="$(systemctl list-unit-files --type=service 2>/dev/null | grep 'php[0-9.]*-fpm\.service' || true)"
  FPMSVC="$(printf '%s\n' "$FPMSVC" | sed -n '1p')"
  if [ -n "$FPMSVC" ]; then
    systemctl reload "$FPMSVC" 2>/dev/null || systemctl restart "$FPMSVC" 2>/dev/null || true
    echo "    php-fpm service: $FPMSVC"
  fi
fi

# ---- verify -----------------------------------------------------------------
echo "==> Verifying deployment ..."
if [ -n "$DEPLOY_HOST" ]; then
  ssh "$DEPLOY_HOST" "for f in \$(find '$DEPLOY_DIR' -name '*.php'); do php -l \"\$f\" >/dev/null 2>&1 || echo \"SYNTAX ERROR: \$f\"; done; echo '    [done] php parse check on $DEPLOY_HOST'" || true
  ssh "$DEPLOY_HOST" "test -f '$DEPLOY_DIR/config.php' && echo '    [ok] live config.php preserved'" || true
else
  bad=0
  while IFS= read -r f; do
    if ! php -l "$f" >/dev/null 2>&1; then echo "    SYNTAX ERROR: $f"; bad=1; fi
  done < <(find "$DEPLOY_DIR" -name '*.php')
  [ "$bad" -eq 0 ] && echo "    [ok] all PHP files parse"

  [ -f "$DEPLOY_DIR/admin/assets/theme.css" ] && echo "    [ok] admin/assets/theme.css present"
  [ -f "$DEPLOY_DIR/config.php" ] && echo "    [ok] live config.php preserved ($(stat -c%s "$DEPLOY_DIR/config.php") bytes)"

  leftover="$(grep -rl '<style' "$DEPLOY_DIR/admin/superUserFilesContainer" 2>/dev/null || true)"
  if [ -z "$leftover" ]; then
    echo "    [ok] no inline <style> in super-admin pages"
  else
    echo "    [warn] inline <style> still present in:"; echo "$leftover" | sed 's/^/        /'
  fi
fi

echo
echo "========================================================"
echo " DONE. CYRAD synced to ${DEPLOY_HOST:+$DEPLOY_HOST:}$DEPLOY_DIR"
SERVER_IP="${SERVER_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
echo " Panel:  http://${SERVER_IP}:${WEB_PORT:-8080}/admin/login.php"
echo "========================================================"
