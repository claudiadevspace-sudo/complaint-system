<?php
require_once 'config/config.php';
requireLogin();

if (!isset($_GET['id'])) {
    redirect('dashboard.php');
}

$complaintObj = new Complaint();
$complaintId = intval($_GET['id']);
$complaint = $complaintObj->getComplaintById($complaintId);

if (!$complaint) {
    $_SESSION['message'] = 'Complaint not found';
    $_SESSION['message_type'] = 'danger';
    redirect('dashboard.php');
}

if (!$complaintObj->canAccessComplaint($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '')) {
    $_SESSION['message'] = 'You do not have permission to view this complaint';
    $_SESSION['message_type'] = 'danger';
    redirect('dashboard.php');
}

$canManage = $complaintObj->canManageComplaint($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');
$canEscalate = $complaintObj->canEscalate($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');

if (isset($_POST['update_status']) && $canManage) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $newStatus = $_POST['new_status'] ?? '';
    $result = $complaintObj->updateStatus($complaintId, $newStatus, $_SESSION['user_id']);

    if ($result['success']) {
        $_SESSION['message'] = 'Status updated successfully';
        $_SESSION['message_type'] = 'success';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $_SESSION['message'] = $result['message'] ?? 'Failed to update status';
    $_SESSION['message_type'] = 'danger';
}

$canComment = $complaintObj->canComment($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');

if (isset($_POST['add_comment']) && $canComment) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $comment = sanitizeInput($_POST['comment_text'] ?? '');
    $result = $complaintObj->addComment($complaintId, $_SESSION['user_id'], $comment);

    if ($result['success']) {
        $_SESSION['message'] = 'Message sent';
        $_SESSION['message_type'] = 'success';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $_SESSION['message'] = $result['message'] ?? 'Failed to send message';
    $_SESSION['message_type'] = 'danger';
}

if (isset($_POST['escalate']) && $canEscalate) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $reason = sanitizeInput($_POST['escalation_reason'] ?? '');
    $result = $complaintObj->escalateComplaint($complaintId, $_SESSION['user_id'], $reason, $_SESSION['role'] ?? '');

    if ($result['success']) {
        $_SESSION['message'] = 'Complaint escalated successfully';
        $_SESSION['message_type'] = 'success';
        redirect('view_complaint.php?id=' . $complaintId);
    }
    $_SESSION['message'] = $result['message'] ?? 'Failed to escalate complaint';
    $_SESSION['message_type'] = 'danger';
}

// ---- Resolution proof (see classes/ResolutionProof.php) ----------------
// Staff upload proof; the student approves or rejects it; only then can
// the complaint be set to "Resolved".
$proofService = new ResolutionProof();

if (isset($_POST['upload_proof']) && $canManage) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $result = $proofService->upload($complaint, $_SESSION['user_id'], $_FILES['proof_file'] ?? null, $_POST['proof_note'] ?? '');
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
    }
    redirect('view_complaint.php?id=' . $complaintId);
}

if (isset($_POST['respond_proof']) && ($_SESSION['role'] ?? '') === 'student') {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $result = $proofService->respond($complaint, $_SESSION['user_id'], (int)($_POST['proof_id'] ?? 0),
            $_POST['respond_proof'], $_POST['proof_response'] ?? '');
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
    }
    redirect('view_complaint.php?id=' . $complaintId);
}

// Private conversations: staff see only their own chat with the student.
$comments = $complaintObj->getComments($complaintId, $_SESSION['user_id'], $_SESSION['role'] ?? '');
// Latest event at the top, oldest at the bottom.
$timeline = array_reverse(filterTimelineForDisplay($complaintObj->getTimeline($complaintId)));
$complaint = $complaintObj->getComplaintById($complaintId);
$canManage = $complaintObj->canManageComplaint($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');
$canEscalate = $complaintObj->canEscalate($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');
$canComment = $complaintObj->canComment($complaint, $_SESSION['user_id'], $_SESSION['role'] ?? '');
// Escalation is locked while the case is still Pending (shown as a disabled button).
$escalateLocked = !$canEscalate && ($complaint['status'] ?? '') === 'pending'
    && $complaintObj->canEscalate(array_merge($complaint, ['status' => 'under_review']), $_SESSION['user_id'], $_SESSION['role'] ?? '');
$nextLevel = nextWorkflowLevel($complaint['current_level'] ?? '');
$isStudent = ($_SESSION['role'] ?? '') === 'student';
$currentUserId = (int)$_SESSION['user_id'];
$proofs = $proofService->forComplaint($complaintId);
$latestProof = $proofs[0] ?? null;
$canResolve = $latestProof !== null && $latestProof['status'] === 'approved';
$isClosed = in_array($complaint['status'], ['resolved', 'rejected'], true);
// Staff who no longer hold it (e.g. a coordinator after escalating): view only.
$viewOnly = !$isStudent && !$canManage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Complaint #' . $complaintId . ' - ' . SITE_NAME; include 'includes/head.php'; ?>
</head>
<body class="app-body">
<?php include 'includes/skip_link.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/flash.php'; ?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 app-main" id="main-content">
            <div class="d-flex justify-content-between flex-wrap align-items-start pt-3 nemsu-page-header gap-2">
                <div>
                    <h1 class="h2 mb-1">Case #<?php echo (int)$complaint['complaint_id']; ?></h1>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($complaint['complaint_title']); ?></p>
                </div>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Dashboard</a>
            </div>

            <?php if ($viewOnly): ?>
                <div class="alert alert-info d-flex align-items-start gap-2" role="status">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    <div>
                        <strong>View only.</strong>
                        This complaint is now handled by <?php echo htmlspecialchars($complaint['assigned_name'] ?? workflowLevelLabel($complaint['current_level'] ?? '')); ?>
                        (<?php echo htmlspecialchars(workflowLevelLabel($complaint['current_level'] ?? '')); ?>).
                        You can follow its progress, but you can no longer change the status, escalate it or chat.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isStudent): ?>
                <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-progress-card mb-4">
                    <div class="card-header"><i class="bi bi-signpost-split me-2" aria-hidden="true"></i>Your complaint progress</div>
                    <div class="card-body">
                        <?php echo renderComplaintProgressSteps($complaint, false); ?>
                        <p class="small mb-1 mt-3"><?php echo statusPlainLanguage($complaint['status']); ?></p>
                        <p class="small text-muted mb-0"><i class="bi bi-clock me-1" aria-hidden="true"></i><?php echo htmlspecialchars(expectedResponseHint($complaint['status'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mb-4">
                        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <span>Summary</span>
                            <div class="d-flex gap-2">
                                <?php echo statusBadgeHtml($complaint['status']); ?>
                                <?php echo severityBadgeHtml($complaint['severity']); ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0 small">
                                <dt class="col-sm-4">Category</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars($complaint['complaint_category'] ?? '—'); ?></dd>
                                <dt class="col-sm-4">Type</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars(formatStatus($complaint['complaint_type'] ?? 'behavioral')); ?></dd>
                                <dt class="col-sm-4">Handler</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars(workflowLevelLabel($complaint['current_level'] ?? '')); ?><?php if (!empty($complaint['assigned_name'])): ?> — <?php echo htmlspecialchars($complaint['assigned_name']); ?><?php endif; ?></dd>
                                <dt class="col-sm-4">Description</dt>
                                <dd class="col-sm-8"><?php echo nl2br(htmlspecialchars($complaint['complaint_description'])); ?></dd>
                                <?php if (!empty($complaint['respondent_name'])): ?><!-- "Name of person involved" from the form -->
                                <dt class="col-sm-4">Person involved</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars($complaint['respondent_name']); ?><?php if (!empty($complaint['respondent_details'])): ?> <span class="text-muted">— <?php echo htmlspecialchars($complaint['respondent_details']); ?></span><?php elseif ($complaint['respondent_type']): ?> (<?php echo htmlspecialchars(formatStatus($complaint['respondent_type'])); ?>)<?php endif; ?></dd>
                                <?php endif; ?>
                                <dt class="col-sm-4">Incident</dt>
                                <dd class="col-sm-8"><?php echo date('M j, Y', strtotime($complaint['incident_date'])); ?> · <?php echo htmlspecialchars($complaint['incident_location'] ?? 'No location given'); ?></dd>
                                <?php if (!$isStudent): ?>
                                    <dt class="col-sm-4">Submitted by</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($complaint['complainant_name']); ?></dd>
                                <?php endif; ?>
                                <?php if ($complaint['supporting_documents']): ?>
                                    <dt class="col-sm-4">Evidence</dt>
                                    <dd class="col-sm-8">
                                        <?php
                                        // How the pop-up viewer shows this file (see initDocViewer in app.js).
                                        $docFile = (string)$complaint['supporting_documents'];
                                        $docExt = strtolower(pathinfo($docFile, PATHINFO_EXTENSION));
                                        $docKind = in_array($docExt, ['jpg', 'jpeg', 'png'], true) ? 'image' : ($docExt === 'pdf' ? 'pdf' : 'other');
                                        ?>
                                        <!-- Computers: opens in a pop-up on this page. Phones (and no JavaScript): opens in a new tab. -->
                                        <a href="<?php echo htmlspecialchars(complaintFileUrl($complaintId, $docFile)); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
                                           data-doc-viewer data-doc-kind="<?php echo $docKind; ?>"
                                           data-doc-name="Evidence for complaint #<?php echo (int)$complaintId; ?> (<?php echo strtoupper($docExt); ?>)">
                                            <i class="bi bi-paperclip me-1" aria-hidden="true"></i>View document
                                        </a>
                                    </dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>

                    <?php if ($proofs || ($canManage && !$isClosed)): ?>
                    <!-- ===== Resolution proof: staff upload → student approves → staff may resolve ===== -->
                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mb-4" id="resolution-proof">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><i class="bi bi-patch-check me-2" aria-hidden="true"></i>Resolution proof</span>
                            <?php if ($latestProof): ?>
                                <?php [$pClass, $pLabel, $pIcon] = ResolutionProof::statusMeta($latestProof['status']); ?>
                                <span class="badge bg-<?php echo $pClass; ?>"><i class="bi bi-<?php echo $pIcon; ?> me-1" aria-hidden="true"></i><?php echo $pLabel; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($isStudent && $latestProof && $latestProof['status'] === 'pending'): ?>
                                <div class="alert alert-warning py-2 small" role="status">
                                    <i class="bi bi-hand-index me-1" aria-hidden="true"></i>
                                    Staff say your complaint has been dealt with. Please check the proof below and approve it, or reject it and say what is still wrong.
                                </div>
                            <?php elseif (!$isStudent && !$proofs): ?>
                                <p class="small text-muted">Before this complaint can be resolved, upload proof of what was done (a photo or document). The student must approve it.</p>
                            <?php endif; ?>

                            <?php foreach ($proofs as $i => $proof): ?>
                                <?php [$pClass, $pLabel, $pIcon] = ResolutionProof::statusMeta($proof['status']); ?>
                                <div class="nemsu-proof <?php echo $i === 0 ? 'nemsu-proof--latest' : ''; ?>">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <span class="badge bg-<?php echo $pClass; ?> mb-1"><i class="bi bi-<?php echo $pIcon; ?> me-1" aria-hidden="true"></i><?php echo $pLabel; ?></span>
                                            <div class="small text-muted">
                                                Sent <?php echo date('M j, Y g:i A', strtotime($proof['created_at'])); ?>
                                                by <?php echo htmlspecialchars($proof['uploaded_by_name']); ?> (<?php echo htmlspecialchars(roleLabel($proof['uploaded_by_role'])); ?>)
                                            </div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars(complaintFileUrl($complaintId, $proof['file_name'])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
                                           data-doc-viewer data-doc-kind="<?php echo documentKind($proof['file_name']); ?>"
                                           data-doc-name="Resolution proof for complaint #<?php echo (int)$complaintId; ?> (<?php echo strtoupper(pathinfo($proof['file_name'], PATHINFO_EXTENSION)); ?>)">
                                            <i class="bi bi-eye me-1" aria-hidden="true"></i>View proof
                                        </a>
                                    </div>
                                    <p class="mb-1 mt-2"><?php echo nl2br(htmlspecialchars($proof['note'])); ?></p>
                                    <?php if ($proof['status'] === 'rejected' && $proof['student_response']): ?>
                                        <p class="small mb-0 text-danger-emphasis"><i class="bi bi-chat-left-quote me-1" aria-hidden="true"></i>Student's reason: <?php echo htmlspecialchars($proof['student_response']); ?></p>
                                    <?php endif; ?>

                                    <?php if ($i === 0 && $isStudent && $proof['status'] === 'pending'): ?>
                                        <!-- Student: approve or reject the latest proof -->
                                        <form method="POST" class="mt-3">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="proof_id" value="<?php echo (int)$proof['proof_id']; ?>">
                                            <label for="proof_response" class="form-label small">If you reject it, tell staff what is still wrong</label>
                                            <textarea class="form-control form-control-sm mb-2" id="proof_response" name="proof_response" rows="2" maxlength="1000"></textarea>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="submit" name="respond_proof" value="approve" class="btn btn-success btn-sm"
                                                        data-confirm-message="Approve this proof? Staff will then be able to mark your complaint as resolved.">
                                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Approve
                                                </button>
                                                <button type="submit" name="respond_proof" value="reject" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Reject
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($canManage && !$isClosed && (!$latestProof || $latestProof['status'] !== 'approved')): ?>
                                <!-- Staff: upload (new) proof for the student's review -->
                                <form method="POST" enctype="multipart/form-data" class="nemsu-proof-upload <?php echo $proofs ? 'mt-3' : ''; ?>">
                                    <?php echo csrfField(); ?>
                                    <h3 class="h6 mb-2">
                                        <?php echo !$latestProof ? 'Upload proof' : ($latestProof['status'] === 'pending' ? 'Replace the proof waiting for review' : 'Upload new proof'); ?>
                                    </h3>
                                    <label for="proof_file" class="form-label small">Image or document (JPG, PNG, PDF, DOC, DOCX · up to 5MB)</label>
                                    <input type="file" class="form-control form-control-sm mb-2" id="proof_file" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required>
                                    <label for="proof_note" class="form-label small">What was done to resolve the complaint</label>
                                    <textarea class="form-control form-control-sm mb-2" id="proof_note" name="proof_note" rows="2" minlength="10" maxlength="2000" required></textarea>
                                    <button type="submit" name="upload_proof" value="1" class="btn btn-primary btn-sm">
                                        <i class="bi bi-upload me-1" aria-hidden="true"></i>Send to student for review
                                    </button>
                                </form>
                            <?php elseif ($canManage && $canResolve && !$isClosed): ?>
                                <p class="small mb-0 mt-2"><i class="bi bi-check2-circle me-1 text-success" aria-hidden="true"></i>The student approved the proof. You can now set the status to <strong>Resolved</strong>.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mb-4">
                        <div class="card-header"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Timeline</div>
                        <div class="card-body" id="liveTimeline" data-live-region>
                            <?php if (empty($timeline)): ?>
                                <p class="text-muted mb-0">No activity recorded yet.</p>
                            <?php else: ?>
                                <ul class="nemsu-timeline">
                                    <?php foreach ($timeline as $event): ?>
                                        <li class="nemsu-timeline__item">
                                            <div class="nemsu-timeline__marker" aria-hidden="true"><i class="bi bi-dot"></i></div>
                                            <div class="nemsu-timeline__body">
                                                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                                    <strong><?php echo htmlspecialchars($event['full_name']); ?></strong>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars(formatStatus($event['action_type'])); ?></span>
                                                    <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($event['created_at'])); ?></small>
                                                </div>
                                                <p class="mb-0 small"><?php echo htmlspecialchars($event['action_description']); ?></p>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel">
                        <div class="card-header"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Chat</div>
                        <div class="card-body">
                            <!-- data-live-region: swapped in place by live updates (app.js) while the user is typing -->
                            <div class="nemsu-chat-thread mb-3" aria-live="polite" id="liveComments" data-live-region>
                                <?php if (empty($comments)): ?>
                                    <p class="text-muted small mb-0"><?php echo $isStudent ? 'No messages yet. Staff replies will appear here.' : 'No messages yet. Only you and the student can see this conversation.'; ?></p>
                                <?php else: ?>
                                    <?php foreach ($comments as $comment): ?>
                                        <?php $mine = (int)$comment['user_id'] === $currentUserId; ?>
                                        <article class="nemsu-chat-bubble <?php echo $mine ? 'nemsu-chat-bubble--mine' : 'nemsu-chat-bubble--theirs'; ?>">
                                            <header class="nemsu-chat-bubble__meta">
                                                <strong><?php echo htmlspecialchars($comment['full_name']); ?></strong>
                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(roleLabel($comment['role'])); ?></span>
                                                <?php if ($isStudent && $mine && !empty($comment['thread_with_name'])): ?>
                                                    <span class="small text-muted">to <?php echo htmlspecialchars($comment['thread_with_name'] . ' (' . roleLabel($comment['thread_with_role']) . ')'); ?></span>
                                                <?php endif; ?>
                                                <time datetime="<?php echo htmlspecialchars($comment['created_at']); ?>"><?php echo date('M j, g:i A', strtotime($comment['created_at'])); ?></time>
                                            </header>
                                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></p>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($canComment): ?>
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <label for="comment_text" class="form-label">Message</label>
                                <textarea class="form-control mb-2" id="comment_text" name="comment_text" rows="3" required></textarea>
                                <div class="form-text mb-2"><i class="bi bi-lock me-1" aria-hidden="true"></i><?php echo $isStudent
                                    ? 'Private: only you and ' . htmlspecialchars($complaint['assigned_name'] ?? 'the staff member handling it') . ' can see this message.'
                                    : 'Private: only you and the student can see this conversation.'; ?></div>
                                <button type="submit" name="add_comment" class="btn btn-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Send</button>
                            </form>
                            <?php else: ?>
                                <p class="small text-muted mb-0"><i class="bi bi-eye me-1" aria-hidden="true"></i>View only: you can no longer chat on this complaint.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="nemsu-sticky-panel">
                        <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel mb-3">
                            <div class="card-header">Case status</div>
                            <div class="card-body">
                                <p class="mb-2"><?php echo statusBadgeHtml($complaint['status']); ?></p>
                                <p class="mb-2"><?php echo severityBadgeHtml($complaint['severity']); ?></p>
                                <p class="small text-muted mb-1">Created <?php echo date('M j, Y g:i A', strtotime($complaint['created_at'])); ?></p>
                                <p class="small text-muted mb-0">Updated <?php echo date('M j, Y g:i A', strtotime($complaint['updated_at'])); ?></p>

                                <?php if ($canManage): ?>
                                    <hr>
                                    <form method="POST">
                                        <?php echo csrfField(); ?>
                                        <label for="new_status" class="form-label">Update status</label>
                                        <select class="form-select mb-2" id="new_status" name="new_status" required<?php echo $isClosed ? ' disabled' : ''; ?>>
                                            <?php foreach (selectableStatusesForUpdate($complaint['status']) as $statusOption): ?>
                                                <?php $locked = $statusOption === 'resolved' && !$canResolve && $complaint['status'] !== 'resolved'; ?>
                                                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $complaint['status'] === $statusOption ? 'selected' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>>
                                                    <?php echo htmlspecialchars(formatStatus($statusOption)); ?><?php echo $locked ? ' — needs approved proof' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!$canResolve && !$isClosed): ?>
                                            <p class="small text-muted mb-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>To resolve, upload proof in <a href="#resolution-proof">Resolution proof</a>; the student must approve it first.</p>
                                        <?php endif; ?>
                                        <?php if ($isClosed): ?>
                                            <!-- Final status: nothing left to change. -->
                                            <button type="button" class="btn btn-success w-100" disabled aria-describedby="statusClosedHint">
                                                <i class="bi bi-lock me-1" aria-hidden="true"></i>Save status
                                            </button>
                                            <p class="small text-muted mt-2 mb-0" id="statusClosedHint">
                                                This case is already <strong><?php echo htmlspecialchars(formatStatus($complaint['status'])); ?></strong>, so its status can no longer be changed.
                                            </p>
                                        <?php else: ?>
                                            <button type="submit" name="update_status" class="btn btn-success w-100">Save status</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($canEscalate && $nextLevel): ?>
                            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel border-warning">
                                <div class="card-header bg-warning-subtle">Escalate case</div>
                                <div class="card-body">
                                    <p class="small text-muted">Send to <?php echo htmlspecialchars(workflowLevelLabel($nextLevel)); ?> if this office cannot resolve the case.</p>
                                    <button type="button" class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#escalateModal">
                                        <i class="bi bi-arrow-up-circle me-1" aria-hidden="true"></i>Escalate
                                    </button>
                                </div>
                            </div>
                        <?php elseif ($escalateLocked && $nextLevel): ?>
                            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel border-warning">
                                <div class="card-header bg-warning-subtle">Escalate case</div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-warning w-100" disabled aria-describedby="escalateLockedHint">
                                        <i class="bi bi-lock me-1" aria-hidden="true"></i>Escalate
                                    </button>
                                    <p class="small text-muted mt-2 mb-0" id="escalateLockedHint">
                                        This case is still <strong>Pending</strong>. Change its status (for example to Under review) first, then you can escalate it to <?php echo htmlspecialchars(workflowLevelLabel($nextLevel)); ?>.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php if ($canEscalate && $nextLevel): ?>
<div class="modal fade" id="escalateModal" tabindex="-1" aria-labelledby="escalateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="escalateModalLabel">Confirm escalation</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">This will reassign the case to <?php echo htmlspecialchars(workflowLevelLabel($nextLevel)); ?>.</p>
                    <label for="escalation_reason" class="form-label">Reason for escalation *</label>
                    <textarea class="form-control" id="escalation_reason" name="escalation_reason" rows="4" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="escalate" class="btn btn-warning">Escalate case</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($complaint['supporting_documents'] || $proofs): ?>
<!-- Document viewer pop-up (filled by initDocViewer in app.js on computers/tablets) -->
<div class="modal fade" id="docViewerModal" tabindex="-1" aria-labelledby="docViewerTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="docViewerTitle"><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Document</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body nemsu-doc-viewer" id="docViewerBody"></div>
            <div class="modal-footer">
                <a href="#" class="btn btn-outline-secondary" id="docViewerOpen" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open in new tab
                </a>
                <a href="#" class="btn btn-primary" id="docViewerDownload" download>
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Download
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/scripts.php'; ?>
</body>
</html>
