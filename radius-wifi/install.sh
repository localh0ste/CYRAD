#!/usr/bin/env bash
# ============================================================================
# CYRAD - RADIUS 802.1X WiFi authentication installer  (no captive portal)
# ============================================================================
# One-shot, idempotent installer. It:
#   1. Detects the OS (Debian/Ubuntu or RHEL/Rocky/Alma/Fedora/Amazon).
#   2. Installs ALL prerequisites (FreeRADIUS + mysql module + utils, openssl,
#      MariaDB server if missing, hostapd if --ap).
#   3. Ensures MariaDB is running and the `radius` DB + tables exist.
#   4. Generates EAP TLS certs if missing.
#   5. Writes SQL / EAP / sites configs (PAP kept so captive-portal still works).
#   6. Registers the WiFi AP as a RADIUS client (nas table).
#   7. Opens the firewall for RADIUS (1812/1813 UDP).
#   8. Validates the FreeRADIUS config, then starts + enables services.
#   9. Seeds a demo user (fresh DB or --seed) and runs an end-to-end radtest.
#
# Usage:
#   sudo bash install.sh [--ap] [--seed] [--nas-ip IP] [--nas-secret SECRET] \
#                        [--ap-ssid SSID] [--ap-iface wlan0] \
#                        [--db-host 127.0.0.1] [--db-user radius] \
#                        [--db-pass PASS] [--db-name radius]
#
# Any config it overwrites is backed up to *.bak-<timestamp> first.
# ============================================================================
set -euo pipefail

FR_DIR="/etc/freeradius/3.0"
CERTS_DIR="$FR_DIR/certs"

# ---- args ----
WITH_AP=0
SEED_DEMO=0
NAS_IP=""
NAS_SECRET="cyradsecret"
AP_SSID="CYRAD-Students"
AP_IFACE="wlan0"
DB_HOST="127.0.0.1"
DB_USER=""
DB_PASS=""
DB_NAME="radius"
SUPER_ADMIN=""
SUPER_PASS=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ap)         WITH_AP=1 ;;
    --seed)       SEED_DEMO=1 ;;
    --nas-ip)     NAS_IP="$2"; shift ;;
    --nas-secret) NAS_SECRET="$2"; shift ;;
    --ap-ssid)    AP_SSID="$2"; shift ;;
    --ap-iface)   AP_IFACE="$2"; shift ;;
    --db-host)    DB_HOST="$2"; shift ;;
    --db-user)    DB_USER="$2"; shift ;;
    --db-pass)    DB_PASS="$2"; shift ;;
    --db-name)    DB_NAME="$2"; shift ;;
    --super-admin) SUPER_ADMIN="$2"; shift ;;
    --super-pass)  SUPER_PASS="$2"; shift ;;
    -h|--help)    sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
  shift
done

[[ $EUID -eq 0 ]] || { echo "Run as root: sudo bash $0" >&2; exit 1; }

echo "==> CYRAD 802.1X installer: FreeRADIUS + MySQL (no captive portal)"

# ---- OS detection ----
DISTRO=""
if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  DISTRO="$ID"
fi
case "$DISTRO" in
  ubuntu|debian|raspbian|linuxmint|kali)
    PKG=apt;  SVC=freeradius; MARIADB_SVC=mariadb ;;
  centos|rhel|rocky|alma|fedora|amzn|ol)
    PKG=dnf;  SVC=radiusd;     MARIADB_SVC=mariadb ;;
  *)
    echo "!! Unrecognised OS ($DISTRO) - defaulting to apt/freeradius" >&2
    PKG=apt;  SVC=freeradius; MARIADB_SVC=mariadb ;;
esac
echo "==> OS: ${DISTRO:-unknown}  | pkg manager: $PKG  | service: $SVC"

# freeradius user/group (for cert perms)
if id freerad >/dev/null 2>&1; then FR_USER=freerad; FR_GROUP=freerad;
elif id radiusd >/dev/null 2>&1; then FR_USER=radiusd; FR_GROUP=radiusd;
else FR_USER=root; FR_GROUP=root; fi

# ---- DB credentials (explicit > CYRAD config.php > prompt) ----
if [[ -z "$DB_USER" || -z "$DB_PASS" ]]; then
  for cf in /var/www/html/cyrad/config.php /tmp/CYRAD/config.php; do
    if [[ -f "$cf" ]] && command -v php >/dev/null 2>&1; then
      DB_USER="$(php -d error_reporting=0 -r "require '$cf'; echo defined('CYRAD_DB_USER')?CYRAD_DB_USER:'';" 2>/dev/null || true)"
      DB_PASS="$(php -d error_reporting=0 -r "require '$cf'; echo defined('CYRAD_DB_PASS')?CYRAD_DB_PASS:'';" 2>/dev/null || true)"
      [[ -n "$DB_USER" ]] && break
    fi
  done
fi
[[ -z "$DB_USER" ]] && DB_USER="radius"
if [[ -z "$DB_PASS" ]]; then
  read -rsp "MySQL password for RADIUS DB user ($DB_USER): " DB_PASS; echo
fi
echo "==> DB: $DB_USER@$DB_HOST/$DB_NAME"

# ---- install prerequisites ----
export DEBIAN_FRONTEND=noninteractive
echo "==> Installing prerequisites"
AP_PKG=""
[[ $WITH_AP -eq 1 ]] && AP_PKG="hostapd"
if [[ $PKG == apt ]]; then
  apt-get update -y
  # shellcheck disable=SC2086
  apt-get install -y freeradius freeradius-mysql freeradius-utils openssl $AP_PKG
  if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    apt-get install -y mariadb-server
  fi
elif [[ $PKG == dnf ]]; then
  # shellcheck disable=SC2086
  dnf -y install freeradius freeradius-mysql freeradius-utils openssl $AP_PKG
  if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    dnf -y install mariadb-server
  fi
fi

# ---- ensure MariaDB is running ----
if systemctl list-unit-files 2>/dev/null | grep -q "$MARIADB_SVC.service"; then
  echo "==> Ensuring MariaDB is running"
  systemctl enable --now "$MARIADB_SVC" 2>/dev/null || systemctl enable --now mysql 2>/dev/null || true
fi

# ---- ensure radius DB + tables ----
echo "==> Ensuring database '$DB_NAME'"
mysql_ok() { mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "SELECT 1;" "$DB_NAME" >/dev/null 2>&1; }

if ! mysql_ok; then
  echo "    DB connect as '$DB_USER' failed - attempting to create DB as root (socket)"
  mysql -u root <<SQL || echo "    (root auto-create failed - please create DB '$DB_NAME' manually)"
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'%';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
fi

HAS_RADCHECK=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e "SHOW TABLES LIKE 'radcheck';" "$DB_NAME" 2>/dev/null | wc -l) || true
if [[ "${HAS_RADCHECK:-0}" -eq 0 ]]; then
  echo "    'radcheck' missing - loading FreeRADIUS schema"
  SCHEMA=""
  for p in /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql \
           /usr/share/freeradius/sql/mysql/schema.sql \
           /etc/raddb/mods-config/sql/main/mysql/schema.sql; do
    [[ -f "$p" ]] && SCHEMA="$p" && break
  done
  if [[ -n "$SCHEMA" ]]; then
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" < "$SCHEMA" || true
    # CYRAD extension: link users to an organization
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" -e \
      "ALTER TABLE radcheck ADD COLUMN IF NOT EXISTS organization_id INT NULL;" 2>/dev/null || true
    SEED_DEMO=1   # fresh DB -> seed demo user
  else
    echo "    WARNING: FreeRADIUS schema.sql not found; expecting CYRAD to have created the tables."
  fi
fi

# ---- EAP certs ----
echo "==> Ensuring EAP TLS certs"
if [[ ! -f "$CERTS_DIR/server.pem" ]]; then
  echo "    generating default certs"
  ( cd "$CERTS_DIR" && make >/dev/null 2>&1 ) || \
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$CERTS_DIR/server.key" \
      -out "$CERTS_DIR/server.pem" -days 3650 -subj "/CN=CYRAD-RADIUS" \
      -passout pass:whatever 2>/dev/null || true
fi
if [[ ! -f "$CERTS_DIR/dh" ]]; then
  openssl dhparam -out "$CERTS_DIR/dh" 2048 2>/dev/null || true
fi
chown -R root:"$FR_GROUP" "$CERTS_DIR" 2>/dev/null || true
chmod 640 "$CERTS_DIR/server.key" "$CERTS_DIR/dh" 2>/dev/null || true
chmod 644 "$CERTS_DIR/server.pem" "$CERTS_DIR/ca.pem" 2>/dev/null || true

# ---- helpers ----
backup() {
  local f="$1"
  if [[ -f "$f" && ! -L "$f" ]]; then cp -a "$f" "$f.bak-$(date +%s)"; fi
}

# ---- SQL module ----
echo "==> Configuring SQL module (MySQL) -> database '$DB_NAME'"
backup "$FR_DIR/mods-enabled/sql"
cat > "$FR_DIR/mods-enabled/sql" <<SQL
sql {
    dialect    = "mysql"
    driver     = "rlm_sql_mysql"
    server     = "$DB_HOST"
    port       = 3306
    login      = "$DB_USER"
    password   = "$DB_PASS"
    radius_db  = "$DB_NAME"
    read_clients = yes          # NAS clients are read from the 'nas' table
    client_table = "nas"
    group_attribute = "SQL-Group"
    \$INCLUDE \${mods-config}/sql/main/mysql/queries.conf
}
SQL

# ---- EAP module (PEAP-MSCHAPv2 + TTLS/PAP) ----
echo "==> Configuring EAP (PEAP / TTLS)"
backup "$FR_DIR/mods-enabled/eap"
cat > "$FR_DIR/mods-enabled/eap" <<'EAP'
eap {
    default_eap_type = peap
    timer_expire     = 60
    ignore_unknown_eap_types = no
    cisco_accounting_username_bug = no
    max_sessions = ${max_requests}

    tls-config tls-common {
        private_key_password = whatever
        private_key_file = ${certdir}/server.key
        certificate_file = ${certdir}/server.pem
        ca_file = ${cadir}/ca.pem
        dh_file = ${certdir}/dh
        random_file = /dev/urandom
        fragment_size = 1024
        include_length = yes
        check_crl = no
    }

    peap {
        default_eap_type = mschapv2
        copy_request_to_tunnel = yes
        use_tunneled_reply = yes
        virtual_server = "inner-tunnel"
    }

    ttls {
        default_eap_type = pap
        copy_request_to_tunnel = yes
        use_tunneled_reply = yes
        virtual_server = "inner-tunnel"
    }

    mschapv2 {}
}
EAP

# ---- sites (handle BOTH 802.1X/EAP and the existing web PAP auth) ----
echo "==> Configuring sites (default + inner-tunnel)"
backup "$FR_DIR/sites-enabled/default"
cat > "$FR_DIR/sites-enabled/default" <<'SITE'
server default {
    listen {
        type = auth
        ipaddr = *
        port = 0
        limit { max_connections = 16; lifetime = 0; idle_timeout = 30; }
    }
    listen {
        type = acct
        ipaddr = *
        port = 0
        limit {}
    }

    authorize {
        filter_username
        pap
        sql
        eap { ok = return }
    }
    authenticate {
        eap
        pap
    }
    post-auth {
        sql
        remove_reply_message_if_eap
    }
    preacct {
        framedip
    }
    accounting {
        sql
    }
}
SITE

backup "$FR_DIR/sites-enabled/inner-tunnel"
cat > "$FR_DIR/sites-enabled/inner-tunnel" <<'INNER'
server inner-tunnel {
    listen {
        type = auth
        ipaddr = 127.0.0.1
        port = 18120
        limit {}
    }
    authorize {
        sql
        mschap
        eap { ok = return }
    }
    authenticate {
        mschap
        eap
    }
    post-auth {
        sql
    }
}
INNER

# ---- enable modules (idempotent) ----
for m in sql eap mschap pap; do
  ln -sf "$FR_DIR/mods-available/$m" "$FR_DIR/mods-enabled/$m"
done

# ---- register the WiFi AP as a RADIUS client (nas table) ----
if [[ -n "$NAS_IP" ]]; then
  echo "==> Registering WiFi AP ($NAS_IP) as RADIUS client"
  mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" <<SQL || echo "  (nas insert failed - add the AP to the nas table manually)"
INSERT IGNORE INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('$NAS_IP', 'college-ap', 'other', NULL, '$NAS_SECRET', 'CYRAD WiFi AP (802.1X)');
SQL
fi

# ---- seed demo user ----
if [[ $SEED_DEMO -eq 1 ]]; then
  SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  if [[ -f "$SCRIPT_DIR/users.sql" ]]; then
    echo "==> Seeding demo user (student01 / ChangeMe123!)"
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" < "$SCRIPT_DIR/users.sql" || echo "  (seed failed)"
  fi
fi

# ---- firewall ----
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q active; then
  echo "==> Opening RADIUS ports in ufw"
  ufw allow 1812/udp; ufw allow 1813/udp
fi
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active firewalld >/dev/null 2>&1; then
  echo "==> Opening RADIUS ports in firewalld"
  firewall-cmd --permanent --add-port=1812/udp
  firewall-cmd --permanent --add-port=1813/udp
  firewall-cmd --reload
fi

# ---- validate config ----
echo "==> Validating FreeRADIUS config"
RBIN=""
command -v freeradius >/dev/null 2>&1 && RBIN=freeradius
command -v radiusd    >/dev/null 2>&1 && RBIN=radiusd
[[ -n "$RBIN" ]] || { echo "!! FreeRADIUS binary not found" >&2; exit 1; }
if ! $RBIN -XC; then
  echo "!! CONFIG CHECK FAILED - aborting (originals backed up to *.bak-*)" >&2
  exit 1
fi

# ---- restart + enable ----
echo "==> Starting FreeRADIUS ($SVC)"
systemctl restart "$SVC"
systemctl enable "$SVC"

# ---- optional software AP ----
if [[ $WITH_AP -eq 1 ]]; then
  echo "==> Configuring hostapd ($AP_IFACE / $AP_SSID)"
  cat > /etc/hostapd/hostapd.conf <<AP
interface=$AP_IFACE
driver=nl80211
ssid=$AP_SSID
hw_mode=g
channel=6
macaddr_acl=0
auth_algs=1
ignore_broadcast_ssid=0
wpa=2
wpa_key_mgmt=WPA-EAP
wpa_pairwise=TKIP CCMP
ieee8021x=1
eap_server=0
own_ip_addr=127.0.0.1
auth_server_addr=127.0.0.1
auth_server_port=1812
auth_server_shared_secret=$NAS_SECRET
acct_server_addr=127.0.0.1
acct_server_port=1813
acct_server_shared_secret=$NAS_SECRET
AP
  if [[ -f /etc/default/hostapd ]]; then
    sed -i 's|^#\?DAEMON_CONF=.*|DAEMON_CONF=/etc/hostapd/hostapd.conf|' /etc/default/hostapd
  fi
  systemctl enable hostapd
  systemctl restart hostapd
fi

# ---- self-test (PAP against DB) ----
if command -v radtest >/dev/null 2>&1; then
  echo "==> Self-test: radtest student01 (PAP vs DB)"
  mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" -e \
    "INSERT IGNORE INTO nas (nasname, shortname, type, ports, secret, description) VALUES ('127.0.0.1','localhost','other',NULL,'$NAS_SECRET','CYRAD local selftest');" 2>/dev/null || true
  if radtest student01 ChangeMe123! 127.0.0.1 0 "$NAS_SECRET" 2>&1 | grep -q "Access-Accept"; then
    echo "    PASS: student01 authenticated (Access-Accept)"
  else
    echo "    WARN: radtest did not return Access-Accept - check: journalctl -u $SVC"
  fi
fi

# ---- ensure web-panel org + super-admin (if web tables exist) ----
RADIUS_IP=$(hostname -I 2>/dev/null | awk '{print $1}') || true
echo "==> Ensuring web-panel organization + super-admin"
HAS_ADMIN_TBL=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e "SHOW TABLES LIKE 'admin_users';" "$DB_NAME" 2>/dev/null | wc -l) || true
SUPER_ADMIN_OUT="$SUPER_ADMIN"
SUPER_PASS_OUT="$SUPER_PASS"
if [[ "${HAS_ADMIN_TBL:-0}" -gt 0 ]]; then
  ORG_COUNT=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e "SELECT COUNT(*) FROM organizations;" "$DB_NAME" 2>/dev/null || echo 0)
  if [[ "${ORG_COUNT:-0}" -eq 0 ]]; then
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" -e "INSERT INTO organizations (org_name, vpn_block_enabled) VALUES ('Default Organization', 1);" 2>/dev/null || true
  fi
  ORG_ID=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e "SELECT id FROM organizations ORDER BY id LIMIT 1;" "$DB_NAME" 2>/dev/null || echo 1)
  SUPER_COUNT=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -N -e "SELECT COUNT(*) FROM admin_users WHERE role='super_admin';" "$DB_NAME" 2>/dev/null || echo 0)
  if [[ "${SUPER_COUNT:-0}" -eq 0 ]]; then
    [[ -z "$SUPER_ADMIN_OUT" ]] && SUPER_ADMIN_OUT="superadmin"
    if [[ -z "$SUPER_PASS_OUT" ]]; then
      SUPER_PASS_OUT="$(openssl rand -base64 9 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 12)"
      [[ -z "$SUPER_PASS_OUT" ]] && SUPER_PASS_OUT="ChangeMe123!"
    fi
    HASH=$(SUPER_PASS_OUT="$SUPER_PASS_OUT" php -r "echo password_hash(getenv('SUPER_PASS_OUT'), PASSWORD_DEFAULT);" 2>/dev/null) || true
    if [[ -n "$HASH" ]]; then
      cat > /tmp/cyrad_super.sql <<SQL
INSERT INTO admin_users (username, password, full_name, organization_id, role) VALUES ('$SUPER_ADMIN_OUT', '$HASH', 'Super Admin', $ORG_ID, 'super_admin');
SQL
      if mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" < /tmp/cyrad_super.sql 2>/dev/null; then
        echo "    Created super-admin: $SUPER_ADMIN_OUT"
      else
        echo "    (super-admin insert failed - create manually in admin_users)"
      fi
    else
      echo "    (php unavailable to hash password - skipping super-admin creation)"
    fi
  else
    SUPER_ADMIN_OUT="(already exists)"
    SUPER_PASS_OUT="(unchanged)"
  fi
else
  SUPER_ADMIN_OUT="(web tables absent)"
  SUPER_PASS_OUT="(n/a)"
fi

echo
echo "=========================================================="
echo " CYRAD 802.1X WiFi auth is configured."
echo " - Connect with WPA2-Enterprise / 802.1X"
echo " - Identity = college username, password = college password"
echo " - FreeRADIUS validates against the '$DB_NAME' DB (radcheck)"
echo " - Captive-portal PAP auth is preserved."
echo " Next: point your AP/controller RADIUS auth to this host"
echo "        (UDP 1812/1813, secret: $NAS_SECRET)."
if [[ $SEED_DEMO -eq 1 ]]; then
echo " Demo user: student01 / ChangeMe123!  (change it!)"
fi
echo
echo " Web Control Panel (super-admin):"
echo "   Username : $SUPER_ADMIN_OUT"
echo "   Password : $SUPER_PASS_OUT"
echo "   URL      : http://$RADIUS_IP:8080/admin/login.php"
echo " Router / AP RADIUS config:"
echo "   RADIUS Server IP : $RADIUS_IP"
echo "   RADIUS Secret    : $NAS_SECRET   (UDP 1812 auth / 1813 acct)"
echo "   Test user        : student01 / ChangeMe123!"
echo "=========================================================="
