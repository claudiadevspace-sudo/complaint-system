<?php
/**
 * Unlock requests moved to the Super Admin: super_admin_unlock_requests.php.
 * This old address forwards there (or back to the dashboard for other roles).
 */
require_once 'config/config.php';
requireLogin();
if (hasRole('super_admin')) {
    redirect('super_admin_unlock_requests.php' . (isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : ''));
}
$_SESSION['message'] = 'Account unlock requests are now handled by the Super Admin.';
$_SESSION['message_type'] = 'info';
redirect('dashboard.php');
