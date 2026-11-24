# CYRAD OpenWrt router — one script

`cyrad-router-setup.sh` is the **only** router script now. The legacy scripts
(`setup-openwrt.sh`, `setup-cyrad-dns.sh`, `deploy-dns-block.sh`,
`cyrad-enforcer.sh`, `cyrad-natsync.sh` and the separate WireGuard router
scripts) were removed from the project and the web root.

It configures, in one run:

1. **WireGuard tunnel** to the CYRAD VPS (`wg0`, raw `ip`/`wg`, reboot-persistent
   via `/etc/rc.local`). No inbound port-forward.
2. **ubus disconnect helper** (`/usr/bin/cyrad-kick`) plus the VPS SSH public key,
   so the backend can deauthenticate a MAC over the tunnel when hostapd does not
   answer RADIUS DAE.
3. **DNS blocking**: `/etc/cyrad.conf`, dnsmasq blocklist confdir, a 5-minute
   blocklist sync from `admin/api_dns_rules.php`, and an optional forced-LAN-DNS
   nft rule.

It deliberately does **not** restart WiFi or change RADIUS/PPPoE/LAN settings —
SSH and clients stay connected.

## Deploy from the VPS

The VPS installer writes a ready-to-run file:

```sh
scp root@<VPS>:/root/cyrad-router-bootstrap.sh /tmp/
sh /tmp/cyrad-router-bootstrap.sh
```

That file exports all settings and does:

```sh
wget -q http://<VPS>:8000/cyrad-router-setup.sh -O /tmp/cyrad-router-setup.sh
sh /tmp/cyrad-router-setup.sh
```

## Manual invocation

```sh
wget -q http://<VPS>:8000/cyrad-router-setup.sh -O /tmp/cyrad-router.sh
BACKEND_IP=139.59.80.184 RADIUS_SECRET='...' API_TOKEN='...' ORG_ID=2 \
SERVER_ENDPOINT=139.59.80.184 SERVER_WG_IP=10.9.0.1 ROUTER_WG_IP=10.9.0.2 \
SERVER_PUBLIC_KEY='...' ROUTER_PRIVATE_KEY='...' CYRAD_VPS_SSH_PUBKEY='ssh-ed25519 ...' \
sh /tmp/cyrad-router.sh
```

`ORG_ID=0` (default) exports **all** enabled block rules from the panel; use a
specific org id to limit the router to that organization.

## Verify

```sh
wg show
ping -c 2 10.9.0.1
cat /etc/cyrad.conf
wc -l /etc/dnsmasq.d/cyrad-block.conf
nslookup <blocked-domain> 192.168.2.1
ubus call hostapd.wlan1 del_client '{"addr":"aa:bb:cc:dd:ee:ff","deauth":true,"ban_time":0}'
```
