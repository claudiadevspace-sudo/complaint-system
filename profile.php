<?php
require_once 'config/config.php';
requireLogin();

$userObj = new User();
// Students can only view their profile; staff can still edit theirs.
$isStudent = ($_SESSION['role'] ?? '') === 'student';

if (isset($_POST['update_profile']) && $isStudent) {
    $_SESSION['message'] = 'Your profile is read only. Contact OSWD to correct your details.';
    $_SESSION['message_type'] = 'danger';
    redirect('profile.php');
} elseif (isset($_POST['update_profile'])) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        $fullName = sanitizeInput($_POST['full_name'] ?? '');
        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['message'] = 'Please provide a valid name and email address.';
            $_SESSION['message_type'] = 'danger';
        } else {
            $data = [
                'full_name' => $fullName,
                'email' => $email,
                'contact_number' => sanitizeInput($_POST['contact_number'] ?? ''),
                'department' => sanitizeInput($_POST['department'] ?? ''),
                'program' => sanitizeInput($_POST['program'] ?? ''),
            ];
            $result = $userObj->updateProfile($_SESSION['user_id'], $data);
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
            if ($result['success']) {
                $_SESSION['full_name'] = $data['full_name'];
                $_SESSION['email'] = $data['email'];
            }
        }
    }
}

// Change password (every role, including students).
$passwordErrors = [];
if (isset($_POST['change_password'])) {
    if (!verifyCsrf()) {
        $passwordErrors[] = 'Invalid request. Please try again.';
    } else {
        $result = $userObj->changePassword($_SESSION['user_id'], $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '');
        if ($result['success']) {
            session_regenerate_id(true);   // fresh session after a credential change
            $_SESSION['message'] = $result['message'];
            $_SESSION['message_type'] = 'success';
            redirect('profile.php');
        }
        $passwordErrors = $result['errors'];
    }
}

$user = $userObj->getUserById($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Profile - ' . SITE_NAME; include 'includes/head.php'; ?>
</head>
<body class="app-body">
<?php include 'includes/skip_link.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/flash.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 app-main" id="main-content">
            <div class="pt-3 nemsu-page-header mb-4">
                <h1 class="h2">My profile</h1>
                <p class="text-muted mb-0">
                    <?php echo $isStudent
                        ? 'Your account details. Sign-in verification codes are sent to the email below.'
                        : 'Keep your contact details current — OTP codes are sent to your email.'; ?>
                </p>
            </div>

            <?php if ($isStudent): ?>
            <!-- Students: read only. Details come from registration and the
                 enrolled list; only the Super Admin can correct them. -->
            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel">
                <div class="card-header"><i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Account details</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted fw-normal">Full name</dt>
                        <dd class="col-sm-8 fw-semibold"><?php echo htmlspecialchars($user['full_name']); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Username</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($user['username']); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Email</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($user['email']); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Student ID</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($user['student_id'] ?: '—'); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Department</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($user['department'] ?: '—'); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Program</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($user['program'] ?: '—'); ?></dd>
                        <dt class="col-sm-4 text-muted fw-normal">Contact number</dt>
                        <dd class="col-sm-8 mb-0"><?php echo htmlspecialchars($user['contact_number'] ?: '—'); ?></dd>
                    </dl>
                </div>
                <div class="card-footer bg-white small text-muted">
                    <i class="bi bi-lock me-1" aria-hidden="true"></i>
                    These details can't be edited here. If something is wrong, contact OSWD to have it corrected.
                </div>
            </div>
            <?php else: ?>
            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel">
                <div class="card-header"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>Account details</div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label">Full name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required autocomplete="name">
                            </div>
                            <div class="col-md-6">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required autocomplete="email">
                            </div>
                            <div class="col-md-6">
                                <label for="role" class="form-label">Role</label>
                                <input type="text" class="form-control" id="role" value="<?php echo htmlspecialchars(roleLabel($user['role'])); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label for="contact_number" class="form-label">Contact number</label>
                                <input type="tel" class="form-control" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" autocomplete="tel">
                            </div>
                            <div class="col-md-6">
                                <label for="department" class="form-label">Department</label>
                                <?php if (($user['role'] ?? '') === 'student'): ?>
                                    <select class="form-select" id="department" name="department" required>
                                        <option value="">Choose your department</option>
                                        <?php foreach (departments() as $dept): ?>
                                            <option value="<?php echo $dept; ?>" <?php echo ($user['department'] ?? '') === $dept ? 'selected' : ''; ?>><?php echo $dept; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <!-- Personnel: assigned by the Super Admin, so shown read-only -->
                                    <input type="text" class="form-control" id="department" value="<?php echo htmlspecialchars($user['department'] ?: 'Not assigned'); ?>" readonly>
                                    <div class="form-text">Assigned by the Super Admin.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label for="program" class="form-label">Program</label>
                                <input type="text" class="form-control" id="program" name="program" value="<?php echo htmlspecialchars($user['program'] ?? ''); ?>"<?php echo ($user['role'] ?? '') === 'student' ? '' : ' readonly'; ?>>
                            </div>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary mt-4">Save changes</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ===== Change password (every role) ===== -->
            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mt-4" id="change-password">
                <div class="card-header"><i class="bi bi-key me-2" aria-hidden="true"></i>Change password</div>
                <div class="card-body">
                    <?php if ($passwordErrors): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <ul class="mb-0 small"><?php foreach ($passwordErrors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="profile.php#change-password" autocomplete="off">
                        <?php echo csrfField(); ?>
                        <div class="row g-3">
                            <?php
                            $passwordFields = [
                                'current_password' => ['Current password', 'current-password'],
                                'new_password' => ['New password', 'new-password'],
                                'confirm_password' => ['Confirm new password', 'new-password'],
                            ];
                            foreach ($passwordFields as $fieldId => [$fieldLabel, $autocomplete]):
                            ?>
                                <div class="col-md-4">
                                    <label for="<?php echo $fieldId; ?>" class="form-label"><?php echo $fieldLabel; ?></label>
                                    <!-- Eye button shows/hides what was typed (assets/js/auth.js) -->
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="<?php echo $fieldId; ?>" name="<?php echo $fieldId; ?>"
                                               required autocomplete="<?php echo $autocomplete; ?>">
                                        <button type="button" class="btn btn-outline-secondary" data-toggle-password="<?php echo $fieldId; ?>"
                                                aria-label="Show password" title="Show / hide password">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text mt-2">
                            At least 12 characters, with upper- and lower-case letters, a number and a symbol.
                            It must not be a common password or contain your username. You will get an email confirming the change.
                        </div>
                        <button type="submit" name="change_password" value="1" class="btn btn-primary mt-3">
                            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Change password
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include 'includes/scripts.php'; ?>
</body>
</html>
