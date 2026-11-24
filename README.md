# CYRAD Control Panel

**C**yprus **R**ADIUS — a self-hosted RADIUS / 802.1X WiFi access-control and
visibility stack with DNS monitoring, Telegram alerts, per-organization browsing
history, and per-user **data caps with automatic disconnect** (PoD kick).

CYRAD lets you run your own eduroam-style access plane:

- **Routers / APs** point their RADIUS server at the CYRAD VPS and authenticate
  users with provider-issued usernames/passwords (WPA2-Enterprise / 802.1X).
- The **admin control panel** (`/admin/`) manages orgs, users, DNS block rules,
  Telegram alerts, data caps, and a 7-day browsing-history viewer.
- The **DNS monitor** is a custom PHP forwarder that logs every query, enforces
  per-org block rules, and alerts via Telegram.
- **Data limits** are enforced automatically: when a user crosses their cap the
  account is disabled *and* their live session is force-disconnected (RADIUS
  Disconnect-Message / PoD).

Developed by **Vikram Pawar (localh0ste)** & **Jigyansh Gawri (sh3ll)**.

---

## 📦 What's in this package (finalCyrad.zip)

```
CYRAD/
├── install.sh              ← VPS installer entry point (invokes setup-cyrad.sh)
├── setup-cyrad.sh          ← full one-command VPS bootstrap (LEMP+FreeRADIUS+DNS+DoT)
├── setup-mail.sh           ← guest-mail key, cron and logrotate (run by install.sh)
├── MANUAL.md               ← operations manual: NAT forwarding, router platforms, policy
├── deploy.sh               ← push code updates to a running server (safe, repeats)
├── INSTALLATION.md         ← fresh VPS + router step-by-step guide
├── config.php.template     ← copy to config.php for manual installs (installer makes it)
├── config.php              ← DOES NOT SHIP (generated with random secrets on install)
├── admin/                  ← web panel + APIs + data-limit PoD script + SQL schema
│   └── active_sessions.php ← separate side-menu page (MAC, Session ID, username, usage)
├── dns/                    ← DNS monitor + systemd unit
├── openwrt/
│   ├── cyrad-router-setup.sh ← THE ONE router script (WireGuard + ubus + DNS block)
│   └── README.md
├── wireguard/              ← VPS-side WireGuard setup (called by install.sh)
│   ├── README.md           ← architecture, verification
│   └── server/             ← VPS: keys/wg0, bootstrap HTTP service, router bootstrap file
├── radius-wifi/            ← optional: turn the server itself into a software AP
├── db/                     ← incremental schema migrations
├── block.php               ← branded "Access Blocked" page
├── deploy.sh, LICENSE.txt, README.md
```

> **Note:** `config.php` is intentionally *not* in the zip. The installer
> generates it with random DB/Api-Token secrets. If you unzip and immediately
> re-run the installer, it would think CYRAD is "already installed" — so a fresh
> box always gets a fresh `config.php`.

---

## 🚀 Quick start

### 1) VPS (any Debian/Ubuntu 22.04+ cloud VM — no domain required)

```bash
# as root, on the target machine (a public IP is enough):
curl -fsSL https://<your-domain-or-raw-git>/install.sh -o /root/install.sh
sudo bash /root/install.sh
```

Or run directly from the unzipped package:

```bash
sudo bash install.sh
```

No domain is required. In this mode CYRAD automatically skips DNS-over-TLS
(DoT), installs the web panel, DNS monitor, FreeRADIUS, and MariaDB, then prints
the reachable panel URL, admin username, generated admin password, RADIUS
secret, and OpenWrt API token. It also writes those credentials securely to
`/root/CYRAD_CREDENTIALS.txt` (outside the web root).

If the VPS has more than one network interface, explicitly set the public IP
that should appear in the printed panel link:

```bash
CYRAD_SERVER_IP=<public-vps-ip> sudo bash install.sh
```

Open inbound on the **VPS**: **`8080/tcp`** (panel), **`53/udp`** (DNS monitor),
**`1812/udp` + `1813/udp`** (RADIUS from the router), **`51820/udp`**
(WireGuard) and **`8000/tcp`** (router bootstrap). The router itself needs
**no inbound port-forward**: it accepts UDP `3799` (DAE) and SSH only on the
WireGuard interface.

### 2) OpenWrt router (the WiFi AP / NAS)

The installer writes `/root/cyrad-router-bootstrap.sh` with the tunnel keys and
token. Copy it to the router and run it — it fetches one script by IP:

```sh
ssh root@<router-ip> 'cat > /tmp/cyrad-router.sh' < /root/cyrad-router-bootstrap.sh
ssh root@<router-ip> 'chmod 700 /tmp/cyrad-router.sh; sh /tmp/cyrad-router.sh'
```

`openwrt/cyrad-router-setup.sh` configures WireGuard, the ubus `cyrad-kick`
helper (over the tunnel) and DNS blocking in one pass, without restarting WiFi.
Full guide: [INSTALLATION.md](INSTALLATION.md).

---

## 🚀 Production install / update / uninstall (one command)

The `install.sh` in this repo drives **everything** — core stack, optional
Active Directory, optional Cloudflare Tunnel — from a single command on any
Debian/Ubuntu 22.04+ server:

### Installer options (`install.sh --help`)

```
sudo bash install.sh [OPTIONS] [KEY=VALUE ...]
  --help, -h          show help, changes nothing
  --check             verify package layout, OS and script syntax only
  --non-interactive   unattended install (the default; never prompts)
  --update            update in place, keep config.php, DB and secrets
  --config FILE       read KEY=VALUE lines (no shell evaluation, chmod 600 it)
  --server-ip IP      address shown in the credentials file
  --domain FQDN       enable DNS-over-TLS for this name
  --skip-dot          skip DoT / certificates
  --deploy-dir PATH   web root (default /var/www/html/cyrad)
  --no-ad / --no-cloudflare   skip those optional parts
```

Unattended example with a secrets file:

```bash
install -m 600 /dev/null /root/cyrad.conf
printf 'CYRAD_ADMIN_PASS=%s\nCYRAD_SERVER_IP=203.0.113.10\n' "$(openssl rand -base64 18)" > /root/cyrad.conf
sudo bash install.sh --non-interactive --skip-dot --config /root/cyrad.conf
```

Run it from the **complete extracted folder** (`unzip finalCyrad.zip && cd CYRAD`),
not as a lone downloaded script. It installs nginx, PHP-FPM, MariaDB,
FreeRADIUS, the PHPMailer dependency, applies every migration in order and stops
on the first SQL error, creates `config.php` with random secrets, installs the
crons and the guest-mail worker, and writes credentials to
`/root/CYRAD_CREDENTIALS.txt` (mode 600). Re-running is safe: `--update` keeps
your secrets and only refreshes code, migrations and crons.

### 1) Fresh install (core only — panel + FreeRADIUS + DNS + crons)
```bash
sudo bash install.sh
```

### 2) Fresh install, everything at once (AD + Cloudflare tunnel)
```bash
CYRAD_AD_DOMAIN=corp.local CYRAD_AD_HOST=dc01.corp.local \
CYRAD_AD_BASE_DN='DC=corp,DC=local' \
CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
CYRAD_AD_JOIN_USER=Administrator CYRAD_AD_JOIN_PASS='***' \
CYRAD_CF_DOMAIN=cyrad.example.com CYRAD_CF_TOKEN='***' \
sudo bash install.sh
```
`install.sh` auto-detects these env vars: if `CYRAD_AD_DOMAIN` is set it runs
`freeradius/setup-ad.sh` (domain join + `ntlm_auth`/`rlm_ldap`);
if `CYRAD_CF_DOMAIN` or `CYRAD_CF_TOKEN` is set it runs
`freeradius/cloudflared-tunnel.sh`. Use `INSTALL_AD=0` / `INSTALL_CF=0` to force
them off. Any other `CYRAD_*` knob from the section below passes straight
through.

Prefer not to use env vars at all? Set the domain controller once in the panel
(**Users → Add User → Active Directory → Save domain controller**) and just run
`sudo bash install.sh`; `setup-ad.sh` picks the saved values up from the
`ad_config` table by itself. See
[Active Directory authentication](#-optional-add-ons-active-directory-guest-vouchers-nat-tracing-cloudflare-tunnel).

### 3) Update an existing server in place (safe)
```bash
sudo bash install.sh UPDATE=1
```
Runs all pending SQL migrations, re-deploys the web files, refreshes the
crons and services — **without regenerating** the DB / RADIUS / API secrets,
so `config.php` and the deployed routers keep working. (To update files only,
`sudo bash deploy.sh`; to fully rebuild and get new secrets, `FORCE=1`.)

### 4) Uninstall from the server
```bash
sudo bash uninstall.sh            # removes the stack, keeps the database
CONFIRM=1 DROP_DB=1 sudo bash uninstall.sh   # ...also drop the radius DB+user
DRY_RUN=1 sudo bash uninstall.sh  # show exactly what would be removed
PURGE=1 sudo bash uninstall.sh    # additionally purge nginx/php/mariadb
```
The database, nginx, PHP and MariaDB are kept by default (removing the pure
CYRAD packages). `uninstall.sh` prints the router-side cleanup commands too.

---

## 🏠 Home-lab guide (home server + static IP + router + Cloudflare)

This is the exact topology you described — CYRAD runs on your own Linux machine,
one OpenWrt router connects everything, and you control it from your local browser
and from the cloud via a Cloudflare tunnel:

```
Admin browser ──http://<VPS-IP>:8080/admin  or https://panel.<domain> (CF tunnel)
                                   │
         ┌─────────────────────────┴───────────────┐
         │  CYRAD VPS (install.sh / deploy.sh --install) │
         │  nginx :8080 panel · :8000 bootstrap    │
         │  FreeRADIUS UDP 1812 auth / 1813 acct   │
         │  MariaDB, dnsmon, cap crons (2 min)     │
         │  WireGuard 10.9.0.1 (wg-quick@wg0)      │
         └─────────┬──────────────────┬────────────┘
          RADIUS 1812/1813       WireGuard (encrypted, router initiates)
                   │                    │
         ┌─────────┴──────────────────┴────────────┐
         │  OpenWrt router / AP                     │
         │  · 802.1X AP → RADIUS to VPS             │
         │  · WireGuard 10.9.0.2 + ubus cyrad-kick  │
         │  · dnsmasq DNS blocklist (5-min sync)    │
         └─────────────────────────────────────────┘
```

### Step-by-step to make it functional

1. **On the VPS** run the one-command installer; RADIUS/DAS secret and API token
   are generated for you and saved in `/root/CYRAD_CREDENTIALS.txt`:
   ```bash
   sudo bash deploy.sh --install          # = sudo bash install.sh
   ```
   → panel on `http://<VPS-IP>:8080`, RADIUS UDP 1812/1813, WireGuard UDP 51820,
   bootstrap HTTP `:8000`, blocklists at `:8080/admin/api_dns_rules.php`.
2. **Router → backend reachability.** RADIUS requests go from the router to the
   VPS. No inbound port-forward on the router is required: the WireGuard tunnel
   is initiated by the router, and the backend's disconnect/DNS traffic uses
   that tunnel. Allow UDP 1812/1813 (RADIUS), 51820 (WireGuard), 8000 and 8080
   on the **VPS** firewall.
3. **On the router** run the generated bootstrap (it fetches
   `openwrt/cyrad-router-setup.sh` over HTTP by IP). It installs the tunnel, the
   ubus disconnect helper and DNS blocking in one pass, with no WiFi restart:
   ```bash
   ssh root@<router-ip> 'cat > /tmp/cyrad-router.sh' < /root/cyrad-router-bootstrap.sh
   ssh root@<router-ip> 'sh /tmp/cyrad-router.sh'
   ```
   DNS block rules added in the panel's **DNS Rules** side menu sync to the
   router every 5 minutes; **Active Sessions** shows live WiFi sessions.
4. **Control from the local machine:** `http://<static-ip>:8080/admin/`.
5. **Control from the cloud / remotely use the block page:** `cloudflared-tunnel.sh`
   only needs *outbound* 443 (great behind home NAT/DNS):
   ```bash
   CYRAD_CF_DOMAIN=cyrad.example.com CYRAD_CF_TOKEN=<zero-trust-token> \
   sudo bash freeradius/cloudflared-tunnel.sh
   ```

### What the tunnel can and cannot carry (functionality per service)

| Service            | Protocol | Via Cloudflare tunnel? | Requirement to reach backend |
|--------------------|----------|------------------------|------------------------------|
| Admin panel / block page | HTTP/S `:8080`/`:80` | ✅ yes | tunnel outbound only |
| SDN/config (blocklists, NAT POST, PoD, disabled poll) | HTTPS `:8080` | ✅ yes (host = `https://panel.<domain>` works too) | tunnel outbound only |
| RADIUS auth/acct | UDP **1812/1813** | ❌ **no** (HTTP tunnel can't carry UDP) | port-forward or WireGuard |
| DNS monitor (optional) | UDP **53** | ❌ no | port-forward or WireGuard (or rely on router-local dnsmasq blocking) |
| RADIUS over Spectrum | UDP 1812/1813 | ✅ paid add-on (`cloudflared` + Spectrum) | Cloudflare account upgrade |

So: **everything "control-plane" is tunnelable; the RADIUS security plane is not.**
For a pure home-lab you can skip the DNS monitor entirely — the OpenWrt router does
local blocking with dnsmasq from the blocklists it pulls, which is what protects
the clients. If needed, the AP side can follow `openwrt/example-hostapd-vlan.conf`.

---

## 🖥️ VPS installation (detailed)

### Requirements
- Debian 12+ / Ubuntu 22.04+ VPS or local machine, **root** access
- A public IPv4 address; **a domain name is optional**
- Inbound `8080/tcp` (panel), `53/udp` (DNS), `1812/1813/udp` (RADIUS),
  `3799/udp` (PoD, backend→router)
- ~1 GB RAM minimum; works on the cheapest VPS tier

### One-command install
```bash
sudo bash install.sh
```
The script is idempotent: re-running aborts unless `FORCE=1` (which
regenerates `config.php` + the RADIUS `home_router` secret).

### Overridable environment variables
| Variable | Default | Purpose |
|----------|---------|---------|
| `CYRAD_DEPLOY_DIR` | `/var/www/html/cyrad` | Web root |
| `CYRAD_DB_USER` | `radius` | MariaDB user |
| `CYRAD_DB_PASS` | random 24-char | MariaDB password |
| `CYRAD_DB_NAME` | `radius` | Database name |
| `CYRAD_DOMAIN` | *(none)* | Optional FQDN for DoT cert. Without it, DoT is skipped automatically. |
| `CYRAD_RADIUS_SECRET` | random 24-char | Shared secret for the `home_router` RADIUS client |
| `CYRAD_ADMIN_PASS` | *(prompt/random)* | Web admin `admin` password |
| `CYRAD_API_TOKEN` | random 16-byte hex | Token the OpenWrt router must present (token-gated endpoints) |
| `CYRAD_OPENWRT_IP` | *(none)* | If set, also register a FreeRADIUS client for this router IP |
| `CYRAD_OPENWRT_SECRET` | = `CYRAD_RADIUS_SECRET` | RADIUS secret for that client |
| `CYRAD_DAS_TARGET_IP` | *(none)* | Router WireGuard tunnel IP (e.g. `10.9.0.2`) for NATed NAS; empty = send DAE directly to the NAS IP |
| `CYRAD_BLOCK_MODE` | `nxdomain` | `nxdomain` = plain refusal; `page` = branded block page |
| `CYRAD_DEFAULT_ORG` | `0` (off) | Org used when a client IP can't be mapped (NAT) |
| `CYRAD_SKIP_DOT` | automatic | `1` without a domain; `0` when a domain is supplied. Override to control certbot + dnsproxy. |
| `CYRAD_SERVER_IP` | auto-detected | Public IPv4 printed in the admin panel URL and saved credentials. |
| `CYRAD_CREDENTIALS_FILE` | `/root/CYRAD_CREDENTIALS.txt` | Secure, non-web-served path for generated credentials. |

### What it installs
LEMP (nginx + PHP-FPM + MariaDB) · FreeRADIUS 3 (SQL module, PEAP-MSCHAPv2 +
PAP) · CYRAD web panel · DNS monitor (systemd) · optional DoT (dnsproxy +
Let's Encrypt) · **`/etc/cron.d/cyrad-limit`** (data-limit enforcement, every
**2 min**) · daily browsing-history purge cron.

---

## 🌐 OpenWrt router installation (detailed)

### Requirements
- OpenWrt **22.03+** with a working 802.1X SSID already pointing at the VPS
  RADIUS (auth `1812`, accounting `1813`, interim interval `120` s).
- `wireguard-tools` / `kmod-wireguard` — installed automatically, with an HTTP
  package fallback if opkg/DNS is broken.
- The router must be able to reach the VPS over the internet (outbound only).

### One-command install (on the router)
```sh
ssh root@<router-ip> 'cat > /tmp/cyrad-router.sh' < /root/cyrad-router-bootstrap.sh
ssh root@<router-ip> 'sh /tmp/cyrad-router.sh'
```

The generated bootstrap exports all values and fetches the only router script:

```sh
wget -q http://<VPS-IP>:8000/cyrad-router-setup.sh -O /tmp/cyrad-router-setup.sh
sh /tmp/cyrad-router-setup.sh
```

Advanced/manual env vars: `BACKEND_IP`, `SERVER_ENDPOINT`, `RADIUS_SECRET`,
`API_TOKEN`, `ORG_ID` (`0` = all rules), `SERVER_WG_IP`, `ROUTER_WG_IP`,
`SERVER_PUBLIC_KEY`, `ROUTER_PRIVATE_KEY`, `CYRAD_VPS_SSH_PUBKEY`, plus optional
`WG_PORT`, `CYRAD_HTTP_PORT`, `OPENWRT_LAN_IP`, `UPSTREAM_DNS`, `BACKEND_URL`.

### What it does
1. Brings up `wg0` (`10.9.0.2/32` → VPS `10.9.0.1`) with raw `ip`/`wg` and
   persists it through `/etc/rc.local` — no netifd dependency, no WiFi reload.
2. Installs `/usr/bin/cyrad-kick` (ubus `hostapd.* del_client`) and the VPS SSH
   key, so over-limit users are disconnected over the tunnel even when hostapd
   ignores RADIUS DAE. Opens UDP/3799 on `wg0` in fw4.
3. DNS: dnsmasq `confdir=/etc/dnsmasq.d`, 5-minute sync from
   `admin/api_dns_rules.php`, and a forced-LAN-DNS nft rule so clients cannot
   bypass the blocklist with an external resolver.

### Verify on the router
```sh
wg show                                             # tunnel + handshake
ping -c 2 10.9.0.1                                  # VPS reachable
nft list table inet fw4 | grep -A4 cyrad_force_dns  # forced DNS rule
cat /etc/cyrad.conf                                 # backend + org + token
wc -l /etc/dnsmasq.d/cyrad-block.conf               # block rule count
ubus list | grep hostapd                            # interfaces for kick helper
```

---

## 📶 RADIUS WiFi + data caps + live kick (PoD)

### How auth works
Students/users connect to the CYRAD SSID and enter their **username + password**
at the WiFi layer (802.1X / PEAP-MSCHAPv2) — no captive-portal splash. FreeRADIUS
validates against the `radius` DB (`radcheck`). After auth, normal IP traffic
flows and CYRAD's DNS monitor + content blocking + history still apply.
FreeRADIUS returns `Acct-Interim-Interval = 120`, so usage is refreshed into
`radacct` every **2 minutes**.

### Active sessions page
`admin/active_sessions.php` (side menu: **Active Sessions**) lists the current
sessions with **Username, Device MAC, Session ID, IP, NAS, SSID/BSSID,
Connected time, Duration and Usage**. The dashboard no longer repeats the full
table; the IP Map also shows the Session ID. This is the quickest way to confirm
a user is online and to identify the MAC used by the live-kick logic.

### Data limits (automatic enforcement)
- The cap is a **rolling 24-hour window** anchored in
  `data_limits.usage_reset_ts`, not a calendar day. Usage is summed from
  `radacct` rows with `acctstarttime >= usage_reset_ts`.
- Set a per-user cap in the **`data_limits`** table (admin panel user → set
  limit, or CSV import). `unlimited` / `0` means no cap.
- `/etc/cron.d/cyrad-limit` runs
  `admin/disable_user_if_limit_exceeded.php` **every 2 minutes**. For each
  capped user:
  - **Over the cap:** set `banned_until = NOW() + 24 HOUR`, disable the account
    (`disabled_` prefix on `Cleartext-Password`), blacklist the user's MACs and
    kick any live session.
  - **Ban expired, under cap, or limit raised/removed:** clear the ban, re-enable
    the account, remove MAC blacklist entries and roll the usage window forward.
- Tunables in `config.php`: `CYRAD_WINDOW_HOURS=24`, `CYRAD_BAN_HOURS=24`,
  `CYRAD_RESET_KICK=1`.
- Logs go to `/var/log/cyrad-limit.log`, e.g.
  `🚫 'piet_test' blocked for 24 hours (cap reached; 1 kick(s), 1 MAC(s) blacklisted).`
  and `✅ ban expired for 'piet_test' — re-enabled, usage cleared, blacklist lifted.`
- `Session-Timeout = 300` reply attributes are maintained for capped users so
  the NAS periodically re-authenticates: if the account is disabled, re-auth
  fails even if a live kick is missed.

> If a cap always shows 0, confirm `data_limits.usage_reset_ts` exists
> (`mysql radius < db/v8_usage_reset_ts.sql`) and that the cron points at the
> real deploy directory.

### Live kick without port forwarding — WireGuard
The router is behind NAT/CGNAT, so inbound UDP/3799 to its public IP does not
work. CYRAD uses an **outbound WireGuard tunnel** instead; the complete scripts
and procedure are in [`wireguard/README.md`](wireguard/README.md).

1. VPS `wg0` (`10.9.0.1`, UDP `51820`) peers with router `wg0` (`10.9.0.2`).
2. `config.php` sets `CYRAD_DAS_TARGET_IP='10.9.0.2'`, so RFC 5176
   Disconnect-Requests travel through the tunnel.
3. Router fw4/nftables accepts UDP/3799 on `wg0`, and hostapd is configured with
   `radius_das_client=<VPS tunnel IP>`.
4. If this hostapd build does not answer DAE (it accepts the packets but sends
   no ACK/NACK), `admin/lib/router_policy.php` falls back to SSH over the same
   tunnel and runs `ubus hostapd.wlanX del_client` for the client MAC via a
   passwordless key. No inbound port, no `tcpdump`, no extra router packages.

The tunnel is recreated on boot by `/usr/bin/cyrad-wg-up` from `/etc/rc.local`.
The router helper is installed by `wireguard/server/install-router-kick.sh`.
`wifi reload` is deliberately **not** automatic: it drops all WiFi/SSH clients —
apply DAE config changes during a maintenance window with
`CYRAD_WIFI_RELOAD=1`.

### PoD prerequisites (if the live kick doesn't fire)
- `wg show wg0` and `ping -c 2 10.9.0.2` work from the VPS.
- Router firewall counter increases: `nft list ruleset | grep 3799`.
- Router socket is present: `grep -i 0ED7 /proc/net/udp` (port 3799 = `0ED7`).
- `grep -i radius_das /var/run/hostapd-*.conf` shows the VPS tunnel IP and the
  same secret as RADIUS (`RADIUS_SECRET` / `CYRAD_DAS_SECRET`).
- `/usr/local/bin/cyrad_router_kick AA:BB:CC:DD:EE:FF` succeeds from the VPS.

---

## ☁️ Optional add-ons: Active Directory, guest vouchers, NAT tracing, Cloudflare Tunnel

The core panel works standalone. The following add-ons live in the same repo and
are installed on the VPS (and, for NAT tracing, on each OpenWrt router).

### Active Directory authentication (PEAP-MSCHAPv2 against a real domain)

Nobody is added by hand: any account with valid domain credentials is
authenticated by your domain controller and auto-registered with an Unlimited
plan on its first WiFi login. The only thing you type yourself is **where the
domain controller is**.

**Enter the domain controller in the panel** — go to **Users → Add User**, set
*Authentication Source* to **Active Directory**, and fill in the Domain Controller
box that appears:

| Field | What to type |
|-------|--------------|
| **AD domain** | `corp.local` — optional if already in `config.php` |
| **DC host** | `dc01.corp.local` (required unless you give just the IP) |
| **DC IP** | optional — used as-is instead of resolving the host name |
| **LDAP port** | `389`, or `636` for LDAPS |
| **Use LDAPS (TLS)** | switches the port to `636` automatically |
| **Base DN** | `DC=corp,DC=local` (required) |
| **Bind DN** | read-only service account, e.g. `CN=radius,OU=svc,DC=corp,DC=local` (required) |
| **Bind password** | leave blank to keep the stored secret |
| **Restrict to AD group** | optional group DN; empty = any domain user. Applied by `setup-ad.sh` as a marker-commented block in `authorize { }`; clearing the field removes it again |

**Save domain controller** stores them in the `ad_config` table (migration
`db/v12_ad_config.sql`, single row `id=1`). The panel is then the source of
truth: `setup-ad.sh` reads that row by itself, so there is nothing to copy by
hand. Run it once over SSH (or again after any change — every edit is idempotent):

```bash
sudo bash /var/www/html/cyrad/freeradius/setup-ad.sh
```

Check what a run would use, without changing anything (safe any time, no root
needed, and it never prints the bind password):

```bash
sudo bash /var/www/html/cyrad/freeradius/setup-ad.sh --print-effective
#   source:     panel: Users -> Add User -> Active Directory
#   domain:     corp.local
#   dc:         10.0.0.10
#   ldap url:   ldap://10.0.0.10:389
#   base dn:    DC=corp,DC=local
#   bind dn:    CN=radius,OU=svc,DC=corp,DC=local
#   bind pass:  set
#   ldaps:      0   port: 389
```

**Precedence** — an explicit environment variable always wins, the panel row
fills in whatever is still unset, and `config.php` is the last fallback:

| Source | Used for |
|--------|----------|
| `CYRAD_AD_*` environment variables | one-shot runs, CI, overriding the panel |
| `ad_config` table (the panel form) | the normal path — what the admin typed |
| `CYRAD_AD_*` constants in `config.php` | anything left blank in the form |

To run with the values spelled out instead of reading the panel (the panel also
prints this under *Run it with the values spelled out*), pass them as shown in
the table below:

```bash
# one-shot: the panel prints this exact line, with your values
sudo CYRAD_AD_DOMAIN='corp.local' CYRAD_AD_HOST='dc01.corp.local' \
  CYRAD_AD_BASE_DN='DC=corp,DC=local' \
  CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
  CYRAD_AD_TLS=0 CYRAD_AD_PORT=389 \
  bash /var/www/html/cyrad/freeradius/setup-ad.sh
```

The privileged join account (`CYRAD_AD_JOIN_USER` / `CYRAD_AD_JOIN_PASS`, default
`Administrator`) is only needed for the domain join, and is never read from the
panel — pass it on the command line. Port `636` always means LDAPS, whether or
not the TLS box is ticked, so the panel and the script can never disagree about
the URL. The full variable list for the SSH/CI path:

| Variable | Example |
|----------|---------|
| `CYRAD_AD_DOMAIN` | `corp.local` |
| `CYRAD_AD_HOST` | `dc01.corp.local` |
| `CYRAD_AD_BASE_DN` | `DC=corp,DC=local` |
| `CYRAD_AD_BIND_DN` / `CYRAD_AD_BIND_PASS` | read-only service account, e.g. `CN=radius,OU=svc,DC=corp,DC=local` |
| `CYRAD_AD_JOIN_USER` / `CYRAD_AD_JOIN_PASS` | privileged account used **once** for the join (default `Administrator`) |

```bash
CYRAD_AD_DOMAIN=corp.local CYRAD_AD_HOST=dc01.corp.local \
CYRAD_AD_BASE_DN='DC=corp,DC=local' \
CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
CYRAD_AD_JOIN_USER=Administrator CYRAD_AD_JOIN_PASS='***' \
sudo bash freeradius/setup-ad.sh
```

(Or set the same `CYRAD_AD_*` variables and run `sudo bash install.sh` — it
detects `CYRAD_AD_DOMAIN` and runs this step automatically.)

**How the handoff works.** `admin/ad_config_export.php` (CLI only — it answers
`403` over HTTP) reads the `ad_config` row through the same
`admin/lib/ad_config.php` loader the page uses, writes the values to a `0600`
temp file, and `setup-ad.sh` sources it, filling only the variables that are
still unset. The file is deleted on every exit path, and the bind secret is never
printed to stdout or into the page. The bind password sits in the `ad_config`
row in plain text, readable by the database user and the web app only — use a
read-only service account whose only job is `User-Read` on the users you need.

**What happens automatically** (every step idempotent + marker-commented):

| Variable | Example |
|----------|---------|
| `CYRAD_AD_DOMAIN` | `corp.local` |
| `CYRAD_AD_HOST` | `dc01.corp.local` |
| `CYRAD_AD_BASE_DN` | `DC=corp,DC=local` |
| `CYRAD_AD_BIND_DN` / `CYRAD_AD_BIND_PASS` | read-only service account, e.g. `CN=radius,OU=svc,DC=corp,DC=local` |
| `CYRAD_AD_JOIN_USER` / `CYRAD_AD_JOIN_PASS` | privileged account used **once** for the join (default `Administrator`) |

```bash
CYRAD_AD_DOMAIN=corp.local CYRAD_AD_HOST=dc01.corp.local \
CYRAD_AD_BASE_DN='DC=corp,DC=local' \
CYRAD_AD_BIND_DN='CN=radius,OU=svc,DC=corp,DC=local' CYRAD_AD_BIND_PASS='***' \
CYRAD_AD_JOIN_USER=Administrator CYRAD_AD_JOIN_PASS='***' \
sudo bash freeradius/setup-ad.sh
```

(Or set the same `CYRAD_AD_*` variables and run `sudo bash install.sh` — it
detects `CYRAD_AD_DOMAIN` and runs this step automatically.)

**How the handoff works.** `admin/ad_config_export.php` (CLI only — it answers
`403` over HTTP) reads the `ad_config` row through the same
`admin/lib/ad_config.php` loader the page uses, writes the values to a `0600`
temp file, and `setup-ad.sh` sources it, filling only the variables that are
still unset. The file is deleted on every exit path, and the bind secret is never
printed to stdout or into the page. The bind password sits in the `ad_config`
row in plain text, readable by the database user and the web app only — use a
read-only service account whose only job is `User-Read` on the users you need.

**What happens automatically** (every step idempotent + marker-commented):

- Installs `winbind` + `freeradius-ldap`, joins the domain and creates the host
  **machine account**.
- Points `mschap` at `ntlm_auth → winbind → DC`, so the MSCHAPv2
  challenge/response is validated **by the DC**. Wi-Fi clients just enter their
  normal domain username + password on the CYRAD SSID (true 802.1X).
- Enables `rlm_ldap` and runs it **FIRST** in `authorize` (`cyrad-ad-first-*`
  markers): AD wins when a username exists in both places, and the local
  `radcheck` DB is only a fallback when AD doesn't know the user or the DC is
  unreachable. A wrong AD password never falls through to local.
- Applies the **BSSID → VLAN** post-auth policy for every SSID mapped in the
  panel's WiFi/BSSID table.
- **Auto-registers every successful AD login** (`cyrad-auto-reg-*` markers): the
  `ad` billing shadow is upserted, the **Unlimited** plan is enforced, and
  `last_login` is refreshed — domain users appear in the panel by themselves,
  with no manual add. Set `CYRAD_AD_AUTO_REGISTER=0` to disable the DB writes,
  or `CYRAD_AD_DEFAULT_ORG=<id>` to place them in one organization.

**The panel handles the rest.** The **Users → Active Directory** tab lists the
auto-registered domain shadows (`auth_source='ad'`) with Auth, Plan, Data Cap,
Online/Offline and a **Last Seen** column (`last_login`, migration
`db/v11_ad_last_login.sql`). They are always **Unlimited** (`edit_user`
preselects it, `db/v10_ad_users_unlimited.sql` backfills, and the post-auth upsert
re-enforces it on every login). **Add User** is where the domain controller is
pointed at: its *Active Directory* section holds the DC host/IP, base DN and
service account you type, and no AD user is ever added by hand.

**Where AD logins are monitored.** Nothing to configure: every authentication
attempt is written by FreeRADIUS to `/var/log/freeradius/radius.log` (AD/`ldap`
lines appear as soon as the module is enabled), rotated automatically by stock
`logrotate` — no separate log page and no manual cleanup. For "who connected and
when", use the Users → Active Directory tab's **Last Seen** column.

**Verify / optional knobs.** Check the join with `wbinfo -t` and a domain login
with `radtest DOMAIN\USER 'pass' 127.0.0.1 0 testing123`. TLS is the **Use
LDAPS** box in the panel (`CYRAD_AD_TLS=1` on the command line), which switches
`ldap://` → `ldaps://` and the port 389 → 636. To restrict Wi-Fi to one AD
group, either fill in **Restrict to AD group** in the panel, or add this after the
`ldap` line of the `authorize { }` block in
`/etc/freeradius/3.0/sites-available/inner-tunnel`:

```unlang
# cyrad-ldap-group: only members of "CN=WiFi Users,CN=Users,DC=corp,DC=local"
if (&User-Name && &LDAP-Group) {
    if (!(&LDAP-Group =~ /^CN=WiFi Users,/)) {
        update reply { Reply-Message := "Account not in the allowed group" }
        reject
    }
}
```

### Guest vouchers (admin-generated, expiring)
**Guest Tickets** in the panel mints a username/password plus an expiry range.
An `expire_guest_tickets.php` cron (`/etc/cron.d/cyrad-guest`, every 5 min)
revokes tickets past their expiry or device-count and disables the RADIUS
account. Migration `db/v16_guest_ticket_collation.sql` keeps the guest, mail,
blacklist and RADIUS tables on the same `utf8mb4_unicode_ci` collation, so the
ticket list and cron never fail with “Illegal mix of collations”.

**Revoke vs delete.** *Revoke* only flips the status: the ticket stops signing in
(radcheck password prefixed `disabled_`) but the record is kept. *Delete* removes
the ticket for good together with its RADIUS account (`radcheck`), billing row
(`data_limits`) and `user_profiles` row, so nothing is left
behind in the user list; `radacct` accounting history is kept for reporting.
The list is filtered to **Active** by default, with tabs for *Revoked & expired*
and *All*. Dead tickets are removed one at a time with the per-row **Delete**
button — there is deliberately no bulk delete, so a cleanup can never take more
than the one ticket you clicked. All of these actions are POSTs behind a
per-session CSRF token
(`hash_equals` check in `admin/guest_tickets.php`), so a link or an image tag
cannot revoke or delete anything.

**Emailing tickets.** Open **Guest Tickets → Email / SMTP settings** (org admins)
or **Edit Organization** (super admin); both edit the same per-organization row.
Fill in host, port, encryption (STARTTLS 587 or TLS 465), username, password,
From address, subject and template, tick *Enable*, save, then use
*Send test email*. Placeholders: `{{organization_name}}`, `{{username}}`,
`{{password}}`, `{{expires_at}}`, `{{data_limit}}`.

- When minting, the optional **Recipient emails** box takes one address per line,
  matched to the tickets in creation order; a blank line (or an empty box) skips
  email for that ticket. Existing active tickets have a **Send email** button.
- Sending is asynchronous: mint only queues rows in `guest_email_queue`;
  `/etc/cron.d/cyrad-guest-mail` runs `admin/send_guest_ticket_emails.php` every
  minute (3 attempts, retries after 5 and 10 minutes, one worker at a time).
  Revoking, expiring or deleting a ticket cancels its pending mail.
- The Email column shows *queued*, *Accepted by mail server* (SMTP accepted the
  message, which is not a guarantee of inbox delivery) or *failed* with a reason.
- The SMTP password is stored AES-256-GCM encrypted with
  `/etc/cyrad/mail.key` (created by the installer, root:www-data 640). **Back the
  key up with the database**: without it saved passwords cannot be decrypted and
  must be re-entered.
- Troubleshooting: many cloud providers silently drop outbound ports 25/465/587.
  Test with `timeout 5 bash -c '</dev/tcp/smtp.example.com/587' && echo open`.
  If it times out, ask the provider to lift the block or use a relay on an open
  port. Logs: `/var/log/cyrad-guest-mail.log`.

**Internet type is all-or-nothing.** *Unlimited* stores `internet_limit = 0` and
the MB box is hidden and not submitted, so a guest ticket can never end up
"unlimited" with a cap attached; *Limited* stores the MB you type (minimum
50 MB). Same rule everywhere: unlimited means `internet_limit = 0`, which is what
`add_user`, `edit_user` and the AD auto-registration write, and what the
cap-watching pages (`Users → Data Left`, the over-limit auto-disable cron) key
on — they list only users with a real cap.

### WiFi mappings
**WiFi Profiles** maps each AP BSSID → SSID with an auth source.

### DNS rules
**DNS Rules** define block/allow rules per organization.

### NAT tracing (which user has which IP behind the router)
Each OpenWrt router runs `cyrad-natsync` (cron, every 2 min) which POSTs its
DHCP/ARP board to `admin/api_nat_table.php`. The DNS monitor then chains
`query-source IP → nat_mappings → MAC → radacct → user/org`, so clients
behind a single-IP NAT still can be attributed. **IP Map** shows the
live map of `IP → user`.

### Public panel via Cloudflare Tunnel (no inbound port)
```bash
CYRAD_CF_DOMAIN=cyrad.example.com CYRAD_CF_TOKEN=<zero-trust-token> \
sudo bash freeradius/cloudflared-tunnel.sh
```
Serves `https://panel.<domain>` → :8080 (admin) and `https://block.<domain>` → :80
(block page). **RADIUS UDP 1812/1813 and DNS UDP 53 are NOT tunneled** — they
stay direct (or go through Cloudflare Spectrum); the tunnel is HTTP-only by
design and documented in `cloudflared-tunnel.sh`.

---

## 🚦 Router policy: daily reset, instant kick, MAC blacklist

The cap lifecycle has three layers that keep the panel, the routers and the
RADIUS server in agreement:

- **Auth-time check (instant).** `freeradius/auth-limit-check.sh` (run by the
  installer) patches the SQL `authorize_check_query` so every authentication is
  rejected when the rolling 24 h usage is at/over the cap **or** the client MAC
  is blacklisted. Raising a limit immediately allows the next authentication —
  no cron wait.
- **Every 2 minutes** the data-cap cron resets expired 24 h windows (clearing
  usage, bans and MAC blocks, and by default kicking the live session), and
  blocks anyone over the cap: password disabled, MAC(s) blacklisted, live
  sessions kicked via RFC 5176 DAE over the WireGuard tunnel with an `ubus`
  fallback, 24 h ban stamped. Revoked/expired guest tickets are never
  resurrected.
- **Every 5 minutes** the guest cron expires over-time/over-use tickets,
  blacklists their MACs, and **deletes tickets that have been revoked for
  `CYRAD_GUEST_PURGE_DAYS` (default 7 days)** along with their account, billing,
  pin, profile and blacklist rows (accounting history is kept).

Panel controls: **Users → Reset** (single/bulk) clears usage, lifts bans and MAC
blocks, re-enables and kicks; saving a higher limit in **Edit User** does the
same for that user; **MAC Blacklist** lets you add/unblock MACs manually.
Live disconnection is handled by the backend calling
`/usr/local/bin/cyrad_router_kick <MAC>` over WireGuard, which runs
`/usr/bin/cyrad-kick` on the router (ubus `del_client`). No inbound port and no
polling service are required.

Tuning: `CYRAD_WINDOW_HOURS`, `CYRAD_BAN_HOURS`, `CYRAD_RESET_KICK`,
`CYRAD_GUEST_PURGE_DAYS` in `config.php`.

**How the router forwards its NAT/DHCP board, static-IP same-network layout,
and Cisco/Aruba/enterprise RADIUS + CoA examples are in
[MANUAL.md](MANUAL.md).**

## ✅ Tests

```bash
bash tests/test_features_contract.sh     # static wiring checks
bash tests/test_setup_contract.sh        # installer invariants
php  tests/test_ad_config.php            # AD settings (snapshots/restores ad_config)
# mail: isolated database only (the script refuses names not ending in _test)
mysql -u root -e "CREATE DATABASE cyrad_mail_test CHARACTER SET utf8mb4"
sed 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' admin/radius.sql | mysql -u root cyrad_mail_test
CYRAD_DB_HOST=localhost CYRAD_DB_USER=root CYRAD_DB_PASS= CYRAD_DB_NAME=cyrad_mail_test \
  php admin/apply_migrations.php
CYRAD_DB_HOST=localhost CYRAD_DB_USER=root CYRAD_DB_PASS= CYRAD_DB_NAME=cyrad_mail_test \
  php tests/test_guest_mail.php          # includes a real SMTP round-trip to a local sink
```

## 🔄 Pushing code updates (deploy.sh)

After editing files in this repo, push to a running server (preserves the live
`config.php` and credentials, reloads nginx + php-fpm):

```bash
# local sync + perms + reload
sudo bash deploy.sh

# push to a remote host over SSH (also mirrors a clean copy to /tmp/CYRAD)
DEPLOY_HOST=cyrad-prod bash deploy.sh
```

First-time install on a fresh box is `install.sh`, **not** `deploy.sh`.
`deploy.sh` refuses if the target has no `config.php`.

`deploy.sh` also re-applies the correct web-root ownership/permissions after
every sync (`www-data:www-data`; dirs `755`, files `644`, `config.php` `640`),
for both local and `DEPLOY_HOST` remote deploys. This matters because `rsync -a`
copies the **source** owner/group/mode: a working tree owned by a normal user
with a restrictive umask (for example `kali:kali 0770`) would otherwise land in
the docroot unreadable by nginx/php-fpm, and every page would fail with
`404`/`403` right after an otherwise "successful" deploy. Override the web user
with `WEB_USER=<user>` if you don't use `www-data`.

### Troubleshooting: panel returns 404/403 right after `deploy.sh`

If `http://<server-ip>:8080/admin/login.php` returns **404** or **403** even
though the deploy reported success, the docroot is not readable by the web user.
Confirm the ownership, then fix it as root:

```bash
ls -ld /var/www/html/cyrad        # want: www-data www-data  drwxr-xr-x
# fix (root):
sudo chown -R www-data:www-data /var/www/html/cyrad
sudo find /var/www/html/cyrad -type d -exec chmod 755 {} +
sudo find /var/www/html/cyrad -type f -exec chmod 644 {} +
sudo chmod 640 /var/www/html/cyrad/config.php
sudo systemctl reload nginx
```

Then open the panel on **port 8080**. Port **80** intentionally serves the
branded "Access Blocked" page (`block.php`), *not* the admin panel — browsing
`http://<server-ip>/` is expected to show the block page.

---

## 🌐 Access URLs

| Component      | URL                              |
|----------------|----------------------------------|
| Admin Panel    | `http://<server-ip>:8080/admin/` |
| Block page     | served on `:80` for blocked domains |

Default admin user: `admin` (password set during install / in
`CYRAD_CREDENTIALS.txt`).

---

## 🔒 Security recommendations
- Change the DB and super-admin passwords from the install-generated values.
- Put the panel behind HTTPS (Let's Encrypt / reverse proxy) for production.
- Restrict `53/udp` to your own client subnets; don't expose the resolver publicly.
- The OpenWrt DAS firewall rule is locked to the backend IP — keep it that way.
- Watch logs: `/var/log/nginx/`, `/var/log/cyrad-limit.log`, and
  `journalctl -u cyrad-dnsmon`.

---

## 🏢 Developed By
**CYRAD — Control Panel** · 🔐 Empowering Secure Network Access & Identity Control

## Credit -> Development 
Vikram Pawar ( localh0ste )
Jigyansh Gawri ( sh3ll )
