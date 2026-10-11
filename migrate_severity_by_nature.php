<?php
// Command line only (php migrate_severity_by_nature.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: severity follows the nature of the complaint.
 *
 * complaints.severity changes from low/medium/high/critical (chosen by the
 * student) to '1'–'6', decided by the complaint category:
 *   1 Academic Integrity Violation   4 Institutional Rules Violation
 *   2 Unprofessional Behavior        5 Campus Services
 *   3 Teaching Standards Failure     6 Campus Facilities
 * (see severityByNature() in config/config.php).
 *
 * The column becomes VARCHAR so restoring an older backup still works; old
 * values are read through normalizeSeverity().
 *
 * Safe to run more than once.  Run: php migrate_severity_by_nature.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

$db->exec("ALTER TABLE complaints MODIFY severity VARCHAR(10) NOT NULL DEFAULT '6'");
echo "complaints.severity now stores the severity level (1-6)\n";

$rows = $db->query('SELECT complaint_id, complaint_category, severity FROM complaints')->fetchAll();
$update = $db->prepare('UPDATE complaints SET severity = :s WHERE complaint_id = :id');
$changed = 0;
foreach ($rows as $row) {
    $level = severityForCategory($row['complaint_category']);
    if ($row['severity'] !== $level) {
        $update->execute([':s' => $level, ':id' => $row['complaint_id']]);
        $changed++;
    }
}
echo "Set the severity of {$changed} existing complaint(s) from their nature.\n";
echo "Severity by nature ready.\n";
