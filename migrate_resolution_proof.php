<?php
// Command line only (php migrate_resolution_proof.php): never run from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Migration: resolution proof.
 *
 * Before staff can mark a complaint "Resolved" they upload proof (an image or
 * document), and the student must approve it. One row per uploaded proof:
 *   pending  — waiting for the student
 *   approved — the student accepted it → staff may now resolve the complaint
 *   rejected — the student said it does not show the problem was fixed (reason given)
 *   replaced — staff uploaded a newer proof before the student answered
 *
 * Safe to run more than once.  Run: php migrate_resolution_proof.php
 */
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();

$db->exec("CREATE TABLE IF NOT EXISTS complaint_resolution_proofs (
    proof_id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,          -- stored name in /uploads
    original_name VARCHAR(255) NULL,          -- name of the file on the uploader's computer
    note TEXT NOT NULL,                       -- what was done to resolve the complaint
    status ENUM('pending','approved','rejected','replaced') NOT NULL DEFAULT 'pending',
    student_response TEXT NULL,               -- the student's reason when rejecting
    responded_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proofs_complaint (complaint_id, proof_id),
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "Resolution proof table ready.\n";
