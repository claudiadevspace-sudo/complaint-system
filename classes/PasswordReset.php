<?php
/**
 * Forgot password (forgot_password.php), in three steps:
 *   1. the user enters the email of their account → a 6-digit code is emailed;
 *   2. they enter the code (like the sign-in verification code);
 *   3. they choose a new password.
 *
 * The code is kept only as a hash in the server-side session, expires after
 * LOGIN_OTP_TTL, allows MAX_ATTEMPTS tries, and can be resent once a minute.
 * An email that has no active account is refused with "not registered".
 */
class PasswordReset {
    const SESSION_KEY = 'password_reset';
    const MAX_ATTEMPTS = 5;
    const RESEND_SECONDS = 60;
    const CHANGE_WINDOW = 900;   // after verifying, 15 minutes to choose the password

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Current step: 'email', 'code' or 'password'. */
    public function step() {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$state) {
            return 'email';
        }
        if (!empty($state['verified'])) {
            if (time() - (int)$state['verified_at'] > self::CHANGE_WINDOW) {
                $this->cancel();
                return 'email';
            }
            return 'password';
        }
        return 'code';
    }

    public function maskedEmail() {
        $email = (string)($_SESSION[self::SESSION_KEY]['email'] ?? '');
        $parts = explode('@', $email, 2);
        return count($parts) === 2 && strlen($parts[0]) > 1 ? substr($parts[0], 0, 1) . '***@' . $parts[1] : $email;
    }

    public function secondsUntilResend() {
        $sentAt = (int)($_SESSION[self::SESSION_KEY]['sent_at'] ?? 0);
        return max(0, self::RESEND_SECONDS - (time() - $sentAt));
    }

    public function cancel() {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Step 1: email → send a code (when the email belongs to an active account). */
    public function start($email) {
        $email = trim((string)$email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Enter a valid email address.'];
        }
        // Server-side limits (survive clearing cookies): stops flooding an
        // inbox with codes and testing many emails from one computer.
        if (rateLimitExceeded('reset_ip', clientIp(), 20, 3600)) {
            return ['success' => false, 'message' => 'Too many password reset requests from this device. Please try again in an hour.'];
        }
        $stmt = $this->db->prepare(
            "SELECT user_id, username, email, full_name FROM users
             WHERE email = :e AND status = 'active' AND archived_at IS NULL LIMIT 1"
        );
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            // Tell the user plainly (OSWD's choice) instead of a neutral message.
            unset($_SESSION[self::SESSION_KEY]);
            return ['success' => false, 'message' => 'This email is not registered. Check the spelling, or use the email of your account.'];
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_id' => (int)$user['user_id'],
            'email' => $email,
            'hash' => null,
            'expires_at' => 0,
            'attempts' => 0,
            'sent_at' => 0,
            'verified' => false,
        ];
        return $this->sendCode($user);
    }

    public function resend() {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$state || !empty($state['verified'])) {
            return ['success' => false, 'message' => 'Start again by entering your email.'];
        }
        if ($this->secondsUntilResend() > 0) {
            return ['success' => false, 'message' => 'Please wait ' . $this->secondsUntilResend() . ' seconds before asking for a new code.'];
        }
        $user = null;
        if ($state['user_id']) {
            $user = (new User())->getUserById((int)$state['user_id']);
        }
        return $this->sendCode($user);
    }

    private function sendCode($user) {
        $state = &$_SESSION[self::SESSION_KEY];
        $state['sent_at'] = time();
        $state['attempts'] = 0;
        $generic = 'We sent a 6-digit code to ' . $this->maskedEmail() . '. '
            . 'Check your inbox and spam folder, then enter the code below.';
        if (!$user) {
            return ['success' => true, 'message' => $generic];
        }
        // At most 5 codes per email address per hour (also across resends).
        if (rateLimitExceeded('reset_email', $user['email'], 5, 3600)) {
            return ['success' => false, 'message' => 'Too many codes were sent to this email. Please wait an hour, then try again.'];
        }

        $code = (string)random_int(100000, 999999);
        $state['hash'] = hash('sha256', $code . LOGIN_HASH_SALT . $user['user_id']);
        $state['expires_at'] = time() + LOGIN_OTP_TTL;
        $minutes = max(1, (int)(LOGIN_OTP_TTL / 60));
        $sent = Mailer::send($user['email'], $user['full_name'] ?? '', 'Your password reset code',
            SITE_NAME . "\nNorth Eastern Mindanao State University\n\n"
            . 'Hello ' . ($user['full_name'] ?? '') . ",\n\n"
            . "Someone asked to reset the password of your account. Enter this code to continue:\n\n"
            . "    {$code}\n\n"
            . "This code expires in {$minutes} minutes.\n\n"
            . 'If you did not ask for this, you can ignore this email: your password has not been changed.');
        if (!$sent) {
            return ['success' => false, 'message' => 'We could not send the email right now. Please try again in a few minutes.'];
        }
        return ['success' => true, 'message' => $generic];
    }

    /** Step 2: check the code. */
    public function verify($code) {
        $state = &$_SESSION[self::SESSION_KEY];
        if (!$state) {
            return ['success' => false, 'message' => 'Start again by entering your email.'];
        }
        $code = trim((string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit code.'];
        }
        if (++$state['attempts'] > self::MAX_ATTEMPTS) {
            $this->cancel();
            return ['success' => false, 'message' => 'Too many wrong codes. Start again by entering your email.'];
        }
        if (!$state['hash'] || time() > (int)$state['expires_at']) {
            return ['success' => false, 'message' => 'This code is not valid or has expired. Ask for a new code.'];
        }
        if (!hash_equals($state['hash'], hash('sha256', $code . LOGIN_HASH_SALT . $state['user_id']))) {
            $left = self::MAX_ATTEMPTS - $state['attempts'];
            return ['success' => false, 'message' => 'Incorrect code.' . ($left > 0 ? " {$left} attempt(s) left." : '')];
        }
        $state['verified'] = true;
        $state['verified_at'] = time();
        $state['hash'] = null;   // one use only
        return ['success' => true, 'message' => 'Code verified. Choose your new password.'];
    }

    /** Step 3: save the new password. */
    public function complete($new, $confirm) {
        if ($this->step() !== 'password') {
            return ['success' => false, 'errors' => ['Start again by entering your email.']];
        }
        $user = (new User())->getUserById((int)$_SESSION[self::SESSION_KEY]['user_id']);
        if (!$user) {
            $this->cancel();
            return ['success' => false, 'errors' => ['Account not found.']];
        }
        $errors = passwordPolicyErrors($new, $user['username'], $user['email']);
        if ((string)$new !== (string)$confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if (password_verify((string)$new, $user['password'])) {
            $errors[] = 'Choose a password different from your current one.';
        }
        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->prepare('UPDATE users SET password = :p WHERE user_id = :id')
            ->execute([':p' => password_hash((string)$new, PASSWORD_BCRYPT), ':id' => (int)$user['user_id']]);
        // Proving the email unlocks any failed-sign-in lock on the account.
        (new AccountLockout())->clearForUser((int)$user['user_id'], strtolower($user['username']));
        (new AuditLog())->record('password_reset', 'user', (int)$user['user_id'], $user['username'], null,
            ['via' => 'forgot_password', 'role' => $user['role']],
            ['id' => (int)$user['user_id'], 'username' => $user['username']]);
        Mailer::send($user['email'], $user['full_name'] ?? '', 'Your password was changed',
            'Hello ' . ($user['full_name'] ?? '') . ",\n\nThe password of your " . SITE_NAME . ' account (' . $user['username'] . ') was reset on '
            . date('M j, Y g:i A') . " using the Forgot password page.\n\nIf you did not do this, contact OSWD immediately.");
        $this->cancel();
        return ['success' => true, 'message' => 'Your password was changed. You can now sign in with your new password.'];
    }
}
