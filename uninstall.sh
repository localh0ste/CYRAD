#!/usr/bin/env bash
# ============================================================================
#  CYRAD — production uninstaller (removes the CYRAD stack from this server)
# ============================================================================
#  Removes everything CYRAD installed on THIS server:
#    * web panel + deploy dir         * nginx site (admin :8080 + block :80)
#    * FreeRADIUS + mysql module      * DNS monitor systemd unit
#    * winbind/samba AD join          * cloudflared tunnel + configs
#    * cyrad crons (limit + guest)    * credentials files
#
#  By default the MariaDB server and the `radius` database are KEPT (data is
#  precious). Pass DROP_DB=1 to also drop the database + DB user.
#
#  Controls (env):
#    CONFIRM=1   skip the interactive confirmation
#    DRY_RUN=1   print exactly what would be done, change nothing
#    DROP_DB=1   also drop the 'radius' database + 'radius' DB user
#    PURGE=1     also purge generic packages nginx / php-* / mariadb-server*
#                (be careful — any other site on this box uses them)
#    KEEP_CREDS=1 keep /root/CYRAD_CREDENTIALS.txt
#
#  Router side is NOT touched (it lives on the routers): re-provision or wipe
#  with the OpenWrt installer's companion steps (remove /usr/bin/cyrad-*,
#  /etc/cyrad.conf, /etc/dnsmasq.d/cyrad-block.conf, /etc/nftables.d/
#  cyrad-force-dns.nft, /etc/crontabs/root cyrad-* lines, /etc/init.d/cyrad-*).
# ============================================================================
set -u

DEPLOY_DIR="${CYRAD_DEPLOY_DIR:-/var/www/html/cyrad}"
FRC="/etc/freeradius"
DRY_RUN="${DRY_RUN:-0}"
DROP_DB="${DROP_DB:-0}"
PURGE="${PURGE:-0}"
KEEP_CREDS="${KEEP_CREDS:-0}"

run() {
  if [ "$DRY_RUN" = "1" ]; then
    echo "    WOULD: $*"
  else
    echo "    RUN:   $*"
    "$@" || echo "    [warn] (ignored failure): $*"
  fi
}

if [ "$(id -u)" -ne 0 ]; then
  echo "[ERROR] run as root:  sudo bash $0" >&2; exit 1
fi

echo "================================================================"
echo " CYRAD uninstaller"
echo "   deploy dir : $DEPLOY_DIR"
echo "   freeradius : $FRC"
echo "   drop db    : $DROP_DB   purge pkgs: $PURGE   dry-run: $DRY_RUN"
echo "================================================================"

if [ "${CONFIRM:-0}" != "1" ]; then
  echo
  read -r -p "Remove ALL CYRAD components from this server? [y/N] " ans
  [ "$ans" = "y" ] || [ "$ans" = "Y" ] || { echo "Aborted."; exit 0; }
  if [ "$DROP_DB" = "1" ]; then
    read -r -p "DROP the 'radius' database + user too? (type 'drop' to confirm) " ans2
    [ "$ans2" = "drop" ] || { echo "Keeping the database (Dropping aborted)."; DROP_DB=0; }
  fi
fi

# ---- 1. stop + disable services ----------------------------------------------
declare -a SVC=(freeradius cyrad-dnsmon dnsproxy cloudflared-cyrad cloudflared)
for s in "${SVC[@]}"; do
  if systemctl list-unit-files 2>/dev/null | grep -q "^$s\.service"; then
    run systemctl disable --now "$s"
  fi
done

# ---- 2. remove systemd units -------------------------------------------------
for u in /etc/systemd/system/cyrad-dnsmon.service \
         /etc/systemd/system/cloudflared-cyrad.service \
         /etc/systemd/system/dnsproxy.service; do
  [ -f "$u" ] && run rm -f "$u"
done
run systemctl daemon-reload

# ---- 3. remove app / config files --------------------------------------------
[ -d "$DEPLOY_DIR" ]           && run rm -rf "$DEPLOY_DIR"
[ -f /etc/cron.d/cyrad-limit ] && run rm -f /etc/cron.d/cyrad-limit
[ -f /etc/cron.d/cyrad-guest ] && run rm -f /etc/cron.d/cyrad-guest
[ -f /etc/cron.d/cyrad-guest-mail ] && run rm -f /etc/cron.d/cyrad-guest-mail
[ -f /etc/logrotate.d/cyrad-guest-mail ] && run rm -f /etc/logrotate.d/cyrad-guest-mail
[ -f /etc/nginx/sites-available/cyrad ] && run rm -f /etc/nginx/sites-available/cyrad
[ -L /etc/nginx/sites-enabled/cyrad ]   && run rm -f /etc/nginx/sites-enabled/cyrad
[ -d "$FRC" ]                  && run rm -rf "$FRC"
[ -d /etc/cloudflared ]        && run rm -rf /etc/cloudflared
[ -d /root/.cloudflared ]      && run rm -rf /root/.cloudflared
[ -f /etc/dnsproxy.yml ]       && run rm -f /etc/dnsproxy.yml
[ -d /etc/dnsproxy ]           && run rm -rf /etc/dnsproxy

# samba: restore the pre-CYRAD smb.conf if we saved one, else remove the AD join
if [ -f /root/smb.conf.cyrad.bak ]; then
  run cp -f /root/smb.conf.cyrad.bak /etc/samba/smb.conf
  run rm -f /root/smb.conf.cyrad.bak
elif [ -f /etc/samba/smb.conf ]; then
  if grep -q "CYRAD RADIUS" /etc/samba/smb.conf; then
    run rm -f /etc/samba/smb.conf
  fi
fi

if [ "$KEEP_CREDS" != "1" ]; then
  [ -f /root/CYRAD_CREDENTIALS.txt ] && run rm -f /root/CYRAD_CREDENTIALS.txt
  [ -f /root/cred.txt ]              && run rm -f /root/cred.txt
fi

# ---- 4. nginx reload (if nginx stays) ----------------------------------------
run systemctl reload nginx

# ---- 5. restore systemd-resolved if it was disabled ---------------------------
if [ -x /lib/systemd/systemd-resolved ]; then
  run systemctl enable --now systemd-resolved
fi

# ---- 6. packages --------------------------------------------------------------
if [ "$DRY_RUN" = "1" ]; then
  echo "    WOULD: apt-get purge CYRAD packages (freeradius* freeradius-ldap winbind samba-common-bin)"
else
  apt-get purge -y freeradius freeradius-common freeradius-config freeradius-utils \
                      freeradius-mysql freeradius-ldap winbind samba-common-bin \
                  >/dev/null 2>&1 || true
  if command -v cloudflared >/dev/null 2>&1; then
    dpkg --purge cloudflared >/dev/null 2>&1 || rm -f "$(command -v cloudflared)" || true
  fi
  echo "    [ok] CYRAD packages purged"
fi

if [ "$PURGE" = "1" ]; then
  echo "    PURGE=1 — additionally removing generic packages ..."
  run apt-get purge -y nginx nginx-common php-fpm php-common php-mysql php-cli \
                     php-mbstring php-xml php-curl mariadb-server certbot
elif [ "$DROP_DB" = "1" ]; then
  # DROP_DB without PURGE: keep the packages but remove the data
  run mysql -e "DROP DATABASE IF EXISTS \`radius\`; DROP USER IF EXISTS 'radius'@'localhost'; FLUSH PRIVILEGES;"
fi

echo
echo "================================================================"
echo " CYRAD removed${DROP_DB:+ (including the radius database)}."
[ "$DRY_RUN" = "1" ] && echo " DRY_RUN — nothing was changed."
echo
echo " Still to do on the ROUTERS (not this server):"
echo "   rm -f /usr/bin/cyrad-enforcer /usr/bin/cyrad-flowmon /usr/bin/cyrad-natsync"
echo "   rm -f /usr/bin/cyrad-sync-blocklist /etc/cyrad.conf /etc/dnsmasq.d/cyrad-block.conf"
echo "   rm -f /etc/nftables.d/cyrad-force-dns.nft /etc/hotplug.d/iface/99-cyrad-sinkhole"
echo "   rm -f /etc/init.d/cyrad-enforcer /etc/init.d/cyrad-flowmon /etc/init.d/cyrad-blockpage"
echo "   sed -i '/cyrad-/d' /etc/crontabs/root && service dnsmasq restart"
echo " If you used a Cloudflare tunnel: delete it in the Cloudflare Zero Trust"
echo " dashboard (Networks -> Tunnels)."
echo "================================================================"
