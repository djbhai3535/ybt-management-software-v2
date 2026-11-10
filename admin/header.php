<?php
/**
 * DJ CRM Software 5.2.2 — Super Admin Responsive SaaS Navigation Header
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
$activeAdsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM advertisements WHERE is_active = 1");
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
            --admin-border: rgba(148, 163, 184, 0.15);
        }
        .admin-topbar {
            background: #0f172a;
            border-bottom: 1px solid var(--admin-border);
            padding: 0.5rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
        }
        .admin-brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
            color: #f8fafc;
            flex-shrink: 0;
        }
        .admin-brand-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .admin-brand-text {
            line-height: 1.1;
        }
        .admin-brand-title {
            font-weight: 800;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            color: #f8fafc;
        }
        .admin-brand-badge {
            font-size: 0.65rem;
            color: #f59e0b;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .admin-nav-pills {
            display: flex;
            gap: 0.25rem;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }
        .admin-nav-link {
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.8125rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }
        .admin-nav-link:hover {
            color: #f8fafc;
            background: rgba(255, 255, 255, 0.05);
            transform: translateY(-1px);
        }
        .admin-nav-link.active {
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.14);
            font-weight: 600;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .admin-nav-link.nav-whatsapp {
            color: #25d366;
            background: rgba(37, 211, 102, 0.08);
            border: 1px solid rgba(37, 211, 102, 0.25);
        }
        .admin-nav-link.nav-whatsapp:hover {
            color: #ffffff;
            background: #25d366;
            border-color: #25d366;
        }
        .admin-nav-link.nav-whatsapp.active {
            color: #ffffff;
            background: #25d366;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(37, 211, 102, 0.4);
        }
        .admin-badge-count {
            background: rgba(239, 68, 68, 0.25);
            color: #ef4444;
            font-size: 0.68rem;
            padding: 0.1rem 0.4rem;
            border-radius: 10px;
            font-weight: 700;
            line-height: 1;
        }
        .admin-badge-count-warning {
            background: rgba(245, 158, 11, 0.25);
            color: #f59e0b;
        }
        .admin-container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem 1.25rem;
        }
        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: 1px solid var(--admin-border);
            color: #f8fafc;
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.25rem;
        }
        @media (max-width: 1100px) {
            .mobile-menu-btn {
                display: block;
            }
            .admin-nav-pills {
                display: none;
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                padding-top: 0.75rem;
                margin-top: 0.5rem;
                border-top: 1px solid var(--admin-border);
            }
            .admin-nav-pills.mobile-open {
                display: flex;
            }
            .admin-nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.88rem;
            }
            .admin-topbar {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <header class="admin-topbar">
        <div class="d-flex align-center gap-3">
            <a href="<?= url('admin/index.php') ?>" class="admin-brand">
                <div class="admin-brand-icon">
                    <i class="ti ti-shield-lock"></i>
                </div>
                <div class="admin-brand-text">
                    <div class="admin-brand-title">DJ CRM SOFTWARE</div>
                    <div class="admin-brand-badge">SaaS Platform Admin</div>
                </div>
            </a>
        </div>

        <button type="button" class="mobile-menu-btn" id="admin-mobile-toggle" aria-label="Toggle Navigation">
            <i class="ti ti-menu-2"></i>
        </button>

        <nav class="admin-nav-pills" id="admin-nav-menu">
            <a href="<?= url('admin/index.php') ?>" class="admin-nav-link <?= $adminPage === 'index.php' ? 'active' : '' ?>">
                <i class="ti ti-layout-dashboard"></i> Dashboard
            </a>
            <a href="<?= url('admin/tenants.php') ?>" class="admin-nav-link <?= in_array($adminPage, ['tenants.php', 'tenant-view.php']) ? 'active' : '' ?>">
                <i class="ti ti-building-store"></i> Shops
                <?php if ($suspendedShopsCount > 0): ?>
                    <span class="admin-badge-count" title="Suspended Shops"><?= $suspendedShopsCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('admin/subscriptions.php') ?>" class="admin-nav-link <?= $adminPage === 'subscriptions.php' ? 'active' : '' ?>">
                <i class="ti ti-crown"></i> Subscriptions
                <?php if ($pendingSubCount > 0): ?>
                    <span class="admin-badge-count admin-badge-count-warning" title="Pending Approvals"><?= $pendingSubCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('admin/users.php') ?>" class="admin-nav-link <?= $adminPage === 'users.php' ? 'active' : '' ?>">
                <i class="ti ti-users"></i> Users
            </a>
            <a href="<?= url('admin/advertisements.php') ?>" class="admin-nav-link <?= $adminPage === 'advertisements.php' ? 'active' : '' ?>">
                <i class="ti ti-ad"></i> Advertisements
                <?php if ($activeAdsCount > 0): ?>
                    <span class="admin-badge-count" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8;"><?= $activeAdsCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('admin/whatsapp-support.php') ?>" class="admin-nav-link nav-whatsapp <?= $adminPage === 'whatsapp-support.php' ? 'active' : '' ?>">
                <i class="ti ti-brand-whatsapp"></i> Support & WhatsApp
            </a>
            <a href="<?= url('admin/settings.php') ?>" class="admin-nav-link <?= $adminPage === 'settings.php' ? 'active' : '' ?>">
                <i class="ti ti-adjustments-alt"></i> Platform & AI
            </a>
            <a href="<?= url('admin/announcements.php') ?>" class="admin-nav-link <?= $adminPage === 'announcements.php' ? 'active' : '' ?>">
                <i class="ti ti-speakerphone"></i> Announcements
            </a>
            <a href="<?= url('admin/activity-logs.php') ?>" class="admin-nav-link <?= $adminPage === 'activity-logs.php' ? 'active' : '' ?>">
                <i class="ti ti-file-text"></i> Audit Logs
            </a>
            <a href="<?= url('admin/system-health.php') ?>" class="admin-nav-link <?= $adminPage === 'system-health.php' ? 'active' : '' ?>">
                <i class="ti ti-heart-rate-monitor"></i> System Health
            </a>
            <a href="<?= url('dashboard.php') ?>" class="admin-nav-link text-muted" title="Switch to Normal Shopkeeper View">
                <i class="ti ti-arrow-back-up"></i> Shop View
            </a>
        </nav>

        <div class="d-flex align-center gap-2">
            <div class="d-none-sm d-flex align-center gap-2" style="background: rgba(255, 255, 255, 0.04); padding: 0.25rem 0.65rem; border-radius: 20px; border: 1px solid var(--admin-border);">
                <div style="width: 24px; height: 24px; border-radius: 50%; background: #f59e0b; color: #000; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.72rem;">
                    SA
                </div>
                <div style="font-size: 0.78rem; font-weight: 600; color: #f8fafc; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= e(Auth::name() ?: 'Super Admin') ?>
                </div>
            </div>
            <a href="<?= url('logout.php') ?>" class="btn btn-secondary btn-sm" title="Sign Out" style="padding: 0.35rem 0.6rem;">
                <i class="ti ti-logout"></i>
            </a>
        </div>
    </header>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('admin-mobile-toggle');
        const menu = document.getElementById('admin-nav-menu');
        if (toggle && menu) {
            toggle.addEventListener('click', function() {
                menu.classList.toggle('mobile-open');
            });
        }
    });
    </script>

    <main class="admin-container">
        <?php if ($flash = Flash::get('success')): ?>
            <div class="alert alert-success mb-3 d-flex align-center gap-2" style="border-radius: 8px;">
                <i class="ti ti-circle-check fs-5"></i>
                <span><?= e($flash) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($flash = Flash::get('error')): ?>
            <div class="alert alert-danger mb-3 d-flex align-center gap-2" style="border-radius: 8px;">
                <i class="ti ti-alert-circle fs-5"></i>
                <span><?= e($flash) ?></span>
            </div>
        <?php endif; ?>
