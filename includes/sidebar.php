<?php
$role = $_SESSION['role'] ?? '';
?>
<div class="col-auto px-0 nemsu-sidebar-col">
    <div class="offcanvas-md offcanvas-start sidebar nemsu-sidebar" tabindex="-1" id="sidebarOffcanvas" data-bs-theme="<?php echo appTheme(); ?>"
         aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header d-md-none border-bottom">
            <h2 class="offcanvas-title h6" id="sidebarOffcanvasLabel">Navigation</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarOffcanvas"
                    aria-label="Close navigation"></button>
        </div>
        <div class="offcanvas-body nemsu-sidebar__body">
            <?php if ($role === 'super_admin'): ?>
                <?php
                // Super Admin menu. Every page it links to checks
                // requireRole('super_admin') itself; hiding links is not security.
                $superAdminLinks = [
                    'super_admin.php' => ['speedometer2', 'Overview'],
                    'super_admin_auth.php' => ['shield-lock', 'Authentication'],
                    'super_admin_users.php' => ['people', 'User management'],
                    'super_admin_unlock_requests.php' => ['unlock', 'Unlock requests'],
                    'super_admin_reveal.php' => ['incognito', 'Identity reveal'],
                    'super_admin_complaints.php' => ['folder2-open', 'Complaints'],
                    'super_admin_audit.php' => ['journal-check', 'Audit log'],
                    'super_admin_reports.php' => ['file-earmark-arrow-down', 'Reports'],
                    'super_admin_backup.php' => ['database-down', 'Backup & restore'],
                    'super_admin_settings.php' => ['sliders', 'Settings'],
                ];
                $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
                // Pending unlock requests, shown as a badge on the menu item.
                $pendingUnlocks = 0;
                try {
                    $pendingUnlocks = (new AccountLockout())->getPendingCount();
                } catch (Throwable $e) {
                    $pendingUnlocks = 0;
                }
                ?>
                <p class="sidebar-label">Super Admin</p>
                <ul class="nav flex-column nemsu-sidebar__nav">
                    <?php foreach ($superAdminLinks as $file => [$icon, $label]): ?>
                        <?php
                        // The complaint detail page highlights "Complaints".
                        $active = $currentPage === $file
                            || ($file === 'super_admin_complaints.php' && $currentPage === 'super_admin_complaint.php');
                        ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo $file; ?>">
                                <i class="bi bi-<?php echo $icon; ?>" aria-hidden="true"></i>
                                <span class="nemsu-sidebar__text"><?php echo $label; ?></span>
                                <?php if ($file === 'super_admin_unlock_requests.php' && $pendingUnlocks > 0): ?>
                                    <span class="badge rounded-pill bg-warning text-dark ms-auto" title="Pending unlock requests"><?php echo (int)$pendingUnlocks; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
            <p class="sidebar-label">Navigation</p>
            <ul class="nav flex-column nemsu-sidebar__nav">
                <li class="nav-item">
                    <a class="<?php echo navLinkClass('dashboard.php'); ?>" href="dashboard.php">
                        <i class="bi bi-house-door" aria-hidden="true"></i>
                        <span class="nemsu-sidebar__text">Dashboard</span>
                    </a>
                </li>

                <?php if ($role === 'student'): ?>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('submit_complaint.php'); ?>" href="submit_complaint.php">
                            <i class="bi bi-plus-circle" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Submit complaint</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('my_complaints.php'); ?>" href="my_complaints.php">
                            <i class="bi bi-journal-text" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">My complaints</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('notifications.php'); ?>" href="notifications.php">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Notifications</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (in_array($role, staffRoles(), true) && $role !== 'oswd'): ?>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('assigned_complaints.php'); ?>" href="assigned_complaints.php">
                            <i class="bi bi-inbox" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Assigned to me</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (in_array($role, staffRoles(), true)): ?>
                    <!-- Staff notifications (students have theirs in the list above). -->
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('notifications.php'); ?>" href="notifications.php">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Notifications</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if ($role === 'oswd'): ?>
                <p class="sidebar-label nemsu-sidebar__section-label">Administration</p>
                <ul class="nav flex-column nemsu-sidebar__nav">
                    <?php
                    // Escalated cases waiting for OSWD (still Pending).
                    $escalatedPending = 0;
                    try {
                        $escalatedPending = count(array_filter((new Complaint())->getEscalatedToOffice('oswd'),
                            fn($c) => ($c['status'] ?? '') === 'pending'));
                    } catch (Throwable $e) {
                        $escalatedPending = 0;
                    }
                    ?>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('assigned_complaints.php'); ?>" href="assigned_complaints.php">
                            <i class="bi bi-arrow-up-circle" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Escalated to me</span>
                            <?php if ($escalatedPending > 0): ?>
                                <span class="badge rounded-pill bg-warning text-dark ms-auto" title="Pending escalated cases"><?php echo $escalatedPending; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php
                    // Campus Services & Facilities complaints still Pending.
                    $servicesPending = 0;
                    try {
                        $servicesPending = count(array_filter((new Complaint())->getServiceComplaints(),
                            fn($c) => ($c['status'] ?? '') === 'pending'));
                    } catch (Throwable $e) {
                        $servicesPending = 0;
                    }
                    ?>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('services_complaints.php'); ?>" href="services_complaints.php">
                            <i class="bi bi-building-gear" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text" title="Campus services &amp; facilities">Services &amp; facilities</span>
                            <?php if ($servicesPending > 0): ?>
                                <span class="badge rounded-pill bg-warning text-dark ms-auto" title="Pending services/facilities complaints"><?php echo $servicesPending; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('complaints.php'); ?>" href="complaints.php">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">All complaints</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('reports.php'); ?>" href="reports.php">
                            <i class="bi bi-bar-chart" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('login_security.php'); ?>" href="login_security.php">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Login security</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('enrolled_students.php'); ?>" href="enrolled_students.php">
                            <i class="bi bi-person-vcard" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Enrolled students</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="<?php echo navLinkClass('users.php'); ?>" href="users.php">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span class="nemsu-sidebar__text">Users</span>
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
            <?php endif; /* end: not super_admin */ ?>

            <?php
            // Signed-in user's profile, pinned to the bottom of the sidebar
            // (every role). Initials stand in for a photo.
            $sidebarName = trim((string)($_SESSION['full_name'] ?? 'User'));
            $sidebarInitials = '';
            foreach (array_slice(preg_split('/\s+/', $sidebarName), 0, 2) as $part) {
                $sidebarInitials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            ?>
            <div class="nemsu-sidebar__profile">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="nemsu-sidebar__avatar" aria-hidden="true"><?php echo htmlspecialchars($sidebarInitials ?: 'U'); ?></span>
                    <div class="min-w-0">
                        <div class="nemsu-sidebar__profile-name" title="<?php echo htmlspecialchars($sidebarName); ?>"><?php echo htmlspecialchars($sidebarName); ?></div>
                        <div class="nemsu-sidebar__profile-role"><?php echo htmlspecialchars(roleLabel($role)); ?></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="profile.php" class="nemsu-sidebar__profile-btn<?php echo basename($_SERVER['SCRIPT_NAME'] ?? '') === 'profile.php' ? ' is-active' : ''; ?>">
                        <i class="bi bi-person-gear" aria-hidden="true"></i> Profile
                    </a>
                    <a href="logout.php" class="nemsu-sidebar__profile-btn nemsu-sidebar__profile-btn--logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Log out
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
