<?php
/**
 * Minimal plain-SMTP sink for tests. Usage:
 *   php tests/fake_smtp_server.php <port> <outdir> [seconds]
 * Writes each accepted DATA message to <outdir>/msg-N.eml, exits after the
 * time budget. Test-only: no TLS, no auth, one message at a time.
 */
$port   = (int)($argv[1] ?? 2525);
$outdir = $argv[2] ?? '/tmp/cyrad-fake-smtp';
$secs   = (int)($argv[3] ?? 60);
@mkdir($outdir, 0777, true);
$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
$deadline = time() + $secs;
$n = 0;
while (time() < $deadline) {
    $conn = @stream_socket_accept($server, 1);
    if (!$conn) continue;
    stream_set_timeout($conn, 10);
    fwrite($conn, "220 fake ESMTP ready\r\n");
    $in_data = false; $msg = '';
    while (($line = fgets($conn, 8192)) !== false) {
        $t = rtrim($line, "\r\n");
        if ($in_data) {
            if ($t === '.') {
                $in_data = false;
                file_put_contents(sprintf('%s/msg-%d.eml', $outdir, ++$n), $msg);
                fwrite($conn, "250 OK queued\r\n");
                continue;
            }
            $msg .= $line;
            continue;
        }
        if (stripos($t, 'EHLO') === 0 || stripos($t, 'HELO') === 0) { fwrite($conn, "250-fake\r\n250 AUTH LOGIN PLAIN\r\n"); continue; }
        if (stripos($t, 'MAIL FROM') === 0 || stripos($t, 'RCPT TO') === 0) { fwrite($conn, "250 OK\r\n"); continue; }
        if (stripos($t, 'DATA') === 0) { fwrite($conn, "354 send data\r\n"); $in_data = true; continue; }
        if (stripos($t, 'QUIT') === 0) { fwrite($conn, "221 bye\r\n"); break; }
        fwrite($conn, "250 OK\r\n");
    }
    fclose($conn);
}
