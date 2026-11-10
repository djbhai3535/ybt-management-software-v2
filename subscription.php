<?php
/**
 * DJ CRM Software 5.2.2 — Shopkeeper Subscription & Upgrade Redirect Route
 */
require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();
header('Location: ' . url('settings.php#subscription'));
exit;
