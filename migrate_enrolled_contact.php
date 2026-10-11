<?php
// Command line only (php migrate_enrolled_contact.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: email and contact number on the enrolled-students list.
 *
 * Students now register with only their Student ID and a password: the
 * name, department, program, email and contact number all come from this
 * list (uploaded by OSWD) and the student cannot change them.
 *
 * Safe to run more than once.  Run: php migrate_enrolled_contact.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

if (!$db->query("SHOW COLUMNS FROM enrolled_students LIKE 'email'")->fetch()) {
    $db->exec("ALTER TABLE enrolled_students ADD COLUMN email VARCHAR(100) NULL AFTER program");
    echo "Added enrolled_students.email\n";
}
if (!$db->query("SHOW COLUMNS FROM enrolled_students LIKE 'contact_number'")->fetch()) {
    $db->exec("ALTER TABLE enrolled_students ADD COLUMN contact_number VARCHAR(20) NULL AFTER email");
    echo "Added enrolled_students.contact_number\n";
}
echo "Enrolled list contact details ready.\n";
