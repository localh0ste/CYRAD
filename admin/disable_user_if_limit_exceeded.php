<?php
/**
 * CYRAD — data-cap enforcement cron (every minute via /etc/cron.d/cyrad-limit).
 *
 *   1. Daily reset: every limited user whose 24 h window has passed gets a
 *      fresh allowance — usage cleared, ban lifted, account re-enabled,
 *      blacklist entries removed, and (unless CYRAD_RESET_KICK=0) their live
 *      session disconnected so the next connect starts from zero.
 *   2. Ban expiry safety net for stamped bans.
 *   3. Per-user check: over cap  -> disable + blacklist MAC + PoD kick +
 *      24 h ban. Under cap -> re-enable, lift ban, drop blacklist entries
 *      (raising the limit lets the user join again immediately).
 *   4. Keep Session-Timeout replies in sync for the NAS re-auth fallback.
 *
 * Revoked/expired guest tickets are never resurrected by this cron.
 *
 * Auth-time enforcement also runs in FreeRADIUS itself (see
 * freeradius/auth-limit-check.sh): the SQL authorize query rejects an
 * over-limit user or a blacklisted MAC on every authentication, so the two
 * agree even between cron runs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/router_policy.php';

$conn  = cyrad_connect();
$BAN_HOURS    = max(1, (int)cyrad_env('CYRAD_BAN_HOURS', '24'));
$WINDOW_HOURS = max(1, (int)cyrad_env('CYRAD_WINDOW_HOURS', '24'));
$RESET_KICK   = cyrad_env('CYRAD_RESET_KICK', '1') === '1';

// Keep radreply Session-Timeout in sync so the NAS force-re-auths limited
// users; an over-limit (disabled) account then fails re-auth and is dropped
// even if the router enforcer isn't running (defense-in-depth).
sync_radreply_timeouts($conn);

// --- Step 0: daily window reset ---------------------------------------------
// Limited users whose usage window has expired get a clean slate. A reset only
// re-enables local accounts; dead guest tickets stay disabled and blacklisted.
$reset = $conn->query(
    "SELECT d.username FROM data_limits d
     JOIN radcheck r ON r.username = d.username AND r.attribute='Cleartext-Password'
     WHERE d.internet_limit > 0
       AND LOWER(d.internet_type) <> 'unlimited'
       AND (d.usage_reset_ts IS NULL
            OR d.usage_reset_ts <= UTC_TIMESTAMP() - INTERVAL $WINDOW_HOURS HOUR)"
);
if ($reset) {
    while ($row = $reset->fetch_assoc()) {
        $u = $row['username'];
        if (cyrad_guest_ticket_dead($conn, $u)) {
            cyrad_set_disabled($conn, $u, true);
            continue;
        }
        $r = cyrad_reset_user($conn, $u, $RESET_KICK);
        echo "🔄 window reset for '$u' — usage cleared, ban lifted"
           . ($RESET_KICK && $r['kicked'] ? ", {$r['kicked']} session(s) kicked" : '') . ".\n";
    }
}

// --- Step 1: auto-expire any lingering bans (safety net) ---------------------
$exp = $conn->query(
    "SELECT d.username FROM data_limits d
     JOIN radcheck r ON r.username = d.username AND r.attribute='Cleartext-Password'
     WHERE d.banned_until IS NOT NULL AND d.banned_until <= UTC_TIMESTAMP()"
);
if ($exp) {
    while ($row = $exp->fetch_assoc()) {
        $u = $row['username'];
        if (cyrad_guest_ticket_dead($conn, $u)) { cyrad_set_disabled($conn, $u, true); continue; }
        cyrad_reset_user($conn, $u, false);
        echo "✅ ban expired for '$u' — re-enabled, usage cleared, blacklist lifted.\n";
    }
}

// --- Step 2: per-user cap check ----------------------------------------------
$limit_query = $conn->query("SELECT username, internet_limit, internet_type FROM data_limits");
if (!$limit_query) {
    fwrite(STDERR, "❌ Query failed: " . $conn->error . "\n");
    exit(1);
}

while ($row = $limit_query->fetch_assoc()) {
    $username = $row['username'];
    $limit_bytes = (int)$row['internet_limit'];
    $internet_type = strtolower($row['internet_type'] ?? 'limited');
    $dead_guest = cyrad_guest_ticket_dead($conn, $username);

    // Unlimited (type=unlimited OR limit<=0): never disable — ensure enabled,
    // unless the account belongs to a revoked/expired guest ticket.
    if ($limit_bytes <= 0 || $internet_type === 'unlimited') {
        if ($dead_guest) { cyrad_set_disabled($conn, $username, true); continue; }
        cyrad_set_disabled($conn, $username, false);
        cyrad_blacklist_forget_user($conn, $username);
        $st = $conn->prepare("UPDATE data_limits SET banned_until=NULL WHERE username=? AND banned_until IS NOT NULL");
        $st->bind_param('s', $username); $st->execute(); $st->close();
        continue;
    }

    if ($dead_guest) { cyrad_set_disabled($conn, $username, true); continue; }

    // Rolling window usage from usage_reset_ts (NULL = window starts now).
    $st = $conn->prepare("
        SELECT COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) AS total_usage
        FROM radacct
        WHERE username = ?
          AND acctstarttime >= COALESCE(
                (SELECT usage_reset_ts FROM data_limits WHERE username = ? LIMIT 1),
                UTC_TIMESTAMP())
    ");
    $st->bind_param('ss', $username, $username);
    $st->execute();
    $total_usage = (int)($st->get_result()->fetch_assoc()['total_usage'] ?? 0);
    $st->close();

    if ($total_usage >= $limit_bytes) {
        $r = cyrad_block_user($conn, $username, 'Data cap reached', $BAN_HOURS);
        $st = $conn->prepare("UPDATE data_limits SET banned_until = UTC_TIMESTAMP() + INTERVAL ? HOUR WHERE username = ?");
        $st->bind_param('is', $BAN_HOURS, $username); $st->execute(); $st->close();
        echo "🚫 '$username' blocked for $BAN_HOURS hours (cap reached; "
           . "{$r['kicked']} kick(s), {$r['blacklisted']} MAC(s) blacklisted).\n";
    } else {
        // Under cap: ensure the user can connect and is not banned/blacklisted.
        cyrad_set_disabled($conn, $username, false);
        cyrad_blacklist_forget_user($conn, $username);
        $st = $conn->prepare("UPDATE data_limits SET banned_until = NULL WHERE username = ? AND banned_until IS NOT NULL");
        $st->bind_param('s', $username); $st->execute(); $st->close();
    }
}

$conn->close();

// ---------- helpers ----------

/**
 * Ensure limited users have a Session-Timeout reply attribute so the NAS
 * periodically re-authenticates them. When disabled (over cap), the re-auth
 * fails and the session is dropped — a NAT-proof fallback that works even
 * without the router-side enforcer. Only manages rows we create (value 300).
 */
function sync_radreply_timeouts(mysqli $conn): void {
    $conn->query("DELETE FROM radreply WHERE attribute='Session-Timeout' AND value='300'");
    $conn->query("
        INSERT INTO radreply (username, attribute, op, value)
        SELECT username, 'Session-Timeout', ':=', '300'
        FROM data_limits
        WHERE internet_limit > 0 AND LOWER(internet_type) <> 'unlimited'
    ");
}
