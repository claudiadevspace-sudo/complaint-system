<?php
/**
 * Forgot password: email → 6-digit code → new password.
 * The steps are handled by classes/PasswordReset.php.
 */
require_once 'config/config.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$reset = new PasswordReset();
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
        redirect('forgot_password.php');
    }
    if (isset($_POST['send_code'])) {
        $result = $reset->start($_POST['email'] ?? '');
    } elseif (isset($_POST['resend_code'])) {
        $result = $reset->resend();
    } elseif (isset($_POST['verify_code'])) {
        $result = $reset->verify($_POST['otp_code'] ?? '');
    } elseif (isset($_POST['save_password'])) {
        $result = $reset->complete($_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '');
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'success';
            redirect('login.php');
        }
        $formErrors = $result['errors'];
        $result = null;   // errors are listed in the form, not as a banner
    } elseif (isset($_POST['cancel'])) {
        $reset->cancel();
        redirect('login.php');
    }
    if (!empty($result)) {
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'info' : 'danger';
    }
    if (!$formErrors) {
        redirect('forgot_password.php');   // refreshing never resends the form
    }
}

$step = $reset->step();
$authMessage = $_SESSION['message'] ?? null;
$authMessageType = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Forgot password - ' . SITE_NAME; include 'includes/head.php'; ?>
</head>
<body class="auth-body">
<?php include 'includes/skip_link.php'; ?>
<div class="auth-split">
    <?php include 'includes/auth_brand_panel.php'; ?>
    <main class="auth-panel-form" id="main-content">
        <div class="auth-panel-form__inner">
            <div class="auth-form-card">
                <h2 class="h4 mb-2">Forgot password</h2>
                <!-- Steps: 1 Email · 2 Code · 3 New password -->
                <ol class="list-inline small text-muted mb-3" aria-label="Steps">
                    <?php foreach (['email' => 'Email', 'code' => 'Verification code', 'password' => 'New password'] as $key => $label): ?>
                        <li class="list-inline-item<?php echo $step === $key ? ' fw-semibold text-body' : ''; ?>"<?php echo $step === $key ? ' aria-current="step"' : ''; ?>>
                            <?php echo array_search($key, ['email', 'code', 'password'], true) + 1; ?>. <?php echo $label; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($authMessage !== null): ?>
                    <div class="alert alert-<?php echo in_array($authMessageType, ['success', 'danger', 'warning', 'info'], true) ? $authMessageType : 'info'; ?> py-2" role="alert">
                        <?php echo htmlspecialchars($authMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'email'): ?>
                    <p class="text-muted small">Enter the email (Gmail) of your account. We will send you a 6-digit code.</p>
                    <form method="POST" action="forgot_password.php" data-no-loading>
                        <?php echo csrfField(); ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="email" placeholder="you@gmail.com">
                            </div>
                        </div>
                        <button type="submit" name="send_code" class="btn btn-primary w-100">Send code</button>
                    </form>

                <?php elseif ($step === 'code'): ?>
                    <p class="text-muted small mb-2">Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($reset->maskedEmail()); ?></strong>.</p>
                    <form method="POST" action="forgot_password.php" id="otpForm" data-no-loading>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="otp_code" id="otp_code" value="">
                        <div class="mb-3">
                            <label class="form-label">Verification code</label>
                            <div class="otp-input-group" role="group" aria-label="Six digit verification code">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                    <input type="text" class="form-control otp-digit" inputmode="numeric" maxlength="1"
                                           autocomplete="one-time-code" aria-label="Digit <?php echo $i + 1; ?>"<?php echo $i === 0 ? ' autofocus' : ''; ?>>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <button type="submit" name="verify_code" class="btn btn-primary w-100">Verify code</button>
                    </form>
                    <form method="POST" action="forgot_password.php" class="mt-3 text-center" data-no-loading>
                        <?php echo csrfField(); ?>
                        <!-- Resend timer handled by assets/js/auth.js -->
                        <button type="submit" name="resend_code" class="btn btn-link btn-sm" id="resendOtpBtn" data-cooldown="<?php echo (int)$reset->secondsUntilResend(); ?>">Resend code</button>
                        <div class="small text-muted" id="resendOtpTimer" aria-live="polite"></div>
                    </form>

                <?php else: ?>
                    <p class="text-muted small">Choose a new password for your account.</p>
                    <?php if ($formErrors): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($formErrors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="forgot_password.php" data-no-loading>
                        <?php echo csrfField(); ?>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" required autofocus autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="new_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <div class="form-text">At least 12 characters with upper and lower case, a number and a symbol.</div>
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="confirm_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <button type="submit" name="save_password" class="btn btn-primary w-100">Save new password</button>
                    </form>
                <?php endif; ?>

                <?php if ($step !== 'email'): ?>
                    <form method="POST" action="forgot_password.php" class="text-center mt-3" data-no-loading>
                        <?php echo csrfField(); ?>
                        <button type="submit" name="cancel" class="btn btn-link btn-sm">Cancel</button>
                    </form>
                <?php endif; ?>
                <p class="text-center small mt-3 mb-0"><a href="login.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to sign in</a></p>
                <p class="text-center text-muted small mt-2 mb-0">Account locked or no access to your email? <a href="student_services.php">Contact Student Services</a></p>
            </div>
        </div>
    </main>
</div>
<?php include 'includes/scripts.php'; ?>
</body>
</html>
