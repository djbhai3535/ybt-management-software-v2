/**
 * YBT Management Software — Core Application JS
 * Handles sidebar, mobile drawer, dropdowns, modals, toasts, tooltips
 */
(function() {
    'use strict';

    const App = {
        init() {
            this.initSidebar();
            this.initDropdowns();
            this.initModals();
            this.initAlerts();
            this.initGlobalSearch();
        },

        /* ---------- Sidebar & Mobile Drawer ---------- */
        initSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.querySelector('[data-action="toggle-sidebar"]');
            const mobileToggleBtn = document.querySelector('[data-action="toggle-mobile-sidebar"]');
            const overlay = document.querySelector('.mobile-overlay');

            if (toggleBtn && sidebar) {
                toggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    sidebar.classList.toggle('collapsed');
                    const isCollapsed = sidebar.classList.contains('collapsed');
                    localStorage.setItem('ybt_sidebar_collapsed', isCollapsed ? '1' : '0');
                });

                // Restore state on desktop
                if (window.innerWidth > 1024) {
                    if (localStorage.getItem('ybt_sidebar_collapsed') === '1') {
                        sidebar.classList.add('collapsed');
                    }
                }
            }

            if (mobileToggleBtn && sidebar && overlay) {
                mobileToggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('active');
                });

                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                });
            }
        },

        /* ---------- Dropdowns ---------- */
        initDropdowns() {
            document.addEventListener('click', (e) => {
                const trigger = e.target.closest('[data-toggle="dropdown"]');
                const openDropdowns = document.querySelectorAll('.dropdown.open');

                if (trigger) {
                    e.preventDefault();
                    e.stopPropagation();
                    const dropdown = trigger.closest('.dropdown');
                    const isOpen = dropdown.classList.contains('open');

                    // Close all others
                    openDropdowns.forEach(d => {
                        if (d !== dropdown) d.classList.remove('open');
                    });

                    dropdown.classList.toggle('open', !isOpen);
                } else if (!e.target.closest('.dropdown-menu')) {
                    // Click outside closes dropdowns
                    openDropdowns.forEach(d => d.classList.remove('open'));
                }
            });
        },

        /* ---------- Modals ---------- */
        initModals() {
            // Open modal triggers
            document.addEventListener('click', (e) => {
                const openBtn = e.target.closest('[data-modal-target]');
                if (openBtn) {
                    e.preventDefault();
                    const modalId = openBtn.getAttribute('data-modal-target');
                    App.openModal(modalId);
                }

                const closeBtn = e.target.closest('[data-modal-close], [data-modal-dismiss]');
                if (closeBtn) {
                    e.preventDefault();
                    const modal = closeBtn.closest('.modal-backdrop');
                    if (modal) {
                        App.closeModal(modal.id);
                    }
                }
            });

            // Close on backdrop click
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('modal-backdrop')) {
                    App.closeModal(e.target.id);
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const activeModal = document.querySelector('.modal-backdrop.active');
                    if (activeModal) {
                        App.closeModal(activeModal.id);
                    }
                }
            });
        },

        openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                const firstInput = modal.querySelector('input:not([type="hidden"]), select, textarea');
                if (firstInput) setTimeout(() => firstInput.focus(), 100);
            }
        },

        closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                modal.style.display = 'none';
                if (!document.querySelector('.modal-backdrop.active')) {
                    document.body.style.overflow = '';
                }
            }
        },

        /* ---------- Toast Notifications ---------- */
        toast(message, type = 'info', title = '', duration = 4000) {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }

            const icons = {
                success: 'ti-circle-check',
                error: 'ti-alert-circle',
                warning: 'ti-alert-triangle',
                info: 'ti-info-circle'
            };

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="ti ${icons[type] || 'ti-info-circle'} toast-icon"></i>
                <div class="toast-body">
                    ${title ? `<div class="toast-title">${title}</div>` : ''}
                    <div class="toast-message">${message}</div>
                </div>
                <button type="button" class="toast-close" aria-label="Close">&times;</button>
            `;

            toast.querySelector('.toast-close').addEventListener('click', () => {
                toast.classList.add('toast-fade-out');
                setTimeout(() => toast.remove(), 300);
            });

            container.appendChild(toast);

            if (duration > 0) {
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.classList.add('toast-fade-out');
                        setTimeout(() => toast.remove(), 300);
                    }
                }, duration);
            }
        },

        /* ---------- Auto-dismiss Flash Alerts ---------- */
        initAlerts() {
            document.querySelectorAll('.flash-dismissible').forEach(alert => {
                const btn = alert.querySelector('.flash-close');
                if (btn) {
                    btn.addEventListener('click', () => alert.remove());
                }
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.style.transition = 'opacity 0.3s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 300);
                    }
                }, 5000);
            });
        },

        /* ---------- Universal Global Search ---------- */
        initGlobalSearch() {
            const input = document.querySelector('#global-search-input');
            const results = document.querySelector('#global-search-results');
            const searchIcon = document.querySelector('#search-icon');
            const loadingSpinner = document.querySelector('#search-loading-spinner');
            const clearBtn = document.querySelector('#search-clear-btn');

            if (!input || !results) return;

            let debounceTimer = null;
            let currentFocusIndex = -1;

            function escapeHtml(str) {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function clearSearch() {
                input.value = '';
                results.style.display = 'none';
                results.innerHTML = '';
                if (clearBtn) clearBtn.style.display = 'none';
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (searchIcon) searchIcon.style.display = 'inline-block';
                currentFocusIndex = -1;
            }

            function setActiveItem(items, index) {
                items.forEach((item, i) => {
                    if (i === index) {
                        item.classList.add('active');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('active');
                    }
                });
            }

            // Clear button handler
            if (clearBtn) {
                clearBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    clearSearch();
                    input.focus();
                });
            }

            // Keyboard navigation inside input
            input.addEventListener('keydown', (e) => {
                const items = results.querySelectorAll('.search-result-item');
                if (results.style.display === 'block' && items.length > 0) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        currentFocusIndex = (currentFocusIndex + 1) % items.length;
                        setActiveItem(items, currentFocusIndex);
                        return;
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        currentFocusIndex = (currentFocusIndex - 1 + items.length) % items.length;
                        setActiveItem(items, currentFocusIndex);
                        return;
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const activeItem = items[currentFocusIndex >= 0 ? currentFocusIndex : 0];
                        if (activeItem && activeItem.href) {
                            window.location.href = activeItem.href;
                        }
                        return;
                    }
                }

                if (e.key === 'Escape') {
                    clearSearch();
                }
            });

            // Global shortcut: Ctrl+K or Cmd+K
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });

            // Input typing listener
            input.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                const query = e.target.value.trim();

                if (clearBtn) {
                    clearBtn.style.display = query.length > 0 ? 'flex' : 'none';
                }

                if (query.length < 2) {
                    results.style.display = 'none';
                    results.innerHTML = '';
                    currentFocusIndex = -1;
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (searchIcon) searchIcon.style.display = 'inline-block';
                    return;
                }

                if (loadingSpinner) loadingSpinner.style.display = 'inline-block';
                if (searchIcon) searchIcon.style.display = 'none';

                debounceTimer = setTimeout(() => {
                    fetch(`${window.APP_URL || ''}/api/search.php?q=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (searchIcon) searchIcon.style.display = 'inline-block';
                            currentFocusIndex = -1;

                            if (!data.results || data.results.length === 0) {
                                results.innerHTML = `
                                    <div class="search-empty-state">
                                        <i class="ti ti-search-off mb-2" style="font-size: 1.5rem; display: block; opacity: 0.6;"></i>
                                        <div>No records found for "<strong>${escapeHtml(query)}</strong>"</div>
                                        <div class="fs-xs text-muted mt-1">Try searching customer name, phone, product, SKU, barcode, or invoice #</div>
                                    </div>
                                `;
                                results.style.display = 'block';
                                return;
                            }

                            // Group results by category
                            const groups = {};
                            data.results.forEach(item => {
                                const cat = item.category || 'General';
                                if (!groups[cat]) groups[cat] = [];
                                groups[cat].push(item);
                            });

                            let html = '';
                            const categoryIcons = {
                                'Customers': 'search-icon-customer',
                                'Products': 'search-icon-product',
                                'Services': 'search-icon-service',
                                'Invoices': 'search-icon-invoice',
                                'Khata / Ledger': 'search-icon-khata'
                            };

                            for (const [catName, catItems] of Object.entries(groups)) {
                                html += `<div class="search-category-header"><span>${escapeHtml(catName)}</span><span>(${catItems.length})</span></div>`;
                                catItems.forEach(item => {
                                    const iconBoxClass = categoryIcons[item.category] || 'search-icon-product';
                                    const badgeHtml = item.badge ? `<span class="badge ${item.badge_class || 'badge-secondary'} search-item-badge">${escapeHtml(item.badge)}</span>` : '';

                                    html += `
                                        <a href="${item.url}" class="search-result-item" data-category="${escapeHtml(item.category)}">
                                            <div class="search-item-icon-box ${iconBoxClass}">
                                                <i class="ti ${item.icon || 'ti-file'}"></i>
                                            </div>
                                            <div class="search-item-info">
                                                <div class="search-item-title">${escapeHtml(item.title)}</div>
                                                <div class="search-item-desc">${escapeHtml(item.subtitle || '')}</div>
                                            </div>
                                            ${badgeHtml}
                                        </a>
                                    `;
                                });
                            }

                            html += `<div class="search-footer-hint"><kbd>↑</kbd> <kbd>↓</kbd> to navigate • <kbd>Enter</kbd> to select • <kbd>Esc</kbd> to close</div>`;
                            results.innerHTML = html;
                            results.style.display = 'block';
                        })
                        .catch(() => {
                            if (loadingSpinner) loadingSpinner.style.display = 'none';
                            if (searchIcon) searchIcon.style.display = 'inline-block';
                            results.style.display = 'none';
                        });
                }, 250);
            });

            // Click outside closes dropdown
            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !results.contains(e.target) && (!clearBtn || !clearBtn.contains(e.target))) {
                    results.style.display = 'none';
                    currentFocusIndex = -1;
                }
            });
        },

        /* ---------- Cloud Sync Supervisor ---------- */
        initSyncSupervisor() {
            const pill = document.getElementById('btnSyncStatus');
            const icon = document.getElementById('syncStatusIcon');
            const text = document.getElementById('syncStatusText');
            const badge = document.getElementById('syncPendingBadge');
            const popBadge = document.getElementById('syncPopoverBadge');
            const popPending = document.getElementById('syncPopoverPending');
            const popFailed = document.getElementById('syncPopoverFailed');
            const popLastSync = document.getElementById('syncPopoverLastSync');
            const btnSyncNow = document.getElementById('btnSyncNow');
            const btnSyncNowIcon = document.getElementById('btnSyncNowIcon');

            if (!pill) return; // Exit if not in current layout

            let isSyncing = false;
            let syncInterval = null;

            function updateUI(state, pendingCount = 0, failedCount = 0) {
                pill.classList.remove('sync-status-synced', 'sync-status-syncing', 'sync-status-pending', 'sync-status-offline', 'sync-status-error');
                icon.className = 'sync-status-icon ti';

                if (popPending) popPending.textContent = pendingCount;
                if (popFailed) popFailed.textContent = failedCount;

                switch (state) {
                    case 'syncing':
                        pill.classList.add('sync-status-syncing');
                        icon.classList.add('ti-loader-2');
                        text.textContent = 'Syncing...';
                        if (popBadge) { popBadge.className = 'badge badge-subtle badge-primary'; popBadge.textContent = 'Syncing'; }
                        break;
                    case 'pending':
                        pill.classList.add('sync-status-pending');
                        icon.classList.add('ti-cloud-upload');
                        text.textContent = 'Pending';
                        if (badge) {
                            badge.textContent = pendingCount;
                            badge.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
                        }
                        if (popBadge) { popBadge.className = 'badge badge-subtle badge-warning'; popBadge.textContent = 'Pending Upload'; }
                        break;
                    case 'offline':
                        pill.classList.add('sync-status-offline');
                        icon.classList.add('ti-cloud-off');
                        text.textContent = 'Offline';
                        if (badge) {
                            badge.textContent = pendingCount;
                            badge.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
                        }
                        if (popBadge) { popBadge.className = 'badge badge-subtle badge-secondary'; popBadge.textContent = 'Offline'; }
                        break;
                    case 'error':
                        pill.classList.add('sync-status-error');
                        icon.classList.add('ti-cloud-x');
                        text.textContent = 'Sync Error';
                        if (badge) {
                            badge.textContent = pendingCount;
                            badge.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
                        }
                        if (popBadge) { popBadge.className = 'badge badge-subtle badge-danger'; popBadge.textContent = 'Sync Error'; }
                        break;
                    case 'synced':
                    default:
                        pill.classList.add('sync-status-synced');
                        icon.classList.add('ti-cloud-check');
                        text.textContent = 'Synced';
                        if (badge) badge.style.display = 'none';
                        if (popBadge) { popBadge.className = 'badge badge-subtle badge-success'; popBadge.textContent = 'Online & Synced'; }
                        break;
                }
            }

            async function checkStatus() {
                if (isSyncing) return;
                try {
                    if (typeof navigator !== 'undefined' && !navigator.onLine) {
                        updateUI('offline', parseInt(popPending?.textContent || '0', 10));
                        return;
                    }

                    const response = await fetch((window.APP_URL || '') + '/api/sync-agent.php?action=status', {
                        cache: 'no-store'
                    });
                    if (!response.ok) {
                        updateUI('offline');
                        return;
                    }
                    const data = await response.json();
                    if (!data.success) {
                        updateUI('error', 0, 0);
                        return;
                    }

                    const pending = data.pending_count || 0;
                    const failed = data.failed_count || 0;
                    const online = data.cloud_reachable;

                    if (!online) {
                        updateUI('offline', pending, failed);
                    } else if (failed > 3) {
                        updateUI('error', pending, failed);
                    } else if (pending > 0) {
                        updateUI('pending', pending, failed);
                        // Trigger background sync if pending changes exist
                        triggerSync();
                    } else {
                        updateUI('synced', 0, failed);
                    }

                    if (popLastSync && data.last_sync_time) {
                        popLastSync.textContent = data.last_sync_time;
                    }
                } catch (err) {
                    updateUI('offline');
                }
            }

            async function triggerSync() {
                if (isSyncing) return;
                isSyncing = true;
                updateUI('syncing');
                if (btnSyncNow) {
                    btnSyncNow.disabled = true;
                    if (btnSyncNowIcon) btnSyncNowIcon.classList.add('spinning-icon');
                }

                try {
                    const response = await fetch((window.APP_URL || '') + '/api/sync-agent.php?action=trigger_sync', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        cache: 'no-store'
                    });
                    const data = await response.json();
                    if (data.success) {
                        if (popLastSync) popLastSync.textContent = 'Just now';
                        updateUI('synced', 0, data.stats?.failed || 0);
                        if (data.stats && (data.stats.pushed > 0 || data.stats.pulled > 0)) {
                            App.toast(`Cloud sync completed: ${data.stats.pushed} uploaded, ${data.stats.pulled} downloaded.`, 'success', 'Synced');
                        }
                    } else {
                        updateUI(data.error && data.error.includes('reachable') ? 'offline' : 'error', data.stats?.pending || 0);
                    }
                } catch (err) {
                    updateUI('offline');
                } finally {
                    isSyncing = false;
                    if (btnSyncNow) {
                        btnSyncNow.disabled = false;
                        if (btnSyncNowIcon) btnSyncNowIcon.classList.remove('spinning-icon');
                    }
                }
            }

            // Expose globally
            window.SyncSupervisor = {
                checkStatus,
                triggerSync,
                updateUI
            };

            // Network listeners
            window.addEventListener('online', () => checkStatus());
            window.addEventListener('offline', () => updateUI('offline'));

            if (btnSyncNow) {
                btnSyncNow.addEventListener('click', (e) => {
                    e.preventDefault();
                    triggerSync();
                });
            }

            // Initial poll & 45s timer
            checkStatus();
            syncInterval = setInterval(checkStatus, 45000);
        },

        /* ---------- Confirm Action Helper ---------- */
        confirmAction(message, onConfirm) {
            if (confirm(message)) {
                onConfirm();
            }
        }
    };

    window.App = App;
    document.addEventListener('DOMContentLoaded', () => App.init());
})();
