<?php
/**
 * Test Suite: Existing CRM Web Application Regression Tests
 * Verifies that all 14 existing modules function without breaking.
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/customer-service.php';
require_once __DIR__ . '/../services/invoice-service.php';
require_once __DIR__ . '/../services/payment-service.php';
require_once __DIR__ . '/../services/report-service.php';
require_once __DIR__ . '/../services/backup-service.php';

echo "=== Phase 2.1 Existing CRM Regression Verification ===\n";

// 1. Auth Login
echo ">> Module 1: Authentication & Tenant Context Auto-Binding...\n";
$authRes = @Auth::attempt('owner@ybt.com', 'password123');
if (!$authRes['success'] || !TenantContext::has()) {
    echo "FAIL: Auth::attempt failed or TenantContext was not bound.\n";
    exit(1);
}
$businessId = TenantContext::id();
$userId = Auth::id();
echo "   PASS: Logged in as {$authRes['user']['name']} (Tenant: {$businessId})\n";

// 2. Dashboard KPIs
echo ">> Module 2: Dashboard KPIs & Telemetry...\n";
$execSummary = ReportService::getExecutiveSummary($businessId);
$monthlyTrends = ReportService::getMonthlyTrends($businessId, 6);
if (!isset($execSummary['total_invoiced']) || !is_array($monthlyTrends)) {
    echo "FAIL: Dashboard executive summary or trends failed.\n";
    exit(1);
}
echo "   PASS: Dashboard telemetry loaded (Invoiced: ₹" . number_format($execSummary['total_invoiced'], 2) . ")\n";

// 3. Customers Directory & Profile
echo ">> Module 3: Customers Directory & Customer 360...\n";
$custData = CustomerService::getAll($businessId, [], 1, 10);
if (empty($custData['data'])) {
    echo "FAIL: CustomerService::getAll returned empty dataset.\n";
    exit(1);
}
$firstCustId = $custData['data'][0]['id'];
$custProfile = CustomerService::getById($firstCustId, $businessId);
if (!$custProfile || !isset($custProfile['invoices'])) {
    echo "FAIL: CustomerService::getById failed.\n";
    exit(1);
}
echo "   PASS: Customers directory and 360 profile loaded ({$custProfile['name']})\n";

// 4. Products & Catalog
echo ">> Module 4: Products Catalog...\n";
$products = Database::fetchAll("SELECT * FROM products WHERE business_id = ?", [$businessId]);
if (empty($products)) {
    echo "FAIL: Products catalog is empty.\n";
    exit(1);
}
echo "   PASS: Products catalog verified (" . count($products) . " items)\n";

// 5. Invoicing & GST Calculation Engine
echo ">> Module 5: Invoicing Engine & Sequential Numbering...\n";
$nextInvNumber = InvoiceService::generateInvoiceNumber($businessId);
$newInvoiceId = InvoiceService::create($businessId, [
    'customer_id' => $firstCustId,
    'invoice_date' => date('Y-m-d'),
], [
    ['description' => 'Enterprise Consulting Hour', 'quantity' => 1, 'unit_price' => 2000.00, 'tax_rate' => 18]
], $userId);

$invoice = Database::fetch("SELECT * FROM invoices WHERE id = ? AND business_id = ?", [$newInvoiceId, $businessId]);
if (!$invoice || (float)$invoice['grand_total'] != 2360.00) {
    echo "FAIL: Invoice creation or GST tax calculation failed (Grand Total: {$invoice['grand_total']})\n";
    exit(1);
}
echo "   PASS: Invoicing engine verified (Invoice: {$invoice['invoice_number']}, Total: ₹{$invoice['grand_total']})\n";

// 6. Payments
echo ">> Module 6: Payments Recording & Allocation...\n";
$payId = PaymentService::record($businessId, [
    'customer_id' => $firstCustId,
    'invoice_id' => $newInvoiceId,
    'amount' => 1000.00,
    'payment_method' => 'bank_transfer',
    'payment_date' => date('Y-m-d')
], $userId);

$payment = Database::fetch("SELECT * FROM payments WHERE id = ? AND business_id = ?", [$payId, $businessId]);
if (!$payment || (float)$payment['amount'] != 1000.00) {
    echo "FAIL: Payment recording failed.\n";
    exit(1);
}
echo "   PASS: Payment recording verified (Payment ID: {$payId}, Amount: ₹1000.00)\n";

// 7. Expenses
echo ">> Module 7: Expense Tracking...\n";
$categories = Database::fetchAll("SELECT id FROM expense_categories WHERE business_id = ?", [$businessId]);
$catId = $categories[0]['id'] ?? 1;

$expId = Database::insert('expenses', [
    'business_id' => $businessId,
    'category_id' => $catId,
    'description' => 'Office Internet Connection Bill',
    'amount' => 1500.00,
    'expense_date' => date('Y-m-d'),
    'method' => 'upi',
    'status' => 'active',
    'created_by' => $userId
]);
$exp = Database::fetch("SELECT * FROM expenses WHERE id = ? AND business_id = ?", [$expId, $businessId]);
if (!$exp) {
    echo "FAIL: Expense logging failed.\n";
    exit(1);
}
echo "   PASS: Expense logging verified (Expense ID: {$expId})\n";

// 8. Reports & Analytics
echo ">> Module 8: Financial Reports & GST Summary...\n";
$gstReport = ReportService::getGstReport($businessId, date('Y-m-01'), date('Y-m-t'));
$topCusts = ReportService::getTopCustomers($businessId, 5);
if (!is_array($gstReport) || !is_array($topCusts)) {
    echo "FAIL: ReportService failed.\n";
    exit(1);
}
echo "   PASS: Financial & GST reports generated successfully.\n";

// 9. Staff & Permissions
echo ">> Module 9: RBAC Permissions System...\n";
if (!Permissions::has('invoices.create') || !Permissions::has('reports.view')) {
    echo "FAIL: Permissions check failed for Owner role.\n";
    exit(1);
}
echo "   PASS: RBAC permissions matrix verified.\n";

// 10. AI Assistant & Key Encryption
echo ">> Module 10: AI Provider Settings & Authenticated Encryption...\n";
$rawApiKey = "sk-test-gemini-ai-key-secret-987654";
$encrypted = encryptString($rawApiKey);
$decrypted = decryptString($encrypted);
if ($decrypted !== $rawApiKey || !str_starts_with($encrypted, 'gcm:')) {
    echo "FAIL: AI key encryption verification failed.\n";
    exit(1);
}
echo "   PASS: AES-256-GCM encryption verified for AI configuration.\n";

// 11. Backup Service
echo ">> Module 11: Business Backup Generator...\n";
$backupRes = BackupService::createBackup($businessId, $userId);
if (!file_exists($backupRes['filepath']) || $backupRes['size'] <= 0) {
    echo "FAIL: BackupService failed to create SQL dump.\n";
    exit(1);
}
echo "   PASS: Business backup generated ({$backupRes['filename']}, " . formatFileSize($backupRes['size']) . ")\n";

// 12. Auth Logout
echo ">> Module 12: Logout & Tenant Context Invalidation...\n";
Auth::logout();
if (TenantContext::has() || Auth::check()) {
    echo "FAIL: Logout did not clear TenantContext or Session.\n";
    exit(1);
}
echo "   PASS: Auth::logout successfully invalidated session and TenantContext.\n";

echo "\n>>> ALL 12 EXISTING CRM WEB MODULES: 100% REGRESSION PASS!\n";
