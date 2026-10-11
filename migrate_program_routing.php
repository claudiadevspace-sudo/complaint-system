<?php
// Command line only (php migrate_program_routing.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: program-based routing to the Program Coordinator.
 *
 * Adds complaints.program — the complainant's program when the complaint was
 * submitted (like complaints.department). Routing uses this snapshot, so a
 * later profile change does not move complaints already in progress.
 *
 * Existing complaints are only back-filled (never re-routed), and only when
 * the student's program matches an official program of their department
 * (Super Admin → Settings → Programs).
 *
 * Safe to run more than once.  Run: php migrate_program_routing.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

if (!$db->query("SHOW COLUMNS FROM complaints LIKE 'program'")->fetch()) {
    $db->exec("ALTER TABLE complaints ADD COLUMN program VARCHAR(100) NULL AFTER department");
    echo "Added complaints.program column\n";
}

$rows = $db->query(
    "SELECT c.complaint_id, c.department, u.program
     FROM complaints c JOIN users u ON u.user_id = c.complainant_id
     WHERE c.program IS NULL AND u.program IS NOT NULL AND u.program <> ''"
)->fetchAll();
$update = $db->prepare('UPDATE complaints SET program = :p WHERE complaint_id = :id');
$filled = 0;
foreach ($rows as $row) {
    $official = canonicalProgram($row['department'], $row['program']);
    if ($official !== null) {
        $update->execute([':p' => $official, ':id' => $row['complaint_id']]);
        $filled++;
    }
}
echo "Recorded the program of {$filled} existing complaint(s) (" . (count($rows) - $filled) . " had a program not on the official list).\n";
echo "Program routing ready.\n";
