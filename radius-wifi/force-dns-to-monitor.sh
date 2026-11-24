#!/usr/bin/env bash
# ============================================================================
# OPTIONAL gateway hardening for CYRAD.
#
# The DNS monitor only sees queries that actually reach it. A student who sets
# their device DNS to 8.8.8.8 (or enables a browser DoH) bypasses it entirely,
# which also defeats the VPN/proxy DNS-layer block (db/v3_vpn_block.sql).
#
# Run this on your gateway/router (or on the CYRAD box if it IS the gateway) to:
#   - redirect ALL UDP/TCP port 53 to the CYRAD DNS monitor
#   - block DoT (port 853)
# so clients physically cannot use an outside resolver.
#
# Usage:  sudo CYRAD_MONITOR_IP=10.0.0.1 LAN_IFACE=eth0 bash force-dns-to-monitor.sh
# ============================================================================
set -euo pipefail

MONITOR_IP="${CYRAD_MONITOR_IP:-127.0.0.1}"
LAN_IFACE="${LAN_IFACE:-eth0}"

echo "==> Forcing all DNS (UDP/TCP 53) on $LAN_IFACE to $MONITOR_IP"
iptables -t nat -A PREROUTING -i "$LAN_IFACE" -p udp --dport 53 -j DNAT --to-destination "$MONITOR_IP":53
iptables -t nat -A PREROUTING -i "$LAN_IFACE" -p tcp --dport 53 -j DNAT --to-destination "$MONITOR_IP":53

echo "==> Blocking DoT (TCP 853) so clients can't tunnel DNS over TLS"
iptables -A FORWARD -i "$LAN_IFACE" -p tcp --dport 853 -j REJECT --reject-with tcp-reset

echo
echo "Done. DNS is now forced to $MONITOR_IP; outside resolvers and DoT are blocked."
echo "Note: this is runtime-only. Persist with iptables-save / nftables on your gateway."
