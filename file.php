<?php
/**
 * Sends a complaint's uploaded file (student evidence or resolution proof)
 * ONLY to someone allowed to see that complaint:
 *   the student who filed it, the staff who hold / handled it, the office
 *   at its level, or the Super Admin.
 * Direct access to uploads/ is blocked (uploads/.htaccess), so evidence
 * cannot be opened by guessing or sharing a file address.
 *
 *   file.php?c=<complaint id>&f=<file name>   (see complaintFileUrl())
 */
require_once 'config/config.php';
requireLogin();

$complaintId = (int)($_GET['c'] ?? 0);
$fileName = basename((string)($_GET['f'] ?? ''));

function fileNotFound() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

if ($complaintId <= 0 || !preg_match('/^[A-Za-z0-9._-]{1,200}$/', $fileName)) {
    fileNotFound();
}

$complaintObj = new Complaint();
$complaint = $complaintObj->getComplaintById($complaintId);
$role = (string)($_SESSION['role'] ?? '');
if (!$complaint || ($role !== 'super_admin' && !$complaintObj->canAccessComplaint($complaint, (int)$_SESSION['user_id'], $role))) {
    fileNotFound();   // same answer as "no such file": nothing to learn by guessing
}

// The file must really belong to this complaint.
$belongs = basename((string)($complaint['supporting_documents'] ?? '')) === $fileName;
if (!$belongs) {
    $stmt = Database::getInstance()->getConnection()->prepare(
        'SELECT 1 FROM complaint_resolution_proofs WHERE complaint_id = :c AND file_name = :f LIMIT 1'
    );
    $stmt->execute([':c' => $complaintId, ':f' => $fileName]);
    $belongs = (bool)$stmt->fetchColumn();
}
$path = UPLOAD_DIR . $fileName;
if (!$belongs || !is_file($path)) {
    fileNotFound();
}

$types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    fileNotFound();
}
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="complaint-' . $complaintId . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
// Shown inside this site's document pop-up (an iframe), never on other sites.
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf; frame-ancestors 'self'; sandbox allow-same-origin");
readfile($path);
