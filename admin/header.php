<?php
/**
 * YBT Management Software — Super Admin Navigation Header
 */

require_once __DIR__ . '/auth-check.php';

$adminPage = basename($_SERVER['SCRIPT_NAME']);
$pageTitle = $pageTitle ?? 'Super Admin Control Panel';
$adminUser = Auth::user();

// Quick platform counter badges
$totalShopsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses");
$suspendedShopsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM businesses WHERE status = 'suspended'");
$totalUsersCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM users");
$pendingSubCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM business_subscriptions WHERE status = 'pending_approval'");
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — DJ CRM Super Admin</title>
    <!-- Tabler Icons (Local with CDN fallback) -->
    <link rel="stylesheet" href="<?= asset('css/tabler-icons.min.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <!-- Core Stylesheets -->
    <link rel="stylesheet" href="<?= asset('css/global.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/themes.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/responsive.css') ?>">
    <style>
        :root {
            --admin-primary: #f59e0b;
            --admin-primary-dark: #d97706;
            --admin-primary-light: rgba(245, 158, 11, 0.15);
        }
        .admin-topbar {
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .admin-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--color-text);
        }
        .admin-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: bold;
        }
        .admin-nav-pills {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .admin-nav-link {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            color: var(--color-text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.15s ease;
        }
        .admin-nav-link:hover {
            color: var(--color-text);
            background: var(--color-bg);
        }
        .admin-nav-link.active {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.12);
            font-weight: 600;
        }
        .admin-badge-count {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            font-size: 0.7rem;
            padding: 0.1rem 0.45rem;
            border-radius: 10px;
            font-weight: bold;
        }
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
    </style>
</head>
<body>
    <header class="admin-topbar">
        <a href="<?= url('admin/index.php') ?>" class="admin-brand">
            <div class="admin-brand-icon">
                <i class="ti ti-shield-lock"></i>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 1.1rem; line-height: 1.2;">DJ CRM SOFTWARE</div>
                <div style="font-size: 0.72rem; color: #f59e0b; font-weight: 600; letter-spacing: 0.5px;">CENTRAL SUPER ADMIN PORTAL</div>
            </div>
        </a>

        <nav class="admin-nav-pills">
            <a href="<?= url('admin/index.php') ?>" class="admin-nav-link <?= $adminPage === 'index.php' ? 'active' : '' ?>">
                <i class="ti ti-layout-dashboard"></i> Dashboard
            </a>
            <a href="<?= url('admin/tenants.php') ?>" class="admin-nav-link <?= in_array($adminPage, ['tenants.php', 'tenant-view.php']) ? 'active' : '' ?>">
                <i class="ti ti-building-store"></i> Shops / Tenants
                <?php if ($suspendedShopsCount > 0): ?>
                    <span class="admin-badge-count" title="Suspended Shops"><?= $suspendedShopsCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('admin/subscriptions.php') ?>" class="admin-nav-link <?= $adminPage === 'subscriptions.php' ? 'active' : '' ?>">
                <i class="ti ti-crown"></i> Subscriptions & Plans
                <?php if ($pendingSubCount > 0): ?>
                    <span class="admin-badge-count" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;" title="Pending Payments"><?= $pendingSubCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('admin/users.php') ?>" class="admin-nav-link <?= $adminPage === 'users.php' ? 'active' : '' ?>">
                <i class="ti ti-users"></i> Users & Staff
            </a>
            <a href="<?= url('admin/settings.php') ?>" class="admin-nav-link <?= $adminPage === 'settings.php' ? 'active' : '' ?>">
                <i class="ti ti-adjustments-alt"></i> Platform & Central AI
            </a>
            <a href="<?= url('admin/announcements.php') ?>" class="admin-nav-link <?= $adminPage === 'announcements.php' ? 'active' : '' ?>">
                <i class="ti ti-speakerphone"></i> Announcements
            </a>
            <a href="<?= url('admin/advertisements.php') ?>" class="admin-nav-link <?= $adminPage === 'advertisements.php' ? 'active' : '' ?>">
                <i class="ti ti-ad"></i> Advertisements
            </a>
            <a href="<?= url('admin/activity-logs.php') ?>" class="admin-nav-link <?= $adminPage === 'activity-logs.php' ? 'active' : '' ?>">
                <i class="ti ti-file-text"></i> Audit Logs
            </a>
            <a href="<?= url('admin/system-health.php') ?>" class="admin-nav-link <?= $adminPage === 'system-health.php' ? 'active' : '' ?>">
                <i class="ti ti-heart-rate-monitor"></i> System Health
            </a>
            <a href="<?= url('dashboard.php') ?>" class="admin-nav-link text-muted" title="Switch to Shop View">
                <i class="ti ti-arrow-back-up"></i> Shop View
            </a>
        </nav>

        <div class="d-flex align-center gap-3">
            <div class="d-flex align-center gap-2">
                <div style="width: 32px; height: 32px; border-radius: 50%; background: #f59e0b; color: #000; font-weight: bold; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                    SA
                </div>
                <div style="font-size: 0.85rem; line-height: 1.2;">
                    <div style="font-weight: 600;"><?= e(Auth::name() ?: 'Super Administrator') ?></div>
                    <div style="font-size: 0.72rem; color: #f59e0b;">Platform Owner</div>
                </div>
            </div>
            <a href="<?= url('logout.php') ?>" class="btn btn-secondary btn-sm" title="Log Out">
                <i class="ti ti-logout"></i>
            </a>
        </div>
    </header>

    <main class="admin-container">
        <?php if ($flash = Flash::get('success')): ?>
            <div class="alert alert-success mb-4 d-flex align-center gap-2">
                <i class="ti ti-circle-check fs-5"></i>
                <span><?= e($flash) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($flash = Flash::get('error')): ?>
            <div class="alert alert-danger mb-4 d-flex align-center gap-2">
                <i class="ti ti-alert-circle fs-5"></i>
                <span><?= e($flash) ?></span>
            </div>
        <?php endif; ?>
