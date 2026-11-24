<?php
/**
 * CYRAD — guest ticket lifecycle cron.
 *
 * Runs from /etc/cron.d/cyrad-guest (installed by setup-cyrad.sh, every 5 min):
 *
 *   * expire tickets whose expires_at has passed or whose max_uses is spent:
 *     mark the ticket, disable the RADIUS account, cancel
 *     pending mail, disconnect live sessions and blacklist the device MAC;
 *   * purge tickets that have been revoked for CYRAD_GUEST_PURGE_DAYS days
 *     (default 7): the ticket plus its account/billing/pin/profile/blacklist
 *     rows are deleted — accounting history (radacct) is kept;
 *   * prune blacklist rows whose expiry has passed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/guest_ticket_ops.php';

$conn = cyrad_connect();
$purgeDays = max(1, (int)cyrad_env('CYRAD_GUEST_PURGE_DAYS', '7'));
$blacklistUntil = gmdate('Y-m-d H:i:s', time() + $purgeDays * 86400);

$expired = $conn->query(
    "SELECT t.id, t.username, t.max_uses, t.expires_at, t.organization_id,
            (SELECT COUNT(DISTINCT a.acctuniqueid) FROM radacct a WHERE a.username = t.username COLLATE utf8mb4_unicode_ci) AS logins
     FROM guest_tickets t
     WHERE t.status = 'active'
       AND ( (t.expires_at IS NOT NULL AND t.expires_at <= UTC_TIMESTAMP())
          OR t.max_uses > 0 AND (SELECT COUNT(DISTINCT a.acctuniqueid) FROM radacct a WHERE a.username = t.username COLLATE utf8mb4_unicode_ci) >= t.max_uses )"
);

$n = 0;
if ($expired) {
    while ($t = $expired->fetch_assoc()) {
        $u = $conn->real_escape_string($t['username']);
        $expired_by = ($t['expires_at'] !== null && strtotime($t['expires_at'] . ' UTC') <= time()) ? 'expired' : 'revoked';
        $conn->begin_transaction();
        $st = $conn->prepare("UPDATE guest_tickets SET status=? WHERE id=?");
        $st->bind_param("si", $expired_by, $t['id']); $st->execute(); $st->close();
        $st = $conn->prepare("UPDATE guest_email_queue SET status='cancelled',last_error='Ticket expired or used' WHERE ticket_id=? AND status='queued'");
        $st->bind_param('i', $t['id']); $st->execute(); $st->close();
        $conn->query(
            "UPDATE radcheck SET value=CONCAT('disabled_',value)
             WHERE username='$u' AND attribute='Cleartext-Password' AND LEFT(value,9)<>'disabled_'"
        );
        $conn->query("DELETE FROM radreply WHERE username='$u'");
        $conn->commit();
        // Disconnect now and keep the device out until the ticket is purged.
        cyrad_kick_user($conn, (string)$t['username']);
        foreach (cyrad_user_macs($conn, (string)$t['username']) as $mac) {
            cyrad_blacklist_add($conn, (int)$t['organization_id'], $mac,
                $expired_by === 'expired' ? 'Guest ticket expired' : 'Guest ticket used up',
                (string)$t['username'], $blacklistUntil);
        }
        echo "[" . date('Y-m-d H:i:s') . "] guest ticket '{$t['username']}' $expired_by (logins {$t['logins']}/{$t['max_uses']})\n";
        $n++;
    }
}

// Safety net: prune ghost radreply Tunnel rows for non-active guests.
$conn->query(
    "DELETE rr FROM radreply rr
     LEFT JOIN guest_tickets t ON t.username COLLATE utf8mb4_unicode_ci = rr.username
     WHERE rr.username LIKE 'guest\\_%'
       AND (t.id IS NULL OR t.status <> 'active')"
);

// Auto-remove tickets that have been revoked for $purgeDays days.
$purged = cyrad_purge_revoked_guests($conn, $purgeDays);
foreach ($purged as $u) {
    echo "[" . date('Y-m-d H:i:s') . "] purged revoked guest ticket '$u' (older than {$purgeDays}d)\n";
}

// Drop blacklist rows whose expiry has passed.
$pruned = cyrad_blacklist_prune($conn);

echo "[" . date('Y-m-d H:i:s') . "] expire_guest_tickets done ($n expired, "
   . count($purged) . " purged, $pruned blacklist rows pruned)\n";
$conn->close();
