<?php
require_once 'config/config.php';
requireLogin();

if (!hasRole('oswd')) {
    redirect('dashboard.php');
}

$complaint = new Complaint();
$loginSecurity = new LoginSecurity();
$all = $complaint->getAllComplaints();
$stats = $complaint->getStatistics();
$total = count($all);
$loginSummary = $loginSecurity->getSummary(7);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="oswd_complaints_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Title', 'Category', 'Status', 'Severity', 'Complainant', 'Created', 'Updated']);
    foreach ($all as $row) {
        fputcsv($out, [
            $row['complaint_id'],
            $row['complaint_title'],
            $row['complaint_category'] ?? '',
            $row['status'],
            severityMeta($row['severity'])['label'],
            $row['complainant_name'] ?? '',
            $row['created_at'],
            $row['updated_at'],
        ]);
    }
    fclose($out);
    exit;
}

/*
 * Complaint analytics: complaints per category over a school year (by month)
 * or a single month of it (by day). ?sy=2026 picks the school year,
 * ?month=2026-10 picks one month; no month = the whole school year.
 */
$analytics = new ComplaintAnalytics();
$schoolYears = $analytics->availableSchoolYears();
$selectedSy = in_array((int)($_GET['sy'] ?? 0), $schoolYears, true) ? (int)$_GET['sy'] : $schoolYears[0];
$syMonths = $analytics->months($selectedSy);
$selectedMonth = in_array($_GET['month'] ?? '', $syMonths, true) ? $_GET['month'] : null;
$analyticsData = $analytics->data($selectedSy, $selectedMonth, 'category');
$statusAnalytics = $analytics->data($selectedSy, $selectedMonth, 'status');
$analyticsQuery = http_build_query(array_filter(['sy' => $selectedSy, 'month' => $selectedMonth]));

if (isset($_GET['export']) && $_GET['export'] === 'analytics_pdf') {
    // ?by=status exports the status graph; otherwise the category graph.
    $byStatus = ($_GET['by'] ?? '') === 'status';
    $exportData = $byStatus ? $statusAnalytics : $analyticsData;
    $pdf = ReportExporter::analyticsPdf($exportData, [
        'generated' => date('M j, Y g:i A'),
        'by' => $_SESSION['full_name'] . ' (' . $_SESSION['username'] . ')',
    ]);
    (new AuditLog())->record('report_export', 'report', null, 'Analytics PDF (by ' . strtolower($exportData['dimension_label']) . ')', null,
        ['school_year' => $exportData['school_year'], 'period' => $exportData['period'], 'complaints' => $exportData['total']]);
    $filename = 'complaint_analytics_by_' . ($byStatus ? 'status_' : 'category_') . $selectedSy . '-' . ($selectedSy + 1) . ($selectedMonth ? '_' . $selectedMonth : '') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$byCategory = [];
$resolutionDays = [];
$resolvedCount = 0;

foreach ($all as $row) {
    $cat = $row['complaint_category'] ?? 'Uncategorized';
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
    if (($row['status'] ?? '') === 'resolved') {
        $resolvedCount++;
        $days = (strtotime($row['updated_at']) - strtotime($row['created_at'])) / 86400;
        if ($days >= 0) {
            $resolutionDays[] = $days;
        }
    }
}

$avgResolution = count($resolutionDays) ? round(array_sum($resolutionDays) / count($resolutionDays), 1) : 0;

$chartCategory = ['label' => 'By category', 'labels' => array_keys($byCategory), 'values' => array_values($byCategory)];
// Same color per category as the analytics line graph.
$chartCategory['colors'] = array_map(fn($cat) => $analyticsData['colors'][$cat] ?? '#a3aec2', $chartCategory['labels']);

$pageScripts = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', 'assets/js/reports.js?v=' . (int)@filemtime(__DIR__ . '/assets/js/reports.js')];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Reports - ' . SITE_NAME; include 'includes/head.php'; ?>
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
                    <h1 class="h2">Reports</h1>
                    <p class="text-muted mb-0">Complaint trends and performance overview</p>
                </div>
                <a href="reports.php?export=csv" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV
                </a>
            </div>

            <div class="row g-3 mb-4 nemsu-stat-grid">
                <div class="col-6 col-md-3">
                    <div class="card nemsu-stat-card nemsu-stat-card--blue h-100">
                        <div class="card-body">
                            <i class="bi bi-pie-chart stat-icon" aria-hidden="true"></i>
                            <h3 class="h6">Total</h3>
                            <p class="display-6 mb-0"><?php echo $total; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card nemsu-stat-card nemsu-stat-card--gold h-100">
                        <div class="card-body">
                            <i class="bi bi-hourglass-split stat-icon" aria-hidden="true"></i>
                            <h3 class="h6">Pending</h3>
                            <p class="display-6 mb-0"><?php echo $stats['pending'] ?? 0; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card nemsu-stat-card nemsu-stat-card--success h-100">
                        <div class="card-body">
                            <i class="bi bi-check2-circle stat-icon" aria-hidden="true"></i>
                            <h3 class="h6">Resolved</h3>
                            <p class="display-6 mb-0"><?php echo $stats['resolved'] ?? 0; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card nemsu-stat-card nemsu-stat-card--sky h-100">
                        <div class="card-body">
                            <i class="bi bi-stopwatch stat-icon" aria-hidden="true"></i>
                            <h3 class="h6">Avg resolution</h3>
                            <p class="display-6 mb-0"><?php echo $avgResolution; ?><small class="fs-6">d</small></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== Analytics period: one choice drives both graphs below ===== -->
            <form method="GET" data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel nemsu-analytics mb-4">
                <div class="card-header"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Analytics period</div>
                <div class="card-body row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label for="sy" class="form-label small mb-1">School year</label>
                        <select class="form-select form-select-sm" id="sy" name="sy">
                            <?php foreach ($schoolYears as $sy): ?>
                                <option value="<?php echo $sy; ?>" <?php echo $sy === $selectedSy ? 'selected' : ''; ?>><?php echo ComplaintAnalytics::label($sy); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="month" class="form-label small mb-1">View</label>
                        <select class="form-select form-select-sm" id="month" name="month">
                            <option value="">Whole school year (by month)</option>
                            <?php foreach ($syMonths as $m): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === $selectedMonth ? 'selected' : ''; ?>><?php echo date('F Y', strtotime($m . '-01')); ?> (by day)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Show</button>
                    </div>
                    <div class="col-12 col-md-4 text-md-end small nemsu-analytics-note">
                        <strong><?php echo $analyticsData['total']; ?></strong> complaint(s) in <?php echo htmlspecialchars($analyticsData['period']); ?>
                    </div>
                </div>
            </form>

            <?php
            // Same card layout for both breakdowns (includes/analytics_card.php).
            $card = $analyticsData;
            $cardId = 'chartAnalytics';
            $cardTitle = 'Complaint analytics by category';
            $cardIcon = 'graph-up';
            $cardExport = 'reports.php?' . $analyticsQuery . '&export=analytics_pdf&by=category';
            $cardNote = '';
            include 'includes/analytics_card.php';

            $card = $statusAnalytics;
            $cardId = 'chartAnalyticsStatus';
            $cardTitle = 'Complaint analytics by status';
            $cardIcon = 'activity';
            $cardExport = 'reports.php?' . $analyticsQuery . '&export=analytics_pdf&by=status';
            $cardNote = 'Each point counts the complaints submitted in that period, by their current status.';
            include 'includes/analytics_card.php';
            ?>

            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel nemsu-analytics h-100">
                        <div class="card-header"><i class="bi bi-pie-chart me-2" aria-hidden="true"></i>By category (all time)</div>
                        <div class="card-body">
                            <div class="nemsu-chart-wrap" id="chartCategoryData" data-chart="<?php echo htmlspecialchars(json_encode($chartCategory), ENT_QUOTES, 'UTF-8'); ?>">
                                <canvas id="chartCategory" height="220" aria-label="Complaints by category chart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div data-bs-theme="<?php echo appTheme(); ?>" class="card nemsu-panel nemsu-analytics h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Login security (7 days)</span>
                            <a href="login_security.php" class="btn btn-sm btn-outline-primary">Details</a>
                        </div>
                        <div class="card-body d-flex align-items-center">
                            <div class="row text-center g-3 w-100">
                                <div class="col-4 col-md-2"><strong><?php echo $loginSummary['total']; ?></strong><br><small class="text-muted">Attempts</small></div>
                                <div class="col-4 col-md-2"><strong><?php echo $loginSummary['success']; ?></strong><br><small class="text-muted">Success</small></div>
                                <div class="col-4 col-md-2"><strong><?php echo $loginSummary['otp_required']; ?></strong><br><small class="text-muted">OTP</small></div>
                                <div class="col-4 col-md-2"><strong><?php echo $loginSummary['high_risk']; ?></strong><br><small class="text-muted">High risk</small></div>
                                <div class="col-4 col-md-2"><strong><?php echo $loginSummary['blocked']; ?></strong><br><small class="text-muted">Blocked</small></div>
                                <div class="col-4 col-md-2"><strong><?php echo $loginSecurity->modelExists() ? 'Ready' : 'Fallback'; ?></strong><br><small class="text-muted">Model</small></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<?php include 'includes/scripts.php'; ?>
</body>
</html>
