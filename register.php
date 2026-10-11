<?php
require_once 'config/config.php';
if (isLoggedIn()) {
    redirect('dashboard.php');
}

/*
 * Registration has two steps:
 *   1. The student enters their Student ID. It must be on the enrolled list
 *      uploaded by OSWD (enrolled_students) and not already have an account.
 *   2. Only then is the account form shown. The verified ID is kept in the
 *      session — never taken from the form — so it cannot be swapped.
 * User::register() repeats the check when the account is created.
 */
$enrollment = new EnrollmentList();

// "Use a different Student ID" link: go back to step 1.
if (isset($_GET['change_id'])) {
    unset($_SESSION['registration_student_id']);
    redirect('register.php');
}

if (isset($_POST['verify_student_id']) && (!REGISTRATION_OPEN || MAINTENANCE_MODE)) {
    redirect('register.php');   // closed: the page explains why
}

if (isset($_POST['verify_student_id'])) {
    // Limit guesses: 10 checks per 15 minutes per browser session, so the
    // form cannot be used to discover which IDs are on the list.
    $window = $_SESSION['id_check_window'] ?? ['start' => time(), 'count' => 0];
    if (time() - $window['start'] > 900) {
        $window = ['start' => time(), 'count' => 0];
    }
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } elseif ($window['count'] >= 10 || rateLimitExceeded('register_id_check', clientIp(), 30, 900)) {
        // Per browser session AND per IP on the server (clearing cookies does not reset it).
        $_SESSION['message'] = 'Too many Student ID checks. Please wait 15 minutes and try again, or contact OSWD.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $window['count']++;
        $check = $enrollment->verifyForRegistration($_POST['student_id'] ?? '');
        if ($check['ok']) {
            $_SESSION['registration_student_id'] = $check['record']['student_id'];
            $_SESSION['message'] = 'Student ID verified. Check your details and create a password.';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $check['message'];
            $_SESSION['message_type'] = 'danger';
        }
    }
    $_SESSION['id_check_window'] = $window;
    redirect('register.php');
}

$verifiedId = $_SESSION['registration_student_id'] ?? null;

if (isset($_POST['register']) && !$verifiedId) {
    // Account form submitted without passing step 1.
    $_SESSION['message'] = 'Please verify your Student ID first.';
    $_SESSION['message_type'] = 'danger';
} elseif (isset($_POST['register'])) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } elseif (($_POST['password'] ?? '') !== ($_POST['confirm_password'] ?? '')) {
        $_SESSION['message'] = 'Passwords do not match.';
        $_SESSION['message_type'] = 'danger';
    } else {
        // Only the password comes from the form. Name, department, program,
        // email and contact number are taken from OSWD's enrolled record by
        // User::register(); the username is the Student ID.
        $data = [
            'password' => $_POST['password'] ?? '',
            'role' => 'student',
            'student_id' => $verifiedId,   // from step 1, not from the form
        ];

        $user = new User();
        $result = $user->register($data);
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
        if ($result['success']) {
            unset($_SESSION['registration_student_id'], $_SESSION['id_check_window']);
            redirect('login.php');
        }
    }
}

// Step 2 details: re-check the verified ID (OSWD may have removed it, or
// someone may have registered it meanwhile) and pre-fill from the list.
$enrolledRecord = null;
if ($verifiedId) {
    $recheck = $enrollment->verifyForRegistration($verifiedId);
    if ($recheck['ok']) {
        $enrolledRecord = $recheck['record'];
    } else {
        unset($_SESSION['registration_student_id']);
        $verifiedId = null;
        $_SESSION['message'] = $recheck['message'];
        $_SESSION['message_type'] = 'danger';
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
    <?php $pageTitle = 'Register - ' . SITE_NAME; include 'includes/head.php'; ?>
</head>
<body class="auth-body">
<?php include 'includes/skip_link.php'; ?>
<div class="auth-split">
    <?php include 'includes/auth_brand_panel.php'; ?>
    <main class="auth-panel-form" id="main-content">
        <div class="auth-panel-form__inner auth-panel-form__inner--wide">
            <div class="auth-form-card">
                <h2 class="h4 mb-2">Student registration</h2>
                <p class="text-muted small mb-3">
                    <?php echo $verifiedId
                        ? 'Step 2 of 2 — your details come from your enrolled record. Just create a password.'
                        : 'Step 1 of 2 — verify that you are an enrolled student.'; ?>
                </p>
                <div class="progress mb-4" style="height: 4px" role="progressbar" aria-label="Registration progress"
                     aria-valuenow="<?php echo $verifiedId ? 100 : 50; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: <?php echo $verifiedId ? 100 : 50; ?>%"></div>
                </div>

                <?php if ($authMessage !== null): ?>
                    <div class="alert alert-<?php echo htmlspecialchars(in_array($authMessageType, ['success', 'danger', 'warning', 'info'], true) ? $authMessageType : 'info', ENT_QUOTES, 'UTF-8'); ?> py-2" role="alert" aria-live="assertive">
                        <?php echo htmlspecialchars($authMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if (!REGISTRATION_OPEN || MAINTENANCE_MODE): ?>
                <!-- Closed by the Super Admin (Settings) -->
                <div class="alert alert-warning" role="status">
                    <i class="bi bi-lock me-1" aria-hidden="true"></i>
                    <?php echo MAINTENANCE_MODE
                        ? htmlspecialchars(MAINTENANCE_MESSAGE, ENT_QUOTES, 'UTF-8')
                        : 'Student registration is currently closed. Please check again later or contact OSWD.'; ?>
                </div>
                <?php elseif (!$verifiedId): ?>
                <!-- ===== Step 1: verify the Student ID against OSWD's enrolled list ===== -->
                <form method="POST" action="register.php" data-no-loading>
                    <?php echo csrfField(); ?>
                    <label for="student_id" class="form-label">Student ID *</label>
                    <input type="text" class="form-control form-control-lg" id="student_id" name="student_id" required
                           autocomplete="off" placeholder="e.g. 2023-01721" maxlength="50" autofocus>
                    <div class="form-text">Your ID must be on the list of enrolled students provided by OSWD.</div>
                    <button type="submit" name="verify_student_id" value="1" class="btn btn-primary w-100 mt-4">
                        Verify Student ID <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </button>
                </form>
                <?php else: ?>
                <!-- ===== Step 2: account details (only after the ID is verified) ===== -->
                <form method="POST" action="register.php" data-no-loading>
                    <?php echo csrfField(); ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="student_id" class="form-label">Student ID</label>
                            <div class="input-group">
                                <span class="input-group-text text-success"><i class="bi bi-patch-check-fill" aria-hidden="true"></i></span>
                                <input type="text" class="form-control" id="student_id" value="<?php echo htmlspecialchars($verifiedId); ?>" readonly>
                                <a href="register.php?change_id=1" class="btn btn-outline-secondary">Change</a>
                            </div>
                            <div class="form-text">Verified against the enrolled students list.</div>
                        </div>
                        <!-- Details from OSWD's enrolled record: shown, never editable. -->
                        <div class="col-12">
                            <div class="nemsu-enrolled-summary">
                                <p class="small text-muted mb-2"><i class="bi bi-lock me-1" aria-hidden="true"></i>From your enrolled record. These cannot be changed here; if something is wrong, contact OSWD.</p>
                                <dl class="row small mb-0">
                                    <dt class="col-sm-4">Full name</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($enrolledRecord['full_name'] ?? ''); ?></dd>
                                    <dt class="col-sm-4">Department</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($enrolledRecord['department'] ?? ''); ?></dd>
                                    <dt class="col-sm-4">Program</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars(canonicalProgram($enrolledRecord['department'] ?? '', $enrolledRecord['program'] ?? '') ?? ''); ?></dd>
                                    <dt class="col-sm-4">Email</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($enrolledRecord['email'] ?? ''); ?> <span class="text-muted">(sign-in codes are sent here)</span></dd>
                                    <dt class="col-sm-4">Contact number</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars(($enrolledRecord['contact_number'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-sm-4">Username</dt>
                                    <dd class="col-sm-8 mb-0"><?php echo htmlspecialchars($verifiedId); ?> <span class="text-muted">(your Student ID)</span></dd>
                                </dl>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" minlength="12" required autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <div class="form-text">At least 12 characters with upper and lower case, a number and a symbol.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="confirm_password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="register" class="btn btn-primary w-100 mt-4">Create account</button>
                </form>
                <?php endif; ?>
                <p class="text-center text-muted small mt-4 mb-0">Already registered? <a href="login.php">Sign in</a></p>
            </div>
        </div>
    </main>
</div>
<?php include 'includes/scripts.php'; ?>
</body>
</html>
