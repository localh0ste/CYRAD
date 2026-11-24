<?php
/**
 * test_router_policy.php — behaviour tests for the router policy layer:
 * MAC blacklist, block/reset helpers, guest purge, and the exact SQL the
 * FreeRADIUS auth-time check runs.
 *
 * Safety: refuses to run unless CYRAD_DB_NAME ends in `_test`; builds and
 * removes its own fixtures.
 *
 *   CYRAD_DB_NAME=cyrad_policy_test php tests/test_router_policy.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

$dbName = getenv('CYRAD_DB_NAME') ?: '';
if (!preg_match('/_test$/', $dbName)) {
    fwrite(STDERR, "Refusing to run: CYRAD_DB_NAME must end with _test (got '$dbName')\n");
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/admin/lib/guest_ticket_ops.php';

$db = cyrad_connect();
$pass = 0; $fails = 0;
function ok(bool $c, string $w): void { global $pass,$fails; if ($c) { $pass++; return; } $fails++; fwrite(STDERR, "FAIL: $w\n"); }
function eq($g, $w, string $m): void { ok($g === $w, "$m (got " . var_export($g, true) . ', want ' . var_export($w, true) . ')'); }

$ORG = 4747;
$db->query("DELETE FROM organizations WHERE id=$ORG");
$db->query("INSERT INTO organizations (id, org_name) VALUES ($ORG, 'Policy Test')");
$VLAN = null;

$cleanup = function () use ($db, $ORG) {
    $db->query("DELETE FROM guest_tickets WHERE username LIKE 'poltest%' OR organization_id=$ORG");
    $db->query("DELETE FROM guest_email_queue WHERE organization_id=$ORG");
    $db->query("DELETE FROM mac_blacklist WHERE organization_id=$ORG");
    foreach (['data_limits','user_profiles','radcheck','radacct','radreply'] as $t) {
        $db->query("DELETE FROM `$t` WHERE username LIKE 'poltest%'");
    }
    $db->query("DELETE FROM organizations WHERE id=$ORG");
};
register_shutdown_function(function () use ($db, $cleanup) { $cleanup(); $db->close(); });

function mkuser(mysqli $db, int $org, string $u, int $limitBytes, string $type = 'limited', string $status = 'active', ?string $expires = null): void {
    $db->query("DELETE FROM radcheck WHERE username='$u'");
    $db->query("INSERT INTO radcheck (username,attribute,op,value,organization_id) VALUES ('$u','Cleartext-Password',':=','pw-$u',$org)");
    $db->query("INSERT INTO data_limits (username,internet_type,internet_limit,organization_id,usage_reset_ts) VALUES ('$u','$type',$limitBytes,$org,UTC_TIMESTAMP())");
    if ($status !== '') {
        $exp = $expires === null ? 'NULL' : "'$expires'";
        $db->query("INSERT INTO guest_tickets (username,password,organization_id,vlan_id,expires_at,status,created_by) VALUES ('$u','pw',$org,NULL,$exp,'$status','test')");
    }
}
function use_bytes(mysqli $db, string $u, int $bytes): void {
    $db->query("DELETE FROM radacct WHERE username='$u'");
    $db->query("INSERT INTO radacct (username,acctsessionid,acctuniqueid,acctstarttime,acctinputoctets,acctoutputoctets,callingstationid,nasipaddress)
                VALUES ('$u','s-$u','u-$u',UTC_TIMESTAMP(),$bytes,0,'aa:bb:cc:dd:ee:ff','127.0.0.1')");
}

// ---------------------------------------------------------- MAC normalisation
eq(cyrad_normalize_mac('AA-BB-CC-DD-EE-FF'), 'aa:bb:cc:dd:ee:ff', 'dashed/upper MAC normalises');
eq(cyrad_normalize_mac('aabbccddeeff'), 'aa:bb:cc:dd:ee:ff', 'bare hex MAC normalises');
eq(cyrad_normalize_mac('garbage'), '', 'invalid MAC rejected');
eq(cyrad_normalize_mac(null), '', 'null MAC rejected');

// --------------------------------------------------------------- blacklist
ok(cyrad_blacklist_add($db, $ORG, 'AA-BB-CC-DD-EE-01', 'test', 'poltest_a'), 'blacklist add returns true');
eq((int)$db->query("SELECT COUNT(*) FROM mac_blacklist WHERE organization_id=$ORG AND mac='aa:bb:cc:dd:ee:01'")->fetch_row()[0], 1, 'row stored normalised');
cyrad_blacklist_add($db, $ORG, 'aa:bb:cc:dd:ee:01', 'updated reason', 'poltest_a');
eq($db->query("SELECT reason FROM mac_blacklist WHERE mac='aa:bb:cc:dd:ee:01'")->fetch_row()[0], 'updated reason', 'upsert updates the reason');
eq(cyrad_blacklist_forget_user($db, 'poltest_a'), 1, 'forget by username removes the row');
$db->query("INSERT INTO mac_blacklist (organization_id,mac,reason,expires_at) VALUES ($ORG,'aa:bb:cc:dd:ee:02','expired',UTC_TIMESTAMP() - INTERVAL 1 MINUTE)");
cyrad_blacklist_prune($db);
eq((int)$db->query("SELECT COUNT(*) FROM mac_blacklist WHERE mac='aa:bb:cc:dd:ee:02'")->fetch_row()[0], 0, 'expired rows are pruned');

// ------------------------------------------------------------- block / reset
mkuser($db, $ORG, 'poltest_block', 100 * 1048576);
use_bytes($db, 'poltest_block', 150 * 1048576);
$r = cyrad_block_user($db, 'poltest_block', 'Data cap reached', 24);
ok($r['blacklisted'] >= 1, 'block blacklists the known MAC');
eq($db->query("SELECT LEFT(value,9) FROM radcheck WHERE username='poltest_block'")->fetch_row()[0], 'disabled_', 'block disables the password');
eq($db->query("SELECT COUNT(*) FROM mac_blacklist WHERE username='poltest_block' AND expires_at > UTC_TIMESTAMP()")->fetch_row()[0] > 0, true, 'blacklist entry has a future expiry');
$r = cyrad_reset_user($db, 'poltest_block', false);
ok($r['ok'], 'reset succeeds');
eq((int)$db->query("SELECT COUNT(*) FROM radacct WHERE username='poltest_block'")->fetch_row()[0], 0, 'reset clears the usage window');
eq($db->query("SELECT value FROM radcheck WHERE username='poltest_block'")->fetch_row()[0], 'pw-poltest_block', 'reset re-enables the account');
eq((int)$db->query("SELECT COUNT(*) FROM mac_blacklist WHERE username='poltest_block'")->fetch_row()[0], 0, 'reset lifts blacklist entries');
eq((int)$db->query("SELECT banned_until IS NULL FROM data_limits WHERE username='poltest_block'")->fetch_row()[0], 1, 'reset lifts the ban');

// dead guest tickets are never resurrected
mkuser($db, $ORG, 'poltest_dead', 100 * 1048576, 'limited', 'revoked');
$db->query("UPDATE radcheck SET value='disabled_pw-poltest_dead' WHERE username='poltest_dead'");
$r = cyrad_reset_user($db, 'poltest_dead', false);
eq($r['ok'], false, 'reset refuses a revoked guest');
eq($db->query("SELECT LEFT(value,9) FROM radcheck WHERE username='poltest_dead'")->fetch_row()[0], 'disabled_', 'revoked guest stays disabled');

// ------------------------------------------------------------- guest purge
mkuser($db, $ORG, 'poltest_old', 100 * 1048576, 'limited', 'revoked');
mkuser($db, $ORG, 'poltest_new', 100 * 1048576, 'limited', 'revoked');
mkuser($db, $ORG, 'poltest_act', 100 * 1048576, 'limited', 'active');
$db->query("UPDATE guest_tickets SET updated_at=UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE username='poltest_old'");
$purged = cyrad_purge_revoked_guests($db, 7);
ok(in_array('poltest_old', $purged, true), '8-day-old revoked ticket is purged');
eq((int)$db->query("SELECT COUNT(*) FROM guest_tickets WHERE username='poltest_old'")->fetch_row()[0], 0, 'purged ticket is gone');
eq((int)$db->query("SELECT COUNT(*) FROM radcheck WHERE username='poltest_old'")->fetch_row()[0], 0, 'purged ticket account is gone');
eq((int)$db->query("SELECT COUNT(*) FROM guest_tickets WHERE username='poltest_new'")->fetch_row()[0], 1, 'recent revoked ticket is kept');
eq((int)$db->query("SELECT COUNT(*) FROM guest_tickets WHERE username='poltest_act'")->fetch_row()[0], 1, 'active ticket is kept');

// ------------------------------------- FreeRADIUS auth-time check (real SQL)
$sh = file_get_contents($root . '/freeradius/auth-limit-check.sh');
$start = strpos($sh, 'SELECT id, username, attribute, value, op FROM (');
$end   = $start === false ? false : strpos($sh, 'ORDER BY zorder, id', $start);
if ($start === false || $end === false) {
    ok(false, 'could not extract authorize_check_query from auth-limit-check.sh');
} else {
    // The SQL sits inside the generator as a Python literal: lines end with a
    // backslash (queries.conf line continuation). Undo both source escapes.
    $sql = substr($sh, $start, $end - $start + strlen('ORDER BY zorder, id'));
    $sql = str_replace(["\\\\\n", "\\\n"], "\n", $sql);
    $sql = str_replace('\t', "\t", $sql); // \t is a Python-level escape in the generator
    $sql = str_replace('${authcheck_table}', 'radcheck', $sql);
    $auth_sql = function (string $user, string $mac = '') use ($db, $sql): int {
        $q = str_replace(['%{SQL-User-Name}', '%{Calling-Station-Id}'], [$user, $mac], $sql);
        $res = $db->query($q);
        $reject = 0;
        while ($row = $res->fetch_assoc()) {
            if ($row['attribute'] === 'Auth-Type' && strcasecmp($row['value'], 'Reject') === 0) $reject++;
        }
        return $reject;
    };

    mkuser($db, $ORG, 'poltest_auth', 100 * 1048576);
    use_bytes($db, 'poltest_auth', 10 * 1048576);
    eq($auth_sql('poltest_auth'), 0, 'auth check allows a user under the cap');

    use_bytes($db, 'poltest_auth', 120 * 1048576);
    eq($auth_sql('poltest_auth'), 1, 'auth check rejects a user over the cap');

    // raising the limit does not touch usage: the very next auth is allowed
    $db->query("UPDATE data_limits SET internet_limit=200*1048576 WHERE username='poltest_auth'");
    eq($auth_sql('poltest_auth'), 0, 'raised limit lets the user rejoin immediately');
    $db->query("UPDATE data_limits SET internet_limit=100*1048576 WHERE username='poltest_auth'");

    cyrad_blacklist_add($db, $ORG, 'aa:bb:cc:dd:ee:99', 'auth test', 'poltest_other', gmdate('Y-m-d H:i:s', time() + 3600));
    use_bytes($db, 'poltest_auth', 10 * 1048576);
    eq($auth_sql('poltest_auth', 'AA:BB:CC:DD:EE:99'), 1, 'auth check rejects a blacklisted MAC (case-insensitive)');
    eq($auth_sql('poltest_auth', 'AA:BB:CC:DD:EE:98'), 0, 'other MACs are unaffected');
    $db->query("UPDATE mac_blacklist SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE mac='aa:bb:cc:dd:ee:99'");
    eq($auth_sql('poltest_auth', 'AA:BB:CC:DD:EE:99'), 0, 'expired blacklist entry no longer rejects');
}

echo "PASS: router policy checks ($pass assertions)" . ($fails ? ", $fails FAILED" : '') . PHP_EOL;
exit($fails ? 1 : 0);
