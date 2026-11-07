<?php
declare(strict_types=1);

/**
 * YBT Management Software — Customers CRM Directory
 * Integrated with Customer Khata Balances (You Gave / You Got)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/customer-service.php';
require_once __DIR__ . '/services/khata-service.php';

Auth::requireLogin();
Permissions::require('customers.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Real-Time Customer Duplicate Check (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'check_duplicate') {
    header('Content-Type: application/json');
    $qPhone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
    $qName = trim($_GET['name'] ?? '');

    $match = null;
    if (strlen($qPhone) >= 7) {
        $phoneSuffix = substr($qPhone, -7);
        $match = Database::fetch(
            "SELECT id, name, phone, current_balance FROM customers WHERE business_id = ? AND phone LIKE ? LIMIT 1",
            [$businessId, "%{$phoneSuffix}"]
        );
    }
    if (!$match && strlen($qName) >= 3) {
        $match = Database::fetch(
            "SELECT id, name, phone, current_balance FROM customers WHERE business_id = ? AND LOWER(name) = LOWER(?) LIMIT 1",
            [$businessId, $qName]
        );
    }

    if ($match) {
        echo json_encode([
            'exists' => true,
            'customer' => [
                'id' => (int)$match['id'],
                'name' => $match['name'],
                'phone' => $match['phone'],
                'balance' => (float)$match['current_balance'],
                'ledger_url' => url('customer-view.php?id=' . $match['id'] . '&tab=khata')
            ]
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// Handle Customer Creation via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    Permissions::require('customers.create');
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token.');
    } else {
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            Flash::error('Customer name is required.');
        } else {
            // Validate and format phone number via PhoneValidator
            $countryCode = trim($_POST['country_code'] ?? '+92');
            $rawPhone = trim($_POST['phone'] ?? '');
            if ($rawPhone !== '') {
                $phoneValidation = PhoneValidator::validate($countryCode, $rawPhone);
                if (!$phoneValidation['valid']) {
                    Flash::error($phoneValidation['error']);
                    header('Location: ' . url('customers.php'));
                    exit;
                }
                $_POST['phone'] = $phoneValidation['formatted'];
            }

            try {
                $newId = CustomerService::create($businessId, $_POST, $userId);
                ActivityLog::log($businessId, $userId, 'customer_created', 'customer', $newId, "Added customer {$name}");
                Flash::success("Customer '{$name}' created successfully with Khata ledger ready.");
                header('Location: ' . url('customer-view.php?id=' . $newId . '&tab=khata'));
                exit;
            } catch (Exception $e) {
                Flash::error('Failed to create customer: ' . $e->getMessage());
            }
        }
    }
}

// Fetch Customers List
$page = currentPage();
$filters = [
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? 'all',
    'city' => $_GET['city'] ?? ''
];

$result = CustomerService::getAll($businessId, $filters, $page, 12);
$customers = $result['data'];
$totalPages = $result['total_pages'];
$totalCount = $result['total'];

$pageTitle = 'Customers Directory';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title">Customers & Customer Ledger CRM</h1>
        <p class="page-subtitle">Manage customer profiles, contact directories, and live financial khata ledgers</p>
    </div>
    <div class="page-header-actions">
        <?php if (Permissions::has('customers.create')): ?>
        <button type="button" class="btn btn-primary" data-modal-target="new-customer-modal">
            <i class="ti ti-user-plus mr-1"></i> Add Customer
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card mb-4 filter-card">
    <div class="card-body">
        <form method="GET" action="<?= url('customers.php') ?>" class="d-flex flex-wrap gap-3 align-center">
            <div class="flex-1 min-w-200">
                <div class="input-icon-wrapper">
                    <i class="ti ti-search input-icon"></i>
                    <input type="text" name="search" class="form-input" placeholder="Search by name, company, phone, email..." value="<?= e($filters['search']) ?>">
                </div>
            </div>

            <div style="width: 160px;">
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <i class="ti ti-filter mr-1"></i> Filter
            </button>
            <?php if (!empty($filters['search']) || $filters['status'] !== 'all'): ?>
                <a href="<?= url('customers.php') ?>" class="btn btn-ghost text-muted">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title">Customer List (<?= $totalCount ?>)</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="empty-state p-5 text-center">
                <i class="ti ti-users text-4xl text-muted mb-2"></i>
                <h3>No Customers Found</h3>
                <p class="text-muted text-sm mb-3">No customers match your criteria or none have been added yet.</p>
                <?php if (Permissions::has('customers.create')): ?>
                <button type="button" class="btn btn-primary btn-sm" data-modal-target="new-customer-modal">
                    <i class="ti ti-plus mr-1"></i> Add Your First Customer
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Contact Info</th>
                            <th>City / Town</th>
                            <th class="text-right">Total Invoiced</th>
                            <th class="text-right">Ledger Balance</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <?php $bal = (float)($c['khata_balance'] ?? 0); ?>
                        <tr>
                            <td>
                                <a href="<?= url('customer-view.php?id=' . $c['id']) ?>" class="font-bold text-primary">
                                    <?= e($c['name']) ?>
                                </a>
                                <?php if (!empty($c['company_name'])): ?>
                                    <div class="text-xs text-muted"><?= e($c['company_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><i class="ti ti-phone text-muted mr-1"></i><strong><?= e($c['phone'] ?: '—') ?></strong></div>
                                <?php if (!empty($c['email'])): ?>
                                    <div class="text-xs text-muted"><i class="ti ti-mail text-muted mr-1"></i><?= e($c['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($c['billing_city'] ?: $c['city'] ?: '—') ?>
                            </td>
                            <td class="text-right font-medium"><?= formatCurrency((float)$c['total_billed']) ?></td>
                            <td class="text-right font-bold">
                                <?php if ($bal > 0): ?>
                                    <span class="badge badge-danger">
                                        <?= formatCurrency($bal) ?> (Dr)
                                    </span>
                                    <div class="text-2xs text-danger">Customer Owes</div>
                                <?php elseif ($bal < 0): ?>
                                    <span class="badge badge-success">
                                        <?= formatCurrency(abs($bal)) ?> (Cr)
                                    </span>
                                    <div class="text-2xs text-success">Customer Advance</div>
                                <?php else: ?>
                                    <span class="badge badge-subtle">
                                        0.00 (Cleared)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $c['status'] === 'active' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($c['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <a href="<?= url('customer-view.php?id=' . $c['id'] . '&tab=khata') ?>" class="btn btn-icon btn-ghost text-primary" title="View Khata Ledger">
                                        <i class="ti ti-notebook"></i>
                                    </a>
                                    <a href="<?= url('customer-ledger-print.php?id=' . $c['id']) ?>" target="_blank" class="btn btn-icon btn-ghost" title="Print Statement">
                                        <i class="ti ti-printer"></i>
                                    </a>
                                    <?php if (Permissions::has('invoices.create')): ?>
                                    <a href="<?= url('invoice-create.php?customer_id=' . $c['id']) ?>" class="btn btn-icon btn-ghost text-secondary" title="Create Invoice">
                                        <i class="ti ti-receipt"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="p-3 d-flex justify-between align-center">
                <span class="text-sm text-muted">Showing page <?= $page ?> of <?= $totalPages ?></span>
                <?= paginate($totalCount, 12, $page, url('customers.php?search=' . urlencode($filters['search']) . '&status=' . urlencode($filters['status']))) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Simplified Add Customer Modal -->
<div class="modal-backdrop" id="new-customer-modal" style="display: none;">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ti ti-user-plus mr-1 text-primary"></i> Add Customer</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form action="<?= url('customers.php') ?>" method="POST" id="quick-customer-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_customer">

            <div class="modal-body">
                <p class="text-xs text-muted mb-3">Enter Customer Name and Mobile Number to immediately create and open their Khata ledger.</p>

                <!-- Duplicate Customer Warning Box (Live search result) -->
                <div id="duplicate-warning" class="p-3 mb-3 rounded" style="display: none; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3);">
                    <div class="d-flex align-center justify-between gap-2">
                        <div>
                            <div class="font-bold text-sm text-warning d-flex align-center gap-1">
                                <i class="ti ti-alert-triangle"></i> Customer Already Exists!
                            </div>
                            <div class="text-xs text-muted mt-1" id="duplicate-details"></div>
                        </div>
                        <a href="#" id="duplicate-ledger-btn" class="btn btn-warning btn-sm font-bold flex-shrink-0">
                            <i class="ti ti-notebook mr-1"></i> Open Ledger
                        </a>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required font-bold">Customer Name *</label>
                    <input type="text" name="name" id="cust-name-input" class="form-input form-input-lg" placeholder="e.g. Tariq Mehmood" required autofocus autocomplete="off">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label required font-bold">Mobile Number *</label>
                    <div class="d-flex gap-2">
                        <select name="country_code" id="cust-country-code" class="form-select" style="width: 170px; flex-shrink: 0;">
                            <option value="+92" selected>🇵🇰 Pakistan (+92)</option>
                            <option value="+971">🇦🇪 UAE (+971)</option>
                            <option value="+966">🇸🇦 Saudi Arabia (+966)</option>
                            <option value="+974">🇶🇦 Qatar (+974)</option>
                            <option value="+968">🇴🇲 Oman (+968)</option>
                            <option value="+965">🇰🇼 Kuwait (+965)</option>
                            <option value="+973">🇧🇭 Bahrain (+973)</option>
                            <option value="+44">🇬🇧 United Kingdom (+44)</option>
                            <option value="+1">🇺🇸 United States (+1)</option>
                            <option value="+1">🇨🇦 Canada (+1)</option>
                            <option value="+91">🇮🇳 India (+91)</option>
                            <option value="+880">🇧🇩 Bangladesh (+880)</option>
                            <option value="+93">🇦🇫 Afghanistan (+93)</option>
                            <option value="+90">🇹🇷 Turkey (+90)</option>
                            <option value="+86">🇨🇳 China (+86)</option>
                            <option value="+60">🇲🇾 Malaysia (+60)</option>
                            <option value="+65">🇸🇬 Singapore (+65)</option>
                            <option value="+61">🇦🇺 Australia (+61)</option>
                            <option value="+49">🇩🇪 Germany (+49)</option>
                            <option value="+33">🇫🇷 France (+33)</option>
                            <option value="+39">🇮🇹 Italy (+39)</option>
                            <option value="+34">🇪🇸 Spain (+34)</option>
                            <option value="+31">🇳🇱 Netherlands (+31)</option>
                            <option value="+46">🇸🇪 Sweden (+46)</option>
                            <option value="+41">🇨🇭 Switzerland (+41)</option>
                            <option value="+47">🇳🇴 Norway (+47)</option>
                            <option value="+45">🇩🇰 Denmark (+45)</option>
                            <option value="+353">🇮🇪 Ireland (+353)</option>
                            <option value="+64">🇳🇿 New Zealand (+64)</option>
                            <option value="+27">🇿🇦 South Africa (+27)</option>
                            <option value="+20">🇪🇬 Egypt (+20)</option>
                            <option value="+234">🇳🇬 Nigeria (+234)</option>
                            <option value="+254">🇰🇪 Kenya (+254)</option>
                            <option value="+94">🇱🇰 Sri Lanka (+94)</option>
                            <option value="+977">🇳🇵 Nepal (+977)</option>
                            <option value="+81">🇯🇵 Japan (+81)</option>
                            <option value="+82">🇰🇷 South Korea (+82)</option>
                            <option value="+62">🇮🇩 Indonesia (+62)</option>
                            <option value="+66">🇹🇭 Thailand (+66)</option>
                            <option value="+55">🇧🇷 Brazil (+55)</option>
                            <option value="+52">🇲🇽 Mexico (+52)</option>
                            <option value="+98">🇮🇷 Iran (+98)</option>
                            <option value="+964">🇮🇶 Iraq (+964)</option>
                            <option value="+962">🇯🇴 Jordan (+962)</option>
                            <option value="+961">🇱🇧 Lebanon (+961)</option>
                            <option value="+967">🇾🇪 Yemen (+967)</option>
                            <option value="+7">🇷🇺 Russia (+7)</option>
                        </select>
                        <input type="tel" name="phone" id="cust-phone-input" class="form-input form-input-lg flex-1" placeholder="03001234567" maxlength="11" required autocomplete="off">
                    </div>
                    <div class="text-xs text-muted mt-1" id="cust-phone-hint">Exclusively 11 digits starting with 03 (e.g. 03001234567).</div>
                </div>

                <!-- Collapsible optional details for advanced info -->
                <details class="mt-2 text-xs">
                    <summary class="text-muted cursor-pointer font-medium p-1">
                        <i class="ti ti-chevron-down mr-1"></i> Optional: Trade Name, City, or Opening Balance
                    </summary>
                    <div class="p-3 bg-subtle rounded mt-2">
                        <div class="form-group mb-2">
                            <label class="form-label">Shop / Trade Name</label>
                            <input type="text" name="company_name" class="form-input" placeholder="e.g. Al-Madina Telecom">
                        </div>
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" name="billing_city" class="form-input" placeholder="e.g. Lahore">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Opening Balance</label>
                                <input type="number" step="0.01" min="0" name="opening_balance" class="form-input" placeholder="0.00">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Balance Type</label>
                            <select name="opening_balance_type" class="form-select">
                                <option value="debit" selected>Customer Owes (Debit)</option>
                                <option value="credit">Customer Advance (Credit)</option>
                            </select>
                        </div>
                    </div>
                </details>
            </div>

            <div class="modal-footer d-flex justify-between align-center">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary font-bold">
                    <i class="ti ti-arrow-right mr-1"></i> Continue to Ledger
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var nameInput = document.getElementById('cust-name-input');
    var phoneInput = document.getElementById('cust-phone-input');
    var warnBox = document.getElementById('duplicate-warning');
    var warnDetails = document.getElementById('duplicate-details');
    var warnBtn = document.getElementById('duplicate-ledger-btn');
    var timer = null;

    function checkCustomerDuplicate() {
        clearTimeout(timer);
        timer = setTimeout(function() {
            var name = nameInput ? nameInput.value.trim() : '';
            var phone = phoneInput ? phoneInput.value.trim() : '';

            if (phone.length < 5 && name.length < 3) {
                if (warnBox) warnBox.style.display = 'none';
                return;
            }

            var checkUrl = '<?= url("customers.php") ?>?action=check_duplicate&name=' + encodeURIComponent(name) + '&phone=' + encodeURIComponent(phone);
            fetch(checkUrl)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.exists && data.customer) {
                        var c = data.customer;
                        var bal = parseFloat(c.balance || 0);
                        var balStr = bal > 0 ? ('Customer Owes Rs. ' + bal.toLocaleString()) : (bal < 0 ? ('Advance Rs. ' + Math.abs(bal).toLocaleString()) : 'Cleared (Rs. 0)');
                        warnDetails.textContent = c.name + ' (' + (c.phone || 'No phone') + ') — Balance: ' + balStr;
                        warnBtn.href = c.ledger_url;
                        warnBox.style.display = 'block';
                    } else {
                        warnBox.style.display = 'none';
                    }
                })
                .catch(function() {});
        }, 300);
    }

    if (nameInput) nameInput.addEventListener('input', checkCustomerDuplicate);
    if (phoneInput) phoneInput.addEventListener('input', checkCustomerDuplicate);

    // Dynamic country and phone formatting in Add Customer modal
    var countrySelect = document.querySelector('select[name="country_code"]');
    var phoneHint = document.getElementById('cust-phone-hint');
    var countryMeta = typeof countryMetadata !== 'undefined' ? countryMetadata : (<?= PhoneValidator::getCountriesJson() ?>);

    function onCustomerCountryChange() {
        if (!countrySelect || !phoneInput) return;
        var code = countrySelect.value;
        var matched = null;
        for (var k in countryMeta) {
            if (countryMeta[k].code === code) {
                matched = countryMeta[k];
                break;
            }
        }
        if (matched) {
            phoneInput.placeholder = matched.placeholder || 'Phone number';
            phoneInput.maxLength = matched.max_len || 15;
            if (phoneHint) phoneHint.textContent = matched.hint || '';
        } else {
            phoneInput.placeholder = 'Phone number';
            phoneInput.maxLength = 15;
            if (phoneHint) phoneHint.textContent = 'Enter international contact number';
        }
    }

    if (countrySelect) {
        countrySelect.addEventListener('change', onCustomerCountryChange);
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            var code = countrySelect ? countrySelect.value : '+92';
            if (code === '+92') {
                var digits = this.value.replace(/[^0-9]/g, '');
                if (digits.length > 11) digits = digits.substring(0, 11);
                this.value = digits;
            }
        });
    }
})();
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
