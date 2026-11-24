# CYRAD — fresh VPS + OpenWrt installation

This is the complete, reproducible install path. One command sets up the VPS;
one wget-based script sets up the router. No inbound port-forward is needed on
the home/ISP router.

## Architecture (why there is no port-forward)

```
WiFi client ──802.1X──> OpenWrt hostapd ──RADIUS 1812/1813──> CYRAD VPS
                              │                                  │
                              │  WireGuard (outbound from router) │
                              └──── 10.9.0.2 <──────> 10.9.0.1 ──┘
                                                                  │
                              cap exceeded → SSH over wg0 → /usr/bin/cyrad-kick
                                           → ubus hostapd.* del_client <MAC>
                              DNS rules  → dnsmasq blocklist (router pulls via API)
```

- The **VPS** has a public IP; UDP 1812/1813 (RADIUS), 51820 (WireGuard), 8000
  (bootstrap), 8080 (panel), 53 (DNS monitor) must be allowed in its firewall.
- The **router** only initiates *outbound* connections. Nothing has to reach it
  from the internet: DAE to `10.9.0.2:3799` and the SSH/ubus kick both travel
  over the WireGuard tunnel.
- This hostapd build accepts RADIUS Disconnect packets but does not answer
  them, so the reliable enforcement path is the `ubus del_client` fallback.

---

## 1) Fresh VPS — one command

```bash
unzip finalCyrad.zip && cd CYRAD
sudo bash deploy.sh --install
# equivalent: sudo bash install.sh
```

`deploy.sh --install` simply delegates to `install.sh` → `setup-cyrad.sh`,
which installs, in order:

1. LEMP (nginx + PHP-FPM + MariaDB), FreeRADIUS, web panel, DNS monitor,
   guest/DNS/limit crons.
2. WireGuard server (`/etc/wireguard/wg0.conf`, `wg-quick@wg0`), UDP `51820`.
3. SSH kick key (`/root/.ssh/cyrad_router_ed25519`) and VPS helper
   `/usr/local/bin/cyrad_router_kick`.
4. Persistent bootstrap HTTP service `cyrad-wg-bootstrap` serving the single
   router script + OpenWrt `.ipk` packages on TCP `8000`.
5. Root-only `/root/cyrad-router-bootstrap.sh` and
   `/root/cyrad-wireguard-router.env` (keys, token, endpoint, DNS org).
6. `/root/CYRAD_CREDENTIALS.txt` with panel/RADIUS/API credentials.

### Useful env vars

All are optional; the installer generates random secrets when omitted and
prints/saves them in `/root/CYRAD_CREDENTIALS.txt`.

| Variable | Default | Purpose |
|---|---|---|
| `CYRAD_SERVER_IP` | detected public IP | backend address used in panel links |
| `CYRAD_ADMIN_PASS` | random | initial admin password |
| `CYRAD_RADIUS_SECRET` | random | RADIUS/DAS shared secret |
| `CYRAD_SKIP_DOT` | `0` | set `1` to skip DNS-over-TLS/certbot |
| `CYRAD_DAS_TARGET_IP` | `10.9.0.2` | DAE target over the tunnel |
| `CYRAD_INSTALL_WIREGUARD` | `1` | `0` skips WireGuard + router bootstrap |
| `CYRAD_WG_PORT` | `51820` | WireGuard UDP port |
| `CYRAD_SERVER_WG_IP` | `10.9.0.1` | VPS tunnel IP |
| `CYRAD_ROUTER_WG_IP` | `10.9.0.2` | router tunnel IP |
| `CYRAD_HTTP_PORT` | `8000` | router-bootstrap HTTP port |
| `CYRAD_DNS_ORG_ID` | `0` | `0` = all enabled DNS rules; set an org id for single-tenant routers |
| `CYRAD_OPENWRT_IP` | empty | register a FreeRADIUS client for a public/static router IP (optional with the tunnel) |
| `CYRAD_CF_DOMAIN` / `CYRAD_CF_TOKEN` | empty | optional Cloudflare tunnel (outbound only) |

Re-run safely after a code change with:

```bash
sudo bash deploy.sh            # code push only, preserves config.php/secrets
sudo bash install.sh UPDATE=1  # full in-place refresh (migrations + services)
```

---

## 2) OpenWrt router — one script, fetched by IP

The VPS writes a ready-to-run file that already contains the tunnel keys and
API token. Copy it to the router over SSH (dropbear has no sftp, so use stdin):

```bash
ssh root@<router-ip> 'cat > /tmp/cyrad-router.sh && chmod 700 /tmp/cyrad-router.sh' \
  < /root/cyrad-router-bootstrap.sh
ssh root@<router-ip> 'sh /tmp/cyrad-router.sh'
```

That file exports the settings and runs:

```sh
wget -q http://<VPS-IP>:8000/cyrad-router-setup.sh -O /tmp/cyrad-router-setup.sh
sh /tmp/cyrad-router-setup.sh
```

`openwrt/cyrad-router-setup.sh` is the **only** router script. It configures:

- `wg0` with raw `ip`/`wg` (no netifd/UCI WireGuard dependency) and persists it
  in `/etc/rc.local`;
- fw4 rule accepting DAE (UDP 3799) on `wg0`;
- `/usr/bin/cyrad-kick` (ubus `hostapd.* del_client`) plus the VPS SSH public
  key in `/etc/dropbear/authorized_keys`;
- DNS blocking: `/etc/cyrad.conf`, dnsmasq `confdir=/etc/dnsmasq.d`, 5-minute
  blocklist sync from `admin/api_dns_rules.php`, and a forced-LAN-DNS nft rule.

It does **not** restart WiFi, change RADIUS/PPPoE/LAN settings or drop clients.
If opkg/DNS is broken on the router, the script falls back to fetching the
WireGuard packages from `http://<VPS-IP>:8000/*.ipk` by IP.

Manual invocation (advanced):

```sh
wget -q http://<VPS-IP>:8000/cyrad-router-setup.sh -O /tmp/cyrad-router.sh
BACKEND_IP=<VPS-IP> SERVER_ENDPOINT=<VPS-IP> \
RADIUS_SECRET='...' API_TOKEN='...' ORG_ID=0 \
SERVER_WG_IP=10.9.0.1 ROUTER_WG_IP=10.9.0.2 \
SERVER_PUBLIC_KEY='...' ROUTER_PRIVATE_KEY='...' \
CYRAD_VPS_SSH_PUBKEY='ssh-ed25519 ...' \
sh /tmp/cyrad-router.sh
```

---

## 3) Verification

On the VPS:

```bash
systemctl is-active wg-quick@wg0 cyrad-wg-bootstrap
wg show wg0
ping -c 2 10.9.0.2
ssh -i /root/.ssh/cyrad_router_ed25519 -o StrictHostKeyChecking=no root@10.9.0.2 'echo ROUTER_OK'
curl -fsS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/cyrad-router-setup.sh
```

On the router:

```sh
wg show
ping -c 2 10.9.0.1
nft list table inet fw4 | grep -A4 cyrad_force_dns
cat /etc/cyrad.conf
wc -l /etc/dnsmasq.d/cyrad-block.conf
```

Panel checks:

- `http://<VPS-IP>:8080/admin/login.php` → **DNS Rules** menu opens, adding a
  block rule works, and `api_dns_rules.php?org=0&token=...` lists it.
- **Active Sessions** side-menu page shows MAC, session id, username, duration.
- **Guest Tickets** side-menu page opens for both admin and super-admin, can
  mint a voucher, and the expiry/mail cron scripts run without a 500. On an
  upgrade, `admin/apply_migrations.php` applies
  `db/v16_guest_ticket_collation.sql` so the guest/RADIUS tables share the
  `utf8mb4_unicode_ci` collation.
- Wait up to 5 minutes (or run `/usr/bin/cyrad-sync-blocklist` on the router),
  then `nslookup <blocked-domain> <router-LAN-IP>` must return NXDOMAIN. The
  router restarts dnsmasq only when the blocklist actually changes; a rule
  covers the domain and all its subdomains.
- Blocking applies to plain DNS (port 53), which is forced to the router.
  Devices using Android Private DNS, Chrome/Firefox “Secure DNS”, iCloud
  Private Relay or a VPN bypass it — turn those off on the phone to test.
- Exceed a user's cap: accounting stops and the client is deauthenticated by
  `/usr/local/bin/cyrad_router_kick <MAC>` (twice-minute cap cron, 24 h ban).

---

## 4) Rollback / safety

Before changes, a production snapshot is written to
`/root/cyrad-production-backup-<timestamp>/` (VPS files, MariaDB dump, router
UCI config). `/root/cyrad-production-backup-LATEST` points to the newest one.
To roll back: stop the affected service, restore the tar/dump, then re-run
`sudo bash deploy.sh`. The router snapshot can be extracted with:

```sh
mkdir -p /tmp/router-restore && tar -C / -xzf <backup>/router-config.tar.gz
```

See [wireguard/README.md](wireguard/README.md) and
[openwrt/README.md](openwrt/README.md) for the detailed component notes.
