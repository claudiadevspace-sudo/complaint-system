<?php

class AccountLockout {
    private $db;
    private $notifications;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->notifications = new Notification();
    }

    public function normalizeKey($username) {
        return strtolower(trim((string)$username));
    }

    public function findUser($username) {
        $username = trim((string)$username);
        if ($username === '') {
            return null;
        }
        $sql = "SELECT * FROM users
                WHERE username = :username OR email = :email OR (role = 'student' AND student_id = :student_id)
                ORDER BY (username = :username2) DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username, ':email' => $username, ':username2' => $username,
            ':student_id' => EnrollmentList::normalizeId($username)]);
        return $stmt->fetch() ?: null;
    }

    public function inspect($username) {
        $user = $this->findUser($username);
        $key = $user ? $this->normalizeKey($user['username']) : $this->normalizeKey($username);
        $row = $this->getRow($key, $user ? (int)$user['user_id'] : null);
        $now = time();

        if ($row) {
            $blockedUntil = !empty($row['blocked_until']) ? strtotime($row['blocked_until']) : 0;
            if ($blockedUntil > $now) {
                return $this->gateResult('blocked', $blockedUntil - $now, $user, $row);
            }
            if ($blockedUntil > 0) {
                $this->clearForUser((int)($row['user_id'] ?? 0), $row['username_key'] ?? $key);
                $row = $this->getRow($key, $user ? (int)$user['user_id'] : null);
            }
            $cooldownUntil = !empty($row['cooldown_until']) ? strtotime($row['cooldown_until']) : 0;
            if ($cooldownUntil > $now) {
                return $this->gateResult('cooldown', $cooldownUntil - $now, $user, $row);
            }
        }

        return [
            'allowed' => true,
            'type' => null,
            'retry_after' => 0,
            'user' => $user,
            'row' => $row,
            'key' => $key,
            'show_student_service' => false,
            'message' => '',
        ];
    }

    public function recordFailure($username, $user = null) {
        $user = $user ?: $this->findUser($username);
        $key = $user ? $this->normalizeKey($user['username']) : $this->normalizeKey($username);
        $userId = $user ? (int)$user['user_id'] : null;
        $row = $this->upsertRow($key, $userId);
        $fails = (int)$row['consecutive_fails'] + 1;
        $cooldownUntil = null;
        $blockedUntil = null;
        $type = 'invalid';

        if ($fails >= LOGIN_BLOCK_AFTER_FAILS) {
            $blockedUntil = date('Y-m-d H:i:s', time() + LOGIN_BLOCK_SECONDS);
            $type = 'blocked';
        } elseif ($fails >= LOGIN_COOLDOWN_AFTER_FAILS) {
            $cooldownUntil = date('Y-m-d H:i:s', time() + LOGIN_COOLDOWN_SECONDS);
            $type = 'cooldown';
        }

        $sql = "UPDATE login_lockouts
                SET consecutive_fails = :fails,
                    cooldown_until = :cooldown_until,
                    blocked_until = :blocked_until,
                    last_failed_at = NOW(),
                    user_id = COALESCE(:user_id, user_id)
                WHERE lockout_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':fails' => $fails,
            ':cooldown_until' => $cooldownUntil,
            ':blocked_until' => $blockedUntil,
            ':user_id' => $userId,
            ':id' => $row['lockout_id'],
        ]);

        $updated = $this->getRowById((int)$row['lockout_id']);
        if ($type === 'blocked') {
            $this->createAutoBlockReview($updated, $user, $username);
        }

        $retryAfter = 0;
        if ($type === 'blocked') {
            $retryAfter = LOGIN_BLOCK_SECONDS;
        } elseif ($type === 'cooldown') {
            $retryAfter = LOGIN_COOLDOWN_SECONDS;
        }

        return [
            'type' => $type,
            'fails' => $fails,
            'retry_after' => $retryAfter,
            'show_student_service' => $type === 'blocked',
            'attempts_left' => max(0, LOGIN_BLOCK_AFTER_FAILS - $fails),
            'message' => $this->failureMessage($type, $retryAfter, max(0, LOGIN_BLOCK_AFTER_FAILS - $fails)),
            'user' => $user,
        ];
    }

    public function recordSuccess($username, $user = null) {
        $user = $user ?: $this->findUser($username);
        $key = $user ? $this->normalizeKey($user['username']) : $this->normalizeKey($username);
        $sql = "UPDATE login_lockouts
                SET consecutive_fails = 0, cooldown_until = NULL, last_failed_at = NULL
                WHERE username_key = :key OR user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':key' => $key,
            ':user_id' => $user ? (int)$user['user_id'] : 0,
        ]);
    }

    public function clearForUser($userId, $usernameKey = '') {
        if ((int)$userId > 0) {
            $sql = "UPDATE login_lockouts
                    SET consecutive_fails = 0, cooldown_until = NULL, blocked_until = NULL, last_failed_at = NULL
                    WHERE user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => (int)$userId]);
            return;
        }
        if ($usernameKey !== '') {
            $sql = "UPDATE login_lockouts
                    SET consecutive_fails = 0, cooldown_until = NULL, blocked_until = NULL, last_failed_at = NULL
                    WHERE username_key = :key";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':key' => $usernameKey]);
        }
    }

    public function submitStudentServiceRequest($username, $message) {
        $username = trim((string)$username);
        $message = trim((string)$message);
        if ($username === '') {
            return ['success' => false, 'message' => 'We could not tell which account is locked. Please try to sign in first.'];
        }
        if ($message === '') {
            return ['success' => false, 'message' => 'Please tell us what happened.'];
        }
        if (strlen($message) > 1000) {
            return ['success' => false, 'message' => 'Please keep your message under 1000 characters.'];
        }

        $user = $this->findUser($username);
        if (!$user) {
            // The lock also applies to names that do not exist (so attackers cannot
            // tell which accounts exist), but there is nothing to unlock here.
            return ['success' => false, 'no_account' => true, 'message' => 'No account uses "' . $username . '". '
                . 'Check that you typed your Student ID or email correctly, or register if you do not have an account yet.'];
        }
        $pending = $this->findPendingReview($user ? (int)$user['user_id'] : null, $this->normalizeKey($username));
        if ($pending) {
            $note = $message !== '' ? $message : $pending['message'];
            $sql = "UPDATE lockout_reviews
                    SET message = :message, source = 'student_service', email = COALESCE(:email, email),
                        full_name = COALESCE(:full_name, full_name), user_id = COALESCE(:user_id, user_id)
                    WHERE review_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':message' => $note,
                ':email' => $user['email'] ?? null,
                ':full_name' => $user['full_name'] ?? null,
                ':user_id' => $user ? (int)$user['user_id'] : null,
                ':id' => $pending['review_id'],
            ]);
            return [
                'success' => true,
                'review_id' => (int)$pending['review_id'],
                'message' => 'Your request was sent to the Super Admin.',
            ];
        }

        $lockout = $this->getRow(
            $user ? $this->normalizeKey($user['username']) : $this->normalizeKey($username),
            $user ? (int)$user['user_id'] : null
        );

        $sql = "INSERT INTO lockout_reviews
                    (lockout_id, user_id, username_attempted, email, full_name, message, source, status)
                VALUES
                    (:lockout_id, :user_id, :username, :email, :full_name, :message, 'student_service', 'pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':lockout_id' => $lockout['lockout_id'] ?? null,
            ':user_id' => $user ? (int)$user['user_id'] : null,
            ':username' => $user['username'] ?? $username,
            ':email' => $user['email'] ?? null,
            ':full_name' => $user['full_name'] ?? null,
            ':message' => $message !== '' ? $message : 'Student requested help unlocking a locked account.',
        ]);

        $reviewId = (int)$this->db->lastInsertId();
        $this->notifySuperAdmins(
            'Unlock request from Student Services',
            'A student asked to unlock a locked account: ' . ($user['username'] ?? $username) . '.'
        );

        return [
            'success' => true,
            'review_id' => $reviewId,
            'message' => 'Your request was sent to the Super Admin.',
        ];
    }

    /**
     * Link requests that have no account yet to the right one, when the typed
     * name, Student ID or the email saved with the request belongs to an account.
     */
    public function linkUnmatchedReviews() {
        try {
            $rows = $this->db->query("SELECT review_id, username_attempted, email FROM lockout_reviews
                                      WHERE user_id IS NULL AND status = 'pending'")->fetchAll();
            foreach ($rows as $row) {
                $user = $this->findUser((string)$row['username_attempted']) ?: $this->findUser((string)$row['email']);
                if ($user) {
                    $this->db->prepare('UPDATE lockout_reviews SET user_id = :u, email = :e, full_name = :n WHERE review_id = :id')
                        ->execute([':u' => $user['user_id'], ':e' => $user['email'], ':n' => $user['full_name'], ':id' => $row['review_id']]);
                }
            }
        } catch (PDOException $e) {
            // Listing still works without the links.
        }
    }

    public function getReviews($status = 'pending') {
        $allowed = ['pending', 'accepted', 'rejected', 'completed', 'all'];
        if (!in_array($status, $allowed, true)) {
            $status = 'pending';
        }

        $sql = "SELECT r.*, u.username AS account_username, u.status AS account_status,
                       a.full_name AS reviewer_name, l.blocked_until, l.consecutive_fails
                FROM lockout_reviews r
                LEFT JOIN users u ON r.user_id = u.user_id
                LEFT JOIN users a ON r.reviewed_by = a.user_id
                LEFT JOIN login_lockouts l ON r.lockout_id = l.lockout_id";
        $params = [];
        if ($status !== 'all') {
            $sql .= " WHERE r.status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY r.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPendingCount() {
        try {
            $sql = "SELECT COUNT(*) FROM lockout_reviews WHERE status = 'pending'";
            return (int)$this->db->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Super Admin approves an unlock request: the system emails a 6-digit
     * unlock code to the account's own email (plus a link to the page where
     * the code is entered, for another device). Entering the code on
     * Student Services unlocks the account and signs the student in.
     */
    public function acceptReview($reviewId, $adminId, $note = '') {
        $review = $this->getReviewById($reviewId);
        if (!$review || $review['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This request is no longer pending.'];
        }
        if (empty($review['user_id'])) {
            return ['success' => false, 'message' => 'This request is not linked to any account (the name typed does not exist), so there is no email to send a code to. Reject it instead.'];
        }
        $user = (new User())->getUserById((int)$review['user_id']);
        if (!$user) {
            return ['success' => false, 'message' => 'The account no longer exists.'];
        }
        $email = trim((string)($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'This account has no valid email address, so no code can be sent.'];
        }

        $code = (string)random_int(100000, 999999);
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + LOGIN_RESET_TTL);

        $this->db->beginTransaction();
        try {
            // Older codes and links for this account stop working.
            $this->db->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
                ->execute([':u' => $user['user_id']]);
            $this->db->prepare('UPDATE credential_resets SET used_at = NOW() WHERE review_id = :id AND used_at IS NULL')
                ->execute([':id' => $reviewId]);
            // The code (stored only as a hash).
            $this->db->prepare('INSERT INTO login_otps (user_id, otp_hash, expires_at) VALUES (:u, :h, :e)')
                ->execute([':u' => $user['user_id'], ':h' => hash('sha256', $code . LOGIN_HASH_SALT), ':e' => $expiresAt]);
            // The link to the code page (stored only as a hash).
            $this->db->prepare('INSERT INTO credential_resets (review_id, user_id, token_hash, expires_at) VALUES (:r, :u, :h, :e)')
                ->execute([':r' => $reviewId, ':u' => $user['user_id'], ':h' => hash('sha256', $token . LOGIN_HASH_SALT), ':e' => $expiresAt]);
            $this->db->prepare(
                "UPDATE lockout_reviews SET status = 'accepted', reviewed_by = :admin, reviewed_at = NOW(), review_note = :note
                 WHERE review_id = :id AND status = 'pending'"
            )->execute([
                ':admin' => $adminId,
                ':note' => trim((string)$note) !== '' ? trim((string)$note) : 'Unlock code sent.',
                ':id' => $reviewId,
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Could not create the unlock code. Please try again.'];
        }

        $hours = max(1, (int)(LOGIN_RESET_TTL / 3600));
        $link = rtrim(SITE_URL, '/') . '/student_services.php?unlock=' . $token;
        $body = SITE_NAME . "\n"
            . "North Eastern Mindanao State University\n\n"
            . 'Hello ' . ($user['full_name'] ?? 'Student') . ",\n\n"
            . "Your request to unlock your account was approved. Your unlock code is:\n\n"
            . "    {$code}\n\n"
            . "Enter it on the Student Services page to open your account. If you are on another device, open this link first:\n"
            . $link . "\n\n"
            . "The code expires in {$hours} hour(s).\n\n"
            . 'If you did not ask for this, contact OSWD immediately.';
        if (!Mailer::send($email, $user['full_name'] ?? '', 'Your account unlock code', $body)) {
            return ['success' => false, 'message' => 'The request was accepted, but the email could not be sent. Check the email settings, then try again.'];
        }
        return ['success' => true, 'message' => 'Request accepted. A 6-digit unlock code was emailed to ' . $this->maskEmail($email) . '.'];
    }

    /** The request a browser is following, for the Student Services page. */
    public function unlockState($reviewId) {
        $review = $this->getReviewById((int)$reviewId);
        if (!$review) {
            return null;
        }
        $user = !empty($review['user_id']) ? (new User())->getUserById((int)$review['user_id']) : null;
        return [
            'review_id' => (int)$review['review_id'],
            'status' => $review['status'],
            'review_note' => $review['review_note'] ?? '',
            'masked_email' => $user ? $this->maskEmail($user['email']) : '',
        ];
    }

    /** The request behind an emailed link (student_services.php?unlock=...), or null. */
    public function reviewIdForLink($token) {
        $reset = $this->getResetByToken($token);
        return $reset && $reset['review_status'] === 'accepted' ? (int)$reset['review_id'] : null;
    }

    /**
     * Check the emailed unlock code. On success the lock is cleared, the
     * request is completed and the account is returned (the caller signs
     * the student in).
     */
    public function verifyUnlockCode($reviewId, $code) {
        $review = $this->getReviewById((int)$reviewId);
        if (!$review || $review['status'] !== 'accepted' || empty($review['user_id'])) {
            return ['success' => false, 'message' => 'This request has not been approved yet.'];
        }
        $code = trim((string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit code.'];
        }
        $attempts = (int)($_SESSION['unlock_code_attempts'][$reviewId] ?? 0) + 1;
        $_SESSION['unlock_code_attempts'][$reviewId] = $attempts;

        $userId = (int)$review['user_id'];
        $stmt = $this->db->prepare(
            'SELECT otp_id FROM login_otps WHERE user_id = :u AND otp_hash = :h AND used_at IS NULL AND expires_at > NOW()
             ORDER BY otp_id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':h' => hash('sha256', $code . LOGIN_HASH_SALT)]);
        $otpId = $stmt->fetchColumn();
        if (!$otpId) {
            if ($attempts >= 5) {
                // Too many wrong codes: the code stops working and the request goes back to the Super Admin.
                $this->db->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')->execute([':u' => $userId]);
                $this->db->prepare("UPDATE lockout_reviews SET status = 'pending' WHERE review_id = :id")->execute([':id' => $reviewId]);
                unset($_SESSION['unlock_code_attempts'][$reviewId]);
                return ['success' => false, 'message' => 'Too many wrong codes. Your request was sent back to the Super Admin for a new code.'];
            }
            return ['success' => false, 'message' => 'Incorrect or expired code. ' . (5 - $attempts) . ' attempt(s) left.'];
        }

        $user = (new User())->getUserById($userId);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'This account cannot be opened. Please contact OSWD.'];
        }
        $this->db->prepare('UPDATE login_otps SET used_at = NOW() WHERE otp_id = :id')->execute([':id' => $otpId]);
        $this->db->prepare('UPDATE credential_resets SET used_at = NOW() WHERE review_id = :id AND used_at IS NULL')->execute([':id' => $reviewId]);
        $this->db->prepare("UPDATE lockout_reviews SET status = 'completed' WHERE review_id = :id")->execute([':id' => $reviewId]);
        $this->clearForUser($userId);
        unset($_SESSION['unlock_code_attempts'][$reviewId]);
        return ['success' => true, 'user' => $user];
    }

    public function rejectReview($reviewId, $adminId, $note = '') {
        $review = $this->getReviewById($reviewId);
        if (!$review || $review['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This request is no longer pending.'];
        }

        $note = trim((string)$note);
        $sql = "UPDATE lockout_reviews
                SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW(), review_note = :note
                WHERE review_id = :id AND status = 'pending'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':admin_id' => $adminId,
            ':note' => $note !== '' ? $note : 'Request rejected.',
            ':id' => $reviewId,
        ]);

        if (!empty($review['email'])) {
            $body = SITE_NAME . "\n\n"
                . "Hello " . ($review['full_name'] ?? 'Student') . ",\n\n"
                . "The Super Admin reviewed your account unlock request and did not approve a credential reset.\n"
                . ($note !== '' ? "Note: {$note}\n\n" : "\n")
                . "If you still cannot sign in, visit Student Services or contact OSWD in person.";
            Mailer::send($review['email'], $review['full_name'] ?? '', 'Your unlock request was not approved', $body);
        }

        return ['success' => true, 'message' => 'Request rejected.'];
    }

    public function getResetByToken($token) {
        $token = trim((string)$token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token . LOGIN_HASH_SALT);
        $sql = "SELECT cr.*, u.username, u.email, u.full_name, r.status AS review_status
                FROM credential_resets cr
                INNER JOIN users u ON cr.user_id = u.user_id
                INNER JOIN lockout_reviews r ON cr.review_id = r.review_id
                WHERE cr.token_hash = :hash AND cr.used_at IS NULL AND cr.expires_at > NOW()
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hash' => $hash]);
        return $stmt->fetch() ?: null;
    }

    public function applyCredentialReset($token, $newUsername, $newPassword) {
        $reset = $this->getResetByToken($token);
        if (!$reset) {
            return ['success' => false, 'message' => 'This reset link is invalid or has expired. Request help from Student Services.'];
        }

        $newUsername = trim((string)$newUsername);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $newUsername)) {
            return ['success' => false, 'message' => 'Username must be 3-50 characters and contain only letters, numbers, dots, underscores, or hyphens.'];
        }
        if (strlen((string)$newPassword) < 6) {
            return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
        }

        $check = $this->db->prepare("SELECT user_id FROM users WHERE username = :username AND user_id <> :user_id LIMIT 1");
        $check->execute([':username' => $newUsername, ':user_id' => $reset['user_id']]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'That username is already taken. Choose another.'];
        }

        $this->db->beginTransaction();
        try {
            $updateUser = $this->db->prepare(
                "UPDATE users SET username = :username, password = :password WHERE user_id = :user_id"
            );
            $updateUser->execute([
                ':username' => $newUsername,
                ':password' => password_hash($newPassword, PASSWORD_BCRYPT),
                ':user_id' => $reset['user_id'],
            ]);

            $mark = $this->db->prepare("UPDATE credential_resets SET used_at = NOW() WHERE reset_id = :id");
            $mark->execute([':id' => $reset['reset_id']]);

            $lockKey = $this->db->prepare("UPDATE login_lockouts SET username_key = :key WHERE user_id = :user_id");
            $lockKey->execute([
                ':key' => $this->normalizeKey($newUsername),
                ':user_id' => $reset['user_id'],
            ]);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Could not update credentials. Please try again.'];
        }

        $user = (new User())->getUserById((int)$reset['user_id']);
        $otp = $this->createUnlockOtp($user, (int)$reset['review_id']);
        if (empty($otp['email_sent'])) {
            return [
                'success' => false,
                'message' => $otp['message'] ?? 'Credentials were saved, but the verification email could not be sent. Contact OSWD.',
            ];
        }

        return [
            'success' => true,
            'otp_required' => true,
            'message' => $otp['message'],
        ];
    }

    public function hasPendingUnlockOtp() {
        return !empty($_SESSION['pending_unlock_otp']['user_id']);
    }

    public function verifyUnlockOtp($code) {
        $pending = $_SESSION['pending_unlock_otp'] ?? null;
        if (!$pending || empty($pending['user_id'])) {
            return ['success' => false, 'message' => 'No unlock verification is pending. Use your reset link again or contact OSWD.'];
        }

        $code = trim((string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit verification code.'];
        }
        if (time() > (int)($pending['expires_at'] ?? 0)) {
            unset($_SESSION['pending_unlock_otp']);
            return ['success' => false, 'message' => 'Verification code expired. Contact OSWD or request a new reset.'];
        }

        $hash = hash('sha256', $code . LOGIN_HASH_SALT);
        $sql = "SELECT otp_id FROM login_otps
                WHERE user_id = :user_id AND otp_hash = :hash AND used_at IS NULL AND expires_at > NOW()
                ORDER BY otp_id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $pending['user_id'], ':hash' => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        $update = $this->db->prepare("UPDATE login_otps SET used_at = NOW() WHERE otp_id = :id");
        $update->execute([':id' => $row['otp_id']]);

        $this->clearForUser((int)$pending['user_id']);
        if (!empty($pending['review_id'])) {
            $done = $this->db->prepare(
                "UPDATE lockout_reviews SET status = 'completed' WHERE review_id = :id AND status IN ('accepted','pending')"
            );
            $done->execute([':id' => $pending['review_id']]);
        }

        unset($_SESSION['pending_unlock_otp'], $_SESSION['login_gate']);
        return ['success' => true, 'message' => 'Account unlocked. You can now sign in with your new credentials.'];
    }

    public function resendUnlockOtp() {
        $pending = $_SESSION['pending_unlock_otp'] ?? null;
        if (!$pending || empty($pending['user_id'])) {
            return ['success' => false, 'message' => 'No unlock verification is pending.'];
        }
        $user = (new User())->getUserById((int)$pending['user_id']);
        if (!$user) {
            unset($_SESSION['pending_unlock_otp']);
            return ['success' => false, 'message' => 'Account not found.'];
        }
        return $this->createUnlockOtp($user, (int)($pending['review_id'] ?? 0), true);
    }

    private function createUnlockOtp(array $user, $reviewId, $isResend = false) {
        $email = trim((string)($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email_sent' => false, 'message' => 'This account has no valid email address. Contact OSWD in person.'];
        }

        $code = (string)random_int(100000, 999999);
        $hash = hash('sha256', $code . LOGIN_HASH_SALT);
        $expiresAt = date('Y-m-d H:i:s', time() + LOGIN_OTP_TTL);

        $invalidate = $this->db->prepare(
            'UPDATE login_otps SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL'
        );
        $invalidate->execute([':user_id' => $user['user_id']]);

        $sql = "INSERT INTO login_otps (user_id, otp_hash, expires_at) VALUES (:user_id, :hash, :expires_at)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $user['user_id'],
            ':hash' => $hash,
            ':expires_at' => $expiresAt,
        ]);

        $minutes = (int)(LOGIN_OTP_TTL / 60);
        $body = SITE_NAME . "\n"
            . "North Eastern Mindanao State University\n\n"
            . "Hello " . ($user['full_name'] ?? 'Student') . ",\n\n"
            . "Your username and password were updated. Enter this code to finish unlocking your account:\n\n"
            . "    {$code}\n\n"
            . "This code expires in {$minutes} minutes.\n\n"
            . "If you did not reset your credentials, contact OSWD immediately.";

        $sent = Mailer::send($email, $user['full_name'] ?? '', 'Your OSWD account unlock code', $body);
        if (!$sent) {
            $otpId = (int)$this->db->lastInsertId();
            if ($otpId > 0) {
                $del = $this->db->prepare('DELETE FROM login_otps WHERE otp_id = :id');
                $del->execute([':id' => $otpId]);
            }
            return [
                'email_sent' => false,
                'success' => false,
                'message' => 'We could not send the verification email. Please try again or contact OSWD.',
            ];
        }

        $_SESSION['pending_unlock_otp'] = [
            'user_id' => (int)$user['user_id'],
            'review_id' => (int)$reviewId,
            'email' => $email,
            'expires_at' => time() + LOGIN_OTP_TTL,
        ];

        return [
            'email_sent' => true,
            'success' => true,
            'message' => 'We sent a 6-digit code to ' . $this->maskEmail($email)
                . '. Enter it below to finish unlocking your account.',
        ];
    }

    private function createAutoBlockReview(array $lockout, $user, $attempted) {
        $userId = $user ? (int)$user['user_id'] : null;
        $pending = $this->findPendingReview($userId, $lockout['username_key'] ?? $this->normalizeKey($attempted));
        if ($pending) {
            return;
        }

        $sql = "INSERT INTO lockout_reviews
                    (lockout_id, user_id, username_attempted, email, full_name, message, source, status)
                VALUES
                    (:lockout_id, :user_id, :username, :email, :full_name, :message, 'auto_block', 'pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':lockout_id' => $lockout['lockout_id'] ?? null,
            ':user_id' => $userId,
            ':username' => $user['username'] ?? $attempted,
            ':email' => $user['email'] ?? null,
            ':full_name' => $user['full_name'] ?? null,
            ':message' => 'Account automatically blocked after repeated failed sign-in attempts.',
        ]);

        $this->notifySuperAdmins(
            'Account locked after failed logins',
            'An account was locked for 1 day and is waiting in Unlock requests: ' . ($user['username'] ?? $attempted) . '.'
        );
    }

    private function findPendingReview($userId, $usernameKey) {
        if ($userId) {
            $sql = "SELECT * FROM lockout_reviews WHERE user_id = :user_id AND status = 'pending' ORDER BY review_id DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        $sql = "SELECT * FROM lockout_reviews
                WHERE LOWER(username_attempted) = :username AND status = 'pending'
                ORDER BY review_id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $usernameKey]);
        return $stmt->fetch() ?: null;
    }

    private function getReviewById($reviewId) {
        $sql = "SELECT * FROM lockout_reviews WHERE review_id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$reviewId]);
        return $stmt->fetch() ?: null;
    }

    /** Unlock requests are handled by the Super Admin (super_admin_unlock_requests.php). */
    private function notifySuperAdmins($title, $message) {
        try {
            $admins = $this->db->query("SELECT user_id FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchAll();
            foreach ($admins as $admin) {
                $this->notifications->create((int)$admin['user_id'], null, $title, $message);
            }
        } catch (PDOException $e) {
            // Notifications must not block lockout handling.
        }
    }

    private function getRow($key, $userId = null) {
        if ($userId) {
            $sql = "SELECT * FROM login_lockouts WHERE user_id = :user_id OR username_key = :key ORDER BY lockout_id DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId, ':key' => $key]);
        } else {
            $sql = "SELECT * FROM login_lockouts WHERE username_key = :key LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':key' => $key]);
        }
        return $stmt->fetch() ?: null;
    }

    private function getRowById($lockoutId) {
        $sql = "SELECT * FROM login_lockouts WHERE lockout_id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $lockoutId]);
        return $stmt->fetch() ?: null;
    }

    private function upsertRow($key, $userId = null) {
        $existing = $this->getRow($key, $userId);
        if ($existing) {
            if ($userId && empty($existing['user_id'])) {
                $stmt = $this->db->prepare("UPDATE login_lockouts SET user_id = :user_id WHERE lockout_id = :id");
                $stmt->execute([':user_id' => $userId, ':id' => $existing['lockout_id']]);
                $existing['user_id'] = $userId;
            }
            return $existing;
        }

        $sql = "INSERT INTO login_lockouts (user_id, username_key, consecutive_fails) VALUES (:user_id, :key, 0)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':key' => $key]);
        return $this->getRowById((int)$this->db->lastInsertId());
    }

    private function gateResult($type, $retryAfter, $user, $row) {
        $attemptsLeft = max(0, LOGIN_BLOCK_AFTER_FAILS - (int)($row['consecutive_fails'] ?? 0));
        return [
            'allowed' => false,
            'type' => $type,
            'retry_after' => max(1, (int)$retryAfter),
            'user' => $user,
            'row' => $row,
            'key' => $row['username_key'] ?? '',
            'show_student_service' => $type === 'blocked',
            'attempts_left' => $attemptsLeft,
            'message' => $this->failureMessage($type, max(1, (int)$retryAfter), $attemptsLeft),
        ];
    }

    private function failureMessage($type, $retryAfter, $attemptsLeft = null) {
        if ($type === 'blocked') {
            return 'This account is locked for 1 day after too many failed sign-in attempts. Please contact Student Services so the Super Admin can review and help you reset your credentials.';
        }
        if ($type === 'cooldown') {
            return 'Too many failed attempts. Please wait ' . (int)$retryAfter . ' seconds before trying again.'
                . lockWarning($attemptsLeft);
        }
        return 'Invalid credentials!';
    }

    private function maskEmail($email) {
        $email = (string)$email;
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || strlen($parts[0]) < 2) {
            return $email;
        }
        return substr($parts[0], 0, 1) . '***@' . $parts[1];
    }
}
