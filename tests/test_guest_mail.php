<?php
/**
 * test_guest_mail.php — behaviour tests for organization SMTP settings, the
 * guest ticket outbox and the retrying worker.
 *
 * Safety: refuses to run unless CYRAD_DB_NAME ends in `_test`, creates all of
 * its own fixtures (org/vlan/tickets) and removes them afterwards. The SMTP
 * delivery test runs against tests/fake_smtp_server.php on 127.0.0.1.
 *
 *   mysqldump cyrad_scratch | mysql cyrad_mail_test
 *   CYRAD_DB_NAME=cyrad_mail_test php tests/test_guest_mail.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

$dbName = getenv('CYRAD_DB_NAME') ?: '';
if (!preg_match('/_test$/', $dbName)) {
    fwrite(STDERR, "Refusing to run: CYRAD_DB_NAME must end with _test (got '$dbName')\n");
    exit(1);
}

$keyFile = '/tmp/cyrad-mail-test.key';
if (!is_file($keyFile)) file_put_contents($keyFile, base64_encode(random_bytes(32)));
putenv('CYRAD_MAIL_KEY_FILE=' . $keyFile);

$root = dirname(__DIR__);
require_once $root . '/admin/lib/guest_mail.php';

$db = new mysqli(getenv('CYRAD_DB_HOST') ?: 'localhost', getenv('CYRAD_DB_USER') ?: 'root',
    getenv('CYRAD_DB_PASS') ?: '', $dbName);
$db->set_charset('utf8mb4');
$db->query("SET time_zone='+00:00'");

$pass = 0; $fails = 0;
function ok(bool $cond, string $what): void {
    global $pass, $fails;
    if ($cond) { $pass++; return; }
    $fails++; fwrite(STDERR, "FAIL: $what\n");
}
function eq($got, $want, string $what): void {
    ok($got === $want, "$what (got " . var_export($got, true) . ', want ' . var_export($want, true) . ')');
}
function throws(callable $fn, string $needle, string $what): void {
    try { $fn(); } catch (Throwable $e) {
        if ($needle === '' || str_contains($e->getMessage(), $needle)) { ok(true, $what); return; }
        ok(false, "$what (wrong message: {$e->getMessage()})"); return;
    }
    ok(false, "$what (no exception thrown)");
}

// ------------------------------------------------------------------ fixtures
$ORG = 4242; $ORG2 = 4243;
foreach ([$ORG, $ORG2] as $o) $db->query("DELETE FROM organizations WHERE id=$o");
$db->query("INSERT INTO organizations (id, org_name, contact_email) VALUES ($ORG, 'Test Org', 'ops@test.local')");
$db->query("INSERT INTO organizations (id, org_name, contact_email) VALUES ($ORG2, 'Other Org', 'other@test.local')");
$VLAN = null;
$VLAN2 = null;

$createdTickets = [];
function make_ticket(mysqli $db, int $org, $vlan, string $user, string $status = 'active',
                     ?string $expires = '+1 day', int $maxUses = 1): int {
    global $createdTickets;
    $exp = $expires === null ? 'NULL' : "'" . gmdate('Y-m-d H:i:s', strtotime($expires)) . "'";
    $vid = $vlan === null ? 'NULL' : (int)$vlan;
    $db->query("INSERT INTO guest_tickets (username,password,organization_id,vlan_id,expires_at,max_uses,status,notes,created_by)
                VALUES ('$user','pw-$user',$org,$vid,$exp,$maxUses,'$status','mail-test','tester')");
    $id = (int)$db->insert_id;
    $createdTickets[] = $id;
    return $id;
}

$cleanup = function () use ($db, $ORG, $ORG2, &$createdTickets) {
    foreach ($createdTickets as $id) $db->query("DELETE FROM guest_email_queue WHERE ticket_id=$id");
    $db->query("DELETE FROM guest_email_queue WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM guest_tickets WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM data_limits WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM user_profiles WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM radcheck WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM radreply WHERE username LIKE 'mailtest%'");
    $db->query("DELETE FROM radacct WHERE username LIKE 'mailtest%'");
    // vlans table removed
    $db->query("DELETE FROM organization_smtp WHERE organization_id IN ($ORG,$ORG2)");
    $db->query("DELETE FROM organizations WHERE id IN ($ORG,$ORG2)");
};
register_shutdown_function(function () use ($db, $cleanup) {
    $cleanup();
    $db->close();
});

// ----------------------------------------------------- A. address validation
eq(guest_mail_email(' guest@example.com '), 'guest@example.com', 'email is trimmed and returned');
throws(fn() => guest_mail_email('not-an-email'), 'valid recipient', 'rejects plain text');
throws(fn() => guest_mail_email("a@b.co\r\nBcc: x@y.z"), 'valid recipient', 'rejects CRLF header injection');
throws(fn() => guest_mail_email(str_repeat('a', 250) . '@x.yz'), 'valid recipient', 'rejects over-long address');

// ------------------------------------------------------- B. recipient lines
eq(guest_mail_recipients('', 3), ['', '', ''], 'blank field -> no recipients');
eq(guest_mail_recipients('a@x.yz', 3), ['a@x.yz', '', ''], 'one line pads to count');
eq(guest_mail_recipients("a@x.yz\n\nb@x.yz", 3), ['a@x.yz', '', 'b@x.yz'], 'blank lines skip that ticket');
eq(guest_mail_recipients("a@x.yz\n", 1), ['a@x.yz'], 'trailing newline tolerated');
throws(fn() => guest_mail_recipients("a@x.yz\nb@x.yz\nc@x.yz\nd@x.yz", 3), 'at most one email line', 'too many lines rejected');
throws(fn() => guest_mail_recipients("a@x.yz\nnope", 2), 'valid recipient', 'bad line rejected');

// --------------------------------------------------------------- C. crypto
$enc = guest_mail_encrypt('S3cret-app-password');
eq(guest_mail_decrypt($enc), 'S3cret-app-password', 'password round-trips through AES-256-GCM');
ok($enc !== 'S3cret-app-password' && !str_contains($enc, 'S3cret'), 'ciphertext does not contain the plaintext');
throws(function () use ($enc) {
    $raw = base64_decode($enc, true);
    $raw[30] = chr(ord($raw[30]) ^ 0x2a);
    guest_mail_decrypt(base64_encode($raw));
}, 'Cannot decrypt', 'tampered ciphertext is rejected');

// ------------------------------------------------------------- D. validation
$good = [
    'enabled' => '1', 'host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls',
    'auth_enabled' => '1', 'username' => 'mailer', 'password' => 'pw123', 'from_address' => 'wifi@example.com',
    'from_name' => 'Guest WiFi', 'reply_to' => 'helpdesk@example.com',
    'subject' => 'Ticket for {{username}}',
    'template' => "Hello {{username}} / {{password}} / {{expires_at}} / {{data_limit}} at {{organization_name}}",
];
$c = guest_mail_validate($good, []);
ok($c['enabled'] === 1 && $c['port'] === 587 && $c['encryption'] === 'tls', 'valid config accepted and normalised');
eq(guest_mail_decrypt($c['password_enc']), 'pw123', 'submitted password stored encrypted');
throws(fn() => guest_mail_validate(['enabled' => '1', 'from_address' => 'a@b.co'], []), 'requires a host', 'enabled without host rejected');
throws(fn() => guest_mail_validate(['host' => 'x', 'port' => '0'], []), 'Invalid SMTP port', 'port 0 rejected');
throws(fn() => guest_mail_validate(['host' => 'x', 'encryption' => 'ssl3'], []), 'Invalid SMTP port or encryption', 'bad encryption rejected');
throws(fn() => guest_mail_validate(['host' => 'http://smtp.example.com'], []), 'without a URL scheme', 'scheme in host rejected');
throws(fn() => guest_mail_validate(['host' => 'x', 'username' => "u\r\nX: y"], []), 'Invalid or oversized', 'CRLF in username rejected');
throws(fn() => guest_mail_validate(['host' => 'x', 'subject' => 'Hi {{passwords}}'], []), 'Unknown template placeholder', 'typo placeholder rejected');
throws(fn() => guest_mail_validate(['host' => 'x', 'from_address' => 'bad'], []), 'valid recipient', 'bad from address rejected');
throws(fn() => guest_mail_validate(['enabled' => '1', 'host' => 'x', 'from_address' => 'a@b.co', 'auth_enabled' => '1', 'username' => 'u'], []), 'requires a username and password', 'auth without password rejected');
throws(fn() => guest_mail_validate(['enabled' => '1', 'host' => 'x', 'from_address' => 'a@b.co', 'auth_enabled' => '1', 'username' => 'u', 'password' => 'p', 'encryption' => 'none'], []), 'Use TLS', 'auth over plaintext rejected');

$old = ['password_enc' => guest_mail_encrypt('original')];
$keep = guest_mail_validate(['host' => 'x'], $old);
eq(guest_mail_decrypt($keep['password_enc']), 'original', 'blank password keeps the saved one');
$clear = guest_mail_validate(['host' => 'x', 'clear_password' => '1'], $old);
eq($clear['password_enc'], '', 'clear_password wipes the saved one');
$rotate = guest_mail_validate(['host' => 'x', 'password' => 'newpw'], $old);
eq(guest_mail_decrypt($rotate['password_enc']), 'newpw', 'new password replaces the saved one');

// --------------------------------------------------------- E. save and load
guest_mail_save($db, $ORG, $c);
$loaded = guest_mail_load($db, $ORG);
eq($loaded['host'], 'smtp.example.com', 'saved host round-trips');
eq($loaded['enabled'], 1, 'saved enabled flag round-trips');
eq(guest_mail_decrypt($loaded['password_enc']), 'pw123', 'saved encrypted password round-trips');
eq(guest_mail_load($db, $ORG2)['enabled'], 0, 'other org is not affected');
guest_mail_save($db, $ORG, [...$c, 'host' => 'smtp2.example.com']);
eq(guest_mail_load($db, $ORG)['host'], 'smtp2.example.com', 'save is an upsert');
guest_mail_save($db, $ORG, [...$c, 'host' => 'smtp.example.com']);

// -------------------------------------------------------------- F. rendering
$msg = guest_mail_render($c, [
    'organization_name' => 'Test <b>Org</b>', 'username' => 'guest_abc', 'password' => 'pw&<>',
    'expires_at' => '2030-01-01 00:00:00', 'data_limit' => '250 MB',
]);
ok(str_contains($msg[0], 'guest_abc'), 'subject substitutes username');
ok(str_contains($msg[1], 'Test <b>Org</b>') && str_contains($msg[1], 'pw&<>'), 'plain body keeps raw values');
ok(str_contains($msg[2], 'Test &lt;b&gt;Org&lt;/b&gt;') && str_contains($msg[2], 'pw&amp;&lt;&gt;'), 'HTML body escapes values');

// --------------------------------------------------------------- G. enqueue
$tActive = make_ticket($db, $ORG, $VLAN, 'mailtest_active1');
$db->query("UPDATE organization_smtp SET enabled=0 WHERE organization_id=$ORG");
throws(fn() => guest_mail_enqueue($db, $ORG, $tActive, 'g@x.yz'), 'Configure and enable SMTP', 'enqueue refused while SMTP disabled');
$db->query("UPDATE organization_smtp SET enabled=1 WHERE organization_id=$ORG");

$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tActive, 'guest1@example.com');
$db->commit();
$q = $db->query("SELECT status,recipient,attempts FROM guest_email_queue WHERE ticket_id=$tActive")->fetch_assoc();
eq($q['status'], 'queued', 'enqueue creates a queued row');
eq($q['recipient'], 'guest1@example.com', 'recipient stored');
eq((int)$q['attempts'], 0, 'attempts start at zero');

throws(function () use ($db, $ORG, $tActive) {
    $db->begin_transaction();
    try { guest_mail_enqueue($db, $ORG, $tActive, 'guest1@example.com'); $db->commit(); }
    catch (Throwable $e) { $db->rollback(); throw $e; }
}, 'already queued', 'duplicate recipient within a minute refused');

$tExpired = make_ticket($db, $ORG, $VLAN, 'mailtest_expired1', 'active', '-1 hour');
throws(function () use ($db, $ORG, $tExpired) {
    $db->begin_transaction();
    try { guest_mail_enqueue($db, $ORG, $tExpired, 'g@x.yz'); $db->commit(); }
    catch (Throwable $e) { $db->rollback(); throw $e; }
}, 'Only active, unexpired tickets', 'expired ticket refused');

guest_mail_save($db, $ORG2, $c);
throws(function () use ($db, $ORG2, $tActive) {
    $db->begin_transaction();
    try { guest_mail_enqueue($db, $ORG2, $tActive, 'g@x.yz'); $db->commit(); }
    catch (Throwable $e) { $db->rollback(); throw $e; }
}, 'Only active, unexpired tickets in this organization', 'cross-organization ticket refused');

// ---------------------------------------------------- H. worker: fake sender
$sent = [];
$fakeOk = function (array $cfg, string $to, array $message, string $messageId = '') use (&$sent): void {
    $sent[] = ['to' => $to, 'subject' => $message[0], 'body' => $message[1], 'id' => $messageId];
};
$tDrain = make_ticket($db, $ORG, $VLAN, 'mailtest_drain1');
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tDrain, 'drain@example.com');
$db->commit();
$n = guest_mail_drain($db, 50, $fakeOk);
ok($n >= 1, 'worker processed at least one due job');
$row = $db->query("SELECT status,attempts,sent_at FROM guest_email_queue WHERE ticket_id=$tDrain")->fetch_assoc();
eq($row['status'], 'sent', 'successful send marks the row sent');
eq((int)$row['attempts'], 1, 'successful send recorded one attempt');
ok($row['sent_at'] !== null, 'sent_at is stamped');
$last = end($sent);
eq($last['to'], 'drain@example.com', 'sender called with the recipient');
ok(str_contains($last['subject'] . $last['body'], 'mailtest_drain1'), 'message contains the ticket username');

// --------------------------------------------- I. worker: failure and retries
$tFail = make_ticket($db, $ORG, $VLAN, 'mailtest_fail1');
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tFail, 'fail@example.com');
$db->commit();
$fakeFail = function (): void { throw new RuntimeException('SMTP connection, TLS, authentication or recipient rejected.'); };
guest_mail_drain($db, 50, $fakeFail);
$row = $db->query("SELECT status,attempts,last_error,available_at>UTC_TIMESTAMP() AS later FROM guest_email_queue WHERE ticket_id=$tFail")->fetch_assoc();
eq($row['status'], 'queued', 'first failure stays queued for retry');
eq((int)$row['attempts'], 1, 'first failure recorded');
eq((int)$row['later'], 1, 'retry is scheduled in the future');
ok(str_contains($row['last_error'], 'SMTP connection'), 'failure reason recorded');
$db->query("UPDATE guest_email_queue SET attempts=2,available_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE ticket_id=$tFail");
guest_mail_drain($db, 50, $fakeFail);
$row = $db->query("SELECT status,attempts FROM guest_email_queue WHERE ticket_id=$tFail")->fetch_assoc();
eq($row['status'], 'failed', 'third failure marks the row failed');
eq((int)$row['attempts'], 3, 'attempt count capped at three');

// ------------------------------------------------ J. worker: cancelled states
$tRevoked = make_ticket($db, $ORG, $VLAN, 'mailtest_revoked1');
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tRevoked, 'revoked@example.com');
$db->commit();
$db->query("UPDATE guest_tickets SET status='revoked' WHERE id=$tRevoked");
$before = count($sent);
guest_mail_drain($db, 50, $fakeOk);
$row = $db->query("SELECT status,last_error FROM guest_email_queue WHERE ticket_id=$tRevoked")->fetch_assoc();
eq($row['status'], 'cancelled', 'revoked ticket queued job is cancelled');
eq(count($sent), $before, 'cancelled job never reaches the sender');

$tUsed = make_ticket($db, $ORG, $VLAN, 'mailtest_used1', 'active', '+1 day', 1);
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tUsed, 'used@example.com');
$db->commit();
$db->query("INSERT INTO radacct (username,acctsessionid,acctuniqueid,acctstarttime) VALUES ('mailtest_used1','s1','uniq-used1',UTC_TIMESTAMP())");
guest_mail_drain($db, 50, $fakeOk);
eq($db->query("SELECT status FROM guest_email_queue WHERE ticket_id=$tUsed")->fetch_row()[0], 'cancelled',
   'ticket past max_uses is cancelled');

// ------------------------------------------------- K. worker: limit wording
$tLimited = make_ticket($db, $ORG, $VLAN, 'mailtest_limit1');
$db->query("INSERT INTO data_limits (username,internet_type,internet_limit,organization_id,usage_reset_ts) VALUES ('mailtest_limit1','limited',262144000,$ORG,UTC_TIMESTAMP())");
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tLimited, 'limited@example.com');
$db->commit();
guest_mail_drain($db, 50, $fakeOk);
$last = end($sent);
ok(str_contains($last['body'], '250 MB'), 'limited ticket renders MB allowance');
$tUnlim = make_ticket($db, $ORG, $VLAN, 'mailtest_unlim1');
$db->query("INSERT INTO data_limits (username,internet_type,internet_limit,organization_id,usage_reset_ts) VALUES ('mailtest_unlim1','unlimited',0,$ORG,UTC_TIMESTAMP())");
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tUnlim, 'unlim@example.com');
$db->commit();
guest_mail_drain($db, 50, $fakeOk);
$last = end($sent);
ok(str_contains($last['body'], 'Unlimited'), 'unlimited ticket renders Unlimited');

// ------------------------------------------- L. real sender against fake SMTP
$fakeDir = '/tmp/cyrad-fake-smtp-' . getmypid();
@mkdir($fakeDir, 0777, true);
$fakeScript = escapeshellarg($root . '/tests/fake_smtp_server.php');
$pid = (int)trim((string)shell_exec("php $fakeScript 2525 " . escapeshellarg($fakeDir) . ' 45 >/dev/null 2>&1 & echo $!'));
usleep(400000);
$tSmtp = make_ticket($db, $ORG, $VLAN, 'mailtest_smtp1');
$cfg = guest_mail_validate([
    'enabled' => '1', 'host' => '127.0.0.1', 'port' => '2525', 'encryption' => 'none',
    'username' => '', 'from_address' => 'wifi@test.local', 'from_name' => 'Guest WiFi',
    'subject' => 'Creds for {{username}}', 'template' => 'user={{username}} pass={{password}}',
], []);
guest_mail_save($db, $ORG, $cfg);
$db->begin_transaction();
guest_mail_enqueue($db, $ORG, $tSmtp, 'inbox@example.com');
$db->commit();
guest_mail_drain($db);
$deadline = time() + 5;
$files = [];
while (time() < $deadline) {
    $files = glob($fakeDir . '/msg-*.eml') ?: [];
    if ($files) break;
    usleep(200000);
}
if (shell_exec("kill $pid >/dev/null 2>&1; echo done") === null) { /* ignore */ }
$status = $db->query("SELECT status,last_error FROM guest_email_queue WHERE ticket_id=$tSmtp")->fetch_assoc();
eq($status['status'], 'sent', 'PHPMailer path delivers to a live SMTP socket' . ($status['last_error'] ? " ({$status['last_error']})" : ''));
ok($files !== [], 'fake SMTP server received a message');
if ($files) {
    $eml = file_get_contents($files[0]);
    ok(str_contains($eml, 'mailtest_smtp1'), 'delivered message contains the username');
    ok(stripos($eml, 'Subject: Creds for mailtest_smtp1') !== false, 'delivered message has the rendered subject');
}
array_map('unlink', glob($fakeDir . '/*') ?: []);
@rmdir($fakeDir);

// ------------------------------------------------------------------- result
echo "PASS: guest mail checks ($pass assertions)" . ($fails ? ", $fails FAILED" : '') . PHP_EOL;
exit($fails ? 1 : 0);
