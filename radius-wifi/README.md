# CYRAD — RADIUS 802.1X WiFi Authentication (no captive portal)

This folder turns CYRAD into a **WPA2-Enterprise / 802.1X** WiFi login system.
Students connect to the college WiFi and are asked for their **college username +
password** directly at the WiFi layer — there is **no web captive portal / splash
page**. FreeRADIUS validates the credentials against the same `radius` database that
CYRAD already uses (`radcheck` / `radusergroup`).

```
 student laptop
      │  (WPA2-Enterprise / 802.1X — PEAP-MSCHAPv2)
      ▼
  WiFi AP / controller  ──RADIUS (UDP 1812/1813)──►  FreeRADIUS 3
                                                      │  SQL
                                                      ▼
                                              MariaDB `radius` DB
                                              (radcheck / radusergroup)
```

Unlike the captive-portal mode (where a browser splash does the RADIUS web-auth),
here authentication happens at **layer 2**, exactly like eduroam on a campus. Once
authenticated, the client's traffic is normal IP — CYRAD's DNS monitor + content
blocking + VPN detection still apply as before.

## What's in this folder (project data)

| File | Purpose |
|------|---------|
| `install.sh` | One-shot auto-installer: installs & configures FreeRADIUS 3 for 802.1X against the existing DB. |
| `users.sql` | Reference data model + a demo student user (seed only — real users come from the admin panel / SIS). |
| `clients.conf.example` | Sample RADIUS client (NAS) definition for the WiFi AP / controller. |
| `hostapd.conf.example` | Sample `hostapd` config to make this server *itself* the WiFi AP (no separate hardware AP needed). |
| `force-dns-to-monitor.sh` | **Optional** gateway hardening: force all DNS to the CYRAD monitor and block DoT/DoH, so clients can't bypass blocking with an outside resolver. |

## Data model (where "username & password" live)

CYRAD already stores every user in the `radius` database. FreeRADIUS reads it directly:

- **`radcheck`** — per-user credentials. One row per attribute:
  - `attribute = 'Cleartext-Password'`, `op = '=='`, `value = '<password>'`
  - (or `NT-Password` for NTLM hashes; PEAP-MSCHAPv2 needs one of these)
  - `organization_id` links the user to their college/org.
- **`radusergroup`** — maps a user to a group (e.g. `students`).
- **`radgroupreply`** — group-level RADIUS replies (session timeout, idle timeout, VLAN, etc.).
- **`radacct`** — accounting; the DNS monitor uses `framedipaddress → username` to
  attribute browsing to a person (this is how the college identifies *who* visited a
  blocked site, satisfying the ISP complaint requirement).
- **`nas`** — the WiFi AP / controller registered as a RADIUS client (shared secret).

> The installer registers the AP into the `nas` table automatically when you pass
> `--nas-ip`. You do **not** need to edit `clients.conf` unless you prefer static files.

## Install

Run on the CYRAD server (or any Debian/Ubuntu 22.04+ box that will be the RADIUS server):

```bash
sudo bash radius-wifi/install.sh \
  --nas-ip 192.168.1.1 \        # IP of your WiFi controller / AP
  --nas-secret cyradsecret \     # RADIUS shared secret (change this!)
  --ap                           # OPTIONAL: also turn this box into the WiFi AP
```

Options:
- `--nas-ip IP`         Register the AP as a RADIUS client (writes to `nas` table).
- `--nas-secret SECRET` Shared RADIUS secret (default `cyradsecret` — change it).
- `--ap`                Also install & start `hostapd` so this machine *is* the AP.
- `--seed`              Force-seed a demo user (`student01` / `ChangeMe123!`) even if the DB already has users.
- `--ap-ssid SSID`     SSID for the software AP (default `CYRAD-Students`).
- `--ap-iface IFACE`   WiFi interface for hostapd (default `wlan0`).
- `--db-pass PASS`     MySQL password for the `radius` DB user (auto-read from
                       `/var/www/html/cyrad/config.php` if present).
- `--db-user/--db-name/--db-host`  Override DB connection (defaults: `radius`/`radius`/`127.0.0.1`).

The installer is fully automatic (one command, idempotent):
1. Detects the OS (Debian/Ubuntu or RHEL/Rocky/Alma/Fedora/Amazon) and the package manager.
2. Installs **all** prerequisites: `freeradius`, `freeradius-mysql`, `freeradius-utils`, `openssl`
   (+ `mariadb-server` if no MySQL/MariaDB is present, + `hostapd` with `--ap`).
3. Ensures MariaDB is running and the `radius` DB + tables exist (loads the FreeRADIUS schema
   and adds the CYRAD `organization_id` column if the DB is fresh).
4. Generates EAP TLS certs if missing (`make` in the certs dir, or an openssl fallback).
5. Configures the **SQL module** to use the `radius` DB (reads NAS clients from the `nas` table).
6. Enables **EAP** (PEAP-MSCHAPv2 primary, EAP-TTLS/PAP fallback) + **MSCHAP** + **PAP**
   (so the existing captive-portal web-auth still works).
7. Writes the `default` + `inner-tunnel` sites, registers the AP in the `nas` table, and
   (optionally) brings up the software AP.
8. Opens the firewall for RADIUS (1812/1813 UDP) on ufw/firewalld if active.
9. Validates the config (`freeradius -XC`), then starts + enables the service.
10. Seeds a demo user on a fresh DB (or with `--seed`) and runs an end-to-end `radtest` self-test.

It backs up any config it overwrites to `*.bak-<timestamp>` first.

## Point your WiFi gear at CYRAD

On your AP / controller (UniFi, Cisco, MikroTik, pfSense, …):
- **Security:** WPA2-Enterprise (802.1X)
- **Auth server:** IP of this CYRAD box, **UDP 1812**
- **Acct server:** same box, **UDP 1813**
- **Shared secret:** the `--nas-secret` you used

That's it. Students pick the SSID, enter their college username + password, and are on.

## Test

```bash
radtest student01 <password> 127.0.0.1 0 <nas-secret>
# Expect:  Access-Accept
```

## Hardening notes (important)

- **DNS bypass:** a determined student can set their device DNS to `8.8.8.8` and skip
  the CYRAD monitor. Run `force-dns-to-monitor.sh` on your gateway to redirect all
  port-53 traffic to the monitor and block DoT (853). This is what makes the
  VPN/proxy DNS-layer block (see `db/v3_vpn_block.sql`) actually effective.
- **True VPN tunnels** (WireGuard, OpenVPN, IPSec) are not DNS names — they need
  firewall/DPI rules on the gateway, not the DNS monitor. The DNS-layer block catches
  the *signals* (public resolvers, DoH endpoints, VPN provider domains) and alerts admins.
- Certificates: FreeRADIUS ships self-signed certs. For production, replace
  `/etc/freeradius/3.0/certs/{server.pem,server.key,ca.pem}` with your CA so clients
  don't get a cert warning on PEAP.
