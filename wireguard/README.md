# CYRAD WireGuard approach (no inbound port forwarding)

This folder contains the **VPS-side** WireGuard setup. The router side is a
single script: [`openwrt/cyrad-router-setup.sh`](../openwrt/cyrad-router-setup.sh).

## Files

```
wireguard/
├── README.md                       this file
├── wireguard.env.example           secret-free config template
└── server/
    ├── setup-wireguard-server.sh   VPS: keys, wg0, bootstrap service, router bootstrap file
    └── bootstrap-http-server.sh    VPS: serve the one router script + ipk packages by IP
```

## How it works

```
WiFi client --EAP--> hostapd (OpenWrt NATed router)
                         | RADIUS auth/acct (UDP 1812/1813) ---> VPS
                         |                                      |
                         <==== WireGuard 10.9.0.2 <--> 10.9.0.1 ===
                              router                      CYRAD VPS
                                                           |
                              cap breach -> radclient DAE -> tunnel
                                           -> SSH over tunnel -> /usr/bin/cyrad-kick
                                           -> ubus del_client (MAC)
```

- VPS `wg0`: `10.9.0.1/32`, UDP `51820`, peer `10.9.0.2/32`.
- Router `wg0`: `10.9.0.2/32`, outbound endpoint `<VPS>:51820`, keepalive 25s.
- `config.php` uses `CYRAD_DAS_TARGET_IP='10.9.0.2'`.
- Router fw4 accepts UDP/3799 on `wg0`.
- Since this hostapd build accepts DAE packets but does not answer them, the
  working disconnect path is the `ubus del_client` fallback over WireGuard.
- The same router script also installs DNS blocklist sync (dnsmasq), so no old
  enforcer/observer scripts are needed.

## Fresh VPS install

`install.sh` / `setup-cyrad.sh` calls `server/setup-wireguard-server.sh`
automatically (opt out with `CYRAD_INSTALL_WIREGUARD=0` or `--no-wireguard`).
It:

- generates `/etc/wireguard/{server,router}_{private,public}.key` if absent;
- writes and enables `/etc/wireguard/wg0.conf` (`wg-quick@wg0`);
- opens UDP `51820`;
- creates `/usr/local/bin/cyrad_router_kick` and `/root/.ssh/cyrad_router_ed25519`;
- prepares `/root/cyrad-wireguard-packages/` (router script + ipk packages);
- enables `cyrad-wg-bootstrap.service` on TCP `8000`;
- writes `/root/cyrad-router-bootstrap.sh` (root-only, contains keys).

Copy that last file to the router and run it — it `wget`s the single router
script from `http://<VPS>:8000/cyrad-router-setup.sh` and executes it.

## Standalone server setup

```sh
SERVER_ENDPOINT=<vps-ip> RADIUS_SECRET='...' API_TOKEN='...' ORG_ID=2 \
  bash wireguard/server/setup-wireguard-server.sh
source /root/cyrad-wireguard-router.env
bash wireguard/server/bootstrap-http-server.sh --prepare   # optional refresh
```

## Verification

VPS:

```sh
wg show wg0
ping -c 2 10.9.0.2
ssh -i /root/.ssh/cyrad_router_ed25519 root@10.9.0.2 'echo OK; wg show'
/usr/local/bin/cyrad_router_kick AA:BB:CC:DD:EE:FF
```

Router:

```sh
wg show
ping -c 2 10.9.0.1
nft list ruleset | grep 3799
cat /etc/cyrad.conf
wc -l /etc/dnsmasq.d/cyrad-block.conf
```

See [`INSTALLATION.md`](../INSTALLATION.md) for the complete fresh-install guide.
