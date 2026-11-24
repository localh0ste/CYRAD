#!/usr/bin/env bash
# CYRAD — prepare and optionally serve router bootstrap files by IP.
#
# Copies the ONE router script (openwrt/cyrad-router-setup.sh) and the
# WireGuard .ipk packages into one directory, then serves it over plain HTTP.
# A router with broken DNS / empty opkg repo lists can still bootstrap by IP.
#
# Usage:
#   bash wireguard/server/bootstrap-http-server.sh --prepare   # download/copy only
#   bash wireguard/server/bootstrap-http-server.sh             # serve forever
# Env: CYRAD_HTTP_PORT (8000), WG_PKG_DIR, PKG_BASE_URL, PKG_TARGET_URL,
#      KMODVER, ARCH.
set -euo pipefail

PREPARE=0
[ "${1:-}" = "--prepare" ] && PREPARE=1

PORT="${CYRAD_HTTP_PORT:-8000}"
PKG_DIR="${WG_PKG_DIR:-/root/cyrad-wireguard-packages}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="$REPO/wireguard"

mkdir -p "$PKG_DIR"

# Flat filenames so the router only has to wget a simple URL.
# ONE router script does WireGuard + ubus kick + DNS blocking.
cp -f "$REPO/openwrt/cyrad-router-setup.sh" "$PKG_DIR/cyrad-router-setup.sh" 2>/dev/null || true

# Default packages: OpenWrt 22.03.7 / ath79-generic / mips_24kc. Override with
# PKG_BASE_URL if your target/arch/kernel differs (see wireguard/README.md).
BASE="${PKG_BASE_URL:-https://downloads.openwrt.org/releases/22.03.7/packages/mips_24kc/base}"
TGT="${PKG_TARGET_URL:-https://downloads.openwrt.org/releases/22.03.7/targets/ath79/generic/packages}"
KMODVER="${KMODVER:-5.10.221-1}"
ARCH="${ARCH:-mips_24kc}"

for f in \
  "wireguard-tools_1.0.20210424-3_${ARCH}.ipk" \
  "kmod-wireguard_${KMODVER}_${ARCH}.ipk" \
  "kmod-crypto-lib-chacha20poly1305_${KMODVER}_${ARCH}.ipk" \
  "kmod-crypto-lib-curve25519_${KMODVER}_${ARCH}.ipk" \
  "kmod-crypto-kpp_${KMODVER}_${ARCH}.ipk" \
  "kmod-crypto-lib-chacha20_${KMODVER}_${ARCH}.ipk" \
  "kmod-crypto-lib-poly1305_${KMODVER}_${ARCH}.ipk" \
  "kmod-udptunnel4_${KMODVER}_${ARCH}.ipk" \
  "kmod-udptunnel6_${KMODVER}_${ARCH}.ipk"; do
  if [ ! -s "$PKG_DIR/$f" ]; then
    curl -fsSL --max-time 45 "$BASE/$f" -o "$PKG_DIR/$f" 2>/dev/null || \
      curl -fsSL --max-time 45 "$TGT/$f" -o "$PKG_DIR/$f" 2>/dev/null || \
      echo "WARNING: could not fetch $f"
  fi
done
# Normalize names expected by the router script (skip self-copies).
normalized_copy() {
  [ "$1" = "$2" ] || cp -f "$1" "$2"
}
for f in "$PKG_DIR"/*.ipk; do
  [ -e "$f" ] || continue
  b=$(basename "$f")
  case "$b" in
    wireguard-tools_*) normalized_copy "$f" "$PKG_DIR/wireguard-tools.ipk" ;;
    kmod-wireguard_*) normalized_copy "$f" "$PKG_DIR/kmod-wireguard.ipk" ;;
    *) short=$(printf '%s' "$b" | sed -E "s/_${KMODVER}_${ARCH}\.ipk$/.ipk/"); normalized_copy "$f" "$PKG_DIR/$short" ;;
  esac
done

(ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true)
chmod -R a+rX "$PKG_DIR"

if [ "$PREPARE" = 1 ]; then
  echo "==> Prepared $PKG_DIR (no server started)"
  ls -1 "$PKG_DIR" | head -30
  exit 0
fi

echo "==> Serving $PKG_DIR on http://0.0.0.0:$PORT"
cd "$PKG_DIR"
exec python3 -m http.server "$PORT" --bind 0.0.0.0
