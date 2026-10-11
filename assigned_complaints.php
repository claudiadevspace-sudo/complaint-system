<?php
require_once 'config/config.php';
requireLogin();

if (!hasRole(staffRoles())) {
    redirect('dashboard.php');
}

$complaint = new Complaint();
$isOswd = ($_SESSION['role'] ?? '') === 'oswd';
// Which list this page shows:
//   assigned  — staff: complaints they hold (or escalated onward, view only)
//   escalated — OSWD: "Escalated to me" (cases escalated up to the OSWD office)
//   services  — OSWD: Campus Services & Facilities (services_complaints.php)
$listMode = $listMode ?? ($isOswd ? 'escalated' : 'assigned');
$serviceFilter = '';
if ($listMode === 'services') {
    $complaints = $complaint->getServiceComplaints();
    $serviceCounts = array_count_values(array_column($complaints, 'complaint_category'));
    $serviceFilter = in_array($_GET['category'] ?? '', serviceCategories(), true) ? $_GET['category'] : '';
    if ($serviceFilter !== '') {
        $complaints = array_values(array_filter($complaints, fn($c) => $c['complaint_category'] === $serviceFilter));
    }
} elseif ($listMode === 'escalated') {
    $complaints = $complaint->getEscalatedToOffice('oswd');
} else {
    $complaints = $complaint->getAssignedComplaints($_SESSION['user_id'], $_SESSION['role'] ?? null);
}
sortComplaintsBySeverity($complaints);   // Severity 1 first
$roleMeta = dashboardMetaForRole($_SESSION['role']);
// [heading, subtitle, empty-list message]
$listMeta = [
    'assigned' => ['Assigned to me', $roleMeta['subtitle'], 'No complaints are assigned to you right now.'],
    'escalated' => ['Escalated to me', 'Complaints escalated up to OSWD from every department and program (Coordinator → Chair → Guidance → OSWD).', 'No complaints have been escalated to OSWD.'],
    'services' => ['Campus services & facilities', 'Complaints about campus services (enrollment, ID, records) and facilities (classrooms, restrooms, equipment). These go directly to OSWD.', 'No campus services or facilities complaints.'],
][$listMode];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = $listMeta[0] . ' - ' . SITE_NAME; include 'includes/head.php'; ?>
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
                <h1 class="h2"><?php echo htmlspecialchars($listMeta[0]); ?></h1>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($listMeta[1]); ?></p>
            </div>

            <?php if ($listMode === 'services'): ?>
                <!-- Quick filter: both / Campus Services / Campus Facilities -->
                <nav class="nav nav-pills gap-2 mb-3" aria-label="Filter by category">
                    <a class="nav-link<?php echo $serviceFilter === '' ? ' active' : ''; ?>" href="services_complaints.php">
                        All <span class="badge bg-light text-dark ms-1"><?php echo array_sum($serviceCounts); ?></span>
                    </a>
                    <?php foreach (serviceCategories() as $cat): ?>
                        <a class="nav-link<?php echo $serviceFilter === $cat ? ' active' : ''; ?>" href="services_complaints.php?category=<?php echo urlencode($cat); ?>">
                            <?php echo htmlspecialchars($cat); ?> <span class="badge bg-light text-dark ms-1"><?php echo (int)($serviceCounts[$cat] ?? 0); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel">
                <div class="card-body p-0">
                    <?php if (empty($complaints)): ?>
                        <?php echo renderEmptyState('inbox', 'Queue is clear', $listMeta[2], 'Go to dashboard', 'dashboard.php'); ?>
                    <?php else: ?>
                        <div class="table-responsive nemsu-table-wrap">
                            <table class="table table-hover align-middle mb-0 nemsu-table nemsu-table--stack">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Complainant</th>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Severity</th>
                                        <th>Date</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($complaints as $comp): ?>
                                        <?php $critical = isUrgentSeverity($comp['severity'] ?? ''); ?>
                                        <tr class="<?php echo $critical ? 'table-danger' : ''; ?>">
                                            <td>#<?php echo (int)$comp['complaint_id']; ?></td>
                                            <td><?php echo htmlspecialchars($comp['complainant_name']); ?></td>
                                            <td><?php echo htmlspecialchars($comp['complaint_title']); ?></td>
                                            <td><?php echo statusBadgeHtml($comp['status']) . viewOnlyBadgeHtml($comp); ?></td>
                                            <td><?php echo severityBadgeHtml($comp['severity']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($comp['created_at'])); ?></td>
                                            <td><a href="view_complaint.php?id=<?php echo (int)$comp['complaint_id']; ?>" class="btn btn-sm btn-primary">Open</a></td>
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
