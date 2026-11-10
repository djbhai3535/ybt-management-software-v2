<?php
/**
 * DJ CRM Software — Advertisement Click Tracker
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/ad-service.php';

$adId = (int)($_GET['id'] ?? 0);
if ($adId > 0) {
    AdService::recordClick($adId);
}

// If redirect requested
$redirect = trim($_GET['r'] ?? '');
if (!empty($redirect) && filter_var($redirect, FILTER_VALIDATE_URL)) {
    header('Location: ' . $redirect);
    exit;
}

http_response_code(204);
exit;
