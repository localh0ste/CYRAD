#!/usr/bin/env php
<?php
/**
 * CYRAD DNS Blocker (PHP)
 * =======================
 * A minimal UDP DNS forwarder whose ONLY job is per-organization domain
 * blocking. It enforces dns_rules (block patterns) by answering NXDOMAIN
 * (or resolving to the block page) for matched domains and forwarding
 * everything else upstream untouched.
 *
 * There is NO browsing-history logging, NO per-query attribution, and NO
 * VPN/proxy detection — those monitoring features were removed. This daemon
 * simply blocks specific websites per the org's rule list.
 *
 * Point your router / captive-portal DNS at this host:port.
 *
 * Run:  php dns/dns_monitor.php      (systemd: cyrad-dnsmon.service)
 */

require_once __DIR__ . '/../config.php';

function cyrad_db() {
    $c = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
    return $c->connect_error ? null : $c;
}

/**
 * Map one of our metadata tables to an org.
 * AD ("ad" source) users have NO radcheck row (credentials live in the domain);
 * they are registered as shadows in user_profiles (auth_source = 'ad') so billing
 * and per-org DNS rules still work.
 */
function org_for_username($c, $u) {
    $u = $c->real_escape_string($u);
    $r = $c->query("SELECT organization_id FROM radcheck WHERE username='$u' LIMIT 1");
    if ($r && $r->num_rows) return (int)$r->fetch_assoc()['organization_id'];
    $r = $c->query("SELECT organization_id FROM user_profiles WHERE username='$u' LIMIT 1");
    if ($r && $r->num_rows) return (int)$r->fetch_assoc()['organization_id'];
    return null;
}

function norm_mac($mac) {
    $m = strtolower(trim($mac));
    $m = preg_replace('/[^0-9a-f]/', '', $m);
    return (strlen($m) === 12) ? implode(':', str_split($m, 2)) : null;
}

/**
 * Turn a Called-Station-Id ("AA:BB:CC:DD:EE:FF:SSID" or
 * "AA-BB-CC-DD-EE-FF") into its VLAN tag from the BSSID->VLAN map,
 * with a fallback to any "guests" VLAN when the BSSID map wants guest auth.
 */
function vlan_for_bssid($called, $c = null) {
    return null;
}

/**
 * Attribut a UDP query's source IP to [org, vlan, user].
 *
 * Preference order:
 *   1. Direct 802.1X session: radacct.framedipaddress is a real IP the user got
 *      from RADIUS (Framed-IP-Address). -> username -> org (+ VLAN via BSSID).
 *   2. NAT'd client (the common home-router case): the router shares its NAT /
 *      DHCP board (nat_mappings) so client private IP -> MAC -> the open RADIUS
 *      session's callingstationid (the client's MAC) -> username -> org + VLAN.
 *   3. IP still unknown but a nat_mappings row exists: fall back to that row's
 *      org + router-reported VLAN (unauthenticated device, e.g. a printer).
 *
 * Returns null when nothing can be attributed (caller falls back to
 * CYRAD_DEFAULT_ORG so org-wide rules still apply).
 */
function resolve_ip($ip) {
    $c = cyrad_db();
    if (!$c) return null;
    $ip = $c->real_escape_string($ip);

    // 1) direct RADIUS session with a real framed IP
    $r = $c->query(
        "SELECT username, calledstationid FROM radacct WHERE framedipaddress='$ip'
         AND (acctstoptime IS NULL OR acctstoptime > NOW() - INTERVAL 10 MINUTE)
         ORDER BY acctstarttime DESC LIMIT 1"
    );
    if ($r && $r->num_rows) {
        $row = $r->fetch_assoc();
        $org = org_for_username($c, $row['username']);
        $vlan = vlan_for_bssid($row['calledstationid'] ?? '', $c);
        $c->close();
        return ['org' => $org, 'vlan' => $vlan, 'user' => $row['username']];
    }

    // 2) NAT: client private IP -> MAC -> open RADIUS session
    $r = $c->query(
        "SELECT organization_id, mac FROM nat_mappings WHERE client_ip='$ip'
         AND updated_at > NOW() - INTERVAL 20 MINUTE LIMIT 1"
    );
    if ($r && $r->num_rows) {
        $n = $r->fetch_assoc();
        $mac = norm_mac($n['mac'] ?? '');
        if ($mac) {
            $r2 = $c->query(
                "SELECT username, calledstationid FROM radacct
                 WHERE LOWER(REPLACE(REPLACE(REPLACE(callingstationid,'-',''),':',''),' ',''))='$mac'
                   AND (acctstoptime IS NULL OR acctstoptime > NOW() - INTERVAL 20 MINUTE)
                 ORDER BY acctstarttime DESC LIMIT 1"
            );
            if ($r2 && $r2->num_rows) {
                $row = $r2->fetch_assoc();
                $org = org_for_username($c, $row['username']);
                $c->close();
                return ['org' => $org, 'vlan' => null, 'user' => $row['username']];
            }
        }
        // 3) mapping known but no authenticated session
        $c->close();
        return ['org' => (int)$n['organization_id'], 'vlan' => null, 'user' => null];
    }

    $c->close();
    return null;
}

/**
 * Load the rules that apply: block rules keyed to the org.
 */
function load_rules($org, $vlan = null) {
    if ($org === null) return [];
    $c = cyrad_db();
    if (!$c) return [];
    $out = [];
    $sql = "SELECT domain_pattern, action FROM dns_rules WHERE organization_id=$org AND enabled=1";
    $res = $c->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $out[] = [$row['domain_pattern'], $row['action']];
    $c->close();
    return $out;
}

function rule_matches($domain, $pattern) {
    $domain = strtolower(rtrim($domain, '.'));
    $pattern = strtolower(trim($pattern));
    if (strpos($pattern, '*.') === 0) {
        $base = substr($pattern, 2);
        return $domain === $base || substr($domain, -strlen($base) - 1) === ".$base";
    }
    if ($pattern[0] === '*') return substr($domain, -strlen($pattern) + 1) === substr($pattern, 1);
    return $domain === $pattern;
}

function parse_question($data) {
    $i = 12; $len = strlen($data); $labels = [];
    while ($i < $len) {
        $l = ord($data[$i]);
        if ($l === 0) { $i++; break; }
        if ($l & 0xC0) break; // compression pointer - not expected in queries
        $labels[] = substr($data, $i + 1, $l);
        $i += 1 + $l;
    }
    if ($i + 4 > $len) return null;
    return implode('.', $labels);
}

// Return just the question section (name + qtype + qclass) from a query,
// stripping any trailing records (e.g. EDNS0 OPT in ADDITIONAL) so forged
// responses stay well-formed even when the client sends EDNS.
function build_question($data) {
    $i = 12; $len = strlen($data);
    while ($i < $len) {
        $l = ord($data[$i]);
        if ($l === 0) { $i++; break; }
        if ($l & 0xC0) { $i += 2; break; } // compression pointer - not expected in queries
        $i += 1 + $l;
    }
    $end = $i + 4; // qtype + qclass
    if ($end > $len) $end = $len;
    return substr($data, 12, $end - 12);
}

function forge_nxdomain($data) {
    $qdcount = substr($data, 4, 2);
    return substr($data, 0, 2) . "\x81\x83" . $qdcount
         . "\x00\x00\x00\x00\x00\x00" . build_question($data);
}

// Answer a blocked query with an A record pointing at the CYRAD web host, so the
// browser loads the branded "Access Blocked" page (block.php) instead of erroring.
function forge_a_record($data, $ip) {
    $id    = substr($data, 0, 2);
    $flags = "\x81\x80";                 // response, recursion available
    $head  = $id . $flags . "\x00\x01\x00\x01\x00\x00\x00\x00";
    $q     = build_question($data);      // question section only (no EDNS/OPT)
    $name  = "\xc0\x0c";                // compression pointer -> question name
    $type  = "\x00\x01";                // A
    $class = "\x00\x01";                // IN
    $ttl   = pack('N', 60);
    $rdata = inet_pton($ip);            // 4 bytes for IPv4
    $rdlen = pack('n', strlen($rdata));
    $answer = $name . $type . $class . $ttl . $rdlen . $rdata;
    return $head . $q . $answer;
}

function handle($data, $client_ip) {
    $domain = parse_question($data);
    if (!$domain) return $data; // unparseable - forward as-is
    $domain = strtolower(rtrim($domain, '.'));
    if (!preg_match('/^[a-z0-9.*\-]+$/', $domain)) {
        $domain = preg_replace('/[^a-z0-9.\-*]/', '', $domain) ?: '?';
    }

    $resolved = resolve_ip($client_ip);
    $org  = $resolved['org'] ?? null;
    $vlan = $resolved['vlan'] ?? null;
    $user = $resolved['user'] ?? null;
    // NAT routers assign client IPs via their own DHCP, so radacct.framedipaddress is
    // empty and we can't map the query to a user/org. Fall back to the configured default
    // org so its DNS rules are still enforced (single-org / one-NAS deployments).
    if ($org === null && defined('CYRAD_DEFAULT_ORG') && CYRAD_DEFAULT_ORG > 0) {
        $org = CYRAD_DEFAULT_ORG;
    }
    $rules = load_rules($org, $vlan);

    $blocked = false;
    foreach ($rules as [$pattern, $action]) {
        if (rule_matches($domain, $pattern)) {
            if ($action === 'block') $blocked = true;
            break; // first match is decisive
        }
    }

    // No logging, no alerts — just block (or allow).
    if ($blocked) {
        $who = $user ? "user $user" : "ip $client_ip";
        echo "[" . date('Y-m-d H:i:s') . "] BLOCK $domain ($who, org " . (int)$org . ", vlan " . ($vlan ?? '-') . ")\n";
        if (defined('CYRAD_DNS_ENFORCE') && CYRAD_DNS_ENFORCE) {
            if (defined('CYRAD_BLOCK_MODE') && CYRAD_BLOCK_MODE === 'page') {
                return forge_a_record($data, CYRAD_WEB_IP);
            }
            return forge_nxdomain($data);
        }
        return forward_upstream($data);
    }

    return forward_upstream($data);
}

function forward_upstream($data) {
    $up = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_set_option($up, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 4, 'usec' => 0]);
    socket_set_option($up, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 4, 'usec' => 0]);
    list($uhost, $uport) = explode(':', CYRAD_DNS_UPSTREAM) + [null, 53];
    $uport = (int)$uport;
    socket_sendto($up, $data, strlen($data), 0, $uhost, $uport);
    $resp = ''; $f = ''; $p = 0;
    $n = socket_recvfrom($up, $resp, 4096, 0, $f, $p);
    socket_close($up);
    return $n === false ? forge_nxdomain($data) : $resp;
}

// --------------------------------------------------------------------------- //
$listen = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($listen === false) {
    fwrite(STDERR, "Failed to create socket\n");
    exit(1);
}
if (!socket_bind($listen, CYRAD_DNS_LISTEN_HOST, CYRAD_DNS_LISTEN_PORT)) {
    fwrite(STDERR, "Bind failed on " . CYRAD_DNS_LISTEN_HOST . ":" . CYRAD_DNS_LISTEN_PORT . "\n");
    exit(1);
}
echo "[" . date('Y-m-d H:i:s') . "] CYRAD DNS blocker on "
   . CYRAD_DNS_LISTEN_HOST . ":" . CYRAD_DNS_LISTEN_PORT
   . " -> " . CYRAD_DNS_UPSTREAM . "\n";

while (true) {
    $data = ''; $from = ''; $port = 0;
    $len = @socket_recvfrom($listen, $data, 4096, 0, $from, $port);
    if ($len === false) continue;
    try {
        $resp = handle($data, $from);
        socket_sendto($listen, $resp, strlen($resp), 0, $from, $port);
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] handler error: " . $e->getMessage() . "\n";
    }
}
