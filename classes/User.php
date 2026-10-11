<?php
/**
 * User Class - Handles authentication and user management
 */
class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function register($data) {
        try {
            $username = trim($data['username'] ?? '');
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';
            $fullName = trim($data['full_name'] ?? '');
            $role = $data['role'] ?? 'student';

            // The student types only a password; everything else comes from
            // OSWD's enrolled record below (username = Student ID).
            if (trim((string)($data['student_id'] ?? '')) === '' || $password === '') {
                return ['success' => false, 'message' => 'Please fill in all required fields.'];
            }

            // Registration can be closed by the Super Admin (Settings).
            if (!REGISTRATION_OPEN || MAINTENANCE_MODE) {
                return ['success' => false, 'message' => 'Student registration is currently closed.'];
            }

            // Only students on OSWD's enrolled list may register, and each
            // Student ID can have one account. This is checked here (not only
            // in register.php) so no other route can skip it.
            $enrollment = new EnrollmentList();
            $check = $enrollment->verifyForRegistration($data['student_id'] ?? '');
            if (!$check['ok']) {
                return ['success' => false, 'message' => $check['message']];
            }
            $data['student_id'] = EnrollmentList::normalizeId($data['student_id']);
            // Students register with their Student ID: every detail comes from
            // OSWD's enrolled record and cannot be typed or changed by them.
            $record = $check['record'];
            $data['full_name'] = $record['full_name'];
            $data['department'] = $record['department'];
            $data['program'] = $record['program'];
            $data['email'] = $record['email'];
            $data['contact_number'] = (string)($record['contact_number'] ?? '');
            $data['username'] = $data['student_id'];
            $username = $data['username'];
            $email = $data['email'];
            $fullName = $data['full_name'];

            // Complaints are routed by the student's department and program, so
            // both must be official (chosen from dropdowns). The program is saved
            // in its official spelling so it matches the Program Coordinator's.
            $program = canonicalProgram($data['department'] ?? '', $data['program'] ?? '');
            if ($program === null && in_array($data['department'] ?? '', departments(), true)) {
                return ['success' => false, 'message' => 'Please choose your program from the list.'];
            }
            $data['program'] = $program;

            if (!in_array($data['department'] ?? '', departments(), true)) {
                return ['success' => false, 'message' => 'Please choose your department.'];
            }

            // Same password rules as every other account (passwordPolicyErrors).
            $passwordErrors = passwordPolicyErrors($password, $username, $email);
            if ($passwordErrors) {
                return ['success' => false, 'message' => 'Password: ' . implode(' ', $passwordErrors)];
            }

            // Public registration is limited to students to prevent privilege escalation
            if ($role !== 'student') {
                $role = 'student';
            }

            // Every student gets a random alias so personnel never see the real name.
            $sql = "INSERT INTO users (username, email, password, full_name, alias, role, student_id,
                    department, program, contact_number)
                    VALUES (:username, :email, :password, :full_name, :alias, :role, :student_id,
                    :department, :program, :contact_number)";
            
            $stmt = $this->db->prepare($sql);
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => $hashedPassword,
                ':full_name' => $fullName,
                ':alias' => $this->generateAlias(),
                ':role' => $role,
                ':student_id' => emptyToNull($data['student_id'] ?? null),
                ':department' => emptyToNull($data['department'] ?? null),
                ':program' => emptyToNull($data['program'] ?? null),
                ':contact_number' => emptyToNull($data['contact_number'] ?? null)
            ]);
            
            return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['success' => false, 'message' => 'Username or email already exists.'];
            }
            return ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }
    }
    
    public function login($username, $password) {
        $auth = new LoginAuth();
        return $auth->attempt($username, $password);
    }
    
    public function getUserById($userId) {
        try {
            $sql = "SELECT * FROM users WHERE user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Accounts for the OSWD Users page. $archived = false → current accounts,
     * true → archived ones. open_assigned = open complaints assigned to that
     * person (shown as a warning before archiving staff).
     */
    public function getAllUsers($archived = false) {
        try {
            $sql = "SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.student_id, u.department, u.program,
                           u.contact_number, u.status, u.created_at, u.archived_at, a.username AS archived_by_username,
                           (SELECT COUNT(*) FROM complaints c
                             WHERE c.assigned_to = u.user_id AND c.status NOT IN ('resolved', 'rejected')) AS open_assigned
                    FROM users u
                    LEFT JOIN users a ON a.user_id = u.archived_by
                    WHERE u.archived_at IS " . ($archived ? 'NOT NULL ORDER BY u.archived_at DESC' : 'NULL ORDER BY u.created_at DESC');
            $stmt = $this->db->query($sql);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Archive an account (OSWD Users page). Replaces deleting: the person
     * can no longer sign in, but their complaints, history and audit trail
     * are all kept, and the account can be restored.
     */
    public function archiveUser($targetUserId, $actorUserId) {
        $targetUserId = (int)$targetUserId;
        $user = $this->getUserById($targetUserId);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        if ($targetUserId === (int)$actorUserId) {
            return ['success' => false, 'message' => 'You cannot archive your own account.'];
        }
        if (!empty($user['archived_at'])) {
            return ['success' => false, 'message' => 'That account is already archived.'];
        }
        if (($user['role'] ?? '') === 'super_admin') {
            return ['success' => false, 'message' => 'Super Admin accounts cannot be archived here.'];
        }
        // Service complaints are routed to OSWD, so at least one must stay active.
        if (($user['role'] ?? '') === 'oswd' && ($user['status'] ?? '') === 'active') {
            $count = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'oswd' AND status = 'active' AND archived_at IS NULL")->fetchColumn();
            if ($count <= 1) {
                return ['success' => false, 'message' => 'Cannot archive the last active OSWD administrator account.'];
            }
        }

        $this->db->prepare(
            "UPDATE users SET archived_at = NOW(), archived_by = :actor, status = 'inactive', is_signed_in = 0
             WHERE user_id = :id"
        )->execute([':actor' => (int)$actorUserId, ':id' => $targetUserId]);

        (new AuditLog())->record('user_archive', 'user', $targetUserId, $user['username'], null, ['role' => $user['role']]);
        return ['success' => true, 'message' => 'Account ' . $user['username'] . ' was archived. It can be restored from the Archived tab.'];
    }

    /** Undo an archive: the account becomes active and visible again. */
    public function restoreUser($targetUserId) {
        $user = $this->getUserById((int)$targetUserId);
        if (!$user || empty($user['archived_at'])) {
            return ['success' => false, 'message' => 'Archived account not found.'];
        }
        $this->db->prepare("UPDATE users SET archived_at = NULL, archived_by = NULL, status = 'active' WHERE user_id = :id")
            ->execute([':id' => (int)$targetUserId]);

        (new AuditLog())->record('user_unarchive', 'user', (int)$targetUserId, $user['username'], null, ['role' => $user['role']]);
        return ['success' => true, 'message' => 'Account ' . $user['username'] . ' was restored and can sign in again.'];
    }
    
    public function deleteUser($targetUserId, $actorUserId) {
        $targetUserId = (int)$targetUserId;
        $actorUserId = (int)$actorUserId;

        if ($targetUserId <= 0) {
            return ['success' => false, 'message' => 'Invalid user account.'];
        }

        if ($targetUserId === $actorUserId) {
            return ['success' => false, 'message' => 'You cannot delete your own account while signed in.'];
        }

        $user = $this->getUserById($targetUserId);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        // Only the Super Admin manages Super Admin accounts (see super_admin_users.php).
        if (($user['role'] ?? '') === 'super_admin') {
            return ['success' => false, 'message' => 'Super Admin accounts cannot be deleted here.'];
        }

        if (($user['role'] ?? '') === 'oswd') {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM users WHERE role = 'oswd' AND status = 'active'"
            );
            if ((int)$stmt->fetchColumn() <= 1) {
                return [
                    'success' => false,
                    'message' => 'Cannot delete the last active OSWD administrator account.',
                ];
            }
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM users WHERE user_id = :user_id');
            $stmt->execute([':user_id' => $targetUserId]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'User could not be deleted.'];
            }

            return [
                'success' => true,
                'message' => 'Account for ' . ($user['username'] ?? 'user') . ' was deleted.',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to delete account. Please try again or contact support.',
            ];
        }
    }

    /**
     * Random, unique student alias such as "Student-7F3KQ2".
     * Characters 0/O/1/I are left out so aliases are easy to read aloud.
     */
    public function generateAlias() {
        $check = $this->db->prepare('SELECT 1 FROM users WHERE alias = :alias');
        do {
            $alias = 'Student-';
            for ($i = 0; $i < 6; $i++) {
                $alias .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
            }
            $check->execute([':alias' => $alias]);
        } while ($check->fetch());
        return $alias;
    }

    /**
     * A signed-in user changes their own password (Profile page, every role).
     * The current password must be correct, and the new one must pass the
     * same rules as accounts created by the Super Admin (passwordPolicyErrors).
     * The password itself is never logged or emailed.
     */
    public function changePassword($userId, $current, $new, $confirm) {
        $user = $this->getUserById((int)$userId);
        if (!$user) {
            return ['success' => false, 'errors' => ['Account not found.']];
        }
        if (!password_verify((string)$current, $user['password'])) {
            return ['success' => false, 'errors' => ['Your current password is incorrect.']];
        }
        $errors = passwordPolicyErrors($new, $user['username'], $user['email']);
        if ((string)$new !== (string)$confirm) {
            $errors[] = 'The new passwords do not match.';
        }
        if (password_verify((string)$new, $user['password'])) {
            $errors[] = 'Choose a password different from your current one.';
        }
        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->prepare('UPDATE users SET password = :p WHERE user_id = :id')
            ->execute([':p' => password_hash($new, PASSWORD_BCRYPT), ':id' => (int)$userId]);
        // Clear any sign-in lockout on the account.
        (new AccountLockout())->clearForUser((int)$userId, strtolower($user['username']));
        (new AuditLog())->record('password_change', 'user', (int)$userId, $user['username'], null, ['role' => $user['role']]);

        // Let the person know, in case it was not them (best effort).
        if (filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            Mailer::send($user['email'], $user['full_name'] ?? '', 'Your password was changed',
                'Hello ' . ($user['full_name'] ?? '') . ",\n\nThe password of your " . SITE_NAME . ' account (' . $user['username'] . ') was changed on '
                . date('M j, Y g:i A') . ".\n\nIf you did not do this, contact OSWD immediately.");
        }
        return ['success' => true, 'message' => 'Your password was changed.'];
    }

    public function updateProfile($userId, $data) {
        try {
            $current = $this->getUserById($userId);

            // Students' profiles are read only (their details come from
            // registration and OSWD's enrolled list); only the Super Admin
            // can correct them, through User management.
            if (($current['role'] ?? '') === 'student') {
                return ['success' => false, 'message' => 'Your profile is read only. Contact OSWD to correct your details.'];
            }

            // Personnel department/program decide which complaints they
            // receive, so only the Super Admin may change them.
            $department = $current['department'] ?? null;
            $program = $current['program'] ?? null;

            $sql = "UPDATE users SET full_name = :full_name, email = :email, contact_number = :contact_number,
                    department = :department, program = :program WHERE user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':full_name' => trim($data['full_name'] ?? ''),
                ':email' => trim($data['email'] ?? ''),
                ':contact_number' => emptyToNull($data['contact_number'] ?? null),
                ':department' => $department,
                ':program' => $program,
                ':user_id' => $userId
            ]);
            return ['success' => true, 'message' => 'Profile updated successfully'];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['success' => false, 'message' => 'Email is already in use by another account.'];
            }
            return ['success' => false, 'message' => 'Failed to update profile.'];
        }
    }
}
