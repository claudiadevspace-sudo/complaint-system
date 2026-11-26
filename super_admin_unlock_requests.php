<?php
/**
 * Super Admin — Unlock requests.
 *
 * Receives the requests students send from Student Services when their
 * account is locked (and the automatic entries created when an account is
 * locked after failed sign-ins). Accept = email the student a credential
 * reset link; Reject = email them that the request was not approved.
 * Every decision is written to the audit log.
 */
require_once 'config/config.php';
requireRole('super_admin');

$lockout = new AccountLockout();
$allowedStatus = ['pending', 'accepted', 'rejected', 'completed', 'all'];
$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, $allowedStatus, true)) {
    $status = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $note = sanitizeInput($_POST['review_note'] ?? '');
        if (isset($_POST['accept_review'])) {
            $result = $lockout->acceptReview($reviewId, (int)$_SESSION['user_id'], $note);
            $action = 'unlock_request_accept';
        } elseif (isset($_POST['reject_review'])) {
            $result = $lockout->rejectReview($reviewId, (int)$_SESSION['user_id'], $note);
            $action = 'unlock_request_reject';
        } else {
            $result = ['success' => false, 'message' => 'Unknown action.'];
            $action = null;
        }
        if ($action && $result['success']) {
            (new AuditLog())->record($action, 'lockout_review', $reviewId, 'Request #' . $reviewId, $note !== '' ? $note : null);
        }
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
    }
    redirect('super_admin_unlock_requests.php?status=' . urlencode($status));
}

$lockout->linkUnmatchedReviews();
$reviews = $lockout->getReviews($status);
$pendingCount = $lockout->getPendingCount();

function unlockStatusBadge($value) {
    return match ((string)$value) {
        'pending' => 'warning',
        'accepted' => 'info',
        'rejected' => 'secondary',
        'completed' => 'success',
        default => 'secondary',
    };
}

$pageTitle = 'Unlock requests';
$pageHeading = 'Unlock requests';
$pageSubtitle = 'Requests from students whose accounts are locked. Accept to email them a 6-digit unlock code, or reject with a note.';
include 'includes/super_admin_top.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($allowedStatus as $filter): ?>
        <a href="super_admin_unlock_requests.php?status=<?php echo urlencode($filter); ?>"
           class="btn btn-sm <?php echo $status === $filter ? 'btn-primary' : 'btn-outline-secondary'; ?>">
            <?php echo htmlspecialchars(ucfirst($filter)); ?>
            <?php if ($filter === 'pending' && $pendingCount > 0): ?>
                <span class="badge text-bg-light text-dark ms-1"><?php echo (int)$pendingCount; ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mb-4">
    <div class="card-header"><i class="bi bi-unlock me-2" aria-hidden="true"></i>Unlock requests</div>
    <div class="card-body p-0">
        <?php if (empty($reviews)): ?>
            <?php echo renderEmptyState('shield-check', 'No requests found', 'There are no unlock requests in this filter.'); ?>
        <?php else: ?>
            <div class="table-responsive nemsu-table-wrap">
                <table class="table table-hover align-middle mb-0 nemsu-table nemsu-table--stack">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Account</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th>Message</th>
                            <th class="text-end nemsu-stack-full">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $review): ?>
                            <tr>
                                <td>
                                    <div><?php echo htmlspecialchars($review['full_name'] ?: 'Unknown student'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($review['email'] ?: 'No email on file'); ?></div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($review['account_username'] ?: $review['username_attempted']); ?></div>
                                    <?php if (empty($review['user_id'])): ?>
                                        <span class="badge bg-danger nemsu-badge" title="Nobody has this username, Student ID or email">No account found</span>
                                    <?php endif; ?>
                                    <?php if (!empty($review['blocked_until'])): ?>
                                        <div class="small text-muted">Locked until <?php echo date('M j, Y g:i A', strtotime($review['blocked_until'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $review['source'] === 'student_service'
                                        ? '<span class="badge bg-primary nemsu-badge">Student Services form</span>'
                                        : '<span class="badge bg-secondary nemsu-badge">Automatic (account locked)</span>'; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo unlockStatusBadge($review['status']); ?> nemsu-badge">
                                        <?php echo htmlspecialchars(ucfirst((string)$review['status'])); ?>
                                    </span>
                                </td>
                                <td><small><?php echo date('M j, Y g:i A', strtotime($review['created_at'])); ?></small></td>
                                <td class="small"><?php echo nl2br(htmlspecialchars($review['message'] ?: '—')); ?></td>
                                <td class="text-end">
                                    <?php if ($review['status'] === 'pending'): ?>
                                        <form method="POST" action="super_admin_unlock_requests.php?status=<?php echo urlencode($status); ?>" class="d-flex flex-column gap-2 align-items-end">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="review_id" value="<?php echo (int)$review['review_id']; ?>">
                                            <input type="text" class="form-control form-control-sm" name="review_note" maxlength="255" placeholder="Optional note to the student">
                                            <div class="btn-group">
                                                <?php if (!empty($review['user_id'])): ?>
                                                <button type="submit" name="accept_review" value="1" class="btn btn-sm btn-success"
                                                        data-confirm-message="Email a 6-digit unlock code to this student's account email?">
                                                    <i class="bi bi-check2 me-1" aria-hidden="true"></i>Accept
                                                </button>
                                                <?php endif; ?>
                                                <button type="submit" name="reject_review" value="1" class="btn btn-sm btn-outline-danger"
                                                        data-confirm-message="Reject this unlock request?">
                                                    <i class="bi bi-x me-1" aria-hidden="true"></i>Reject
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($review['reviewer_name'] ?: '—'); ?>
                                            <?php if (!empty($review['reviewed_at'])): ?>
                                                · <?php echo date('M j, Y', strtotime($review['reviewed_at'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($review['review_note'])): ?>
                                            <div class="small"><?php echo htmlspecialchars($review['review_note']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="small text-muted mb-4">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    After you accept, a 6-digit unlock code is emailed to the student's account email. When they enter it on the Student Services page,
    the account is unlocked, they go straight to their dashboard, and the request shows as <strong>Completed</strong>.
</div>

<?php include 'includes/super_admin_bottom.php'; ?>
