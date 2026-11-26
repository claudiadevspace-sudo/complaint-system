<?php
/**
 * Change the account's email from the Profile page, in two steps:
 *   1. the user types the new email and clicks "Send code" → a 6-digit code
 *      is emailed to that NEW address (proving they can open it);
 *   2. they enter the code → the email is changed.
 *
 * The code is kept only as a hash in the server-side session, expires after
 * LOGIN_OTP_TTL, allows MAX_ATTEMPTS tries and can be resent once a minute.
 * The OLD address is told about the change, in case it was not the owner.
 */
class EmailChange {
    const SESSION_KEY = 'email_change';
    const MAX_ATTEMPTS = 5;
    const RESEND_SECONDS = 60;

    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /** The pending change for this user, or null. */
    public function pending($userId) {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$state || (int)$state['user_id'] !== (int)$userId) {
            return null;
        }
        if (time() > (int)$state['expires_at']) {
            $this->cancel();
            return null;
        }
        return $state;
    }

    public function secondsUntilResend() {
        $sentAt = (int)($_SESSION[self::SESSION_KEY]['sent_at'] ?? 0);
        return max(0, self::RESEND_SECONDS - (time() - $sentAt));
    }

    public function cancel() {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Step 1: check the new email and send it a code. */
    public function sendCode($userId, $newEmail) {
        $user = (new User())->getUserById((int)$userId);
        if (!$user) {
            return ['success' => false, 'message' => 'Account not found.'];
        }
        $newEmail = strtolower(trim((string)$newEmail));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 100) {
            return ['success' => false, 'message' => 'Enter a valid email address.'];
        }
        if (strcasecmp($newEmail, (string)$user['email']) === 0) {
            return ['success' => false, 'message' => 'That is already your email.'];
        }
        $taken = $this->db->prepare('SELECT 1 FROM users WHERE email = :e AND user_id <> :id LIMIT 1');
        $taken->execute([':e' => $newEmail, ':id' => (int)$userId]);
        if ($taken->fetchColumn()) {
            return ['success' => false, 'message' => 'That email is already used by another account.'];
        }
        $pending = $this->pending($userId);
        if ($pending && $pending['email'] === $newEmail && $this->secondsUntilResend() > 0) {
            return ['success' => false, 'message' => 'Please wait ' . $this->secondsUntilResend() . ' seconds before asking for a new code.'];
        }
        if (rateLimitExceeded('email_change_code', 'user:' . (int)$userId, 5, 3600)) {
            return ['success' => false, 'message' => 'Too many codes requested. Please try again in an hour.'];
        }

        $code = (string)random_int(100000, 999999);
        $minutes = max(1, (int)(LOGIN_OTP_TTL / 60));
        $sent = Mailer::send($newEmail, $user['full_name'] ?? '', 'Your email change code',
            SITE_NAME . "\nNorth Eastern Mindanao State University\n\n"
            . 'Hello ' . ($user['full_name'] ?? '') . ",\n\n"
            . "You asked to use this email for your account. Enter this code on your Profile page to confirm:\n\n"
            . "    {$code}\n\n"
            . "This code expires in {$minutes} minutes.\n\n"
            . 'If you did not ask for this, you can ignore this email.');
        if (!$sent) {
            return ['success' => false, 'message' => 'We could not send the code to that email. Check the address and try again.'];
        }

        $_SESSION[self::SESSION_KEY] = [
            'user_id' => (int)$userId,
            'email' => $newEmail,
            'hash' => hash('sha256', $code . LOGIN_HASH_SALT . $userId . $newEmail),
            'expires_at' => time() + LOGIN_OTP_TTL,
            'sent_at' => time(),
            'attempts' => 0,
        ];
        return ['success' => true, 'message' => 'We sent a 6-digit code to ' . $newEmail . '. Enter it below to confirm.'];
    }

    /** Step 2: check the code and change the email. */
    public function verify($userId, $code) {
        $state = $this->pending($userId);
        if (!$state) {
            return ['success' => false, 'message' => 'The code expired. Enter your new email and send a new code.'];
        }
        $code = trim((string)$code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit code.'];
        }
        $_SESSION[self::SESSION_KEY]['attempts']++;
        if ($_SESSION[self::SESSION_KEY]['attempts'] > self::MAX_ATTEMPTS) {
            $this->cancel();
            return ['success' => false, 'message' => 'Too many wrong codes. Enter your new email and send a new code.'];
        }
        if (!hash_equals($state['hash'], hash('sha256', $code . LOGIN_HASH_SALT . $userId . $state['email']))) {
            $left = self::MAX_ATTEMPTS - $_SESSION[self::SESSION_KEY]['attempts'];
            return ['success' => false, 'message' => 'Incorrect code.' . ($left > 0 ? " {$left} attempt(s) left." : '')];
        }

        $user = (new User())->getUserById((int)$userId);
        // Someone may have taken the address since the code was sent.
        $taken = $this->db->prepare('SELECT 1 FROM users WHERE email = :e AND user_id <> :id LIMIT 1');
        $taken->execute([':e' => $state['email'], ':id' => (int)$userId]);
        if (!$user || $taken->fetchColumn()) {
            $this->cancel();
            return ['success' => false, 'message' => 'That email can no longer be used. Try a different one.'];
        }

        $this->db->prepare('UPDATE users SET email = :e WHERE user_id = :id')
            ->execute([':e' => $state['email'], ':id' => (int)$userId]);
        $_SESSION['email'] = $state['email'];
        (new AuditLog())->record('email_change', 'user', (int)$userId, $user['username'], null,
            ['role' => $user['role']], ['id' => (int)$userId, 'username' => $user['username']]);
        // Tell the old address, in case the change was not made by the owner.
        if (filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            Mailer::send($user['email'], $user['full_name'] ?? '', 'Your account email was changed',
                'Hello ' . ($user['full_name'] ?? '') . ",\n\nThe email of your " . SITE_NAME . ' account (' . $user['username'] . ') was changed to '
                . $state['email'] . ' on ' . date('M j, Y g:i A') . ".\n\nIf you did not do this, contact OSWD immediately.");
        }
        $this->cancel();
        return ['success' => true, 'message' => 'Your email was changed to ' . $state['email'] . '. Sign-in codes will be sent there from now on.'];
    }
}
