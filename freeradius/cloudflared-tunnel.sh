#!/usr/bin/env bash
#
# cloudflared-tunnel.sh — expose the CYRAD panel + block page on the public
# internet through a Cloudflare Tunnel (no open ports, no static IP needed).
# =============================================================================
# What is tunneled (HTTP only, Cloudflare Tunnel supports HTTP/TCP, not UDP):
#   panel.<DOMAIN>  -> http://127.0.0.1:8080   (CYRAD admin panel)
#   block.<DOMAIN>  -> http://127.0.0.1:80     (branded Access-Blocked page)
#
# NOT tunneled (keep direct, or use Cloudflare Spectrum):
#   UDP/53  DNS monitor           — your routers point at the VPS IP, not a name
#   UDP/1812/1813 RADIUS auth/acct — a WiFi client's supplicant talks RADIUS to
#                                     the NAS, and the NAS talks RADIUS to the
#                                     backend over UDP directly.
#
# Two modes (choose one):
#
#   A) REMOTE-MANAGED (simplest; ingress is defined in the CF dashboard):
#        CYRAD_CF_DOMAIN=cyrad.example.com \
#        CYRAD_CF_TOKEN=eyJhI... (the tunnel token from Zero Trust dashboard) \
#        sudo bash cloudflared-tunnel.sh
#      B) LOCALLY-MANAGED (ingress lives in /etc/cloudflared/config.yml):
#        CYRAD_CF_DOMAIN=cyrad.example.com sudo bash cloudflared-tunnel.sh
#      This runs `cloudflared tunnel login` (interactive browser) then creates
#      the tunnel + DNS records + local config + systemd unit.
#
# Requirements: a domain on Cloudflare, and its NS set to Cloudflare.
# =============================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run as root:  sudo bash $0" >&2; exit 1
fi

: "${CYRAD_CF_DOMAIN:?set CYRAD_CF_DOMAIN (the zone on Cloudflare, e.g. cyrad.example.com)}"
CYRAD_CF_TOKEN="${CYRAD_CF_TOKEN:-}"
NAME="${CYRAD_CF_TUNNEL_NAME:-cyrad}"
HOST="${CYRAD_CF_HOST:-panel.$CYRAD_CF_DOMAIN}"
BLOCKHOST="${CYRAD_CF_BLOCK_HOST:-block.$CYRAD_CF_DOMAIN}"
CFD=/usr/local/bin/cloudflared
CFDC=/etc/cloudflared

echo "==> CYRAD Cloudflare Tunnel"
echo "    zone:      $CYRAD_CF_DOMAIN"
echo "    panel:     https://$HOST"
echo "    blockpage: https://$BLOCKHOST"
[ -n "$CYRAD_CF_TOKEN" ] && echo "    mode:      REMOTE-MANAGED (token given)" || echo "    mode:      LOCALLY-MANAGED"

# ---- install cloudflared ----------------------------------------------------
if [ ! -x "$CFD" ]; then
  echo "==> Installing cloudflared ..."
  ARCH="$(uname -m)"
  case "$ARCH" in
    x86_64|amd64) P="amd64";; aarch64|arm64) P="arm64";; *) echo "unsupported arch $ARCH" >&2; exit 1;; esac
  . /etc/os-release 2>/dev/null || true
  case "${ID:-debian}" in
    debian|ubuntu)
      curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-$P.deb" -o /tmp/cloudflared.deb
      dpkg -i /tmp/cloudflared.deb || apt-get install -f -y
      ;;
    *) curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-$P" -o "$CFD"; chmod +x "$CFD";;
  esac
fi
cloudflared --version | sed 's/^/    /'

mkdir -p "$CFDC"

# ---- REMOTE-MANAGED tunnel ---------------------------------------------------
if [ -n "$CYRAD_CF_TOKEN" ]; then
  cat > "$CFDC/config.yml" <<EOF
# CYRAD Cloudflare Tunnel — REMOTE-MANAGED ($NAME)
# The ingress mapping for this tunnel is managed in the Zero Trust dashboard
# (Networks -> Tunnels -> $NAME -> Public Hostname). Example hostnames:
#   panel:  $HOST              -> http://127.0.0.1:8080
#   block:  $BLOCKHOST          -> http://127.0.0.1:80
tunnel: $NAME
credentials-file: /etc/cloudflared/${NAME}.json
EOF
  cloudflared service install "$CYRAD_CF_TOKEN"
  echo
  echo "==> DONE (remote-managed). Now in the Cloudflare Zero Trust dashboard:"
  echo "    Public Hostname  Service           "
  echo "    ----------------  -----------------"
  echo "    $HOST  ->  http://127.0.0.1:8080"
  echo "    $BLOCKHOST  ->  http://127.0.0.1:80"
  echo "    then reconnect/refresh; panel online at https://$HOST/admin/login.php"
  exit 0
fi

# ---- LOCALLY-MANAGED tunnel --------------------------------------------------
echo "==> Authenticating cloudflared (open the printed URL in a browser) ..."
cloudflared tunnel login || { echo "ERROR: cloudflared tunnel login failed" >&2; exit 1; }

if ! cloudflared tunnel list | grep -q "  $NAME "; then
  echo "==> Creating tunnel '$NAME' ..."
  cloudflared tunnel create "$NAME"
fi

echo "==> Writing $CFDC/config.yml ..."
cat > "$CFDC/config.yml" <<EOF
# CYRAD Cloudflare Tunnel — LOCALLY-MANAGED
tunnel: $NAME
credentials-file: /etc/cloudflared/${NAME}.json

ingress:
  # CYRAD admin panel (nginx :8080)
  - hostname: $HOST
    service: http://127.0.0.1:8080
  # Branded Access-Blocked page (nginx :80 -> block.php)
  - hostname: $BLOCKHOST
    service: http://127.0.0.1:80
  # Everything else: refuse
  - service: http_status:502
EOF

echo "==> Routing DNS records ..."
cloudflared tunnel route dns "$NAME" "$HOST" || true
cloudflared tunnel route dns "$NAME" "$BLOCKHOST" || true

echo "==> Installing systemd unit cloudflared-cyrad ..."
cat > /etc/systemd/system/cloudflared-cyrad.service <<EOF
[Unit]
Description=Cloudflare Tunnel for CYRAD ($HOST)
After=network.target nginx.service
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cloudflared tunnel --config $CFDC/config.yml run $NAME
Restart=on-failure
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now cloudflared-cyrad

sleep 6
cloudflared tunnel info "$NAME" 2>/dev/null | sed 's/^/    /' || true

echo
echo "================================================================"
echo " Cloudflare Tunnel active."
echo "   Panel:     https://$HOST/admin/login.php"
echo "   Block page:https://$BLOCKHOST  (serves block.php on :80)"
echo
echo " RADIUS (1812/1813/udp) and DNS (53/udp) are NOT tunneled — keep"
echo " your router pointed at the VPS IP directly (or use Cloudflare Spectrum)."
echo "================================================================"