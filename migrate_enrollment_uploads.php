<?php
// Command line only (php migrate_enrollment_uploads.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: history of uploaded enrolled-student lists.
 *
 * Each list OSWD uploads is kept as a file in uploads/enrollment/ (never
 * reachable from the browser; downloaded only through enrolled_students.php
 * by OSWD) and recorded here with who uploaded it and the import result.
 *
 * Safe to run more than once.  Run: php migrate_enrollment_uploads.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

$db->exec("CREATE TABLE IF NOT EXISTS enrollment_uploads (
    upload_id INT AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    mode VARCHAR(10) NOT NULL DEFAULT 'add',
    students_in_file INT NOT NULL DEFAULT 0,
    added INT NOT NULL DEFAULT 0,
    updated INT NOT NULL DEFAULT 0,
    invalid INT NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enrollment_uploads_created (created_at),
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Enrollment upload history ready.\n";
