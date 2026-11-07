<?php
/**
 * YBT Management Software — Main Navigation Sidebar
 * Permission-controlled menu with expanded/compact modes
 */
if (!defined('YBT_ROOT')) exit;

$currentPage = basename($_SERVER['SCRIPT_NAME']);
$businessName = Auth::businessName() ?: 'YBT Business';

// Optional quick notification badge counts
$pendingTasksCount = 0;
$overdueInvoicesCount = 0;
if (Auth::check()) {
    $bId = Auth::businessId();
    $taskStat = Database::fetch("SELECT COUNT(*) as count FROM tasks WHERE business_id = ? AND status != 'completed' AND due_date <= CURDATE()", [$bId]);
    $pendingTasksCount = $taskStat['count'] ?? 0;

    $invStat = Database::fetch("SELECT COUNT(*) as count FROM invoices WHERE business_id = ? AND status = 'overdue'", [$bId]);
    $overdueInvoicesCount = $invStat['count'] ?? 0;
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="<?= url('dashboard.php') ?>" class="sidebar-brand">
            <div class="brand-logo-icon" style="background: transparent; box-shadow: none; padding: 0;">
                <img src="<?= asset('images/logo-icon.svg') ?>" alt="DJ CRM" style="width: 34px; height: 34px; border-radius: 8px;">
            </div>
            <div class="brand-text">
                <span class="brand-title">DJ <span style="color: var(--color-primary, #2563EB);">CRM</span></span>
                <span class="brand-subtitle">Software</span>
            </div>
        </a>
        <button type="button" class="sidebar-toggle-btn" data-action="toggle-sidebar" title="Toggle Sidebar">
            <i class="ti ti-chevron-left"></i>
        </button>
    </div>

    <div class="sidebar-business-info">
        <div class="business-badge-avatar" style="overflow: hidden;">
            <?php if (!empty($business['logo_url'])): ?>
                <img src="<?= url($business['logo_url']) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">
            <?php else: ?>
                <?= strtoupper(substr(e($businessName), 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="business-badge-details">
            <div class="business-name" title="<?= e($businessName) ?>"><?= e(truncate($businessName, 22)) ?></div>
            <div class="user-role-tag"><?= Auth::isOwner() ? 'Business Owner' : 'Operations Manager' ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <!-- 1. Dashboard -->
            <?php if (Permissions::has('dashboard.view')): ?>
            <li class="nav-item">
                <a href="<?= url('dashboard.php') ?>" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="ti ti-layout-dashboard nav-icon"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- CRM Section Header -->
            <li class="nav-section-title"><span>CRM & SALES</span></li>

            <!-- 2. Customers -->
            <?php if (Permissions::has('customers.view')): ?>
            <li class="nav-item">
                <a href="<?= url('customers.php') ?>" class="nav-link <?= in_array($currentPage, ['customers.php', 'customer-view.php']) ? 'active' : '' ?>">
                    <i class="ti ti-users nav-icon"></i>
                    <span class="nav-label">Customers</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 3. Leads & Deals -->
            <?php if (Permissions::has('leads.view')): ?>
            <li class="nav-item">
                <a href="<?= url('leads.php') ?>" class="nav-link <?= $currentPage === 'leads.php' ? 'active' : '' ?>">
                    <i class="ti ti-filter-star nav-icon"></i>
                    <span class="nav-label">Leads & Deals</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 4. Products & Services -->
            <?php if (Permissions::has('products.view')): ?>
            <li class="nav-item">
                <a href="<?= url('products.php') ?>" class="nav-link <?= $currentPage === 'products.php' ? 'active' : '' ?>">
                    <i class="ti ti-package nav-icon"></i>
                    <span class="nav-label">Products & Services</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 5. Tasks & Reminders -->
            <?php if (Permissions::has('tasks.view')): ?>
            <li class="nav-item">
                <a href="<?= url('tasks.php') ?>" class="nav-link <?= $currentPage === 'tasks.php' ? 'active' : '' ?>">
                    <i class="ti ti-checklist nav-icon"></i>
                    <span class="nav-label">Tasks & Reminders</span>
                    <?php if ($pendingTasksCount > 0): ?>
                        <span class="nav-badge badge-warning"><?= $pendingTasksCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- Financials Section Header -->
            <li class="nav-section-title"><span>FINANCE & BILLING</span></li>

            <!-- 6. Quotations -->
            <?php if (Permissions::has('quotations.view')): ?>
            <li class="nav-item">
                <a href="<?= url('quotations.php') ?>" class="nav-link <?= in_array($currentPage, ['quotations.php', 'quotation-create.php']) ? 'active' : '' ?>">
                    <i class="ti ti-file-text nav-icon"></i>
                    <span class="nav-label">Quotations</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 7. Billing & Invoices -->
            <?php if (Permissions::has('invoices.view')): ?>
            <li class="nav-item">
                <a href="<?= url('invoices.php') ?>" class="nav-link <?= in_array($currentPage, ['invoices.php', 'invoice-create.php', 'invoice-view.php']) ? 'active' : '' ?>">
                    <i class="ti ti-receipt-2 nav-icon"></i>
                    <span class="nav-label">Billing & Invoices</span>
                    <?php if ($overdueInvoicesCount > 0): ?>
                        <span class="nav-badge badge-danger"><?= $overdueInvoicesCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- 8. Payments -->
            <?php if (Permissions::has('payments.view')): ?>
            <li class="nav-item">
                <a href="<?= url('payments.php') ?>" class="nav-link <?= $currentPage === 'payments.php' ? 'active' : '' ?>">
                    <i class="ti ti-cash nav-icon"></i>
                    <span class="nav-label">Payments</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 9. Expenses -->
            <?php if (Permissions::has('expenses.view')): ?>
            <li class="nav-item">
                <a href="<?= url('expenses.php') ?>" class="nav-link <?= $currentPage === 'expenses.php' ? 'active' : '' ?>">
                    <i class="ti ti-wallet nav-icon"></i>
                    <span class="nav-label">Expenses</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 10. Reports -->
            <?php if (Permissions::has('reports.view')): ?>
            <li class="nav-item">
                <a href="<?= url('reports.php') ?>" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                    <i class="ti ti-chart-bar nav-icon"></i>
                    <span class="nav-label">Reports</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Intelligence & Administration -->
            <li class="nav-section-title"><span>SYSTEM & INTELLIGENCE</span></li>

            <!-- 11. AI Assistant -->
            <?php if (Permissions::has('ai.access')): ?>
            <li class="nav-item">
                <a href="<?= url('ai-assistant.php') ?>" class="nav-link ai-nav-highlight <?= $currentPage === 'ai-assistant.php' ? 'active' : '' ?>">
                    <i class="ti ti-sparkles nav-icon"></i>
                    <span class="nav-label">AI Assistant</span>
                    <span class="badge badge-accent badge-pulse">AI</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 12. Managers -->
            <?php if (Permissions::has('managers.view') || Auth::isOwner()): ?>
            <li class="nav-item">
                <a href="<?= url('managers.php') ?>" class="nav-link <?= $currentPage === 'managers.php' ? 'active' : '' ?>">
                    <i class="ti ti-user-shield nav-icon"></i>
                    <span class="nav-label">Managers</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- 13. Business Profile & Settings -->
            <?php if (Permissions::has('settings.access')): ?>
            <li class="nav-item">
                <a href="<?= url('settings.php#business') ?>" class="nav-link <?= ($currentPage === 'settings.php' && ($_GET['tab'] ?? '') === 'business') ? 'active' : '' ?>">
                    <i class="ti ti-building-store nav-icon"></i>
                    <span class="nav-label">Business Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= url('settings.php') ?>" class="nav-link <?= ($currentPage === 'settings.php' && ($_GET['tab'] ?? '') !== 'business') ? 'active' : '' ?>">
                    <i class="ti ti-settings nav-icon"></i>
                    <span class="nav-label">Settings</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (Auth::isSuperAdmin()): ?>
            <li class="nav-section-title"><span>PLATFORM OWNER</span></li>
            <li class="nav-item">
                <a href="<?= url('admin/index.php') ?>" class="nav-link text-warning font-semibold">
                    <i class="ti ti-shield-lock nav-icon text-warning"></i>
                    <span class="nav-label">Super Admin Panel</span>
                    <span class="badge badge-warning">ADMIN</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= url('logout.php') ?>" class="sidebar-logout-link" title="Logout">
            <i class="ti ti-logout"></i>
            <span class="logout-label">Logout</span>
        </a>
    </div>
</aside>
