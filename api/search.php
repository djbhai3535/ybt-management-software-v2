<?php
/**
 * DJ CRM Software — Universal Global Search API
 * Multi-category, context-aware, permission-enforced, SQL-injection protected.
 */

if (!defined('YBT_API_REQUEST')) {
    define('YBT_API_REQUEST', true);
}
require_once __DIR__ . '/../includes/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Support Bearer Token for desktop clients, external tools, and automated tests
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!Auth::check() && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $tokenHash = hash('sha256', $matches[1]);
    $tokenRecord = Database::fetch(
        "SELECT t.*, u.name as user_name, u.email as user_email, u.is_owner, u.role_id 
         FROM api_tokens t
         JOIN users u ON t.user_id = u.id
         WHERE t.token_hash = ? AND t.expires_at > datetime('now') AND u.is_active = 1
         LIMIT 1",
        [$tokenHash]
    );
    if ($tokenRecord) {
        $_SESSION['user_id'] = (int)$tokenRecord['user_id'];
        $_SESSION['business_id'] = (int)$tokenRecord['business_id'];
        $_SESSION['is_owner'] = (int)$tokenRecord['is_owner'];
        $_SESSION['role_id'] = (int)$tokenRecord['role_id'];
        $_SESSION['permissions'] = ['*'];
    }
}

if (!Auth::check()) {
    if (!headers_sent()) http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required', 'results' => [], 'total' => 0]);
    if (defined('YBT_TEST_MODE')) return;
    exit;
}

$businessId = Auth::businessId();
$query = trim($_GET['q'] ?? '');

if (mb_strlen($query) < 2) {
    echo json_encode(['success' => true, 'query' => $query, 'results' => [], 'total' => 0, 'categories' => []]);
    if (defined('YBT_TEST_MODE')) return;
    exit;
}

$cleanDigits = preg_replace('/[^0-9]/', '', $query);
$term = '%' . $query . '%';
$results = [];
$counts = [
    'customers' => 0,
    'products' => 0,
    'services' => 0,
    'invoices' => 0,
    'khata' => 0
];

// Helper to format currency safely
if (!function_exists('searchFormatMoney')) {
    function searchFormatMoney(float $amount): string {
        if (function_exists('formatCurrency')) {
            return formatCurrency($amount);
        }
        return 'Rs. ' . number_format($amount, 2);
    }
}

// -------------------------------------------------------------
// 1. CUSTOMERS
// -------------------------------------------------------------
if (Permissions::has('customers.view')) {
    $custSql = "SELECT id, name, company_name, phone, email, current_balance, city 
                FROM customers 
                WHERE business_id = ? AND (
                    LOWER(name) LIKE LOWER(?) 
                    OR LOWER(company_name) LIKE LOWER(?) 
                    OR LOWER(email) LIKE LOWER(?)";
    $custParams = [$businessId, $term, $term, $term];

    if (strlen($cleanDigits) >= 4) {
        $custSql .= " OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?";
        $custParams[] = '%' . $cleanDigits . '%';

        if (str_starts_with($cleanDigits, '0') && strlen($cleanDigits) >= 7) {
            $custSql .= " OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?";
            $custParams[] = '%' . ltrim($cleanDigits, '0') . '%';
        }
    }

    $custSql .= ") ORDER BY name ASC LIMIT 5";

    $customers = Database::fetchAll($custSql, $custParams);
    foreach ($customers as $c) {
        $bal = (float)($c['current_balance'] ?? 0.0);
        $balText = '';
        if ($bal > 0) {
            $balText = ' • Due: ' . searchFormatMoney($bal);
        } elseif ($bal < 0) {
            $balText = ' • Advance: ' . searchFormatMoney(abs($bal));
        }

        $metaParts = [];
        if (!empty($c['phone'])) $metaParts[] = $c['phone'];
        if (!empty($c['city'])) $metaParts[] = $c['city'];

        $results[] = [
            'category' => 'Customers',
            'type' => 'Customer',
            'icon' => 'ti-user',
            'title' => $c['name'],
            'subtitle' => implode(' • ', $metaParts) . $balText,
            'balance' => $bal,
            'badge' => $bal > 0 ? 'Due' : ($bal < 0 ? 'Advance' : 'Clear'),
            'badge_class' => $bal > 0 ? 'badge-warning' : ($bal < 0 ? 'badge-info' : 'badge-success'),
            'url' => url('customer-view.php?id=' . $c['id'])
        ];
        $counts['customers']++;
    }
}

// -------------------------------------------------------------
// 2. PRODUCTS & SERVICES
// -------------------------------------------------------------
if (Permissions::has('products.view')) {
    $words = array_values(array_filter(preg_split('/\s+/', trim($query)), fn($w) => mb_strlen($w) > 0));
    $multiWordLike = count($words) > 1 ? ('%' . implode('%', $words) . '%') : $term;

    $prodSql = "SELECT id, name, sku, barcode, category, part_type, brand, model, selling_price, purchase_price, stock_quantity, min_stock_alert, unit 
                FROM products 
                WHERE business_id = ? AND status = 'active' AND (
                    LOWER(name) LIKE LOWER(?) 
                    OR LOWER(name) LIKE LOWER(?)
                    OR LOWER(sku) LIKE LOWER(?) 
                    OR barcode LIKE ? 
                    OR LOWER(brand) LIKE LOWER(?) 
                    OR LOWER(model) LIKE LOWER(?) 
                    OR LOWER(category) LIKE LOWER(?) 
                    OR LOWER(part_type) LIKE LOWER(?)
                ) ORDER BY name ASC LIMIT 6";

    $products = Database::fetchAll($prodSql, [$businessId, $term, $multiWordLike, $term, $term, $term, $term, $term, $term]);
    foreach ($products as $p) {
        $isService = (strtolower(trim($p['category'] ?? '')) === 'service' || strtolower(trim($p['part_type'] ?? '')) === 'service');
        $catName = $isService ? 'Services' : 'Products';
        $typeLabel = $isService ? 'Service' : 'Product';
        $icon = $isService ? 'ti-tool' : 'ti-package';

        $stock = (float)($p['stock_quantity'] ?? 0);
        $minStock = (float)($p['min_stock_alert'] ?? 5);

        $stockBadge = '';
        $stockBadgeClass = 'badge-success';
        if (!$isService) {
            if ($stock <= 0) {
                $stockBadge = 'Out of Stock';
                $stockBadgeClass = 'badge-danger';
            } elseif ($stock <= $minStock) {
                $stockBadge = "Low: {$stock} " . ($p['unit'] ?: 'pcs');
                $stockBadgeClass = 'badge-warning';
            } else {
                $stockBadge = "Stock: {$stock} " . ($p['unit'] ?: 'pcs');
                $stockBadgeClass = 'badge-success';
            }
        }

        $metaParts = [];
        if (!empty($p['sku'])) $metaParts[] = 'SKU: ' . $p['sku'];
        if (!empty($p['barcode'])) $metaParts[] = 'BC: ' . $p['barcode'];
        if (!empty($p['brand']) || !empty($p['model'])) $metaParts[] = trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? ''));

        $priceStr = searchFormatMoney((float)$p['selling_price']);

        $results[] = [
            'category' => $catName,
            'type' => $typeLabel,
            'icon' => $icon,
            'title' => $p['name'],
            'subtitle' => implode(' • ', $metaParts) . ($metaParts ? ' • ' : '') . $priceStr,
            'price' => (float)$p['selling_price'],
            'price_formatted' => $priceStr,
            'stock_quantity' => $stock,
            'badge' => $stockBadge,
            'badge_class' => $stockBadgeClass,
            'url' => url('products.php?search=' . urlencode($p['name']))
        ];

        if ($isService) {
            $counts['services']++;
        } else {
            $counts['products']++;
        }
    }
}

// -------------------------------------------------------------
// 3. INVOICES
// -------------------------------------------------------------
if (Permissions::has('invoices.view')) {
    $invSql = "SELECT id, invoice_number, 
                      total_amount as grand_total, 
                      status, 
                      issue_date as invoice_date 
               FROM invoices 
               WHERE business_id = ? AND (
                   LOWER(invoice_number) LIKE LOWER(?) 
                   OR LOWER(notes) LIKE LOWER(?)
               ) ORDER BY id DESC LIMIT 5";

    $invoices = Database::fetchAll($invSql, [$businessId, $term, $term]);
    foreach ($invoices as $i) {
        $invTotal = (float)($i['grand_total'] ?? 0.0);
        $statusStr = ucfirst($i['status'] ?? 'pending');
        $statusClass = ($i['status'] === 'paid') ? 'badge-success' : (($i['status'] === 'partial') ? 'badge-warning' : 'badge-secondary');

        $dateStr = !empty($i['invoice_date']) ? date('M d, Y', strtotime($i['invoice_date'])) : '';

        $results[] = [
            'category' => 'Invoices',
            'type' => 'Invoice',
            'icon' => 'ti-receipt',
            'title' => 'Invoice #' . $i['invoice_number'],
            'subtitle' => ($dateStr ? $dateStr . ' • ' : '') . searchFormatMoney($invTotal),
            'badge' => $statusStr,
            'badge_class' => $statusClass,
            'url' => url('invoice-view.php?id=' . $i['id'])
        ];
        $counts['invoices']++;
    }
}

// -------------------------------------------------------------
// 4. KHATA / CUSTOMER LEDGER
// -------------------------------------------------------------
if (Permissions::has('customers.view')) {
    $khataSql = "SELECT l.id, l.customer_id, l.entry_type, l.debit, l.credit, l.description, l.entry_date, c.name as customer_name 
                 FROM customer_ledger l 
                 JOIN customers c ON l.customer_id = c.id 
                 WHERE l.business_id = ? AND (
                     LOWER(l.description) LIKE LOWER(?) 
                     OR LOWER(c.name) LIKE LOWER(?)
                 ) ORDER BY l.id DESC LIMIT 5";

    $ledgerEntries = Database::fetchAll($khataSql, [$businessId, $term, $term]);
    foreach ($ledgerEntries as $l) {
        $isGave = (strtolower($l['entry_type']) === 'you_gave' || (float)$l['debit'] > 0);
        $amount = $isGave ? (float)$l['debit'] : (float)$l['credit'];
        $typeBadge = $isGave ? 'You Gave' : 'You Got';
        $badgeClass = $isGave ? 'badge-danger' : 'badge-success';

        $desc = trim($l['description'] ?? '');
        $dateStr = !empty($l['entry_date']) ? date('M d', strtotime($l['entry_date'])) : '';

        $results[] = [
            'category' => 'Khata / Ledger',
            'type' => 'Khata',
            'icon' => 'ti-book-2',
            'title' => $l['customer_name'] . ' (' . $typeBadge . ': ' . searchFormatMoney($amount) . ')',
            'subtitle' => ($dateStr ? $dateStr . ' • ' : '') . ($desc ?: 'Khata transaction'),
            'badge' => $typeBadge,
            'badge_class' => $badgeClass,
            'url' => url('customer-view.php?id=' . $l['customer_id'] . '&tab=khata')
        ];
        $counts['khata']++;
    }
}

echo json_encode([
    'success' => true,
    'query' => $query,
    'total' => count($results),
    'counts' => $counts,
    'results' => $results
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (defined('YBT_TEST_MODE')) return;
exit;
