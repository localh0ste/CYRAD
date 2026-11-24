<?php
/**
 * CYRAD — guest ticket operations shared by the page, the cron and tests.
 */
require_once __DIR__ . '/router_policy.php';

if (!function_exists('guest_ticket_delete')) {
    /**
     * Remove a guest ticket and every row that belongs to it (RADIUS account,
     * billing row, profile note, blacklist entries). Accounting
     * history (radacct) is deliberately kept for reporting.
     * @return array{0: bool, 1: string} [ok, username or error]
     */
    function guest_ticket_delete(mysqli $db, int $id, int $org): array {
        $st = $db->prepare("SELECT username FROM guest_tickets WHERE id=? AND organization_id=?");
        $st->bind_param('ii', $id, $org);
        $st->execute();
        $st->bind_result($un);
        $have = $st->fetch() ? (string)$un : '';
        $st->close();
        if ($have === '') return [false, 'not found'];

        $esc = $db->real_escape_string($have);
        $db->begin_transaction();
        try {
            foreach (['radcheck', 'radreply', 'data_limits', 'user_profiles', 'mac_blacklist'] as $t) {
                $db->query("DELETE FROM `$t` WHERE username='$esc'");
            }
            $db->query("DELETE FROM guest_tickets WHERE id=$id AND organization_id=$org");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            return [false, $e->getMessage()];
        }
        return [true, $have];
    }
}

if (!function_exists('cyrad_purge_revoked_guests')) {
    /**
     * Delete guest tickets revoked more than $days days ago, with their rows
     * and blacklist entries (radacct is kept). Runs from the guest cron.
     * @return string[] usernames purged
     */
    function cyrad_purge_revoked_guests(mysqli $db, int $days = 7): array {
        $days = max(1, $days);
        $sql = "SELECT id, username, organization_id FROM guest_tickets
                WHERE status='revoked' AND updated_at <= UTC_TIMESTAMP() - INTERVAL $days DAY";
        $res = $db->query($sql);
        $purged = [];
        while ($row = $res->fetch_assoc()) {
            [$ok, $info] = guest_ticket_delete($db, (int)$row['id'], (int)$row['organization_id']);
            if ($ok) $purged[] = $info;
        }
        return $purged;
    }
}
