<?php
// Command line only (php migrate_encrypt_existing.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Encrypt files and contact numbers saved before double encryption was
 * added (classes/Crypto.php). Runs once per server; the Super Admin
 * overview also triggers it automatically.
 */
require_once __DIR__ . '/config/config.php';
$result = Crypto::encryptExistingData();
echo "Encrypted {$result['files']} file(s) and {$result['values']} contact number(s).\n";
