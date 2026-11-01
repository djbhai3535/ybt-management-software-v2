<?php
/**
 * YBT Management Software — Mobile Phone Spare-Parts & Products Inventory
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/stock-service.php';

Auth::requireLogin();
Permissions::require('products.view');

$businessId = Auth::businessId();
$userId = Auth::id();

// Handle POST Actions (Create Product, Adjust Stock, Purchase Restock, Return to Supplier)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        Flash::error('Invalid security token. Please refresh and try again.');
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Create Product / Part
        if ($action === 'create_product') {
            Permissions::require('products.create');
            $name = trim($_POST['name'] ?? '');
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $partType = trim($_POST['part_type'] ?? 'Display / Folder');
            $sku = trim($_POST['sku'] ?? '');
            $barcode = trim($_POST['barcode'] ?? '');
            $sellingPrice = max(0, (float)($_POST['selling_price'] ?? 0));
            $purchasePrice = max(0, (float)($_POST['purchase_price'] ?? $_POST['cost_price'] ?? 0));
            $initialStock = max(0, (float)($_POST['initial_stock'] ?? 0));
            $minStockAlert = max(0, (float)($_POST['min_stock_alert'] ?? 2));
            $supplierName = trim($_POST['supplier_name'] ?? '');
            $allowNegative = !empty($_POST['allow_negative_stock']) ? 1 : 0;
            $unit = trim($_POST['unit'] ?? 'Pcs');
            $description = trim($_POST['description'] ?? '');

            if (empty($name)) {
                Flash::error('Product / Part name is required.');
            } else {
                $newId = Database::insert('products', [
                    'business_id' => $businessId,
                    'name' => $name,
                    'brand' => $brand ?: null,
                    'model' => $model ?: null,
                    'part_type' => $partType,
                    'category' => $partType,
                    'sku' => $sku ?: null,
                    'barcode' => $barcode ?: null,
                    'unit' => $unit,
                    'selling_price' => $sellingPrice,
                    'purchase_price' => $purchasePrice,
                    'cost_price' => $purchasePrice,
                    'stock_quantity' => $initialStock,
                    'min_stock_alert' => $minStockAlert,
                    'supplier_name' => $supplierName ?: null,
                    'allow_negative_stock' => $allowNegative,
                    'description' => $description ?: null,
                    'status' => 'active',
                    'created_by' => $userId
                ]);

                // Record initial stock movement if > 0
                if ($initialStock > 0) {
                    StockService::recordMovement(
                        $businessId,
                        $newId,
                        'adjustment',
                        $initialStock,
                        0.0,
                        $initialStock,
                        'manual',
                        null,
                        null,
                        'Initial stock added on part creation',
                        $userId
                    );
                }

                ActivityLog::log($businessId, $userId, 'product_created', 'product', $newId, "Added spare part '{$name}' with initial stock of {$initialStock}");
                Flash::success("Spare part '{$name}' created successfully with stock {$initialStock}.");
                header('Location: ' . url('products.php'));
                exit;
            }
        }

        // 2. Adjust Stock (Recount / Damage / Correction)
        elseif ($action === 'adjust_stock') {
            Permissions::require('products.edit');
            $prodId = (int)($_POST['product_id'] ?? 0);
            $delta = (float)($_POST['delta'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Manual stock adjustment');

            if ($prodId <= 0 || $delta == 0) {
                Flash::error('Invalid adjustment parameters.');
            } else {
                try {
                    $res = StockService::adjustStock($businessId, $prodId, $delta, 'adjustment', $reason, $userId, 'manual');
                    Flash::success("Stock for '{$res['product_name']}' adjusted to {$res['quantity_after']}.");
                    header('Location: ' . url('products.php'));
                    exit;
                } catch (InsufficientStockException $ise) {
                    Flash::error($ise->getMessage());
                } catch (\Throwable $e) {
                    Flash::error('Adjustment error: ' . $e->getMessage());
                }
            }
        }

        // 3. Record Purchase Restock
        elseif ($action === 'record_purchase') {
            Permissions::require('products.edit');
            $prodId = (int)($_POST['product_id'] ?? 0);
            $qty = max(0, (float)($_POST['quantity'] ?? 0));
            $purchasePrice = max(0, (float)($_POST['purchase_price'] ?? 0));
            $supplier = trim($_POST['supplier_name'] ?? '');
            $billNum = trim($_POST['bill_number'] ?? '');

            if ($prodId <= 0 || $qty <= 0) {
                Flash::error('Please enter a valid product and purchase quantity.');
            } else {
                try {
                    $res = StockService::recordPurchase($businessId, $prodId, $qty, $purchasePrice, $supplier, $billNum, $userId);
                    Flash::success("Restocked {$qty} units of '{$res['product_name']}'. Current stock: {$res['quantity_after']}.");
                    header('Location: ' . url('products.php'));
                    exit;
                } catch (\Throwable $e) {
                    Flash::error('Purchase error: ' . $e->getMessage());
                }
            }
        }

        // 4. Return to Supplier
        elseif ($action === 'return_supplier') {
            Permissions::require('products.edit');
            $prodId = (int)($_POST['product_id'] ?? 0);
            $qty = max(0, (float)($_POST['quantity'] ?? 0));
            $reason = trim($_POST['reason'] ?? 'Faulty / damaged component');

            if ($prodId <= 0 || $qty <= 0) {
                Flash::error('Please enter a valid product and return quantity.');
            } else {
                try {
                    $res = StockService::recordSupplierReturn($businessId, $prodId, $qty, $reason, $userId);
                    Flash::success("Returned {$qty} units to supplier. Remaining stock: {$res['quantity_after']}.");
                    header('Location: ' . url('products.php'));
                    exit;
                } catch (InsufficientStockException $ise) {
                    Flash::error($ise->getMessage());
                } catch (\Throwable $e) {
                    Flash::error('Supplier return error: ' . $e->getMessage());
                }
            }
        }
    }
}

// Search and Filters
$search = trim($_GET['search'] ?? '');
$filterBrand = trim($_GET['brand'] ?? 'all');
$filterCategory = trim($_GET['part_type'] ?? 'all');
$filterStock = trim($_GET['stock'] ?? 'all');

$where = ["business_id = ?", "status = 'active'"];
$params = [$businessId];

if ($search !== '') {
    $where[] = "(name LIKE ? OR model LIKE ? OR sku LIKE ? OR barcode LIKE ? OR supplier_name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
}

if ($filterBrand !== 'all' && $filterBrand !== '') {
    $where[] = "brand = ?";
    $params[] = $filterBrand;
}

if ($filterCategory !== 'all' && $filterCategory !== '') {
    $where[] = "(part_type = ? OR category = ?)";
    $params[] = $filterCategory;
    $params[] = $filterCategory;
}

if ($filterStock === 'low') {
    $where[] = "stock_quantity <= min_stock_alert AND stock_quantity > 0";
} elseif ($filterStock === 'out') {
    $where[] = "stock_quantity <= 0";
}

$whereClause = implode(' AND ', $where);
$products = Database::fetchAll("SELECT * FROM products WHERE {$whereClause} ORDER BY name ASC", $params);

// Inventory KPI Stats
$totalItemsCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active'", [$businessId]);
$lowStockCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active' AND stock_quantity <= min_stock_alert AND stock_quantity > 0", [$businessId]);
$outOfStockCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM products WHERE business_id = ? AND status = 'active' AND stock_quantity <= 0", [$businessId]);
$totalInventoryVal = (float)Database::fetchColumn("SELECT COALESCE(SUM(stock_quantity * purchase_price), 0) FROM products WHERE business_id = ? AND status = 'active'", [$businessId]);

// Distinct Brands & Part Types for Dropdowns
$availableBrands = Database::fetchAll("SELECT DISTINCT brand FROM products WHERE business_id = ? AND brand IS NOT NULL AND brand != '' ORDER BY brand ASC", [$businessId]);
$commonBrands = ['Vivo', 'Oppo', 'Samsung', 'Apple (iPhone)', 'Xiaomi / Redmi', 'Infinix', 'Tecno', 'Realme', 'Huawei', 'OnePlus', 'Universal'];

$partCategories = [
    'Display / Folder',
    'Battery',
    'Charging Port / Flex',
    'Touch Glass',
    'Camera Module',
    'Speaker / Ringer / Mic',
    'Body / Housing / Frame',
    'Motherboard / IC',
    'Tempered Glass / Protector',
    'Back Cover / Case',
    'Data Cable / Charger',
    'Other Mobile Accessory'
];

// Recent Movements
$recentMovements = StockService::getMovements($businessId, null, 10);

$pageTitle = 'Mobile Parts & Inventory';
require YBT_ROOT . '/includes/header.php';
?>

<div class="page-header d-flex justify-between align-center mb-4">
    <div>
        <h1 class="page-title"><i class="ti ti-device-mobile mr-2 text-primary"></i> Mobile Parts & Inventory</h1>
        <p class="page-subtitle">Spare-parts catalog, device model filtering, live stock tracking, and supplier restocking</p>
    </div>
    <div class="page-header-actions d-flex gap-2">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('movements-drawer').style.display='flex'">
            <i class="ti ti-history mr-1"></i> Stock Movement Log
        </button>
        <?php if (Permissions::has('products.create')): ?>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('new-part-modal').style.display='flex'">
            <i class="ti ti-plus mr-1"></i> Add Mobile Part
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Inventory KPI Cards -->
<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="card p-3" style="border-left: 4px solid var(--color-primary);">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Total Catalog Items</span>
            <i class="ti ti-package fs-5 text-primary"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 700;"><?= $totalItemsCount ?></div>
        <div class="text-xs text-muted">Parts & accessories</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #f59e0b;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Low Stock Alerts</span>
            <i class="ti ti-alert-triangle fs-5 text-warning"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 700; color: #f59e0b;"><?= $lowStockCount ?></div>
        <div class="text-xs text-muted">Reorder required</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #ef4444;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Out of Stock</span>
            <i class="ti ti-circle-x fs-5 text-danger"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 700; color: #ef4444;"><?= $outOfStockCount ?></div>
        <div class="text-xs text-muted">Zero units available</div>
    </div>

    <div class="card p-3" style="border-left: 4px solid #10b981;">
        <div class="d-flex justify-between align-center mb-1">
            <span class="text-muted text-xs font-semibold uppercase">Inventory Asset Value</span>
            <i class="ti ti-cash fs-5 text-success"></i>
        </div>
        <div style="font-size: 1.6rem; font-weight: 700; color: #10b981;"><?= formatCurrency($totalInventoryVal) ?></div>
        <div class="text-xs text-muted">At purchase cost</div>
    </div>
</div>

<!-- Search & Filtering Bar -->
<div class="card p-3 mb-4">
    <form method="GET" action="<?= url('products.php') ?>" class="d-flex gap-3 align-center flex-wrap">
        <div style="flex: 2; min-width: 220px;">
            <input type="text" name="search" class="form-input" placeholder="Search by Part Name, Model (e.g. V20), SKU, or Barcode..." value="<?= e($search) ?>">
        </div>

        <div style="flex: 1; min-width: 150px;">
            <select name="brand" class="form-select" onchange="this.form.submit()">
                <option value="all">All Brands</option>
                <?php 
                $shownBrands = [];
                foreach ($availableBrands as $b) {
                    $bName = $b['brand'];
                    $shownBrands[] = strtolower($bName);
                    echo "<option value=\"" . e($bName) . "\" " . ($filterBrand === $bName ? 'selected' : '') . ">" . e($bName) . "</option>";
                }
                foreach ($commonBrands as $cb) {
                    if (!in_array(strtolower($cb), $shownBrands, true)) {
                        echo "<option value=\"" . e($cb) . "\" " . ($filterBrand === $cb ? 'selected' : '') . ">" . e($cb) . "</option>";
                    }
                }
                ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 170px;">
            <select name="part_type" class="form-select" onchange="this.form.submit()">
                <option value="all">All Part Types</option>
                <?php foreach ($partCategories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 150px;">
            <select name="stock" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterStock === 'all' ? 'selected' : '' ?>>All Stock</option>
                <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock (Alert)</option>
                <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
        </div>

        <button type="submit" class="btn btn-secondary">
            <i class="ti ti-search mr-1"></i> Filter
        </button>

        <?php if ($search !== '' || $filterBrand !== 'all' || $filterCategory !== 'all' || $filterStock !== 'all'): ?>
            <a href="<?= url('products.php') ?>" class="btn btn-secondary btn-outline" title="Clear Filters">
                <i class="ti ti-rotate-clockwise"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Products Table -->
<div class="card p-0 mb-4">
    <div class="table-responsive">
        <table class="data-table" id="parts-table">
            <thead>
                <tr>
                    <th>Part Name & Description</th>
                    <th>Brand & Model</th>
                    <th>Part Category</th>
                    <th>SKU / Barcode</th>
                    <th class="text-center">Available Stock</th>
                    <th class="text-right">Purchase Price</th>
                    <th class="text-right">Sale Price</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                <tr>
                    <td colspan="8" class="text-center p-5 text-muted">
                        <i class="ti ti-package-off fs-1 d-block mb-2"></i>
                        No mobile spare parts found. Click "Add Mobile Part" to create your first inventory item.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): 
                        $qty = (float)($p['stock_quantity'] ?? 0);
                        $minAlert = (float)($p['min_stock_alert'] ?? 2);
                        $stockClass = ($qty <= 0) ? 'danger' : (($qty <= $minAlert) ? 'warning' : 'success');
                    ?>
                    <tr>
                        <td>
                            <div class="font-bold text-base"><?= e($p['name']) ?></div>
                            <?php if (!empty($p['description'])): ?>
                                <div class="text-xs text-muted"><?= e(truncate($p['description'], 45)) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['supplier_name'])): ?>
                                <div class="text-xs text-muted"><i class="ti ti-truck mr-1"></i> <?= e($p['supplier_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['brand']) || !empty($p['model'])): ?>
                                <span class="badge badge-subtle font-semibold">
                                    <?= e(trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? ''))) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-sm font-medium"><?= e($p['part_type'] ?: $p['category'] ?: 'General') ?></span>
                        </td>
                        <td>
                            <div class="text-xs font-mono"><?= e($p['sku'] ?: '—') ?></div>
                            <?php if (!empty($p['barcode'])): ?>
                                <div class="text-xs text-muted font-mono"><i class="ti ti-barcode mr-1"></i><?= e($p['barcode']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-<?= $stockClass ?> font-bold" style="font-size: 0.95rem; padding: 0.25rem 0.6rem;">
                                <?= $qty ?> <?= e($p['unit'] ?: 'Pcs') ?>
                            </span>
                            <?php if ($qty <= $minAlert && $qty > 0): ?>
                                <div class="text-xs text-warning font-semibold mt-1"><i class="ti ti-alert-triangle"></i> Low Stock</div>
                            <?php elseif ($qty <= 0): ?>
                                <div class="text-xs text-danger font-semibold mt-1"><i class="ti ti-circle-x"></i> Out of Stock</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-muted font-medium">
                            <?= formatCurrency($p['purchase_price'] ?? $p['cost_price'] ?? 0) ?>
                        </td>
                        <td class="text-right font-bold text-primary">
                            <?= formatCurrency($p['selling_price'] ?? 0) ?>
                        </td>
                        <td class="text-right">
                            <div class="d-inline-flex gap-1">
                                <!-- Restock Quick Button -->
                                <button type="button" class="btn btn-secondary btn-sm" title="Restock / Purchase" onclick="openRestockModal(<?= $p['id'] ?>, '<?= addslashes(e($p['name'])) ?>', <?= (float)($p['purchase_price'] ?? 0) ?>)">
                                    <i class="ti ti-truck-delivery text-success"></i>
                                </button>
                                <!-- Adjust Stock Button -->
                                <button type="button" class="btn btn-secondary btn-sm" title="Adjust / Audit Stock" onclick="openAdjustModal(<?= $p['id'] ?>, '<?= addslashes(e($p['name'])) ?>', <?= $qty ?>)">
                                    <i class="ti ti-adjustments text-warning"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Mobile Part Modal -->
<div id="new-part-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-plus mr-2 text-primary"></i> Add New Mobile Spare Part / Product</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('new-part-modal').style.display='none'">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <form method="POST" action="<?= url('products.php') ?>" class="card-body">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create_product">

            <div class="form-group mb-3">
                <label class="form-label required">Part / Product Name</label>
                <input type="text" name="name" class="form-input" placeholder="e.g. V20 Display Panel Original (OLED) or iPhone 13 Battery" required>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-input" placeholder="e.g. Vivo, Samsung, Apple" list="brands-datalist">
                    <datalist id="brands-datalist">
                        <?php foreach ($commonBrands as $cb): ?>
                            <option value="<?= e($cb) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label class="form-label">Mobile Model</label>
                    <input type="text" name="model" class="form-input" placeholder="e.g. V20, A52, iPhone 13">
                </div>

                <div class="form-group">
                    <label class="form-label required">Part Category</label>
                    <select name="part_type" class="form-select">
                        <?php foreach ($partCategories as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Initial Stock</label>
                    <input type="number" step="1" min="0" name="initial_stock" class="form-input" value="0" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Min Stock Alert</label>
                    <input type="number" step="1" min="0" name="min_stock_alert" class="form-input" value="2">
                </div>

                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" class="form-input" value="Pcs">
                </div>

                <div class="form-group">
                    <label class="form-label">SKU</label>
                    <input type="text" name="sku" class="form-input" placeholder="e.g. DISP-V20-01">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Purchase Cost</label>
                    <input type="number" step="0.01" min="0" name="purchase_price" class="form-input" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Selling Price</label>
                    <input type="number" step="0.01" min="0" name="selling_price" class="form-input" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Barcode</label>
                    <input type="text" name="barcode" class="form-input" placeholder="Scan or enter code">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label">Supplier / Source</label>
                    <input type="text" name="supplier_name" class="form-input" placeholder="e.g. Hall Road Trader / Saddar Vendor">
                </div>

                <div class="form-group d-flex align-center mt-4">
                    <label class="d-flex align-center gap-2 cursor-pointer">
                        <input type="checkbox" name="allow_negative_stock" value="1">
                        <span class="text-sm">Allow Negative Stock (Sell even when 0)</span>
                    </label>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">Description / Warranty Notes</label>
                <textarea name="description" class="form-input" rows="2" placeholder="e.g. 7-day checking warranty, original pull part"></textarea>
            </div>

            <div class="d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-part-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-check mr-1"></i> Save Spare Part
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Restock / Purchase Modal -->
<div id="restock-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-truck-delivery mr-2 text-success"></i> Purchase Restock</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('restock-modal').style.display='none'">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <form method="POST" action="<?= url('products.php') ?>" class="card-body">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="record_purchase">
            <input type="hidden" name="product_id" id="restock-prod-id" value="">

            <div class="mb-3">
                <span class="text-muted text-xs d-block">Selected Part</span>
                <strong id="restock-prod-name" class="font-bold text-base"></strong>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div class="form-group">
                    <label class="form-label required">Quantity Received</label>
                    <input type="number" step="1" min="1" name="quantity" class="form-input" value="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label required">Purchase Price</label>
                    <input type="number" step="0.01" min="0" name="purchase_price" id="restock-price" class="form-input" required>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="form-group">
                    <label class="form-label">Supplier Name</label>
                    <input type="text" name="supplier_name" class="form-input" placeholder="Supplier / Vendor">
                </div>
                <div class="form-group">
                    <label class="form-label">Invoice / Bill #</label>
                    <input type="text" name="bill_number" class="form-input" placeholder="e.g. BIL-1092">
                </div>
            </div>

            <div class="d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('restock-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="ti ti-plus mr-1"></i> Add to Stock
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div id="adjust-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-adjustments mr-2 text-warning"></i> Adjust Inventory Stock</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('adjust-modal').style.display='none'">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <form method="POST" action="<?= url('products.php') ?>" class="card-body">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="product_id" id="adjust-prod-id" value="">

            <div class="mb-3">
                <span class="text-muted text-xs d-block">Selected Part</span>
                <strong id="adjust-prod-name" class="font-bold text-base"></strong>
                <div class="text-xs text-muted mt-1">Current Stock: <span id="adjust-current-stock" class="font-bold"></span> units</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label required">Quantity Change (+ to Add, - to Deduct)</label>
                <input type="number" step="1" name="delta" class="form-input" placeholder="e.g. +5 or -2" required>
            </div>

            <div class="form-group mb-4">
                <label class="form-label required">Adjustment Reason</label>
                <select name="reason" class="form-select" required>
                    <option value="Physical count audit / inventory recount">Physical Count Audit / Recount</option>
                    <option value="Damaged / broken in testing or shop handling">Damaged / Broken in Testing</option>
                    <option value="Customer return (restocked)">Customer Return (Restocked)</option>
                    <option value="Defective component (written off)">Defective Component (Written Off)</option>
                    <option value="Other inventory correction">Other Inventory Correction</option>
                </select>
            </div>

            <div class="d-flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('adjust-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-warning">
                    <i class="ti ti-check mr-1"></i> Apply Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Movements Log Drawer -->
<div id="movements-drawer" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 900px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title"><i class="ti ti-history mr-2 text-primary"></i> Stock Movement Audit Trail</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('movements-drawer').style.display='none'">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="card-body p-0" style="overflow-y: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Part Item</th>
                        <th>Movement Type</th>
                        <th class="text-right">Qty Change</th>
                        <th class="text-center">Before &rarr; After</th>
                        <th>Reference / Notes</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentMovements)): ?>
                    <tr>
                        <td colspan="7" class="text-center p-4 text-muted">No stock movements recorded yet.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentMovements as $m): 
                            $typeClass = match($m['type']) {
                                'sale' => 'danger',
                                'purchase', 'cancellation_restore', 'return_customer' => 'success',
                                'return_supplier' => 'warning',
                                default => 'info'
                            };
                        ?>
                        <tr>
                            <td class="text-xs"><?= formatDate($m['created_at']) ?></td>
                            <td>
                                <div class="font-bold text-sm"><?= e($m['product_name']) ?></div>
                                <div class="text-xs text-muted"><?= e($m['sku'] ?: '—') ?></div>
                            </td>
                            <td>
                                <span class="badge badge-<?= $typeClass ?> text-xs font-semibold">
                                    <?= strtoupper(str_replace('_', ' ', $m['type'])) ?>
                                </span>
                            </td>
                            <td class="text-right font-bold <?= $m['quantity'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $m['quantity'] > 0 ? '+' : '' ?><?= $m['quantity'] ?>
                            </td>
                            <td class="text-center text-xs font-mono">
                                <?= $m['quantity_before'] ?> &rarr; <span class="font-bold"><?= $m['quantity_after'] ?></span>
                            </td>
                            <td class="text-xs text-muted"><?= e($m['notes'] ?: '—') ?></td>
                            <td class="text-xs font-medium"><?= e($m['user_name'] ?: 'System') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function openRestockModal(id, name, price) {
    document.getElementById('restock-prod-id').value = id;
    document.getElementById('restock-prod-name').innerText = name;
    document.getElementById('restock-price').value = price;
    document.getElementById('restock-modal').style.display = 'flex';
}

function openAdjustModal(id, name, currentStock) {
    document.getElementById('adjust-prod-id').value = id;
    document.getElementById('adjust-prod-name').innerText = name;
    document.getElementById('adjust-current-stock').innerText = currentStock;
    document.getElementById('adjust-modal').style.display = 'flex';
}
</script>

<?php require YBT_ROOT . '/includes/footer.php'; ?>
