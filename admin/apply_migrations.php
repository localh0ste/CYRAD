<?php
/** Explicit CLI migration runner; numeric ordering and fail-fast errors. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (in_array('--help', $argv, true)) {
    echo "Usage: php admin/apply_migrations.php\nUses CYRAD_DB_* environment variables or config.php.\n"; exit;
}
$root = dirname(__DIR__);
if (is_file($root . '/config.php')) require_once $root . '/config.php';
function migration_setting(string $key, string $default): string {
    $env = getenv($key);
    return $env !== false ? $env : (defined($key) ? (string)constant($key) : $default);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(migration_setting('CYRAD_DB_HOST','localhost'), migration_setting('CYRAD_DB_USER','root'), migration_setting('CYRAD_DB_PASS',''), migration_setting('CYRAD_DB_NAME','radius'));
    $db->set_charset('utf8mb4');
    $files = glob($root . '/db/v[0-9]*_*.sql'); natsort($files);
    foreach ($files as $file) {
        // Compatible with MariaDB 10.6 (Ubuntu 22.04), which predates UCA 14.
        $sql = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', file_get_contents($file));
        $db->multi_query($sql);
        do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
        echo 'Applied ' . basename($file) . PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR, 'Migration failed: '.$e->getMessage().PHP_EOL); exit(1); }
