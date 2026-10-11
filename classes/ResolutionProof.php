<?php
/**
 * ResolutionProof — proof that a complaint was dealt with, approved by the student.
 *
 * The rule: staff cannot mark a complaint "Resolved" until
 *   1. the handling staff member uploads proof (image or document + note), and
 *   2. the student who filed the complaint APPROVES that proof.
 * If the student rejects it (with a reason), staff upload new proof.
 *
 * Complaint::updateStatus() calls canResolve() so the rule is enforced on the
 * server, not only by hiding the option in the page. Every step is written to
 * the complaint's timeline, and the student is notified of new proof.
 */
class ResolutionProof {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** All proofs of a complaint, newest first, with who uploaded them. */
    public function forComplaint($complaintId) {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.full_name AS uploaded_by_name, u.role AS uploaded_by_role
             FROM complaint_resolution_proofs p
             JOIN users u ON u.user_id = p.uploaded_by
             WHERE p.complaint_id = :id
             ORDER BY p.proof_id DESC'
        );
        $stmt->execute([':id' => (int)$complaintId]);
        return $stmt->fetchAll();
    }

    /** The most recent proof (any status), or null. */
    public function latest($complaintId) {
        $stmt = $this->db->prepare(
            'SELECT * FROM complaint_resolution_proofs WHERE complaint_id = :id ORDER BY proof_id DESC LIMIT 1'
        );
        $stmt->execute([':id' => (int)$complaintId]);
        return $stmt->fetch() ?: null;
    }

    /** Resolving is allowed only when the LATEST proof was approved by the student. */
    public function canResolve($complaintId) {
        $latest = $this->latest($complaintId);
        return $latest !== null && $latest['status'] === 'approved';
    }

    /**
     * Staff upload proof for the student to review. A proof still waiting
     * for the student is marked "replaced" (e.g. the wrong file was sent).
     */
    public function upload(array $complaint, $staffId, $file, $note) {
        $note = trim((string)$note);
        if (mb_strlen($note) < 10) {
            return ['success' => false, 'message' => 'Describe what was done to resolve the complaint (at least 10 characters).'];
        }
        if (in_array($complaint['status'], ['resolved', 'rejected'], true)) {
            return ['success' => false, 'message' => 'This complaint is already closed.'];
        }
        $saved = saveUploadedDocument($file);
        if (!$saved['ok']) {
            return ['success' => false, 'message' => $saved['message']];
        }

        $complaintId = (int)$complaint['complaint_id'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE complaint_resolution_proofs SET status = 'replaced'
                 WHERE complaint_id = :id AND status = 'pending'"
            )->execute([':id' => $complaintId]);

            $this->db->prepare(
                'INSERT INTO complaint_resolution_proofs (complaint_id, uploaded_by, file_name, original_name, note)
                 VALUES (:cid, :by, :file, :original, :note)'
            )->execute([
                ':cid' => $complaintId, ':by' => (int)$staffId, ':file' => $saved['file'],
                ':original' => $saved['original'], ':note' => $note,
            ]);
            $this->timeline($complaintId, $staffId, 'proof_uploaded', 'Resolution proof sent to the student for review');
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            @unlink(rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $saved['file']);
            return ['success' => false, 'message' => 'The proof could not be saved. Please try again.'];
        }

        $this->notify((int)$complaint['complainant_id'], $complaintId, 'Please review the resolution',
            'Staff uploaded proof for complaint #' . $complaintId . '. Open it to approve or reject it.');
        return ['success' => true, 'message' => 'Proof sent. The student will review it before the complaint can be resolved.'];
    }

    /**
     * The student approves or rejects the latest pending proof of THEIR
     * complaint. Rejecting needs a reason so staff know what is missing.
     */
    public function respond(array $complaint, $studentId, $proofId, $decision, $reason) {
        if ((int)$complaint['complainant_id'] !== (int)$studentId) {
            return ['success' => false, 'message' => 'Only the student who filed this complaint can review its proof.'];
        }
        $latest = $this->latest($complaint['complaint_id']);
        if (!$latest || (int)$latest['proof_id'] !== (int)$proofId || $latest['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This proof is no longer waiting for your review.'];
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['success' => false, 'message' => 'Choose Approve or Reject.'];
        }
        $reason = trim((string)$reason);
        if ($decision === 'reject' && mb_strlen($reason) < 5) {
            return ['success' => false, 'message' => 'Please say why the proof does not resolve your complaint.'];
        }

        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $this->db->prepare(
            'UPDATE complaint_resolution_proofs SET status = :s, student_response = :r, responded_at = NOW()
             WHERE proof_id = :id AND status = \'pending\''
        )->execute([':s' => $status, ':r' => $reason !== '' ? $reason : null, ':id' => (int)$proofId]);

        $complaintId = (int)$complaint['complaint_id'];
        $this->timeline($complaintId, $studentId, 'proof_' . $status, $status === 'approved'
            ? 'Student approved the resolution proof — staff may now resolve the complaint'
            : 'Student rejected the resolution proof: ' . $reason);

        // Let the handling staff member know too.
        if (!empty($complaint['assigned_to'])) {
            $this->notify((int)$complaint['assigned_to'], $complaintId,
                $status === 'approved' ? 'Proof approved' : 'Proof rejected',
                'The student ' . ($status === 'approved' ? 'approved' : 'rejected') . ' the resolution proof for complaint #' . $complaintId . '.');
        }
        return ['success' => true, 'message' => $status === 'approved'
            ? 'Thank you. You approved the proof; staff can now mark your complaint as resolved.'
            : 'Your response was sent. Staff will look into it and upload new proof.'];
    }

    /** Readable label and colour for a proof's status. */
    public static function statusMeta($status) {
        return match ((string)$status) {
            'approved' => ['success', 'Approved by student', 'check-circle'],
            'rejected' => ['danger', 'Rejected by student', 'x-circle'],
            'replaced' => ['secondary', 'Replaced by newer proof', 'arrow-repeat'],
            default => ['warning', 'Waiting for student review', 'hourglass-split'],
        };
    }

    private function timeline($complaintId, $userId, $type, $description) {
        $this->db->prepare(
            'INSERT INTO complaint_timeline (complaint_id, action_by, action_type, action_description)
             VALUES (:cid, :by, :type, :descr)'
        )->execute([':cid' => (int)$complaintId, ':by' => (int)$userId, ':type' => $type, ':descr' => $description]);
    }

    private function notify($userId, $complaintId, $title, $message) {
        try {
            (new Notification())->create($userId, $complaintId, $title, $message);
        } catch (PDOException $e) {
            // A notification must never block the proof workflow.
        }
    }
}
