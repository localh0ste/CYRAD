<?php
/**
 * Manually entered Active Directory (domain controller) settings.
 *
 * Filled in by hand from the "Active Directory" section of the Add User page
 * and stored in the `ad_config` table (db/v12_ad_config.sql, one row, id=1).
 * Blank fields fall back to the CYRAD_AD_* constants from config.php, so a
 * server that was configured over SSH keeps working unchanged.
 */

function ad_config_defaults() {
    return [
        'ad_domain' => '',
        'dc_host'   => '',
        'dc_ip'     => '',
        'dc_port'   => 389,
        'use_tls'   => 0,
        'base_dn'   => '',
        'bind_dn'   => '',
        'bind_pass' => '',
        'group_dn'  => '',
    ];
}

function ad_config_const($name, $default = '') {
    return defined($name) ? (string)constant($name) : $default;
}

/** Saved values, with config.php constants filling anything still empty. */
function ad_config_load(mysqli $conn, bool $fallback = true) {
    $c = ad_config_defaults();
    if ($r = $conn->query("SELECT * FROM ad_config WHERE id=1 LIMIT 1")) {
        if ($row = $r->fetch_assoc()) {
            foreach (array_keys($c) as $k) {
                if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') $c[$k] = $row[$k];
            }
            $c['use_tls'] = isset($row['use_tls']) ? (int)$row['use_tls'] : 0;
            $c['dc_port'] = (int)$c['dc_port'];
        }
        $r->free();
    }
    if ($fallback) {
        $map = [
            'ad_domain' => 'CYRAD_AD_DOMAIN',
            'dc_host'   => 'CYRAD_AD_HOST',
            'base_dn'   => 'CYRAD_AD_BASE_DN',
            'bind_dn'   => 'CYRAD_AD_BIND_DN',
            'bind_pass' => 'CYRAD_AD_BIND_PASS',
        ];
        foreach ($map as $field => $const) {
            if (trim((string)$c[$field]) === '') {
                $v = trim(ad_config_const($const));
                if ($v !== '') $c[$field] = $v;
            }
        }
        if ($c['dc_port'] === 389) {
            $p = (int)ad_config_const('CYRAD_AD_PORT', '389');
            if ($p > 0) $c['dc_port'] = $p;
        }
    }
    $c['use_tls'] = (int)$c['use_tls'];
    $c['dc_port'] = (int)$c['dc_port'];
    return $c;
}

/** Host used for the LDAP URI: the typed IP wins over the host name. */
function ad_config_host(array $c) {
    $ip = trim((string)$c['dc_ip']);
    return $ip !== '' ? $ip : trim((string)$c['dc_host']);
}

/** Resolve the DC address: typed IP, or a DNS lookup of the host name. */
function ad_config_resolve_ip(array $c) {
    $ip = trim((string)$c['dc_ip']);
    if ($ip !== '') return $ip;
    $host = trim((string)$c['dc_host']);
    if ($host === '') return '';
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    $r = gethostbyname($host);
    return ($r === $host) ? '' : $r;
}

/** e.g. ldaps://10.0.0.10:636 */
function ad_config_uri(array $c) {
    $host = ad_config_host($c);
    if ($host === '') return '';
    $tls  = (int)$c['use_tls'] === 1 || (int)$c['dc_port'] === 636;
    $port = (int)$c['dc_port'] > 0 ? (int)$c['dc_port'] : ($tls ? 636 : 389);
    return sprintf('%s://%s:%d', $tls ? 'ldaps' : 'ldap', $host, $port);
}

/** The AD DNS domain, e.g. corp.local (needed by the domain-join step). */
function ad_config_domain(array $c) {
    return trim((string)$c['ad_domain']);
}

/** Everything needed to reach a DC is present. */
function ad_config_ready(array $c) {
    return trim((string)$c['dc_host']) !== '' && trim((string)$c['base_dn']) !== ''
        && trim((string)$c['bind_dn']) !== '';
}

/** Sanitize a submitted form into the stored shape; '' when valid. */
function ad_config_validate(array &$c) {
    foreach (['ad_domain', 'dc_host', 'dc_ip', 'base_dn', 'bind_dn', 'bind_pass', 'group_dn'] as $k) {
        $c[$k] = trim((string)($c[$k] ?? ''));
    }
    $c['use_tls'] = !empty($c['use_tls']) ? 1 : 0;
    $c['dc_port'] = (int)($c['dc_port'] ?? 389);

    if ($c['dc_port'] < 1 || $c['dc_port'] > 65535) return 'DC port must be between 1 and 65535.';
    if ($c['use_tls'] && $c['dc_port'] === 389) $c['dc_port'] = 636;
    if ($c['dc_ip'] !== '' && !filter_var($c['dc_ip'], FILTER_VALIDATE_IP)) {
        return 'DC IP must be a valid IPv4/IPv6 address, or leave it empty to resolve the host name.';
    }
    if ($c['dc_host'] === '' && $c['dc_ip'] === '') return 'Enter the DC host name (or the DC IP address).';
    if ($c['base_dn'] === '') return 'Base DN is required, e.g. DC=corp,DC=local.';
    if ($c['bind_dn'] === '') return 'Bind DN (the read-only service account) is required.';
    return '';
}

/**
 * Persist the settings. An empty bind_pass keeps the stored secret, so the form
 * can show a placeholder instead of echoing the password back.
 */
function ad_config_save(mysqli $conn, array $c) {
    $keep = trim((string)$c['bind_pass']) === '';

    $sql = "UPDATE ad_config SET ad_domain=?, dc_host=?, dc_ip=?, dc_port=?, use_tls=?, base_dn=?, bind_dn=?"
         . ($keep ? '' : ', bind_pass=?')
         . ", group_dn=? WHERE id=1";

    $st = $conn->prepare($sql);
    if (!$st) return 'Database error: ' . $conn->error;

    if ($keep) {
        // s s s i i s s s  (8)
        $st->bind_param('sssiisss',
            $c['ad_domain'], $c['dc_host'], $c['dc_ip'], $c['dc_port'], $c['use_tls'],
            $c['base_dn'], $c['bind_dn'], $c['group_dn']);
    } else {
        $pass = $c['bind_pass'];
        // s s s i i s s s s  (9)
        $st->bind_param('sssiissss',
            $c['ad_domain'], $c['dc_host'], $c['dc_ip'], $c['dc_port'], $c['use_tls'],
            $c['base_dn'], $c['bind_dn'], $pass, $c['group_dn']);
    }
    if (!$st->execute()) return 'Database error: ' . $st->error;
    $st->close();

    $check = $conn->query("SELECT id FROM ad_config WHERE id=1");
    if ($check && $check->num_rows === 0) {
        $conn->query("INSERT IGNORE INTO ad_config (id) VALUES (1)");
        return ad_config_save($conn, $c);
    }
    if ($check) $check->free();
    return '';
}

/**
 * The command that pushes these values into FreeRADIUS (domain join, mschap/
 * ntlm_auth, AD-first authorize, auto-register). Printed in the panel so the
 * admin can run it over SSH — no privilege grant to the web user needed.
 */
function ad_config_apply_script() {
    $script = '/var/www/html/cyrad/freeradius/setup-ad.sh';
    return is_file($script) ? $script : 'freeradius/setup-ad.sh';
}

function ad_config_apply_command(array $c) {
    $script = ad_config_apply_script();

    $env = [
        'CYRAD_AD_DOMAIN'    => ad_config_domain($c) !== '' ? ad_config_domain($c) : ad_config_const('CYRAD_AD_DOMAIN'),
        'CYRAD_AD_HOST'      => ad_config_host($c),
        'CYRAD_AD_BASE_DN'   => (string)$c['base_dn'],
        'CYRAD_AD_BIND_DN'   => (string)$c['bind_dn'],
        // Masked: the command is printed in the page HTML, so the secret must
        // never be rendered there. The admin replaces *** with the bind password.
        'CYRAD_AD_BIND_PASS' => trim((string)$c['bind_pass']) !== '' ? '***' : '',
        'CYRAD_AD_TLS'       => (int)$c['use_tls'] ? '1' : '0',
        'CYRAD_AD_PORT'      => (string)(int)$c['dc_port'],
    ];
    $prefix = '';
    foreach ($env as $k => $v) {
        if ($v === '') continue;              // let the script's own default/required check speak
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    return 'sudo ' . $prefix . 'bash ' . $script;
}
