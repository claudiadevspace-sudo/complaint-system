<?php
/**
 * OSWD: Campus Services & Facilities complaints (sidebar link).
 * Same list layout as assigned_complaints.php, in "services" mode.
 */
require_once 'config/config.php';
requireRole('oswd');

$listMode = 'services';
require __DIR__ . '/assigned_complaints.php';
