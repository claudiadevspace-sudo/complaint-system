<?php
// Command line only (php migrate_user_archive.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: archive accounts instead of deleting them.
 *
 * users.archived_at — when OSWD archived the account (NULL = not archived)
 * users.archived_by — who archived it
 *
 * An archived account cannot sign in (its status is set to 'inactive') and
 * is hidden from the main Users list, but nothing is deleted: complaints,
 * history and audit records stay intact, and OSWD can restore it.
 *
 * Safe to run more than once.  Run: php migrate_user_archive.php
 */
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

if (!$db->query("SHOW COLUMNS FROM users LIKE 'archived_at'")->fetch()) {
    $db->exec("ALTER TABLE users
        ADD COLUMN archived_at DATETIME NULL AFTER is_signed_in,
        ADD COLUMN archived_by INT NULL AFTER archived_at,
        ADD INDEX idx_users_archived (archived_at)");
    echo "Added users.archived_at / archived_by columns\n";
}

echo "User archive ready.\n";
