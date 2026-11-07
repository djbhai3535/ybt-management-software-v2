<?php
/**
 * YBT Management Software — Topbar Header
 * Global search, quick create actions, notifications, theme toggle, and user menu
 */
if (!defined('YBT_ROOT')) exit;

$userName = Auth::name() ?: 'User';
$userEmail = Auth::email() ?: '';
$userInitial = strtoupper(substr($userName, 0, 1));
$isOwner = Auth::isOwner();
?>
<header class="topbar">
    <div class="topbar-left">
        <!-- Mobile Sidebar Toggle -->
        <button type="button" class="btn btn-icon btn-ghost mobile-menu-toggle" data-action="toggle-mobile-sidebar" aria-label="Open Navigation">
            <i class="ti ti-menu-2"></i>
        </button>

        <!-- Universal Global Search -->
        <div class="global-search-container" id="global-search-container">
            <div class="search-input-wrapper">
                <i class="ti ti-search search-icon" id="search-icon"></i>
                <i class="ti ti-loader-2 search-loading-spinner spinning-icon" id="search-loading-spinner" style="display: none;"></i>
                <input type="text" id="global-search-input" class="global-search-input" placeholder="Search customers, products, barcode, invoices, khata... (Ctrl+K)" autocomplete="off" spellcheck="false">
                <button type="button" class="search-clear-btn" id="search-clear-btn" style="display: none;" title="Clear search (Esc)">&times;</button>
                <kbd class="search-shortcut">Ctrl+K</kbd>
            </div>
            <div id="global-search-results" class="search-dropdown-results" style="display: none;"></div>
        </div>
    </div>

    <div class="topbar-right">
        <!-- Quick Create Dropdown -->
        <div class="dropdown quick-create-dropdown">
            <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-toggle="dropdown">
                <i class="ti ti-plus"></i>
                <span class="d-none-sm">Create</span>
                <i class="ti ti-chevron-down ml-1"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-right">
                <div class="dropdown-header">Quick Actions</div>
                <?php if (Permissions::has('invoices.create')): ?>
                    <a href="<?= url('invoice-create.php') ?>" class="dropdown-item">
                        <i class="ti ti-receipt text-primary"></i> New Invoice
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('quotations.create')): ?>
                    <a href="<?= url('quotation-create.php') ?>" class="dropdown-item">
                        <i class="ti ti-file-text text-secondary"></i> New Quotation
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('customers.create')): ?>
                    <a href="<?= url('customers.php?action=new') ?>" class="dropdown-item">
                        <i class="ti ti-user-plus text-success"></i> Add Customer
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('leads.create')): ?>
                    <a href="<?= url('leads.php?action=new') ?>" class="dropdown-item">
                        <i class="ti ti-star text-warning"></i> Add Lead
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('payments.record')): ?>
                    <a href="<?= url('payments.php?action=record') ?>" class="dropdown-item">
                        <i class="ti ti-cash text-info"></i> Record Payment
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('expenses.create')): ?>
                    <a href="<?= url('expenses.php?action=new') ?>" class="dropdown-item">
                        <i class="ti ti-wallet text-danger"></i> Log Expense
                    </a>
                <?php endif; ?>
                <?php if (Permissions::has('tasks.create')): ?>
                    <div class="dropdown-divider"></div>
                    <a href="<?= url('tasks.php?action=new') ?>" class="dropdown-item">
                        <i class="ti ti-checklist text-purple"></i> Add Task
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cloud Sync Status Indicator -->
        <div class="dropdown sync-status-dropdown" id="syncStatusDropdown">
            <button type="button" class="btn btn-sm sync-status-pill sync-status-synced dropdown-toggle" id="btnSyncStatus" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Cloud Sync Status">
                <i class="ti ti-cloud-check sync-status-icon" id="syncStatusIcon"></i>
                <span class="sync-status-text d-none-sm" id="syncStatusText">Synced</span>
                <span class="sync-pending-badge" id="syncPendingBadge" style="display: none;">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-right sync-popover-card" id="syncPopoverCard" style="min-width: 280px; padding: 0.85rem 1rem;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold fs-sm text-dark">Cloud Sync</span>
                    <span class="badge badge-subtle badge-success" id="syncPopoverBadge">Online</span>
                </div>
                <div class="sync-popover-details text-muted fs-xs mb-3">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span>Pending Changes:</span>
                        <strong class="text-dark" id="syncPopoverPending">0</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span>Failed Retries:</span>
                        <strong class="text-dark" id="syncPopoverFailed">0</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Last Synced:</span>
                        <span class="text-dark" id="syncPopoverLastSync">Just now</span>
                    </div>
                </div>
                <button type="button" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-1" id="btnSyncNow">
                    <i class="ti ti-refresh" id="btnSyncNowIcon"></i>
                    <span>Sync Now</span>
                </button>
            </div>
        </div>

        <!-- AI Assistant Quick Launch -->
        <?php if (Permissions::has('ai.access')): ?>
        <a href="<?= url('ai-assistant.php') ?>" class="btn btn-icon btn-ghost ai-topbar-btn" title="AI Digital Manager">
            <i class="ti ti-sparkles text-primary"></i>
        </a>
        <?php endif; ?>

        <!-- WhatsApp Support Button -->
        <a href="https://wa.me/<?= e(WHATSAPP_SUPPORT_NUMBER) ?>?text=<?= urlencode('Hello DJ CRM Support, I need assistance with my software.') ?>" 
           target="_blank" 
           class="btn btn-sm" 
           title="WhatsApp Support (Click to chat)" 
           style="background-color: #25D366; color: #FFFFFF; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border-radius: 20px; border: none; text-decoration: none; box-shadow: 0 2px 6px rgba(37, 211, 102, 0.3); transition: all 0.2s ease;">
            <i class="ti ti-brand-whatsapp" style="font-size: 1.15rem;"></i>
            <span class="d-none-sm">Support</span>
        </a>

        <!-- Theme Toggle -->
        <button type="button" class="btn btn-icon btn-ghost theme-toggle-btn" data-action="toggle-theme" title="Switch Theme">
            <i class="ti ti-moon theme-toggle-icon"></i>
        </button>

        <!-- User Profile Dropdown -->
        <div class="dropdown user-menu-dropdown">
            <button type="button" class="user-avatar-btn dropdown-toggle" data-toggle="dropdown" aria-label="User Menu">
                <div class="avatar avatar-sm avatar-circle bg-primary text-white">
                    <?= e($userInitial) ?>
                </div>
            </button>
            <div class="dropdown-menu dropdown-menu-right user-profile-card">
                <div class="user-menu-header">
                    <div class="avatar avatar-md avatar-circle bg-primary text-white">
                        <?= e($userInitial) ?>
                    </div>
                    <div class="user-menu-info">
                        <div class="user-name"><?= e($userName) ?></div>
                        <div class="user-email"><?= e($userEmail) ?></div>
                        <span class="badge <?= $isOwner ? 'badge-primary' : 'badge-secondary' ?> mt-1">
                            <?= $isOwner ? 'Owner' : 'Manager' ?>
                        </span>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="<?= url('profile.php') ?>" class="dropdown-item">
                    <i class="ti ti-user"></i> My Profile
                </a>
                <?php if (Permissions::has('settings.access')): ?>
                <a href="<?= url('settings.php') ?>" class="dropdown-item">
                    <i class="ti ti-settings"></i> Business Settings
                </a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="<?= url('logout.php') ?>" class="dropdown-item text-danger">
                    <i class="ti ti-logout"></i> Sign Out
                </a>
            </div>
        </div>
    </div>
</header>
