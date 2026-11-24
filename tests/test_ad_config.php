<?php
/**
 * test_ad_config.php — behaviour tests for the hand-entered domain controller
 * (Users -> Add User -> Active Directory) and for the CLI exporter that
 * freeradius/setup-ad.sh reads.
 *
 * Runs against the real ad_config row but snapshots and restores it, so it is
 * safe to run on a live server. Credentials come from config.php (CYRAD_DB_*),
 * which honours environment overrides.
 *
 *   php tests/test_ad_config.php
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
$cfg = null;
foreach ([$root . '/config.php', $root . '/../config.php'] as $candidate) {
    if (is_file($candidate)) { $cfg = $candidate; break; }
}
if ($cfg === null) {
    fwrite(STDERR, "FAIL: config.php not found next to " . $root . "\n");
    exit(1);
}
require_once $cfg;
require_once $root . '/admin/lib/ad_config.php';

try {
    $conn = new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}
if ($conn->connect_error) {
    fwrite(STDERR, "FAIL: {$conn->connect_error}\n");
    exit(1);
}

$pass = 0;
$fails = 0;
function ok($cond, $what) {
    global $pass, $fails;
    if ($cond) { $pass++; return; }
    $fails++;
    fwrite(STDERR, "FAIL: $what\n");
}
function eq($got, $want, $what) {
    ok($got === $want, "$what (got " . var_export($got, true) . ", want " . var_export($want, true) . ")");
}

// ---------------------------------------------------------------- snapshot ---
$before = $conn->query("SELECT * FROM ad_config WHERE id=1")->fetch_assoc();
ok(is_array($before), 'ad_config row id=1 exists');
$restore = function () use ($conn, $before) {
    $cols = ['ad_domain', 'dc_host', 'dc_ip', 'dc_port', 'use_tls', 'base_dn', 'bind_dn', 'bind_pass', 'group_dn'];
    $set = implode(', ', array_map(fn($c) => "$c=?", $cols));
    $st = $conn->prepare("UPDATE ad_config SET $set WHERE id=1");
    $st->bind_param('sssiissss', ...array_map(fn($c) => $before[$c], $cols));
    $st->execute();
    $st->close();
};
register_shutdown_function(function () use ($conn, $restore) {
    $restore();
    $conn->close();
});

function reset_row(mysqli $conn) {
    $conn->query("UPDATE ad_config SET ad_domain='', dc_host='', dc_ip='', dc_port=389, use_tls=0,"
        . " base_dn='', bind_dn='', bind_pass='', group_dn='' WHERE id=1");
}

// ------------------------------------------------------- validation matrix ---
reset_row($conn);

$cases = [
    ['all blank',                  [], 'Enter the DC host name (or the DC IP address).'],
    ['host only',                  ['dc_host' => 'dc01.corp.local'], 'Base DN is required'],
    ['host + base',                ['dc_host' => 'dc01.corp.local', 'base_dn' => 'DC=corp,DC=local'], 'Bind DN (the read-only service account) is required.'],
    ['bad ip',                     ['dc_host' => 'dc01', 'dc_ip' => '999.1.1.1', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'], 'DC IP must be a valid IPv4/IPv6 address'],
    ['port too high',              ['dc_host' => 'd', 'dc_port' => '99999', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'], 'DC port must be between 1 and 65535'],
    ['port zero',                  ['dc_host' => 'd', 'dc_port' => '0', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'], 'DC port must be between 1 and 65535'],
    ['valid full set',             ['ad_domain' => 'corp.local', 'dc_host' => 'dc01.corp.local', 'dc_ip' => '10.0.0.10', 'dc_port' => '389', 'base_dn' => 'DC=corp,DC=local', 'bind_dn' => 'CN=radius,OU=svc,DC=corp,DC=local', 'bind_pass' => 'Secret123', 'group_dn' => 'CN=WiFi,OU=Groups,DC=corp,DC=local'], ''],
    ['ipv6 literal',               ['dc_host' => 'dc01', 'dc_ip' => '2001:db8::1', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'], ''],
    ['ip only, no host',           ['dc_ip' => '10.0.0.10', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'], ''],
];
foreach ($cases as [$label, $input, $wantErr]) {
    $c = array_merge(ad_config_defaults(), $input);
    $err = ad_config_validate($c);
    ok($wantErr === '' ? $err === '' : strpos($err, $wantErr) === 0,
        "validate/$label -> '" . $err . "'");
}

// TLS bumps the default port to 636 and leaves an explicit port alone.
$c = array_merge(ad_config_defaults(), ['dc_host' => 'd', 'use_tls' => '1', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r']);
ad_config_validate($c);
eq($c['dc_port'], 636, 'TLS with port 389 becomes 636');
$c = array_merge(ad_config_defaults(), ['dc_host' => 'd', 'use_tls' => '1', 'dc_port' => '3269', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r']);
ad_config_validate($c);
eq($c['dc_port'], 3269, 'TLS keeps a global-catalog port');
// Host/IP precedence: the IP is what the LDAP URI uses.
eq(ad_config_host(['dc_host' => 'dc01.corp.local', 'dc_ip' => '10.0.0.10']), '10.0.0.10', 'dc_ip wins over dc_host');
eq(ad_config_host(['dc_host' => 'dc01.corp.local', 'dc_ip' => '']), 'dc01.corp.local', 'dc_host used when no ip');
eq(ad_config_resolve_ip(['dc_host' => 'x', 'dc_ip' => '10.0.0.10']), '10.0.0.10', 'resolve_ip returns the typed ip');
eq(ad_config_resolve_ip(['dc_host' => '2001:db8::1', 'dc_ip' => '']), '2001:db8::1', 'resolve_ip accepts an ipv6 host');
eq(ad_config_resolve_ip(['dc_host' => 'no-such-host.invalid', 'dc_ip' => '']), '', 'resolve_ip gives up on an unresolvable host');

// ------------------------------------------------------------ uri building ---
$mk = fn($h, $ip, $port, $tls) => ad_config_uri(array_merge(ad_config_defaults(), [
    'dc_host' => $h, 'dc_ip' => $ip, 'dc_port' => $port, 'use_tls' => $tls,
]));
eq($mk('dc01', '10.0.0.10', 389, 0), 'ldap://10.0.0.10:389', 'plain ldap uri');
eq($mk('dc01', '10.0.0.10', 636, 1), 'ldaps://10.0.0.10:636', 'ldaps uri');
eq($mk('dc01', '', 389, 0), 'ldap://dc01:389', 'uri uses the host when no ip');
eq($mk('', '', 389, 0), '', 'uri is empty when no dc is set');
eq($mk('dc01', '10.0.0.10', 636, 0), 'ldaps://10.0.0.10:636', 'port 636 implies ldaps, matching setup-ad.sh');
eq($mk('dc01', '2001:db8::1', 389, 0), 'ldap://2001:db8::1:389', 'ipv6 uri keeps the literal');

// ------------------------------------------------------------- ready gate ---
ok(!ad_config_ready(ad_config_load($conn)), 'empty panel row is not ready');
ok(!ad_config_ready(array_merge(ad_config_defaults(), ['dc_host' => 'dc01'])), 'host alone is not ready');
ok(ad_config_ready(array_merge(ad_config_defaults(), ['dc_host' => 'dc01', 'base_dn' => 'DC=c', 'bind_dn' => 'CN=r'])), 'host + base dn + bind dn is ready');

// ---------------------------------------------------- save / load / secret ---
reset_row($conn);
$good = array_merge(ad_config_defaults(), [
    'ad_domain' => 'corp.local', 'dc_host' => 'dc01.corp.local', 'dc_ip' => '10.0.0.10',
    'dc_port' => 389, 'use_tls' => 0, 'base_dn' => 'DC=corp,DC=local',
    'bind_dn' => 'CN=radius,OU=svc,DC=corp,DC=local', 'bind_pass' => 'Secret123',
    'group_dn' => 'CN=WiFi,OU=Groups,DC=corp,DC=local',
]);
eq(ad_config_save($conn, $good), '', 'save returns no error');
$c = ad_config_load($conn, false);
eq($c['dc_host'], 'dc01.corp.local', 'saved host round-trips');
eq($c['dc_ip'], '10.0.0.10', 'saved ip round-trips');
eq($c['ad_domain'], 'corp.local', 'saved domain round-trips');
eq($c['group_dn'], 'CN=WiFi,OU=Groups,DC=corp,DC=local', 'saved group dn round-trips');
eq($c['bind_pass'], 'Secret123', 'saved bind secret round-trips');
ok(ad_config_ready($c), 'saved row is ready');

// Blank password keeps the stored secret (this is what the form relies on).
$keep = $good; $keep['bind_pass'] = '';
eq(ad_config_save($conn, $keep), '', 'save with a blank password succeeds');
eq(ad_config_load($conn, false)['bind_pass'], 'Secret123', 'blank password keeps the stored secret');

// Other fields really do update on a partial re-save.
$upd = $good; $upd['bind_pass'] = ''; $upd['dc_host'] = 'dc02.corp.local'; $upd['use_tls'] = 1; $upd['dc_port'] = 636;
eq(ad_config_save($conn, $upd), '', 're-save succeeds');
$c = ad_config_load($conn, false);
eq($c['dc_host'], 'dc02.corp.local', 're-save updated the host');
eq($c['use_tls'], 1, 're-save updated TLS');
eq(ad_config_uri($c), 'ldaps://10.0.0.10:636', 're-save rebuilt the uri from the saved ip + tls');

// A changed password does overwrite the stored one.
$newp = $good; $newp['bind_pass'] = 'Rotated456';
eq(ad_config_save($conn, $newp), '', 'save with a new password succeeds');
eq(ad_config_load($conn, false)['bind_pass'], 'Rotated456', 'a new password replaces the old one');

// Values that could break SQL are stored verbatim, not executed.
$sqli = $good;
$sqli['dc_host'] = "dc01'; DROP TABLE ad_config; --";
$sqli['bind_dn'] = "CN=r',(SELECT 1),'x";
$sqli['bind_pass'] = "p'w\"ord\\";
eq(ad_config_save($conn, $sqli), '', 'hostile input saves without error');
$c = ad_config_load($conn, false);
eq($c['dc_host'], "dc01'; DROP TABLE ad_config; --", 'quotes are stored verbatim');
eq($c['bind_dn'], "CN=r',(SELECT 1),'x", 'bind dn is stored verbatim');
$still = $conn->query("SELECT COUNT(*) c FROM ad_config WHERE id=1")->fetch_assoc();
eq((int)$still['c'], 1, 'the table survived the injection attempt');

// --------------------------------------------------- config.php fallbacks ---
// A saved value wins; an empty column falls through to the constants. The
// constants are process-wide, so they are set here for the duration of the test
// and cannot leak into the panel (ad_config_load reads the row first).
reset_row($conn);
define('CYRAD_AD_TEST_HOST', 'dc-from-config');
$c = ad_config_load($conn);
eq($c['dc_host'], '', 'no constant override while config.php defines nothing');

reset_row($conn);
eq(ad_config_save($conn, $good), '', 'restore a full row for the fallback test');
$partial = $good; $partial['dc_host'] = ''; $partial['bind_pass'] = ''; $partial['base_dn'] = '';
eq(ad_config_save($conn, $partial), '', 'save a row with empty host/base dn/secret');
$c = ad_config_load($conn, false);
eq($c['dc_host'], '', 'load(false) does not invent a host');
eq($c['bind_pass'], 'Secret123', 'load(false) still returns the stored secret');
ok(!ad_config_ready($c), 'a row missing the base dn is not ready');

// ------------------------------------------------------- printed command ----
$cmd = ad_config_apply_command($good);
ok(strpos($cmd, 'bash ') !== false && strpos($cmd, 'setup-ad.sh') !== false, 'the printed command runs setup-ad.sh');
ok(strpos($cmd, "CYRAD_AD_DOMAIN='corp.local'") !== false, 'the command carries the domain');
ok(strpos($cmd, "CYRAD_AD_HOST='10.0.0.10'") !== false, 'the command carries the ip (uri host)');
ok(strpos($cmd, "CYRAD_AD_BASE_DN='DC=corp,DC=local'") !== false, 'the command carries the base dn');
ok(strpos($cmd, 'Secret123') === false, 'the printed command never contains the bind secret');
ok(strpos($cmd, "CYRAD_AD_BIND_PASS='***'") !== false, 'the printed command masks the bind secret');
ok(strpos($cmd, 'Rotated456') === false, 'a rotated secret is masked too');
$quote = $good; $quote['dc_host'] = "dc'01; rm -rf /"; $quote['dc_ip'] = '';
$cmd2 = ad_config_apply_command($quote);
ok(strpos($cmd2, "dc'01; rm -rf /") === false, 'the printed command shell-quotes a hostile host');
ok(strpos($cmd2, "'dc'\\''01; rm -rf /'") !== false, 'the hostile host is escaped, not stripped');
ok(strpos(ad_config_apply_command(array_merge(ad_config_defaults(), ['dc_host' => 'd'])), 'CYRAD_AD_BASE_DN') === false,
    'unset values are left out of the command entirely');

// ------------------------------------------------------ CLI exporter -------
$export = $root . '/admin/ad_config_export.php';
ok(is_file($export), 'the exporter exists');
$src = file_get_contents($export);
ok(strpos($src, "PHP_SAPI !== 'cli'") !== false, 'the exporter refuses to run over HTTP');

$run = function (array $env = []) use ($root) {
    $cmd = 'php ' . escapeshellarg($root . '/admin/ad_config_export.php');
    foreach ($env as $k => $v) { $cmd = $k . '=' . escapeshellarg($v) . ' ' . $cmd; }
    $out = []; $rc = 0;
    exec($cmd . ' 2>/dev/null', $out, $rc);
    return [$rc, implode("\n", $out)];
};

reset_row($conn);
[$rc, $out] = $run();
eq($rc, 1, 'the exporter reports "not configured" with an empty panel');

eq(ad_config_save($conn, $good), '', 'save a row for the exporter test');
[$rc, $out] = $run();
eq($rc, 0, 'the exporter succeeds with a configured panel');
ok(strpos($out, 'CYRAD_AD_HOST=10.0.0.10') !== false, 'the exporter emits the dc host');
ok(strpos($out, 'CYRAD_AD_BASE_DN=DC=corp,DC=local') !== false, 'the exporter emits the base dn');
ok(strpos($out, 'CYRAD_AD_PORT=389') !== false, 'the exporter emits the port');
ok(strpos($out, 'CYRAD_AD_TLS=0') !== false, 'the exporter emits the tls flag');
ok(strpos($out, 'Secret123') === false, 'the exporter masks the bind secret on stdout');
ok(strpos($out, '***') !== false, 'the exporter shows the mask on stdout');

// --env-file carries the real secret for setup-ad.sh, mode 600.
$tmp = sys_get_temp_dir() . '/cyrad-ad-test-env.' . getmypid();
@unlink($tmp);
$rc = 0; $out = [];
exec('php ' . escapeshellarg($export) . ' --env-file ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $rc);
eq($rc, 0, 'the exporter writes an env file');
$body = (string)@file_get_contents($tmp);
ok(strpos($body, "CYRAD_AD_BIND_PASS='Secret123'") !== false, 'the env file carries the real secret');
ok(strpos($body, "CYRAD_AD_HOST='10.0.0.10'") !== false, 'the env file quotes the host');
eq(substr(sprintf('%o', fileperms($tmp)), -3), '600', 'the env file is mode 600');
@unlink($tmp);

// setup-ad.sh must read that row through the exporter.
$script = $root . '/freeradius/setup-ad.sh';
ok(is_file($script), 'setup-ad.sh exists');
$sh = (string)file_get_contents($script);
ok(strpos($sh, 'ad_config_export.php') !== false, 'setup-ad.sh reads the panel settings');
ok(strpos($sh, '--print-effective') !== false, 'setup-ad.sh can print what it would use');
ok(strpos($sh, 'CYRAD_AD_JOIN_PASS') !== false, 'setup-ad.sh still documents the join account');

if (is_file($script)) {
    $dbEnv = '';
    foreach (['CYRAD_DB_HOST', 'CYRAD_DB_USER', 'CYRAD_DB_PASS', 'CYRAD_DB_NAME'] as $k) {
        $dbEnv .= $k . '=' . escapeshellarg((string)constant($k)) . ' ';
    }
    $out = []; $rc = 0;
    $cmd = 'env -i PATH=/usr/bin:/bin ' . $dbEnv . ' bash ' . escapeshellarg($script) . ' --print-effective 2>&1';
    exec($cmd, $out, $rc);
    $text = implode("\n", $out);
    ok(strpos($text, 'corp.local') !== false, '--print-effective picks the panel domain up');
    ok(strpos($text, '10.0.0.10') !== false, '--print-effective picks the panel dc up');
    ok(strpos($text, 'Secret123') === false, '--print-effective never prints the secret');
    eq($rc, 0, '--print-effective exits 0 when AD is configured');

    // An explicit environment variable still wins over the panel row.
    $out = []; $rc = 0;
    $cmd = 'env -i PATH=/usr/bin:/bin ' . $dbEnv . ' CYRAD_AD_DOMAIN=cli.local CYRAD_AD_HOST=dc99.cli.local'
         . ' bash ' . escapeshellarg($script) . ' --print-effective 2>&1';
    exec($cmd, $out, $rc);
    $text = implode("\n", $out);
    ok(strpos($text, 'cli.local') !== false, 'an env var overrides the panel value');
    ok(strpos($text, '10.0.0.10') === false, 'the panel dc is not used when the env sets one');

    // ------------------------------------------- AD group restriction block ---
    // The python that writes the group restriction is embedded in setup-ad.sh;
    // run it on a synthetic site file to check insert/idempotent/remove.
    $shSrc = (string)file_get_contents($script);
    if (preg_match('/PY "\$FRC\/sites-available\/\$site" "\$\{CYRAD_AD_GROUP_DN:-\}" <<\'PYEOF\'\n(.*?)\nPYEOF\n/s', $shSrc, $m)) {
        $py = tempnam(sys_get_temp_dir(), 'cyradgrp') . '.py';
        file_put_contents($py, $m[1]);
        $adFirst = "authorize {\n    eap {\n        ok = return\n    }\n"
                 . "    # ==== cyrad-ad-first-begin =====================================\n"
                 . "    ldap\n    if (fail || notfound) {\n        sql\n    }\n"
                 . "    # ==== cyrad-ad-first-end ========================================\n"
                 . "    filemodule\n}\n";
        $site = tempnam(sys_get_temp_dir(), 'cyradsite');
        $anchor = '    # ==== cyrad-ad-first-end ========================================';
        $runPy = function (string $file, string $group) use ($py) {
            $out = []; $rc = 0;
            exec('python3 ' . escapeshellarg($py) . ' ' . escapeshellarg($file) . ' '
               . escapeshellarg($group) . ' 2>&1', $out, $rc);
            return implode("\n", $out);
        };

        file_put_contents($site, $adFirst);
        $runPy($site, 'CN=WiFi Users,OU=Groups,DC=corp,DC=local');
        $body = (string)file_get_contents($site);
        ok(strpos($body, 'cyrad-ad-group-begin') !== false, 'the group block is inserted');
        ok(strpos($body, 'if (&LDAP-Group)') !== false, 'the block guards on LDAP-Group');
        ok(strpos($body, 'reject') !== false, 'a non-member is rejected');
        ok(strpos($body, 'CN=WiFi\ Users,OU=Groups,DC=corp,DC=local(,|$)') !== false,
            'the group DN is regex-escaped and anchored');
        // Must sit after the ldap -> local-fallback block, so local logins
        // (which have no LDAP-Group) are never caught by the restriction.
        $guardPos = strpos($body, 'if (&LDAP-Group)');
        $fallbackPos = strpos($body, 'if (fail || notfound)');
        ok($guardPos !== false && $fallbackPos !== false && $guardPos > $fallbackPos,
            'the restriction is applied after the AD-first/local-fallback block');

        $runPy($site, 'CN=WiFi Users,OU=Groups,DC=corp,DC=local');
        eq(substr_count((string)file_get_contents($site), 'cyrad-ad-group-begin'), 1,
            'a second run does not duplicate the block');

        $runPy($site, '');
        ok(strpos((string)file_get_contents($site), 'cyrad-ad-group') === false,
            'clearing the field removes the restriction');
        eq((string)file_get_contents($site), $adFirst,
            'insert + remove leaves the site file byte-identical');

        // A site without the AD-first block must be left alone, not corrupted.
        $noAd = "authorize {\n    eap {\n        ok = return\n    }\n    filemodule\n}\n";
        file_put_contents($site, $noAd);
        $msg = $runPy($site, 'CN=WiFi,DC=c');
        ok(strpos((string)file_get_contents($site), 'cyrad-ad-group') === false,
            'no restriction is written without the AD-first block');
        ok(strpos($msg, 'skip') !== false, 'the script says it skipped instead of failing');
        ok(strpos($msg, $anchor) === false, 'the anchor is not echoed');

        // Metacharacters in the DN must not leak into the regex.
        file_put_contents($site, $adFirst);
        $runPy($site, 'CN=WiFi+Users (HQ),OU=Gr,DC=corp,DC=local');
        $body = (string)file_get_contents($site);
        ok(strpos($body, 'CN=WiFi\+Users\ \(HQ\)') !== false, 'DN metacharacters are escaped');
        ok(strpos($body, 'CN=WiFi+Users (HQ),OU=Gr,DC=corp,DC=local(,|$)') === false,
            'the raw DN is never used as a regex');

        @unlink($site);
        @unlink($py);
    } else {
        ok(false, 'the group-restriction block is missing from setup-ad.sh');
    }
}

reset_row($conn);
$restore();

echo $fails === 0
    ? "PASS: ad_config behaviour checks ($pass assertions)\n"
    : "FAIL: $fails of " . ($pass + $fails) . " ad_config checks failed\n";
exit($fails === 0 ? 0 : 1);
