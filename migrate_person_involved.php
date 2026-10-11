<?php
// Command line only (php migrate_person_involved.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: "Name of person involved" on the complaint form.
 *
 * complaints.respondent_name    — the name the student typed (optional)
 * complaints.respondent_details — their short description of that person
 *                                 (e.g. role / how they are involved)
 * Not asked for Campus Facilities complaints.
 *
 * Safe to run more than once.  Run: php migrate_person_involved.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

if (!$db->query("SHOW COLUMNS FROM complaints LIKE 'respondent_details'")->fetch()) {
    $db->exec("ALTER TABLE complaints ADD COLUMN respondent_details VARCHAR(255) NULL AFTER respondent_type");
    echo "Added complaints.respondent_details\n";
}
echo "Person involved ready.\n";
