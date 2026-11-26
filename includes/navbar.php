<?php
// Unread notifications for the bell. Every signed-in user has one: students
// (complaint updates), staff (e.g. a student approved/rejected resolution
// proof), OSWD, and the Super Admin (account unlock requests).
$unreadNotifications = 0;
if (isLoggedIn()) {
    try {
        $unreadNotifications = (new Notification())->getUnreadCount((int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        $unreadNotifications = 0;
    }
}

// Live updates (assets/js/app.js → live_status.php): every page keeps the bell
// current; these list pages also reload themselves when something changes,
// e.g. a complaint is escalated to this user — no manual refresh needed.
$liveState = null;
if (isLoggedIn()) {
    try {
        // On a complaint page, also watch that complaint (comments, status, proof).
        $liveComplaintId = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'view_complaint.php' ? (int)($_GET['id'] ?? 0) : 0;
        $liveState = liveStateFor((int)$_SESSION['user_id'], (string)$_SESSION['role'], $liveComplaintId);
    } catch (Throwable $e) {
        $liveState = null;
    }
}
$liveReloadPages = ['dashboard.php', 'assigned_complaints.php', 'complaints.php', 'my_complaints.php',
    'notifications.php', 'super_admin.php', 'super_admin_complaints.php', 'super_admin_unlock_requests.php', 'view_complaint.php', 'services_complaints.php'];
?>
<?php if ($liveState): ?>
<div id="liveUpdates" hidden
     data-live-url="live_status.php<?php echo !empty($liveComplaintId) ? '?complaint=' . (int)$liveComplaintId : ''; ?>"
     data-live-sig="<?php echo htmlspecialchars($liveState['sig'], ENT_QUOTES, 'UTF-8'); ?>"
     data-live-latest="<?php echo (int)$liveState['latest_id']; ?>"
     data-live-reload="<?php echo in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $liveReloadPages, true) ? '1' : '0'; ?>"></div>
<?php endif; ?>
<nav class="navbar navbar-expand-lg navbar-dark nemsu-navbar fixed-top">
    <div class="container-fluid">
        <button class="btn btn-link text-white d-md-none nemsu-sidebar-toggle me-1" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas"
                aria-label="Open navigation menu">
            <i class="bi bi-list fs-4" aria-hidden="true"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <?php echo nemsuLogoImg('nemsu-logo nemsu-logo--nav'); ?>
            <span class="d-none d-sm-inline">OSWD Complaints</span>
        </a>
        <!-- Right side: light/dark switch and notifications, always visible (also on phones).
             Profile and Log out are at the bottom of the sidebar. -->
        <ul class="navbar-nav flex-row ms-auto align-items-center gap-2 nemsu-navbar-actions">
            <li class="nav-item">
                <?php $isDark = appTheme() === 'dark'; ?>
                <!-- Light/dark mode switch (assets/js/theme.js). Shows the mode it switches TO. -->
                <button type="button" class="btn btn-sm btn-outline-light nemsu-theme-toggle" id="themeToggle"
                        data-current-theme="<?php echo $isDark ? 'dark' : 'light'; ?>"
                        aria-label="<?php echo $isDark ? 'Switch to light mode' : 'Switch to dark mode'; ?>"
                        title="<?php echo $isDark ? 'Switch to light mode' : 'Switch to dark mode'; ?>">
                    <i class="bi bi-<?php echo $isDark ? 'sun' : 'moon-stars'; ?>" aria-hidden="true"></i>
                </button>
            </li>
            <?php if (isLoggedIn()): ?>
                <li class="nav-item">
                    <a class="nav-link position-relative nemsu-nav-bell px-2" href="notifications.php" title="Notifications"
                       aria-label="Notifications<?php echo $unreadNotifications > 0 ? ', ' . $unreadNotifications . ' unread' : ''; ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="nemsu-notification-badge"><?php echo $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>
