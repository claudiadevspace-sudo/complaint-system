<?php
// Command line only (php migrate_rate_limits.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: server-side rate limits (see rateLimitExceeded() in config.php).
 *
 * Counts sensitive actions per hashed key (an email address or an IP), so
 * limits survive clearing cookies: Forgot password codes per email and per
 * IP, and Student ID checks on the registration page per IP. Only hashes
 * are stored, never the email or IP itself. Old rows are purged.
 *
 * Safe to run more than once.  Run: php migrate_rate_limits.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

$db->exec("CREATE TABLE IF NOT EXISTS security_rate_limits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(40) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_lookup (action, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Rate limit table ready.\n";
