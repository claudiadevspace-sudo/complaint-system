<?php
/**
 * Complaint Class - Part 1: Core Functions
 */
class Complaint {
    /** Name shown for a user: students by alias, staff by their real name. */
    const DISPLAY_NAME_SQL = "CASE WHEN u.role = 'student' THEN COALESCE(u.alias, 'Anonymous student') ELSE u.full_name END";

    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function submitComplaint($data) {
        try {
            $this->db->beginTransaction();

            $category = $data['complaint_category'] ?? null;
            $complaintType = complaintTypeFromCategory($category);
            $currentLevel = initialWorkflowLevel($complaintType);
            // Snapshot of the student's department; routing uses this value.
            $department = $this->complainantDepartment($data['complainant_id']);
            // ...and the program, which picks the Program Coordinator.
            $program = $this->complainantProgram($data['complainant_id'], $department);

            $sql = "INSERT INTO complaints (complainant_id, respondent_name, respondent_type, respondent_details,
                    complaint_title, complaint_description, complaint_category, predicted_category,
                    complaint_type, department, program, incident_date, incident_location, severity, supporting_documents,
                    current_level)
                    VALUES (:complainant_id, :respondent_name, :respondent_type, :respondent_details, :complaint_title,
                    :complaint_description, :complaint_category, :predicted_category, :complaint_type,
                    :department, :program, :incident_date, :incident_location, :severity, :supporting_documents, :current_level)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':complainant_id' => $data['complainant_id'],
                ':respondent_name' => emptyToNull($data['respondent_name'] ?? null),
                ':respondent_type' => emptyToNull($data['respondent_type'] ?? null),
                ':respondent_details' => emptyToNull($data['respondent_details'] ?? null),
                ':complaint_title' => $data['complaint_title'],
                ':complaint_description' => $data['complaint_description'],
                ':complaint_category' => emptyToNull($category),
                ':predicted_category' => emptyToNull($data['predicted_category'] ?? null),
                ':complaint_type' => $complaintType,
                ':department' => $department,
                ':program' => $program,
                ':incident_date' => $data['incident_date'],
                ':incident_location' => emptyToNull($data['incident_location'] ?? null),
                ':severity' => severityForCategory($category),
                ':supporting_documents' => emptyToNull($data['supporting_documents'] ?? null),
                ':current_level' => $currentLevel
            ]);

            $complaintId = $this->db->lastInsertId();
            $this->addTimeline($complaintId, $data['complainant_id'], 'created', 'Complaint submitted');
            // May land on a higher level if the department has nobody at this one.
            $route = $this->assignToRole($complaintId, $currentLevel, $data['complainant_id'], true, $department, $program);

            $this->db->commit();

            $levelLabel = workflowLevelLabel($route['level']);
            $this->notifyComplainant(
                (int)$complaintId,
                'Complaint submitted',
                "Your complaint #{$complaintId} was received and routed to {$levelLabel}.",
                null
            );

            return ['success' => true, 'complaint_id' => $complaintId];
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to submit complaint. Please try again.'];
        }
    }
    
    public function getComplaintById($complaintId) {
        // Anonymity: the complainant is shown by alias, never by real name,
        // email or student ID. Only the Super Admin can reveal the identity
        // (SuperAdmin::revealIdentity), and every reveal is audit-logged.
        $sql = "SELECT c.*, COALESCE(u.alias, 'Anonymous student') as complainant_name,
                       a.full_name as assigned_name, a.role as assigned_role
                FROM complaints c
                INNER JOIN users u ON c.complainant_id = u.user_id
                LEFT JOIN users a ON c.assigned_to = a.user_id
                WHERE c.complaint_id = :complaint_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':complaint_id' => $complaintId]);
        return $stmt->fetch();
    }

    public function canAccessComplaint($complaint, $userId, $role) {
        if (!$complaint) {
            return false;
        }
        if ($role === 'oswd') {
            return true;
        }
        if (in_array($role, staffRoles(), true)) {
            // The current holder, or staff who handled it before and escalated
            // it (they keep a read-only view, see canManageComplaint).
            return (int)($complaint['assigned_to'] ?? 0) === (int)$userId
                || (in_array($role, officeRoles(), true) && ($complaint['current_level'] ?? '') === $role)
                || $this->hasHandled((int)$complaint['complaint_id'], $userId);
        }
        return (int)$complaint['complainant_id'] === (int)$userId;
    }

    /** Did this staff member escalate the complaint onward (so they handled it before)? */
    public function hasHandled($complaintId, $userId) {
        $stmt = $this->db->prepare('SELECT 1 FROM complaint_escalations WHERE complaint_id = :c AND escalated_by = :u LIMIT 1');
        $stmt->execute([':c' => (int)$complaintId, ':u' => (int)$userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Who may post a comment: the student who filed it, and the staff member
     * who holds it now. Everyone else (e.g. a coordinator after escalating,
     * or OSWD looking at a complaint still at a lower level) is read-only.
     */
    public function canComment($complaint, $userId, $role) {
        if ($role === 'student') {
            return $complaint && (int)$complaint['complainant_id'] === (int)$userId;
        }
        return $this->canManageComplaint($complaint, $userId, $role);
    }

    public function canManageComplaint($complaint, $userId, $role) {
        if (!$complaint) {
            return false;
        }
        if (in_array($role, staffRoles(), true)) {
            if (($complaint['current_level'] ?? '') !== $role) {
                return false;
            }
            if (in_array($role, officeRoles(), true)) {
                return true;   // shared office: any Guidance / OSWD account
            }
            return (int)($complaint['assigned_to'] ?? 0) === (int)$userId;
        }
        return false;
    }

    public function canEscalate($complaint, $userId, $role) {
        if (!$this->canManageComplaint($complaint, $userId, $role)) {
            return false;
        }
        if (($complaint['complaint_type'] ?? 'behavioral') === 'services') {
            return false;
        }
        // Pending = not looked at yet: the handler must change the status
        // (e.g. to Under review) before they can escalate it.
        if (in_array($complaint['status'], ['pending', 'resolved', 'rejected'], true)) {
            return false;
        }
        return nextWorkflowLevel($complaint['current_level'] ?? '') !== null;
    }
    
    public function getComplaintsByUser($userId) {
        $sql = "SELECT c.*, a.full_name as assigned_name
                FROM complaints c
                LEFT JOIN users a ON c.assigned_to = a.user_id
                WHERE c.complainant_id = :user_id
                ORDER BY c.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getAssignedComplaints($userId, $role = null) {
        // Guidance is one shared office: every complaint at its level is listed.
        $office = $role !== null && in_array($role, officeRoles(), true) ? $role : null;
        $sql = "SELECT c.*, COALESCE(u.alias, 'Anonymous student') as complainant_name, a.full_name as assigned_name
                FROM complaints c
                INNER JOIN users u ON c.complainant_id = u.user_id
                LEFT JOIN users a ON c.assigned_to = a.user_id
                WHERE c.assigned_to = :user_id
                   OR (:office <> '' AND c.current_level = :office2)
                   OR EXISTS (SELECT 1 FROM complaint_escalations e WHERE e.complaint_id = c.complaint_id AND e.escalated_by = :handler)
                ORDER BY c.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':handler' => $userId, ':office' => (string)$office, ':office2' => (string)$office]);
        // Escalated onward by this user: still listed, but view only.
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['view_only'] = (int)($row['assigned_to'] ?? 0) !== (int)$userId
                && !($office && ($row['current_level'] ?? '') === $office);
        }
        unset($row);
        return $rows;
    }

    /**
     * "Escalated to me" for a shared office (OSWD): complaints that reached
     * this level by escalation (not the ones routed here directly, such as
     * Campus Services / Facilities), from every department and program.
     */
    public function getEscalatedToOffice($role) {
        $sql = "SELECT c.*, COALESCE(u.alias, 'Anonymous student') as complainant_name, a.full_name as assigned_name, 0 AS view_only
                FROM complaints c
                INNER JOIN users u ON c.complainant_id = u.user_id
                LEFT JOIN users a ON c.assigned_to = a.user_id
                WHERE c.current_level = :role
                  AND EXISTS (SELECT 1 FROM complaint_escalations e INNER JOIN users t ON t.user_id = e.escalated_to
                              WHERE e.complaint_id = c.complaint_id AND t.role = :role2)
                ORDER BY c.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':role' => $role, ':role2' => $role]);
        return $stmt->fetchAll();
    }

    /** Campus Services & Facilities complaints (they go directly to OSWD). */
    public function getServiceComplaints() {
        $sql = "SELECT c.*, COALESCE(u.alias, 'Anonymous student') as complainant_name, a.full_name as assigned_name, 0 AS view_only
                FROM complaints c
                INNER JOIN users u ON c.complainant_id = u.user_id
                LEFT JOIN users a ON c.assigned_to = a.user_id
                WHERE c.complaint_type = 'services'
                ORDER BY c.created_at DESC";
        return $this->db->query($sql)->fetchAll();
    }

    /** Is the typed "person involved" this staff account? (personNameMatches in config.php) */
    private function namesStaff($typedName, $staffId) {
        $staff = (new User())->getUserById((int)$staffId);
        return $staff && personNameMatches($typedName, $staff['full_name'] ?? '', $staff['username'] ?? '');
    }

    public function getComplaintsForDashboard($userId, $role) {
        if ($role === 'student') {
            return $this->getComplaintsByUser($userId);
        }
        if ($role === 'oswd') {
            return $this->getAllComplaints();
        }
        return $this->getAssignedComplaints($userId, $role);
    }
    
    public function getAllComplaints($filters = []) {
        $sql = "SELECT c.*, COALESCE(u.alias, 'Anonymous student') as complainant_name FROM complaints c
                INNER JOIN users u ON c.complainant_id = u.user_id WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND c.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        $sql .= " ORDER BY c.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateStatus($complaintId, $newStatus, $userId, $notifyStudent = true) {
        if (!in_array($newStatus, allowedComplaintStatuses(), true)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        $complaint = $this->getComplaintById($complaintId);
        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found'];
        }

        $allowed = selectableStatusesForUpdate($complaint['status'] ?? 'pending');
        if (!in_array($newStatus, $allowed, true)) {
            return ['success' => false, 'message' => 'That status change is not allowed from the current state.'];
        }

        if (($complaint['status'] ?? '') === $newStatus) {
            return ['success' => true];
        }

        // A complaint can only be resolved after staff uploaded proof AND the
        // student approved it (see ResolutionProof). Enforced here, on the server.
        if ($newStatus === 'resolved' && !(new ResolutionProof())->canResolve($complaintId)) {
            return ['success' => false, 'message' => 'Upload proof of the resolution first. The complaint can be resolved once the student approves it.'];
        }

        try {
            $sql = "UPDATE complaints SET status = :status WHERE complaint_id = :complaint_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':status' => $newStatus, ':complaint_id' => $complaintId]);
            $this->addTimeline($complaintId, $userId, 'status_changed', 'Status updated to ' . formatStatus($newStatus));

            if ($notifyStudent) {
                $this->notifyComplainant(
                    (int)$complaintId,
                    'Status updated',
                    'Complaint #' . $complaintId . ' is now ' . formatStatus($newStatus) . '.',
                    (int)$userId
                );
            }

            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Failed to update status. Please try again.'];
        }
    }
    
    public function addComment($complaintId, $userId, $comment) {
        $comment = trim((string)$comment);
        if ($comment === '') {
            return ['success' => false, 'message' => 'Message cannot be empty'];
        }
        $complaint = $this->getComplaintById($complaintId);
        $author = (new User())->getUserById((int)$userId);
        if (!$complaint || !$author || !$this->canComment($complaint, $userId, $author['role'])) {
            return ['success' => false, 'message' => 'You can only view this complaint, not chat on it.'];
        }
        // Private thread: a staff comment belongs to its author's conversation;
        // a student's reply goes to whoever holds the complaint now.
        $staffId = $author['role'] === 'student' ? (int)($complaint['assigned_to'] ?? 0) : (int)$userId;

        $sql = "INSERT INTO complaint_comments (complaint_id, user_id, staff_id, comment_text) 
                VALUES (:complaint_id, :user_id, :staff_id, :comment_text)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':complaint_id' => $complaintId,
            ':user_id' => $userId,
            ':staff_id' => $staffId ?: null,
            ':comment_text' => $comment
        ]);

        if ($complaint && (int)$complaint['complainant_id'] !== (int)$userId) {
            $preview = strlen($comment) > 120 ? substr($comment, 0, 117) . '...' : $comment;
            $this->notifyComplainant(
                (int)$complaintId,
                'New chat message on your complaint',
                'Complaint #' . $complaintId . ': ' . $preview,
                (int)$userId
            );
        }
        // The staff member holding the complaint hears about the student's replies
        // (and about comments from other staff) through the bell too.
        if ($complaint && !empty($complaint['assigned_to'])) {
            $preview = strlen($comment) > 120 ? substr($comment, 0, 117) . '...' : $comment;
            try {
                foreach ($this->staffToNotify((int)$complaint['assigned_to'], $complaint['current_level'] ?? null, (int)$userId) as $recipient) {
                    (new Notification())->create($recipient, (int)$complaintId,
                        'New chat message on complaint #' . $complaintId, $preview);
                }
            } catch (PDOException $e) {
                // Notifications must not block comments.
            }
        }

        return ['success' => true];
    }
    
    /**
     * Comments are private conversations between the student and ONE staff
     * member (complaint_comments.staff_id):
     *  - the student sees all their conversations;
     *  - a staff member sees only their own (OSWD admins share the office's);
     *  - with no viewer given (Super Admin oversight), everything.
     */
    public function getComments($complaintId, $viewerId = null, $viewerRole = null) {
        // Students appear by alias in comments too, so staff cannot learn the name here.
        $sql = "SELECT c.*, " . self::DISPLAY_NAME_SQL . " AS full_name, u.role,
                       s.full_name AS thread_with_name, s.role AS thread_with_role
                FROM complaint_comments c
                INNER JOIN users u ON c.user_id = u.user_id
                LEFT JOIN users s ON c.staff_id = s.user_id
                WHERE c.complaint_id = :complaint_id";
        $params = [':complaint_id' => $complaintId];
        if ($viewerRole !== null && $viewerRole !== 'student' && $viewerRole !== 'super_admin') {
            if (in_array($viewerRole, officeRoles(), true)) {
                // Shared office: all its accounts read the office's conversation.
                $sql .= " AND s.role = :office";
                $params[':office'] = $viewerRole;
            } else {
                $sql .= " AND c.staff_id = :viewer";
                $params[':viewer'] = (int)$viewerId;
            }
        }
        $sql .= " ORDER BY c.created_at ASC, c.comment_id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function escalateComplaint($complaintId, $escalatedBy, $reason, $role) {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Escalation reason is required'];
        }

        $complaint = $this->getComplaintById($complaintId);
        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found'];
        }
        if (!$this->canEscalate($complaint, $escalatedBy, $role)) {
            return ['success' => false, 'message' => 'You cannot escalate this complaint'];
        }

        $nextLevel = nextWorkflowLevel($complaint['current_level'] ?? '');
        if ($nextLevel === null) {
            return ['success' => false, 'message' => 'No further escalation level is available'];
        }

        try {
            if (!$this->resolveRoute($nextLevel, $complaint['department'] ?? null, $complaint['program'] ?? null)) {
                return ['success' => false, 'message' => 'No active staff account is available at the next level'];
            }

            $this->db->beginTransaction();

            // Forward within the complaint's department (see resolveRoute).
            $route = $this->assignToRole($complaintId, $nextLevel, $escalatedBy, false, $complaint['department'] ?? null, $complaint['program'] ?? null);

            $sql = "INSERT INTO complaint_escalations (complaint_id, escalated_by, escalated_to, escalation_reason)
                    VALUES (:complaint_id, :escalated_by, :escalated_to, :reason)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':complaint_id' => $complaintId,
                ':escalated_by' => $escalatedBy,
                ':escalated_to' => $route['user']['user_id'],
                ':reason' => $reason
            ]);

            // The next office starts fresh: the case is Pending again for them,
            // just like a new case for the Program Coordinator.
            $this->db->prepare("UPDATE complaints SET status = 'pending' WHERE complaint_id = :id")
                ->execute([':id' => $complaintId]);
            $label = roleLabel($route['level']);
            $this->addTimeline(
                $complaintId,
                $escalatedBy,
                'escalated',
                "Escalated to {$label}" . $this->skippedNote($route, $complaint['department'] ?? null) . " — status back to Pending: {$reason}"
            );

            $this->db->commit();

            $this->notifyComplainant(
                (int)$complaintId,
                'Complaint forwarded',
                "Your complaint #{$complaintId} was escalated to {$label} for further review.",
                (int)$escalatedBy
            );

            return ['success' => true];
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to escalate complaint. Please try again.'];
        }
    }

    /* ------------------------------------------------------------------
     * DEPARTMENT-BASED ROUTING
     *
     * Program Coordinator and Department Chairperson belong to ONE department,
     * so a complaint goes to the one in the complainant's department.
     * Guidance and OSWD serve the whole campus.
     *
     * Order of preference at a department level:
     *   1. active staff of that role in the complaint's department
     *   2. active staff of that role with NO department set (campus-wide
     *      backup — keeps older accounts working until they are assigned)
     *   3. nobody → skip up to the next level (e.g. Coordinator → Chairperson)
     * The chain always ends at OSWD, so a complaint is never left unassigned.
     * ------------------------------------------------------------------ */

    /** The complainant's department, if it is one of the official departments. */
    private function complainantDepartment($userId) {
        $stmt = $this->db->prepare('SELECT department FROM users WHERE user_id = :id');
        $stmt->execute([':id' => (int)$userId]);
        $department = strtoupper(trim((string)$stmt->fetchColumn()));   // "dcs" → "DCS"
        return in_array($department, departments(), true) ? $department : null;
    }

    /** The complainant's program in its official spelling (see canonicalProgram), or null. */
    private function complainantProgram($userId, $department) {
        $stmt = $this->db->prepare('SELECT program FROM users WHERE user_id = :id');
        $stmt->execute([':id' => (int)$userId]);
        return canonicalProgram($department, $stmt->fetchColumn());
    }

    /**
     * One staff member at a level who can CURRENTLY handle complaints:
     * active and not archived. Matches the complaint's department, and for
     * the Program Coordinator also its program.
     */
    private function findActiveUserForWorkflowLevel($level, $department = null, $program = null) {
        if (!in_array($level, departmentRoles(), true)) {
            // Campus-wide level: first active account of that role.
            $stmt = $this->db->prepare(
                "SELECT user_id, full_name FROM users WHERE role = :role AND status = 'active' ORDER BY user_id ASC LIMIT 1"
            );
            $stmt->execute([':role' => $level]);
            return $stmt->fetch() ?: null;
        }

        // DEPARTMENT CHAIRPERSON: one per department (program is not used).
        // PROGRAM COORDINATOR: the one of the student's own program. Order: same department + same program → same department
        // with no program set (covers the whole department) → campus-wide backup.
        // Staff of a DIFFERENT program are never chosen.
        // A student with no recognised program goes to a department-wide or
        // campus-wide one; any of the department only as a last resort.
        // (The Super Admin account form prevents two active staff covering the
        // same level + department + program, so the choice is never ambiguous.)
        if (in_array($level, departmentRoles(), true)) {
            $stmt = $this->db->prepare(
                "SELECT user_id, full_name, department, program FROM users
                 WHERE role = :role AND status = 'active'
                   AND (department = :d1 OR department IS NULL OR department = '')
                 ORDER BY (department = :d2) DESC, user_id ASC"
            );
            $stmt->execute([':role' => $level, ':d1' => (string)$department, ':d2' => (string)$department]);
            // Coordinators type their program too, so compare the official
            // spelling ("IT" and "Information Technology" are the same program).
            $exact = $wholeDepartment = $anyInDepartment = null;
            $byProgram = in_array($level, programRoles(), true);
            if (!$byProgram) {
                $program = null;
            }
            if ($program && $department) {
                $program = canonicalProgram($department, $program) ?? $program;
            }
            foreach ($stmt->fetchAll() as $c) {
                $theirProgram = $byProgram ? trim((string)($c['program'] ?? '')) : '';
                $sameDepartment = $department !== null && $c['department'] === $department;
                if ($program && $sameDepartment && $theirProgram !== ''
                    && canonicalProgram($department, $theirProgram) === $program) {
                    $exact = $exact ?? $c;
                } elseif ($theirProgram === '' || !$sameDepartment) {
                    // No program = covers the whole department (or, with no
                    // department, the whole campus).
                    $wholeDepartment = $wholeDepartment ?? ($theirProgram === '' ? $c : null);
                } elseif ($sameDepartment) {
                    $anyInDepartment = $anyInDepartment ?? $c;
                }
            }
            return $program
                ? ($exact ?? $wholeDepartment)
                : ($wholeDepartment ?? $anyInDepartment);
        }

        // Department level: same department first, then campus-wide backup.
        $stmt = $this->db->prepare(
            "SELECT user_id, full_name FROM users
             WHERE role = :role AND status = 'active'
               AND (department = :department OR department IS NULL OR department = '')
             ORDER BY (department = :department2) DESC, user_id ASC LIMIT 1"
        );
        $stmt->execute([':role' => $level, ':department' => (string)$department, ':department2' => (string)$department]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Starting at $level, find who should receive the complaint, moving up
     * the chain past any level with nobody available.
     * Returns ['level' => ..., 'user' => [...], 'skipped' => [levels]] or null.
     */
    private function resolveRoute($level, $department, $program = null) {
        $skipped = [];
        while ($level !== null) {
            $user = $this->findActiveUserForWorkflowLevel($level, $department, $program);
            if ($user) {
                return ['level' => $level, 'user' => $user, 'skipped' => $skipped];
            }
            $skipped[] = $level;
            $level = nextWorkflowLevel($level);
        }
        return null;
    }

    /** Text such as " (no Program Coordinator for DCS)" for the history. */
    private function skippedNote(array $route, $department) {
        if (!$route['skipped']) {
            return '';
        }
        $labels = implode(' or ', array_map('roleLabel', $route['skipped']));
        return ' (no ' . $labels . ' for ' . ($department ?: 'this department') . ')';
    }

    /** Assign the complaint to the resolved person and level. Returns the route. */
    private function assignToRole($complaintId, $level, $actionBy, $isInitial, $department = null, $program = null) {
        $route = $this->resolveRoute($level, $department, $program);
        if (!$route) {
            throw new PDOException('No assignee for workflow level: ' . $level);
        }

        // Conflict of interest: a Program Coordinator or Department Chairperson
        // named by the student as the person involved never receives the
        // complaint; it moves up to the next level (on submit and on escalation).
        $named = (string)($this->db->query('SELECT respondent_name FROM complaints WHERE complaint_id = ' . (int)$complaintId)->fetchColumn() ?: '');
        while ($named !== '' && in_array($route['level'], departmentRoles(), true)
               && $this->namesStaff($named, (int)$route['user']['user_id'])) {
            $this->addTimeline($complaintId, $actionBy, 'assigned',
                roleLabel($route['level']) . ' skipped: the complaint names them as the person involved');
            $next = nextWorkflowLevel($route['level']);
            $route = $next ? $this->resolveRoute($next, $department, $program) : null;
            if (!$route) {
                throw new PDOException('No assignee after skipping the named staff member');
            }
        }

        $sql = "UPDATE complaints SET assigned_to = :assigned_to, current_level = :current_level WHERE complaint_id = :complaint_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':assigned_to' => $route['user']['user_id'],
            ':current_level' => $route['level'],
            ':complaint_id' => $complaintId
        ]);

        // Tell the staff member who now holds the complaint (navbar bell).
        $this->notifyStaff((int)$route['user']['user_id'], (int)$complaintId, $isInitial, $route['level']);

        if ($isInitial) {
            $label = roleLabel($route['level']);
            $where = $department && in_array($route['level'], departmentRoles(), true) ? " — {$department}" : '';
            if ($where !== '' && $program) {
                $where .= " · {$program}";
            }
            $this->addTimeline(
                $complaintId,
                $actionBy,
                'assigned',
                "Routed to {$label} ({$route['user']['full_name']}){$where}" . $this->skippedNote($route, $department)
            );
        }
        return $route;
    }
    
    /** Notify the staff member a complaint was routed or escalated to. Never blocks the workflow. */
    private function notifyStaff($staffId, $complaintId, $isInitial, $level = null) {
        try {
            foreach ($this->staffToNotify($staffId, $level) as $recipient) {
                (new Notification())->create($recipient, $complaintId,
                    $isInitial ? 'New complaint assigned to you' : 'Complaint escalated to you',
                    'Complaint #' . $complaintId . ($isInitial ? ' was routed to you.' : ' was escalated to you for review.'));
            }
        } catch (PDOException $e) {
            // Notifications must not block complaint workflow.
        }
    }

    /** The holder, or every active account of a shared office (Guidance / OSWD). */
    private function staffToNotify($staffId, $level, $exceptUserId = 0) {
        $ids = [(int)$staffId];
        if ($level !== null && in_array($level, officeRoles(), true)) {
            $stmt = $this->db->prepare("SELECT user_id FROM users WHERE role = :r AND status = 'active'");
            $stmt->execute([':r' => $level]);
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        return array_values(array_diff(array_unique(array_filter($ids)), [(int)$exceptUserId]));
    }

    private function notifyComplainant($complaintId, $title, $message, $actorUserId = null) {
        if (!class_exists('Notification')) {
            return;
        }

        $complaint = $this->getComplaintById($complaintId);
        if (!$complaint) {
            return;
        }

        $complainantId = (int)$complaint['complainant_id'];
        if ($actorUserId !== null && $complainantId === (int)$actorUserId) {
            return;
        }

        try {
            $notification = new Notification();
            $notification->create($complainantId, $complaintId, $title, $message);
        } catch (PDOException $e) {
            // Notifications must not block complaint workflow.
        }
    }

    private function addTimeline($complaintId, $userId, $actionType, $description) {
        $sql = "INSERT INTO complaint_timeline (complaint_id, action_by, action_type, action_description) 
                VALUES (:complaint_id, :action_by, :action_type, :description)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':complaint_id' => $complaintId,
            ':action_by' => $userId,
            ':action_type' => $actionType,
            ':description' => $description
        ]);
    }
    
    
    public function getTimeline($complaintId) {
        // Same rule for the routing history: student actions show the alias.
        $sql = "SELECT t.*, " . self::DISPLAY_NAME_SQL . " AS full_name FROM complaint_timeline t
                INNER JOIN users u ON t.action_by = u.user_id
                WHERE t.complaint_id = :complaint_id ORDER BY t.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':complaint_id' => $complaintId]);
        return $stmt->fetchAll();
    }
    
    public function getStatistics() {
        $sql = "SELECT status, COUNT(*) as count FROM complaints GROUP BY status";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();
        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }
        return $stats;
    }
}
