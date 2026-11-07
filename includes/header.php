<?php
/**
 * YBT Management Software — Page Header
 * Standard HTML head, CSS dependencies, and layout opening
 */
if (!defined('YBT_ROOT')) {
    exit('Direct script access denied.');
}

// Ensure auth check if not on public page (like login)
$isPublicPage = $isPublicPage ?? false;
if (!$isPublicPage) {
    Auth::requireLogin();
}

$pageTitle = isset($pageTitle) ? $pageTitle . ' — ' . APP_NAME : APP_NAME;
$currentTheme = $_COOKIE['ybt_theme'] ?? 'light';
$business = null;
if (Auth::check()) {
    $business = Database::fetch("SELECT * FROM businesses WHERE id = ?", [Auth::businessId()]);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="DJ CRM Software — Advanced Business Management, Billing, Invoicing & Financial Khata">
    <meta name="author" content="DJ CRM Software">
    <title><?= e($pageTitle) ?></title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Tabler Icons (Local with CDN fallback) -->
    <link rel="stylesheet" href="<?= asset('css/tabler-icons.min.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <!-- Core Stylesheets -->
    <link rel="stylesheet" href="<?= asset('css/global.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/themes.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/print.css') ?>" media="print">

    <!-- Global App Configuration -->
    <script>
        window.APP_URL = <?= json_encode(APP_URL) ?>;
        window.CSRF_TOKEN = <?= json_encode(Csrf::token()) ?>;
    </script>
    <script src="<?= asset('js/theme.js') ?>"></script>
</head>
<body class="<?= $isPublicPage ? 'public-layout' : 'app-layout' ?>">
<?php if (!$isPublicPage): ?>
<div class="mobile-overlay"></div>
<div class="app-shell">
    <?php require YBT_ROOT . '/includes/sidebar.php'; ?>
    <div class="main-wrapper">
        <?php require YBT_ROOT . '/includes/topbar.php'; ?>
        <main class="main-content">
            <div class="content-container">
                <?php Flash::render(); ?>
<?php endif; ?>
