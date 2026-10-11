<?php
// Command line only (php migrate_comment_threads.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: private comment threads.
 *
 * complaint_comments.staff_id = the staff member the conversation is with.
 * A comment is seen only by the student and that staff member (plus the
 * Super Admin), so a Department Chair does not read the student's chat with
 * the Program Coordinator, and so on.
 *
 * Existing comments are filled in: a staff comment belongs to its author's
 * thread; a student comment to whoever held the complaint at that moment
 * (worked out from the escalation history).
 *
 * Safe to run more than once.  Run: php migrate_comment_threads.php
 */
require_once __DIR__ . '/config/config.php';
$db = Database::getInstance()->getConnection();

if (!$db->query("SHOW COLUMNS FROM complaint_comments LIKE 'staff_id'")->fetch()) {
    $db->exec("ALTER TABLE complaint_comments ADD COLUMN staff_id INT NULL AFTER user_id, ADD INDEX idx_comment_thread (complaint_id, staff_id)");
    echo "Added complaint_comments.staff_id\n";
}

$rows = $db->query(
    "SELECT m.comment_id, m.complaint_id, m.user_id, m.created_at, u.role, c.assigned_to
     FROM complaint_comments m
     INNER JOIN users u ON u.user_id = m.user_id
     INNER JOIN complaints c ON c.complaint_id = m.complaint_id
     WHERE m.staff_id IS NULL"
)->fetchAll();

$escalations = $db->prepare(
    'SELECT escalated_by, escalated_to, created_at FROM complaint_escalations WHERE complaint_id = :id ORDER BY created_at, escalation_id'
);
$update = $db->prepare('UPDATE complaint_comments SET staff_id = :s WHERE comment_id = :id');
$filled = 0;
foreach ($rows as $row) {
    if ($row['role'] !== 'student') {
        $staffId = (int)$row['user_id'];
    } else {
        // Holder at the time of the comment: the first escalator held it
        // first; after each escalation, the person it went to.
        $escalations->execute([':id' => $row['complaint_id']]);
        $history = $escalations->fetchAll();
        $staffId = $history ? (int)$history[0]['escalated_by'] : (int)$row['assigned_to'];
        foreach ($history as $step) {
            if ($step['created_at'] <= $row['created_at'] && $step['escalated_to']) {
                $staffId = (int)$step['escalated_to'];
            }
        }
    }
    if ($staffId > 0) {
        $update->execute([':s' => $staffId, ':id' => $row['comment_id']]);
        $filled++;
    }
}
echo "Assigned {$filled} existing comment(s) to their conversation.\n";
echo "Private comment threads ready.\n";
