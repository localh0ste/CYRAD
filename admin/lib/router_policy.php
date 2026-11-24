<?php
/**
 * CYRAD — shared router policy helpers.
 *
 * Used by the data-limit cron, the guest-ticket cron, the admin panel and the
 * HTTP APIs. Everything is env-first (CYRAD_DB_* overrides config.php) so the
 * same code can be pointed at a test database in CI. Callers that need the
 * CYRAD_* constants load config.php first; this file only uses defined() guards.
 */

if (!function_exists('cyrad_env')) {
    function cyrad_env(string $key, string $default = ''): string {
        $v = getenv($key);
        if ($v !== false) return $v;
        return defined($key) ? (string)constant($key) : $default;
    }

    function cyrad_connect(): mysqli {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(cyrad_env('CYRAD_DB_HOST', 'localhost'), cyrad_env('CYRAD_DB_USER', 'radius'),
            cyrad_env('CYRAD_DB_PASS', ''), cyrad_env('CYRAD_DB_NAME', 'radius'));
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone='+00:00'");
        return $db;
    }

    /** 'AA-BB-...' / 'aabbcc...' → 'aa:bb:cc:dd:ee:ff', '' when invalid. */
    function cyrad_normalize_mac(?string $mac): string {
        $m = preg_replace('/[^0-9a-f]/', '', strtolower(trim((string)$mac)));
        return strlen($m) === 12 ? implode(':', str_split($m, 2)) : '';
    }

    function cyrad_user_org(mysqli $db, string $username): int {
        $st = $db->prepare("SELECT organization_id FROM data_limits WHERE username=?
                            UNION SELECT organization_id FROM radcheck WHERE username=?
                            UNION SELECT organization_id FROM user_profiles WHERE username=? LIMIT 1");
        $st->bind_param('sss', $username, $username, $username);
        $st->execute();
        $st->bind_result($org);
        $org = $st->fetch() ? (int)$org : 0;
        $st->close();
        return $org;
    }

    function cyrad_blacklist_add(mysqli $db, int $org, string $mac, string $reason,
                                 string $username = '', ?string $expiresAt = null): bool {
        $mac = cyrad_normalize_mac($mac);
        if ($mac === '' || $org <= 0) return false;
        $reason   = substr($reason, 0, 255);
        $username = substr($username, 0, 64);
        if ($expiresAt === null) {
            $st = $db->prepare("INSERT INTO mac_blacklist (organization_id,mac,reason,username,expires_at)
                                VALUES (?,?,?,?,NULL)
                                ON DUPLICATE KEY UPDATE reason=VALUES(reason),username=VALUES(username),
                                                        expires_at=NULL,created_at=CURRENT_TIMESTAMP");
            $st->bind_param('isss', $org, $mac, $reason, $username);
        } else {
            $st = $db->prepare("INSERT INTO mac_blacklist (organization_id,mac,reason,username,expires_at)
                                VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE reason=VALUES(reason),username=VALUES(username),
                                                        expires_at=VALUES(expires_at),created_at=CURRENT_TIMESTAMP");
            $st->bind_param('issss', $org, $mac, $reason, $username, $expiresAt);
        }
        $st->execute();
        $st->close();
        return true;
    }

    function cyrad_blacklist_forget_user(mysqli $db, string $username): int {
        $st = $db->prepare("DELETE FROM mac_blacklist WHERE username=?");
        $st->bind_param('s', $username);
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        return $n;
    }

    function cyrad_blacklist_prune(mysqli $db): int {
        $db->query("DELETE FROM mac_blacklist WHERE expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()");
        return $db->affected_rows;
    }

    /** Live accounting sessions for a user: session id / NAS IP / station MAC. */
    function cyrad_live_sessions(mysqli $db, string $username): array {
        $st = $db->prepare("SELECT acctsessionid, nasipaddress, callingstationid FROM radacct
                            WHERE username=? AND (acctstoptime IS NULL OR acctstoptime='')");
        $st->bind_param('s', $username);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    /** Every MAC ever seen for this user in the recent accounting window. */
    function cyrad_user_macs(mysqli $db, string $username, int $days = 30): array {
        $days = max(1, $days);
        $st = $db->prepare("SELECT DISTINCT callingstationid FROM radacct
                            WHERE username=? AND acctstarttime >= UTC_TIMESTAMP() - INTERVAL $days DAY");
        $st->bind_param('s', $username);
        $st->execute();
        $macs = [];
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $mac = cyrad_normalize_mac((string)$row['callingstationid']);
            if ($mac !== '') $macs[$mac] = true;
        }
        $st->close();
        return array_keys($macs);
    }

    /** Prefix/strip 'disabled_' on the Cleartext-Password row. Idempotent. */
    function cyrad_set_disabled(mysqli $db, string $username, bool $disabled): bool {
        $st = $db->prepare("SELECT id, value FROM radcheck WHERE username=? AND attribute='Cleartext-Password' LIMIT 1");
        $st->bind_param('s', $username);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return false;
        $is = str_starts_with((string)$row['value'], 'disabled_');
        if ($is === $disabled) return false;
        $new = $disabled ? 'disabled_' . $row['value'] : substr($row['value'], strlen('disabled_'));
        $up = $db->prepare("UPDATE radcheck SET value=? WHERE id=?");
        $up->bind_param('si', $new, $row['id']);
        $up->execute();
        $up->close();
        return true;
    }

    /** True when a guest ticket exists for this user and is not active. */
    function cyrad_guest_ticket_dead(mysqli $db, string $username): bool {
        $st = $db->prepare("SELECT status FROM guest_tickets WHERE username=? LIMIT 1");
        $st->bind_param('s', $username);
        $st->execute();
        $status = $st->get_result()->fetch_row()[0] ?? null;
        $st->close();
        return $status !== null && $status !== 'active';
    }

    // ---- RADIUS Disconnect (PoD / RFC 5176) --------------------------------
    function cyrad_nas_secret(string $nasip): ?string {
        if (defined('CYRAD_DAS_SECRET') && CYRAD_DAS_SECRET !== '') return CYRAD_DAS_SECRET;
        $file = defined('CYRAD_CLIENTS_CONF') ? CYRAD_CLIENTS_CONF : '/etc/freeradius/3.0/clients.conf';
        if (!is_file($file) || !is_readable($file)) return null;
        $txt = file_get_contents($file);
        if (!preg_match_all('/client\s+[^{]*\{([^}]*)\}/s', $txt, $blocks)) return null;
        foreach ($blocks[1] as $body) {
            if (!preg_match('/ipaddr\s*=\s*([^;\s]+)/', $body, $m_ip)) continue;
            if (!preg_match('/secret\s*=\s*([^;\s]+)/', $body, $m_sec)) continue;
            $ip  = trim($m_ip[1]);
            $sec = trim(trim($m_sec[1], "\"'"));
            if ($ip === '0.0.0.0/0') return $sec;
            if ($ip === $nasip)      return $sec;
            if (str_contains($ip, '/') && cyrad_cidr_match($nasip, $ip)) return $sec;
        }
        return null;
    }

    function cyrad_cidr_match(string $ip, string $cidr): bool {
        if (!str_contains($cidr, '/')) return false;
        [$subnet, $mask] = explode('/', $cidr, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $mask_l = ~((1 << (32 - (int)$mask)) - 1) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask_l) === (ip2long($subnet) & $mask_l);
    }

    /** Returns ['ok'=>bool,'msg'=>string]. */
    function cyrad_send_disconnect(string $nasip, string $secret, string $username,
                                   string $sessionid = '', string $calling = ''): array {
        if (!filter_var($nasip, FILTER_VALIDATE_IP)) return ['ok' => false, 'msg' => 'invalid NAS IP'];
        $bin = defined('CYRAD_RADCLIENT_BIN') ? CYRAD_RADCLIENT_BIN : '/usr/bin/radclient';
        $port = defined('CYRAD_DAS_PORT') ? (int)CYRAD_DAS_PORT : 3799;
        // When the NAS is behind NAT/CGNAT, CYRAD_DAS_TARGET_IP points at the
        // router's WireGuard tunnel address; the Disconnect packet then travels
        // through the outbound tunnel instead of requiring an inbound port.
        $target = (defined('CYRAD_DAS_TARGET_IP') && CYRAD_DAS_TARGET_IP !== '')
            ? CYRAD_DAS_TARGET_IP : $nasip;
        if (!is_executable($bin)) return ['ok' => false, 'msg' => "radclient not found at $bin"];
        $attrs = "User-Name = $username\n";
        if ($sessionid !== '') $attrs .= "Acct-Session-Id = $sessionid\n";
        if ($calling !== '')   $attrs .= "Calling-Station-Id = $calling\n";
        $cmd = 'echo ' . escapeshellarg($attrs) . ' | ' . escapeshellarg($bin)
             . ' -r 2 -t 3 ' . escapeshellarg($target . ':' . $port) . ' disconnect ' . escapeshellarg($secret) . ' 2>&1';
        exec($cmd, $out_lines, $retval);
        $out = implode("\n", (array)$out_lines);
        $ok = ($retval === 0) && (stripos($out, 'ack') !== false) && (stripos($out, 'nak') === false);
        if (!$ok && $calling !== '' && is_executable('/usr/local/bin/cyrad_router_kick')) {
            // The NAS sits behind NAT and this hostapd build does not answer
            // DAE, so fall back to deauthenticating the MAC on the router over
            // the WireGuard tunnel using OpenWrt's ubus API.
            $cmd2 = '/usr/local/bin/cyrad_router_kick ' . escapeshellarg($calling) . ' 2>&1';
            exec($cmd2, $ko, $krv);
            if ($krv === 0) {
                return ['ok' => true, 'msg' => 'Disconnected via WireGuard tunnel (ubus deauth); DAE gave no reply'];
            }
        }
        return ['ok' => $ok, 'msg' => trim($out !== '' ? $out : 'no output (timed out / no DAS listener)')];
    }

    /** Kick every live session: PoD to the NAS plus a local accounting close. */
    function cyrad_kick_user(mysqli $db, string $username): array {
        $kicked = 0; $macs = [];
        foreach (cyrad_live_sessions($db, $username) as $s) {
            $mac = cyrad_normalize_mac((string)$s['callingstationid']);
            if ($mac !== '') $macs[] = $mac;
            $nasip = trim((string)$s['nasipaddress']);
            if ($nasip === '' || $nasip === '127.0.0.1') continue;
            $secret = cyrad_nas_secret($nasip);
            if ($secret === null) continue;
            $res = cyrad_send_disconnect($nasip, $secret, $username,
                (string)$s['acctsessionid'], (string)$s['callingstationid']);
            if ($res['ok']) $kicked++;
        }
        return ['kicked' => $kicked, 'macs' => array_values(array_unique($macs))];
    }

    /**
     * Block a user everywhere at once: disable login, blacklist their live MACs
     * (expiring with the ban) and disconnect current sessions.
     */
    function cyrad_block_user(mysqli $db, string $username, string $reason,
                              ?int $banHours = null): array {
        $banHours = $banHours ?? max(1, (int)cyrad_env('CYRAD_BAN_HOURS', '24'));
        $org = cyrad_user_org($db, $username);
        $live = cyrad_kick_user($db, $username);
        cyrad_set_disabled($db, $username, true);
        $expires = gmdate('Y-m-d H:i:s', time() + $banHours * 3600);
        $known = array_unique(array_merge($live['macs'], cyrad_user_macs($db, $username)));
        $macs = 0;
        foreach ($known as $mac) {
            if (cyrad_blacklist_add($db, $org, $mac, $reason, $username, $expires)) $macs++;
        }
        return ['kicked' => $live['kicked'], 'blacklisted' => $macs];
    }

    /**
     * Reset a user's daily usage: clear accounting, lift the ban, re-enable,
     * drop their blacklist entries and optionally kick live sessions so the
     * next connect starts clean. Refuses to resurrect dead guest tickets.
     */
    function cyrad_reset_user(mysqli $db, string $username, bool $kick = true): array {
        if (cyrad_guest_ticket_dead($db, $username)) {
            return ['ok' => false, 'reason' => 'guest ticket is revoked/expired'];
        }
        $kicked = 0;
        if ($kick) $kicked = cyrad_kick_user($db, $username)['kicked'];
        $st = $db->prepare("DELETE FROM radacct WHERE username=?");
        $st->bind_param('s', $username);
        $st->execute();
        $st->close();
        $st = $db->prepare("UPDATE data_limits SET banned_until=NULL, usage_reset_ts=UTC_TIMESTAMP() WHERE username=?");
        $st->bind_param('s', $username);
        $st->execute();
        $st->close();
        cyrad_blacklist_forget_user($db, $username);
        cyrad_set_disabled($db, $username, false);
        return ['ok' => true, 'kicked' => $kicked];
    }
}
