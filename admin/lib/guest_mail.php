<?php
/** Organization-scoped SMTP and guest voucher outbox. No network calls during mint. */
function guest_mail_defaults(): array {
    return ['enabled' => 0, 'host' => '', 'port' => 587, 'encryption' => 'tls',
        'auth_enabled' => 1, 'username' => '', 'password_enc' => '', 'from_address' => '',
        'from_name' => '', 'reply_to' => '', 'subject' => 'Your {{organization_name}} Wi-Fi ticket',
        'template' => "Welcome to {{organization_name}}!\n\nUsername: {{username}}\nPassword: {{password}}\nExpires (UTC): {{expires_at}}\nData allowance: {{data_limit}}\n\nUse these credentials on the guest Wi-Fi network."];
}

function guest_mail_load(mysqli $db, int $org): array {
    $st = $db->prepare('SELECT * FROM organization_smtp WHERE organization_id=?');
    $st->bind_param('i', $org); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return $row ?: guest_mail_defaults();
}

function guest_mail_key(): string {
    $path = getenv('CYRAD_MAIL_KEY_FILE') ?: '/etc/cyrad/mail.key';
    $raw = @file_get_contents($path);
    $key = $raw === false ? false : base64_decode(trim($raw), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Mail encryption key unavailable. Run the mail setup step in the installer.');
    }
    return $key;
}

function guest_mail_encrypt(string $password): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($password, 'aes-256-gcm', guest_mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt SMTP password.');
    return base64_encode($iv . $tag . $cipher);
}

function guest_mail_decrypt(string $encoded): string {
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Invalid encrypted SMTP password.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', guest_mail_key(), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Cannot decrypt SMTP password; check the mail encryption key.');
    return $plain;
}

function guest_mail_email(string $address): string {
    $address = trim($address);
    if (strlen($address) > 254 || !filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
        throw new InvalidArgumentException('Enter a valid recipient email address.');
    }
    return $address;
}

/** Exactly one optional address per ticket; blank lines skip that ticket. */
function guest_mail_recipients(string $text, int $count): array {
    if (trim($text) === '') return array_fill(0, $count, '');
    $lines = preg_split('/\r\n|\r|\n/', $text);
    while (count($lines) > $count && trim(end($lines)) === '') array_pop($lines);
    if (count($lines) > $count) throw new InvalidArgumentException('Provide at most one email line per ticket.');
    $out = [];
    foreach ($lines as $line) $out[] = trim($line) === '' ? '' : guest_mail_email($line);
    return array_pad($out, $count, '');
}

function guest_mail_validate(array $input, array $old): array {
    $c = guest_mail_defaults();
    foreach (['host', 'username', 'from_address', 'from_name', 'reply_to', 'subject', 'template', 'encryption'] as $k) {
        $c[$k] = trim((string)($input[$k] ?? $c[$k]));
    }
    $c['enabled'] = isset($input['enabled']) ? 1 : 0;
    $c['auth_enabled'] = isset($input['auth_enabled']) ? 1 : 0;
    $c['port'] = filter_var($input['port'] ?? 587, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if (!$c['port'] || !in_array($c['encryption'], ['tls', 'ssl', 'none'], true)) throw new InvalidArgumentException('Invalid SMTP port or encryption mode.');
    foreach (['host', 'username', 'from_address', 'from_name', 'reply_to', 'subject'] as $k) {
        if (preg_match('/[\r\n\x00]/', $c[$k]) || strlen($c[$k]) > ($k === 'from_name' ? 100 : 255)) {
            throw new InvalidArgumentException('Invalid or oversized SMTP field: ' . $k);
        }
    }
    if ($c['host'] !== '' && !filter_var($c['host'], FILTER_VALIDATE_IP) && !filter_var($c['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new InvalidArgumentException('SMTP host must be a hostname or IP, without a URL scheme.');
    }
    foreach (['from_address', 'reply_to'] as $k) if ($c[$k] !== '') guest_mail_email($c[$k]);
    if ($c['subject'] === '' || $c['template'] === '' || strlen($c['template']) > 20000) throw new InvalidArgumentException('Subject and template are required (template maximum 20 KB).');
    preg_match_all('/\{\{([^{}]+)\}\}/', $c['subject'] . $c['template'], $tokens);
    foreach ($tokens[1] as $token) if (!in_array($token, ['organization_name','username','password','expires_at','data_limit'], true)) throw new InvalidArgumentException('Unknown template placeholder: ' . $token);
    $c['password_enc'] = !empty($input['clear_password']) ? '' : ($old['password_enc'] ?? '');
    if (($input['password'] ?? '') !== '') $c['password_enc'] = guest_mail_encrypt((string)$input['password']);
    if ($c['enabled'] && ($c['host'] === '' || $c['from_address'] === '')) throw new InvalidArgumentException('Enabled SMTP requires a host and From address.');
    if ($c['enabled'] && $c['auth_enabled'] && ($c['username'] === '' || $c['password_enc'] === '')) throw new InvalidArgumentException('SMTP authentication requires a username and password.');
    if ($c['enabled'] && $c['auth_enabled'] && $c['encryption'] === 'none') throw new InvalidArgumentException('Use TLS when sending SMTP login credentials.');
    return $c;
}

function guest_mail_save(mysqli $db, int $org, array $c): void {
    $fields = array_keys(guest_mail_defaults());
    $updates = implode(',', array_map(static fn($f) => "$f=VALUES($f)", $fields));
    $st = $db->prepare('INSERT INTO organization_smtp (organization_id,' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields)+1, '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates);
    $values = [$org]; foreach ($fields as $f) $values[] = $c[$f];
    $st->bind_param('i' . str_repeat('s', count($fields)), ...$values); $st->execute(); $st->close();
}

function guest_mail_enqueue(mysqli $db, int $org, int $ticket, string $recipient): void {
    $recipient = guest_mail_email($recipient);
    if (!guest_mail_load($db, $org)['enabled']) throw new RuntimeException('Configure and enable SMTP in Edit Organization before requesting email.');
    // Caller holds a transaction. The ticket lock serializes concurrent resend clicks.
    $st = $db->prepare("SELECT id FROM guest_tickets WHERE id=? AND organization_id=? AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) FOR UPDATE");
    $st->bind_param('ii', $ticket, $org); $st->execute();
    $exists = $st->get_result()->fetch_assoc(); $st->close();
    if (!$exists) throw new RuntimeException('Only active, unexpired tickets in this organization can be emailed.');
    $st = $db->prepare("SELECT id FROM guest_email_queue WHERE ticket_id=? AND organization_id=? AND recipient=? AND (status='queued' OR created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE))");
    $st->bind_param('iis', $ticket, $org, $recipient); $st->execute();
    $duplicate = $st->get_result()->fetch_assoc(); $st->close();
    if ($duplicate) throw new RuntimeException('Email already queued or requested within the last minute.');
    $st = $db->prepare('INSERT INTO guest_email_queue (organization_id,ticket_id,recipient) VALUES (?,?,?)');
    $st->bind_param('iis', $org, $ticket, $recipient); $st->execute(); $st->close();
}

function guest_mail_render(array $c, array $ticket): array {
    $values = [];
    foreach (['organization_name','username','password','expires_at','data_limit'] as $k) $values['{{'.$k.'}}'] = (string)($ticket[$k] ?? '');
    $subject = str_replace(["\r", "\n"], ' ', strtr($c['subject'], $values));
    $text = strtr($c['template'], $values);
    return [$subject, $text, '<!doctype html><html><body><div style="font-family:Arial,sans-serif;white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></body></html>'];
}

function guest_mail_send(array $c, string $recipient, array $message, string $messageId = ''): void {
    $autoload = '/usr/share/php/libphp-phpmailer/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('SMTP dependency missing: install libphp-phpmailer.');
    require_once $autoload;
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP(); $m->Host = $c['host']; $m->Port = (int)$c['port'];
    $m->SMTPAuth = (bool)$c['auth_enabled']; $m->Username = $c['username'];
    $m->Password = $m->SMTPAuth ? guest_mail_decrypt($c['password_enc']) : '';
    $m->SMTPSecure = $c['encryption'] === 'none' ? '' : $c['encryption'];
    $m->SMTPAutoTLS = $c['encryption'] !== 'none';
    $m->Timeout = 15; $m->Timelimit = 30; $m->CharSet = 'UTF-8';
    $m->setFrom($c['from_address'], $c['from_name']);
    if ($c['reply_to'] !== '') $m->addReplyTo($c['reply_to']);
    $m->addAddress(guest_mail_email($recipient));
    [$m->Subject, $m->AltBody, $m->Body] = $message;
    $m->isHTML(true);
    if ($messageId !== '') $m->MessageID = $messageId;
    try { $m->send(); }
    catch (Throwable $e) {
        // Do not retain server replies: a relay may echo AUTH or message data.
        throw new RuntimeException('SMTP connection, TLS, authentication or recipient rejected. Check host/port, credentials, sender verification and outbound firewall.');
    }
}

/** Bounded worker. MySQL advisory lock prevents overlapping CLI workers. */
function guest_mail_drain(mysqli $db, int $limit = 50, ?callable $send = null): int {
    $send = $send ?? 'guest_mail_send';
    $lock = 'cyrad-mail-' . substr(hash('sha256', $db->query('SELECT DATABASE()')->fetch_row()[0]), 0, 32);
    $escaped = $db->real_escape_string($lock);
    if ((int)$db->query("SELECT GET_LOCK('$escaped',0)")->fetch_row()[0] !== 1) return 0;
    $done = 0;
    try {
        $jobs = $db->query("SELECT id,ticket_id FROM guest_email_queue WHERE status='queued' AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetch_all(MYSQLI_ASSOC);
        foreach ($jobs as $job) {
            $db->begin_transaction();
            try {
                // Lock ticket first, matching revoke/delete/resend lock order.
                $id = (int)$job['id']; $tid = (int)$job['ticket_id'];
                $db->query("SELECT id FROM guest_tickets WHERE id=$tid FOR UPDATE");
                $q = $db->query("SELECT q.*,t.username,t.password,t.status AS ticket_status,t.expires_at,o.org_name AS organization_name,d.internet_type,d.internet_limit,
                    (SELECT COUNT(DISTINCT a.acctuniqueid) FROM radacct a WHERE a.username=t.username COLLATE utf8mb4_unicode_ci) AS logins,t.max_uses,
                    (t.expires_at IS NOT NULL AND t.expires_at<=UTC_TIMESTAMP()) AS elapsed
                    FROM guest_email_queue q JOIN guest_tickets t ON t.id=q.ticket_id AND t.organization_id=q.organization_id
                    JOIN organizations o ON o.id=q.organization_id LEFT JOIN data_limits d ON d.username=t.username COLLATE utf8mb4_unicode_ci AND d.organization_id=t.organization_id
                    WHERE q.id=$id AND q.status='queued' FOR UPDATE")->fetch_assoc();
                if (!$q) { $db->commit(); continue; }
                if ($q['ticket_status'] !== 'active' || $q['elapsed'] || ($q['max_uses'] > 0 && $q['logins'] >= $q['max_uses'])) {
                    $db->query("UPDATE guest_email_queue SET status='cancelled',last_error='Ticket inactive, expired or used' WHERE id=$id");
                } else {
                    $attempt = (int)$q['attempts'] + 1;
                    try {
                        $c = guest_mail_load($db, (int)$q['organization_id']);
                        if (!$c['enabled']) throw new RuntimeException('SMTP is disabled for this organization.');
                        $q['expires_at'] = $q['expires_at'] ?: 'Never';
                        $q['data_limit'] = $q['internet_type'] === 'limited' ? round($q['internet_limit'] / 1048576, 2) . ' MB' : 'Unlimited';
                        $domain = substr(strrchr($c['from_address'], '@'), 1);
                        $send($c, $q['recipient'], guest_mail_render($c, $q), '<cyrad-guest-'.$id.'@'.$domain.'>');
                        $db->query("UPDATE guest_email_queue SET status='sent',attempts=$attempt,sent_at=UTC_TIMESTAMP(),last_error='' WHERE id=$id");
                    } catch (Throwable $e) {
                        $status = $attempt >= 3 ? 'failed' : 'queued';
                        $error = substr($e->getMessage(), 0, 255);
                        $delay = $attempt * 300;
                        $st = $db->prepare("UPDATE guest_email_queue SET status=?,attempts=?,last_error=?,available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL $delay SECOND) WHERE id=?");
                        $st->bind_param('sisi', $status, $attempt, $error, $id); $st->execute(); $st->close();
                    }
                }
                $db->commit(); $done++;
            } catch (Throwable $e) { $db->rollback(); throw $e; }
        }
    } finally { $db->query("SELECT RELEASE_LOCK('$escaped')"); }
    return $done;
}
