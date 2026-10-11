<?php
require_once 'config/config.php';
requireLogin();

if (!hasRole('oswd')) {
    $_SESSION['message'] = 'You do not have permission to manage users.';
    $_SESSION['message_type'] = 'danger';
    redirect('dashboard.php');
}

$userObj = new User();
$currentUserId = (int)$_SESSION['user_id'];
// Two tabs: current accounts, and archived accounts (users.php?view=archived).
$showArchived = ($_GET['view'] ?? '') === 'archived';

// Archive replaces delete: the account is hidden and cannot sign in, but its
// complaints and history are kept, and it can be restored.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['archive_user']) || isset($_POST['restore_user']))) {
    if (!verifyCsrf()) {
        $_SESSION['message'] = 'Invalid request. Please try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $result = isset($_POST['archive_user'])
            ? $userObj->archiveUser($targetId, $currentUserId)
            : $userObj->restoreUser($targetId);
        $_SESSION['message'] = $result['message'];
        $_SESSION['message_type'] = $result['success'] ? 'success' : 'danger';
    }
    redirect('users.php' . ($showArchived ? '?view=archived' : ''));
}

$users = $userObj->getAllUsers($showArchived);
$archivedCount = count($showArchived ? $users : $userObj->getAllUsers(true));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Users - ' . SITE_NAME; include 'includes/head.php'; ?>
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
                <h1 class="h2">Users</h1>
                <p class="text-muted mb-0">Registered accounts and roles. Archiving keeps all data and can be undone.</p>
            </div>

            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link<?php echo $showArchived ? '' : ' active'; ?>" href="users.php"<?php echo $showArchived ? '' : ' aria-current="page"'; ?>>
                        <i class="bi bi-people me-1" aria-hidden="true"></i>Active accounts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo $showArchived ? ' active' : ''; ?>" href="users.php?view=archived"<?php echo $showArchived ? ' aria-current="page"' : ''; ?>>
                        <i class="bi bi-archive me-1" aria-hidden="true"></i>Archived
                        <span class="badge rounded-pill bg-secondary ms-1"><?php echo $archivedCount; ?></span>
                    </a>
                </li>
            </ul>

            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel">
                <div class="card-header">
                    <i class="bi bi-<?php echo $showArchived ? 'archive' : 'people'; ?> me-2" aria-hidden="true"></i>
                    <?php echo $showArchived ? 'Archived accounts' : 'All registered users'; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($users)): ?>
                        <?php echo $showArchived
                            ? renderEmptyState('archive', 'No archived accounts', 'Archived accounts will appear here and can be restored.')
                            : renderEmptyState('people', 'No users found', 'There are no accounts in the system yet.'); ?>
                    <?php else: ?>
                        <div class="table-responsive nemsu-table-wrap">
                            <table class="table table-hover align-middle mb-0 nemsu-table nemsu-table--stack">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th><?php echo $showArchived ? 'Archived' : 'Status'; ?></th>
                                        <th>Created</th>
                                        <th class="text-end nemsu-stack-full">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <?php
                                        $isSelf = (int)$u['user_id'] === $currentUserId;
                                        // Archive confirmation; staff with open cases get a warning.
                                        $confirmMsg = 'Archive ' . ($u['full_name'] ?? 'this user') . ' (@' . ($u['username'] ?? '') . ')? '
                                            . 'They will not be able to sign in. Their complaints and history are kept, and you can restore the account later.';
                                        if ((int)$u['open_assigned'] > 0) {
                                            $confirmMsg .= "\n\nNote: " . (int)$u['open_assigned'] . ' open complaint(s) are still assigned to this person.';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo (int)$u['user_id']; ?></td>
                                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><span class="badge bg-primary nemsu-badge"><i class="bi bi-person-badge" aria-hidden="true"></i><span><?php echo htmlspecialchars(roleLabel($u['role'])); ?></span></span></td>
                                            <td>
                                                <?php if ($showArchived): ?>
                                                    <div><?php echo date('M j, Y', strtotime($u['archived_at'])); ?></div>
                                                    <?php if (!empty($u['archived_by_username'])): ?>
                                                        <div class="small text-muted">by <?php echo htmlspecialchars($u['archived_by_username']); ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-<?php echo $u['status'] === 'active' ? 'success' : 'secondary'; ?> nemsu-badge">
                                                        <i class="bi bi-<?php echo $u['status'] === 'active' ? 'check-circle' : 'pause-circle'; ?>" aria-hidden="true"></i>
                                                        <span><?php echo htmlspecialchars(ucfirst((string)$u['status'])); ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                                            <td class="text-end">
                                                <?php if ($isSelf): ?>
                                                    <span class="text-muted small">You</span>
                                                <?php elseif ($showArchived): ?>
                                                    <form method="POST" action="users.php?view=archived" class="d-inline"
                                                          data-confirm-message="Restore <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>? The account will be able to sign in again.">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['user_id']; ?>">
                                                        <button type="submit" name="restore_user" value="1" class="btn btn-sm btn-outline-success"
                                                                aria-label="Restore account <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                                            <span class="d-md-none d-lg-inline">Restore</span>
                                                        </button>
                                                    </form>
                                                <?php elseif ($u['role'] === 'super_admin'): ?>
                                                    <span class="text-muted small">Super Admin</span>
                                                <?php else: ?>
                                                    <form method="POST" action="users.php" class="d-inline"
                                                          data-no-loading="true"
                                                          data-confirm-message="<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['user_id']; ?>">
                                                        <button type="submit" name="archive_user" value="1"
                                                                class="btn btn-sm btn-outline-warning"
                                                                aria-label="Archive account <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <i class="bi bi-archive" aria-hidden="true"></i>
                                                            <span class="d-md-none d-lg-inline">Archive</span>
                                                        </button>
                                                    </form>
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
        </main>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
</body>
</html>
