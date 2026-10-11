<?php
/**
 * Live updates: a tiny JSON answer that assets/js/app.js asks for every few
 * seconds. When 'sig' differs from the one the page was built with, list
 * pages reload themselves (a complaint was escalated to this user, etc.).
 */
require_once 'config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['signed_in' => false]);
    exit;
}

try {
    echo json_encode(['signed_in' => true] + liveStateFor((int)$_SESSION['user_id'], (string)$_SESSION['role'], (int)($_GET['complaint'] ?? 0)));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['signed_in' => true, 'error' => true]);
}
