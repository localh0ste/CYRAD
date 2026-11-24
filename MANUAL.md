# CYRAD Operations Manual

Everything an operator needs that is not in the README: how the pieces fit on a
**single LAN**, how the router forwards its **NAT/DHCP board** to the control
panel, how the **data-limit lifecycle** works end to end, and how to wire the
same logic on **OpenWrt, Cisco IOS and other enterprise routers**.

---

## 1. Architecture on one network (everything on the same LAN)

```
                 ┌──────────────────────────────────────────────┐
   Wi-Fi clients │            L2/L3 LAN — one subnet           │
        │        │                                              │
        │   ┌────▼──────────────┐   802.1X (RADIUS)   ┌─────────▼─────────┐
        └──►│  AP / router      ├────────────────────►│  CYRAD server     │
            │  (static IP A)    │  acct + NAT board   │  (static IP B)    │
            └────┬──────────────┘◄────────────────────┤                   │
                 │ DHCP: static  │  UDP 1812/1813     │  panel :8080      │
                 │ leases by MAC │  UDP 3799 (PoD)    │  DNS  :53         │
                 │               │  HTTP :8080 (poll) │  MariaDB, cron    │
                 └───────────────┘                    └───────────────────┘
```

Because all elements share one network with **static IPs**, every actor can
reach every other actor directly:

| Direction | Protocol | Used for |
|---|---|---|
| AP/router → CYRAD | UDP 1812/1813 | RADIUS auth + accounting |
| CYRAD → router | UDP 3799 | Disconnect/CoA (instant kick) |
| router → CYRAD | HTTP 8080 | NAT/DHCP board, enforcer polls, DNS block sync |
| clients → CYRAD | UDP/TCP 53 | DNS monitor (attribution uses the NAT board) |

**Static addressing rules**

1. Give the CYRAD server a static IP (or DHCP reservation by its NIC MAC).
2. Give every AP/router a static IP. For multi-WAN, use the LAN IP.
3. Let the router hand out **static leases by client MAC** (DHCP reservation).
   The panel then always maps `IP → MAC → username` deterministically.
4. Register each router as a RADIUS client with the correct `ipaddr` and shared
   secret. FreeRADIUS refuses requests from unknown clients, and PoD/CoA goes
   back to `radacct.nasipaddress` — so that address must be reachable from the
   server (no NAT between them).

**Same subnet vs NAT:** in the same subnet the RADIUS session usually carries the
real client IP (`Framed-IP-Address`). Behind NAT (home routers, CGNAT) it does
not — that is exactly why the router shares its NAT board (§2): the panel can
still resolve the real device.

---

## 2. NAT/DHCP board forwarding (router → control panel)

### What it is

Every Wi-Fi gateway with NAT hides clients behind one public address. CYRAD
compensates by having the router post its **DHCP leases + ARP neighbours +
VLAN mapping** to the panel every couple of minutes. The panel stores them in
`nat_mappings` and can then answer *“which user owns this IP right now?”* —
for the IP Map page (`admin/ipmap.php`) and the DNS monitor's attribution.

### The forwarder (optional)

The one-script router install (`openwrt/cyrad-router-setup.sh`) does **not**
install a NAT forwarder — live WiFi identity is shown on the **Active Sessions**
page. If you also want the IP Map populated behind NAT, any gateway can POST a
JSON array to `admin/api_nat_table.php` (token-protected). A small OpenWrt
forwarder would build it from:

- `/tmp/dhcp.leases` (dnsmasq: `ts mac ip hostname clientid`),
- `ip neigh` (static/ARP neighbours not in a lease),
- `bridge fdb` per VLAN interface (tag per MAC).

§4.3 shows the same JSON produced on Cisco IOS.

### The API (`admin/api_nat_table.php`)

```
POST http://<panel>:8080/admin/api_nat_table.php
Content-Type: application/x-www-form-urlencoded

token=<CYRAD_API_TOKEN>
org=<organization_id>
entries=[{"ip":"192.168.1.50","mac":"aa:bb:cc:dd:ee:ff","hostname":"macbook","iface":"br-lan"}, ...]
```

- `token` must equal `CYRAD_API_TOKEN` from `config.php` (visible in
  `/root/CYRAD_CREDENTIALS.txt`). A wrong token returns `403`.
- `ip` is required and validated; `mac` is normalised to lowercase
  `aa:bb:cc:dd:ee:ff`; unknown/invalid MACs are stored as `unknown`.
- Repeated IPs upsert (`UNIQUE(organization_id, client_ip)`), so re-posting is
  safe. Rows older than **20 minutes** are pruned automatically — keep the
  forwarder running every ≤ 5 minutes.
- Response is `ok <n>` with the number of entries stored.

### How the panel uses it

```
DNS query from 192.168.1.50
  → nat_mappings.client_ip = 192.168.1.50      (owner mac aa:bb:…)
  → radacct.callingstationid = aa:bb:…         (last session for that MAC)
  → username → organization → DNS rules + browsing history
```

The **IP Map** page shows the same join live.
If a client is missing from the map, check: is the forwarder cron running, does
`nat_mappings.updated_at` advance, and did the client ever complete a RADIUS
session (the MAC must exist in `radacct`)?

### Static IP + same network, summarized

| Data | Where it lives | Who sets it |
|---|---|---|
| Server IP | `config.php: CYRAD_WEB_IP`, credentials file | you, static |
| Router/AP IP | RADIUS client + `nasipaddress` | you, static |
| Client IP | router DHCP **static lease by MAC** | router admin |
| IP→MAC | `nat_mappings` (pushed every 2 min) | forwarder |
| MAC→user | `radacct.callingstationid` | FreeRADIUS accounting |

---

## 3. Data-limit lifecycle (what happens, when)

```
        auth (802.1X)                     every 2 min                   every 5 min
 ┌───────────────────────┐        ┌──────────────────────────┐      ┌──────────────────┐
 │ FreeRADIUS SQL check  │        │ cyrad-limit cron         │      │ cyrad-guest cron │
 │  cap reached?  ───────┼─reject │  cap reached? ──► block: │      │  expire guests   │
 │  MAC blacklisted? ────┼─reject │   disable + blacklist +  │      │  purge revoked   │
 │  raised limit → allow │        │   PoD kick + 24h ban     │      │  > 7 days        │
 └───────────────────────┘        │  24h window passed? ──►  │      └──────────────────┘
                                  │   reset + kick + unblock │
                                  └──────────────────────────┘
```

1. **Auth time (instant).** `freeradius/auth-limit-check.sh` patches the SQL
   module's `authorize_check_query` so every authentication returns
   `Auth-Type := Reject` when either the rolling usage is at/over the cap or the
   `Calling-Station-Id` is in `mac_blacklist`. **Raising the limit makes the sum
   fall below the cap, so the next auth succeeds without waiting for any cron.**
2. **Every minute** (`/etc/cron.d/cyrad-limit`) the cron:
   - resets any limited user whose 24 h window (`usage_reset_ts`) passed —
     clears the window's accounting rows, lifts the ban, re-enables, removes
     their blacklist entries and (by default) kicks the live session;
   - blocks users at/over the cap: password disabled, **all known MACs
     blacklisted** (expiring with the 24 h ban), live sessions PoD-kicked, then
     `banned_until = now + CYRAD_BAN_HOURS`.
   - never resurrects a revoked/expired guest ticket (it stays disabled).
3. **Every 5 minutes** (`/etc/cron.d/cyrad-guest`) the guest cron expires
   over-time/over-use tickets, disables them, blacklists their MACs until the
   purge date, and **deletes tickets that have been revoked for
   `CYRAD_GUEST_PURGE_DAYS` (default 7) days** together with their account,
   billing, profile and blacklist rows (accounting history stays).
4. **Manual, from the panel:**
   - **Users → Reset** (single or bulk): clears the usage window, lifts bans and
     MAC blocks, re-enables the account and kicks the live session.
   - **Users → Edit:** saving a new (higher) limit also re-enables the user,
     clears the ban and drops their MAC blocks, so they can rejoin at once.
   - **MAC Blacklist** page: add/unblock MACs by hand, remove expired entries.

### Tuning constants (`config.php`, all optional)

| Constant | Default | Meaning |
|---|---|---|
| `CYRAD_WINDOW_HOURS` | 24 | rolling allowance window |
| `CYRAD_BAN_HOURS` | 24 | how long a cap-hit block lasts |
| `CYRAD_RESET_KICK` | `1` | kick session when the daily window resets (`0` = no kick) |
| `CYRAD_GUEST_PURGE_DAYS` | 7 | auto-delete revoked guest tickets after N days |
| `CYRAD_DAS_SECRET` | — | force one DAS secret instead of parsing `clients.conf` |

---

## 4. Router platforms

### 4.1 OpenWrt (reference implementation)

`openwrt/cyrad-router-setup.sh` is the only router script. It sets up:

- WireGuard (`wg0`, `10.9.0.2`) with reboot persistence in `/etc/rc.local`;
- `/usr/bin/cyrad-kick` (ubus `del_client`) + VPS SSH key, invoked by the
  backend over the tunnel when a user exceeds the 24 h cap;
- DNS blocklist sync (dnsmasq, every 5 min) + forced-LAN-DNS nft rule;
- a firewall rule accepting DAE (UDP/3799) on `wg0`.

It does not change WiFi/RADIUS/PPPoE/LAN settings and does not restart WiFi.

```
# /etc/cyrad.conf (written by the router script)
BACKEND_URL="http://<VPS-IP>:8080"
BACKEND_IP="<VPS-IP>"
ORG_ID=0                  # 0 = all enabled DNS rules
API_TOKEN=…
UPSTREAM_DNS=8.8.8.8
LAN_IFACE=br-lan
OPENWRT_LAN_IP=192.168.2.1
```

### 4.2 Cisco IOS / IOS-XE (enterprise standard)

RADIUS + 802.1X + CoA/DAS:

```
aaa new-model
radius server CYRAD
 address ipv4 192.168.1.10 auth-port 1812 acct-port 1813
 key <radius-secret>
aaa group server radius CYRAD-GRP
 server name CYRAD
aaa authentication dot1x default group CYRAD-GRP
aaa authorization network default group CYRAD-GRP
aaa accounting dot1x default start-stop group CYRAD-GRP
aaa server radius dynamic-author
 client 192.168.1.10 server-key <radius-secret>   ! accepts PoD/CoA from CYRAD
!
dot1x system-auth-control
interface GigabitEthernet1/0/1
 switchport mode access
 authentication port-control auto
 dot1x pae authenticator
 spanning-tree portfast
```

The RADIUS reply attributes are used for authentication.
**MAB** (MAC as username/password) works too because the panel's SQL check also
matches `Calling-Station-Id` against the blacklist.

**NAT/DHCP board export:** IOS cannot POST, so schedule a TCL script with
`kron`. It produces the same JSON accepted by `admin/api_nat_table.php`:

```tcl
# cyrad-nat.tcl — run with:  kron occurrence CYRAD-NAT at 2 recurring
#   policy-list CYRAD-NAT
#    cli show ip dhcp binding
proc cyrad_nat {} {
  set token "…CYRAD_API_TOKEN…"
  set url   "http://192.168.1.10:8080/admin/api_nat_table.php"
  set org   "1"
  set entries "\["
  set first 1
  foreach line [split [exec "show ip dhcp binding | include ^[0-9]"] "\n"] {
    set f [regexp -inline {\S+} $line]
    if {[llength $f] >= 2} {
      set ip  [lindex $f 0]
      set mac [string tolower [string map {- :} [lindex $f 1]]]
      if {!$first} { append entries "," }
      set first 0
      append entries "{\"ip\":\"$ip\",\"mac\":\"$mac\",\"hostname\":\"\",\"iface\":\"\",\"vlan\":\"\"}"
    }
  }
  append entries "\]"
  # IOS HTTP client (no TLS): post form-urlencoded
  exec "copy /noverify http://$url?token=$token&org=$org&entries=$entries null:"
}
```

> IOS `show ip dhcp binding` prints `IP  MAC  Lease-expiry  Type`; the regexp
> above keeps it deliberately simple. If the platform supports `guest-shell`
> or Python (IOS-XE 16.12+ with AppGigabitEthernet), prefer a real HTTP client
> and JSON encoding. The endpoint only requires a valid token + JSON array, so
> any automation (Ansible, Nornir, a Linux box on the LAN) can ship the board —
> e.g. `ansible` with `ios_command: show ip dhcp binding` + `uri:` POST.

### 4.3 Any other enterprise router (HP/Aruba, Juniper, Cambium, …)

Requirements are the standard RADIUS contract:

1. **RADIUS client** pointing at the CYRAD server (auth 1812, acct 1813,
   shared secret, accounting interim 300 s).
2. **802.1X / MAB** on the SSID/ports, with the AP sending
   `Calling-Station-Id`, `Called-Station-Id` and accounting.
3. **CoA/Disconnect support** (RFC 5176, UDP 3799) *or* the router enforcer
   pattern: a small script on any Linux-ish device that polls
   `api_blocked_macs.php?format=text` + `api_disabled_users.php` and kicks
   locally. The enforcer ship is plain POSIX `sh` + `hostapd_cli`, so it ports
   to any hostapd-based AP.
4. **DHCP/ARP board export**: anything that can print `IP, MAC, hostname,
   VLAN` and POST form data can feed `api_nat_table.php` (the format is above).
   If the router cannot POST, run the collector on a LAN server that reaches it
   via SNMP/CLI.

**Vendor CoA notes**

| Platform | Mechanism | Attribute to send |
|---|---|---|
| Aruba/HPE | `aaa rfc-3576-server`, dynamic-author | Session-Timeout / PoD |
| Cisco | `aaa server radius dynamic-author` | standard PoD (User-Name + Acct-Session-Id) |
| Juniper | `set access radius-server … accounting-port 1813` | PoD via `request` / NAS-IP |
| Cambium / others | usually no CoA — use the enforcer polling model | — |

---

## 5. API quick reference

| Endpoint | Auth | Purpose |
|---|---|---|
| `admin/api_nat_table.php` | POST `token` | router DHCP/ARP board (IP→MAC) |
| `admin/api_blocked_macs.php` | `?t=` / POST `token` | blacklisted MACs (`format=text` for shells) |
| `admin/api_disabled_users.php` | `?t=` | disabled usernames for the enforcer |
| `admin/api_dns_rules.php` | `?t=` | block/allow rules for the DNS monitor |
| `admin/api_dns_flow.php` | `?t=` | DNS query logging from remote resolvers |
| `admin/api_user_status.php` | admin session | online/offline map for the Users page |

Tokens come from `config.php` (`CYRAD_API_TOKEN`) and are printed to
`/root/CYRAD_CREDENTIALS.txt`. An empty token means "open LAN mode" — set a
token on any shared network.

---

## 6. Troubleshooting

| Symptom | Check |
|---|---|
| Cap not enforced immediately | `tail -20 /var/log/cyrad-limit.log`; is `/etc/cron.d/cyrad-limit` present and pointing at the right deploy dir? |
| User still online after cap | Router reachable for PoD (`radacct.nasipaddress` + UDP 3799 open)? Otherwise the enforcer should kick within `ENFORCER_POLL` seconds. |
| User cannot rejoin after limit raise | `mac_blacklist` still holds a MAC? Edit/Save the user (clears it), or use **Users → Reset**; verify the auth check returns allow (`freeradius -XC` + `radtest`). |
| Guest device still online after revoke | Wait ≤ 15 s for the enforcer, or check `api_blocked_macs.php?format=text` contains the MAC. |
| Revoked tickets piling up | `CYRAD_GUEST_PURGE_DAYS` (default 7) and the `cyrad-guest` cron; run `php admin/expire_guest_tickets.php` by hand to see output. |
| IP Map empty | `nat_mappings.updated_at` advancing? Forwarder cron on the router? Client must have a RADIUS session (MAC in `radacct`). |
| Auth rejects everyone after editing FreeRADIUS | `freeradius -XC`; restore `queries.conf.cyrad-bak` and re-run `freeradius/auth-limit-check.sh`. |
