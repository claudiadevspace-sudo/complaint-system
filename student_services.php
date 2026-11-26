<?php
/**
 * Student Services — request to unlock a locked account.
 *
 * 1. A student whose account was locked after failed sign-ins types only
 *    their reason (the locked account is known from that sign-in attempt).
 * 2. The Super Admin approves it (super_admin_unlock_requests.php) and the
 *    system emails a 6-digit unlock code to the account's own email.
 * 3. The student enters the code here and is signed in to their dashboard.
 *
 * The request this browser follows is kept in $_SESSION['unlock_review_id'];
 * the emailed link (?unlock=...) sets it on another device.
 */
require_once 'config/config.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$lockout = new AccountLockout();

// Opened from the link in the approval email.
if (isset($_GET['unlock'])) {
    $reviewId = $lockout->reviewIdForLink((string)$_GET['unlock']);
    if ($reviewId) {
        $_SESSION['unlock_review_id'] = $reviewId;
    } else {
        $_SESSION['message'] = 'This link is invalid or has expired.';
        $_SESSION['message_type'] = 'danger';
    }
    redirect('student_services.php');
}

// The locked account, known from the failed sign-in on this browser.
$gate = $_SESSION['login_gate'] ?? [];
$lockedAccount = (($gate['type'] ?? '') === 'blocked' || !empty($gate['show_student_service']))
    ? trim((string)($gate['username'] ?? '')) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } elseif (isset($_POST['submit_help'])) {
        if ($lockedAccount === '') {
            $result = ['success' => false, 'message' => 'We could not tell which account is locked. Please try to sign in first.'];
        } else {
            $result = $lockout->submitStudentServiceRequest($lockedAccount, $_POST['message'] ?? '');
        }
        if ($result['success']) {
            $_SESSION['unlock_review_id'] = (int)$result['review_id'];
        }
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
    } elseif (isset($_POST['verify_unlock_code']) && !empty($_SESSION['unlock_review_id'])) {
        $result = $lockout->verifyUnlockCode((int)$_SESSION['unlock_review_id'], $_POST['unlock_code'] ?? '');
        if ($result['success']) {
            unset($_SESSION['unlock_review_id'], $_SESSION['login_gate']);
            (new LoginAuth())->signInAfterUnlock($result['user']);
            $_SESSION['message'] = 'Your account is unlocked. Welcome back!';
            $_SESSION['message_type'] = 'success';
            redirect('dashboard.php');
        }
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = 'danger';
    }
    redirect('student_services.php');
}

// Which step to show.
$state = !empty($_SESSION['unlock_review_id']) ? $lockout->unlockState((int)$_SESSION['unlock_review_id']) : null;
if ($state && $state['status'] === 'completed') {
    unset($_SESSION['unlock_review_id']);
    $state = null;
}
if ($state) {
    $step = match ($state['status']) {
        'accepted' => 'code',
        'rejected' => 'rejected',
        default => 'waiting',
    };
} else {
    if ($lockedAccount === '') {
        $step = 'not_locked';
    } elseif (!$lockout->findUser($lockedAccount)) {
        $step = 'no_account';   // the name typed at sign-in does not belong to any account
    } else {
        $step = 'request';
    }
}

$authMessage = $_SESSION['message'] ?? null;
$authMessageType = $_SESSION['message_type'] ?? 'info';
if ($authMessage !== null) {
    unset($_SESSION['message'], $_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Student Services - ' . SITE_NAME; include 'includes/head.php'; ?>
</head>
<body class="auth-body">
<?php include 'includes/skip_link.php'; ?>
<div class="auth-split">
    <?php include 'includes/auth_brand_panel.php'; ?>
    <main class="auth-panel-form" id="main-content">
        <div class="auth-panel-form__inner">
            <div class="auth-form-card">
                <h2 class="h4 mb-2">Student Services</h2>

                <?php if ($authMessage !== null): ?>
                    <div class="alert alert-<?php echo htmlspecialchars(in_array($authMessageType, ['success', 'danger', 'warning', 'info'], true) ? $authMessageType : 'info', ENT_QUOTES, 'UTF-8'); ?> py-2" role="alert" aria-live="assertive">
                        <?php echo htmlspecialchars($authMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'request'): ?>
                    <!-- Step 1: only the reason; the locked account is known from the sign-in attempt -->
                    <p class="text-muted small mb-4">
                        Your account is locked after too many failed sign-in attempts. Tell us what happened.
                        When the Super Admin approves your request, a 6-digit unlock code is sent to your account's email.
                    </p>
                    <form method="POST" action="student_services.php" data-no-loading>
                        <?php echo csrfField(); ?>
                        <div class="mb-4">
                            <label for="message" class="form-label">What happened?</label>
                            <textarea class="form-control" id="message" name="message" rows="5" maxlength="1000" required
                                      placeholder="Example: I forgot my password and my account was locked."></textarea>
                        </div>
                        <button type="submit" name="submit_help" value="1" class="btn btn-primary w-100">
                            <i class="bi bi-send me-1" aria-hidden="true"></i>Send unlock request
                        </button>
                    </form>

                <?php elseif ($step === 'waiting'): ?>
                    <!-- Step 2: waiting for the Super Admin -->
                    <div class="text-center py-2">
                        <i class="bi bi-hourglass-split fs-1 text-primary" aria-hidden="true"></i>
                        <p class="fw-semibold mt-2 mb-1">Waiting for approval</p>
                        <p class="text-muted small mb-3">
                            Your request was sent to the Super Admin. When it is approved, a 6-digit unlock code
                            will be emailed to <strong><?php echo htmlspecialchars($state['masked_email'] ?: 'your account\'s email'); ?></strong>.
                            Come back to this page to enter it.
                        </p>
                        <a href="student_services.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Check again
                        </a>
                    </div>

                <?php elseif ($step === 'code'): ?>
                    <!-- Step 3: enter the emailed unlock code -->
                    <p class="text-muted small mb-3">
                        Your request was approved. Enter the 6-digit unlock code sent to
                        <strong><?php echo htmlspecialchars($state['masked_email']); ?></strong> (check the spam folder too).
                    </p>
                    <form method="POST" action="student_services.php" data-no-loading autocomplete="off">
                        <?php echo csrfField(); ?>
                        <label for="unlock_code" class="form-label">Unlock code</label>
                        <input type="text" class="form-control form-control-lg text-center mb-3" id="unlock_code" name="unlock_code"
                               required inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" autocomplete="one-time-code" autofocus>
                        <button type="submit" name="verify_unlock_code" value="1" class="btn btn-primary w-100">
                            <i class="bi bi-unlock me-1" aria-hidden="true"></i>Unlock and open my account
                        </button>
                    </form>

                <?php elseif ($step === 'no_account'): ?>
                    <div class="alert alert-warning" role="status">
                        No account uses <strong><?php echo htmlspecialchars($lockedAccount); ?></strong>, so there is nothing to unlock.
                    </div>
                    <ul class="small text-muted mb-0">
                        <li>Check that you typed your <strong>Student ID</strong> or <strong>email</strong> correctly when signing in.</li>
                        <li>If you do not have an account yet, <a href="register.php">register as a student</a>.</li>
                    </ul>

                <?php elseif ($step === 'rejected'): ?>
                    <div class="alert alert-warning" role="status">
                        Your unlock request was not approved.
                        <?php if (!empty($state['review_note'])): ?>
                            <br><span class="small">Note: <?php echo htmlspecialchars($state['review_note']); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-0">Please visit OSWD in person for help.</p>

                <?php else: ?>
                    <!-- Opened without a locked account on this browser -->
                    <p class="text-muted small mb-3">
                        Student Services is for accounts that were <strong>locked</strong> after too many failed sign-in attempts.
                    </p>
                    <ul class="small text-muted mb-0">
                        <li>If your account is locked, try to sign in first. When the lock message appears, click <strong>Contact Student Services</strong> to send your request.</li>
                        <li>If you only forgot your password, use <a href="forgot_password.php">Forgot password</a> instead.</li>
                    </ul>
                <?php endif; ?>

                <p class="text-center small mt-4 mb-0"><a href="login.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to sign in</a></p>
            </div>
        </div>
    </main>
</div>
<?php include 'includes/scripts.php'; ?>
</body>
</html>
