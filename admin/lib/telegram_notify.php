<?php
/**
 * CYRAD Telegram alert helper.
 *
 * Sends a message to every allowed admin UID configured for an organization.
 * Token + UIDs live on the organizations row (telegram_bot_token,
 * telegram_admin_uids). Used by admin-facing events; the DNS monitor daemon
 * sends its own alerts directly (see dns/dns_monitor.py).
 */

if (!function_exists('cyrad_send_telegram')) {
    /**
     * @param int    $org_id  Organization id (matches organizations.id)
     * @param string $text    Message body (plain text; <pre> safe)
     * @return array ['sent'=>int,'errors'=>array]
     */
    function cyrad_send_telegram(int $org_id, string $text): array
    {
        $result = ['sent' => 0, 'errors' => []];

        if (!is_file(__DIR__ . '/../config/db.php')) {
            $result['errors'][] = 'db config missing';
            return $result;
        }
        require_once __DIR__ . '/../config/db.php';
        if (!isset($conn) || $conn->connect_error) {
            $result['errors'][] = 'db connection failed';
            return $result;
        }

        $stmt = $conn->prepare(
            "SELECT telegram_bot_token, telegram_admin_uids
             FROM organizations WHERE id = ?"
        );
        if (!$stmt) {
            $result['errors'][] = $conn->error;
            return $result;
        }
        $stmt->bind_param('i', $org_id);
        $stmt->execute();
        $stmt->bind_result($token, $uids);
        if (!$stmt->fetch() || empty($token)) {
            $stmt->close();
            $conn->close();
            return $result; // not configured -> nothing to send
        }
        $stmt->close();
        $conn->close();

        $uid_list = array_filter(array_map('trim', explode(',', (string)$uids)));
        if (empty($uid_list)) {
            return $result;
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        foreach ($uid_list as $uid) {
            $payload = http_build_query([
                'chat_id' => $uid,
                'text'    => $text,
                'parse_mode' => 'HTML',
            ]);
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'timeout' => 10,
                    'content' => $payload,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                $result['errors'][] = "uid $uid: http error";
                continue;
            }
            $json = json_decode($resp, true);
            if (isset($json['ok']) && $json['ok']) {
                $result['sent']++;
            } else {
                $result['errors'][] = "uid $uid: " . ($json['description'] ?? 'unknown');
            }
        }
        return $result;
    }
}

if (!function_exists('cyrad_send_telegram_to')) {
    /**
     * Send a Telegram message to a single chat id using an explicit bot token.
     * Used by the super-admin "forgot password" OTP flow.
     * @return array ['ok'=>bool,'error'=>string]
     */
    function cyrad_send_telegram_to(string $token, string $uid, string $text): array
    {
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $payload = http_build_query([
            'chat_id'    => $uid,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'timeout' => 10,
                'content' => $payload,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'http error'];
        }
        $json = json_decode($resp, true);
        if (isset($json['ok']) && $json['ok']) {
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => $json['description'] ?? 'unknown'];
    }
}

if (!function_exists('cyrad_user_info')) {
    /**
     * Returns the free-text "User Info" note for a username (from user_profiles),
     * or '' if none / on any error. Used to enrich Telegram violation alerts.
     */
    function cyrad_user_info(string $username): string
    {
        static $cache = [];
        if (array_key_exists($username, $cache)) return $cache[$username];
        $out = '';
        if (defined('CYRAD_DB_HOST')) {
            $c = @new mysqli(CYRAD_DB_HOST, CYRAD_DB_USER, CYRAD_DB_PASS, CYRAD_DB_NAME);
            if ($c && !$c->connect_error) {
                $u = $c->real_escape_string($username);
                $r = $c->query("SELECT user_info FROM user_profiles WHERE username='$u'");
                if ($r && $row = $r->fetch_assoc()) $out = (string)($row['user_info'] ?? '');
                $c->close();
            }
        }
        $cache[$username] = $out;
        return $out;
    }
}
