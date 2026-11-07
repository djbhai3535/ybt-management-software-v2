<?php
declare(strict_types=1);

/**
 * YBT Management Software — Customer Service
 * Handles customer CRUD, ledger balance calculations, and history
 * Strictly scoped by TenantContext to prevent cross-tenant leakage.
 */

require_once __DIR__ . '/khata-service.php';

class CustomerService {
    /**
     * Get paginated customer list with filters
     */
    public static function getAll(int $businessId, array $filters = [], int $page = 1, int $perPage = 15): array {
        TenantContext::assertMatches($businessId);

        $where = ["c.business_id = ?"];
        $params = [$businessId];

        if (!empty($filters['search'])) {
            $rawTerm = trim($filters['search']);
            $term = '%' . $rawTerm . '%';
            $cleanDigits = preg_replace('/[^0-9]/', '', $rawTerm);

            $searchSql = "(c.name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.gstin LIKE ?";
            $searchParams = [$term, $term, $term, $term, $term];

            if (strlen($cleanDigits) >= 4) {
                $searchSql .= " OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', '') LIKE ?";
                $searchParams[] = '%' . $cleanDigits . '%';

                if (str_starts_with($cleanDigits, '0') && strlen($cleanDigits) >= 7) {
                    $searchSql .= " OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', '') LIKE ?";
                    $searchParams[] = '%' . ltrim($cleanDigits, '0') . '%';
                }
            }

            $searchSql .= ")";
            $where[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "c.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['city'])) {
            $where[] = "(c.billing_city = ? OR c.city = ?)";
            $params[] = $filters['city'];
            $params[] = $filters['city'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countRow = Database::fetch("SELECT COUNT(*) as cnt FROM customers c WHERE {$whereClause}", $params);
        $total = $countRow['cnt'] ?? 0;

        // Fetch paginated with strictly tenant-scoped invoice subqueries and real-time khata balance
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT c.*, 
                (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id) as invoice_count,
                (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_billed,
                (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_paid,
                (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status IN ('issued', 'partially_paid', 'sent', 'partial', 'overdue')) as total_outstanding,
                COALESCE(c.current_balance, 0) as khata_balance
                FROM customers c 
                WHERE {$whereClause} 
                ORDER BY c.created_at DESC 
                LIMIT {$perPage} OFFSET {$offset}";

        $customers = Database::fetchAll($sql, $params);

        return [
            'data' => $customers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage))
        ];
    }

    /**
     * Get single customer with detailed stats, invoices, and Khata ledger
     */
    public static function getById(int $customerId, int $businessId): ?array {
        TenantContext::assertMatches($businessId);

        $customer = Database::fetch(
            "SELECT c.*,
             (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id) as invoice_count,
             (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_billed,
             (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_paid,
             (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status IN ('issued', 'partially_paid', 'sent', 'partial', 'overdue')) as total_outstanding,
             COALESCE(c.current_balance, 0) as khata_balance
             FROM customers c 
             WHERE c.id = ? AND c.business_id = ?",
            [$customerId, $businessId]
        );

        if (!$customer) return null;

        // Invoices
        $customer['invoices'] = Database::fetchAll(
            "SELECT *, 
                    total_amount as grand_total, 
                    paid_amount as amount_paid, 
                    balance_amount as outstanding, 
                    issue_date as invoice_date 
             FROM invoices 
             WHERE customer_id = ? AND business_id = ? 
             ORDER BY issue_date DESC, id DESC LIMIT 20",
            [$customerId, $businessId]
        );

        // Quotations
        $customer['quotations'] = Database::fetchAll(
            "SELECT *, 
                    total_amount as grand_total, 
                    issue_date as quotation_date, 
                    valid_until as validity_date 
             FROM quotations 
             WHERE customer_id = ? AND business_id = ? 
             ORDER BY issue_date DESC LIMIT 10",
            [$customerId, $businessId]
        );

        // Payments
        $customer['payments'] = Database::fetchAll(
            "SELECT p.*, i.invoice_number 
             FROM payments p 
             LEFT JOIN payment_allocations pa ON p.id = pa.payment_id
             LEFT JOIN invoices i ON pa.invoice_id = i.id
             WHERE p.customer_id = ? AND p.business_id = ? 
             ORDER BY p.payment_date DESC, p.id DESC LIMIT 20",
            [$customerId, $businessId]
        );

        // Customer Khata Statement
        $customer['khata'] = KhataService::getStatement($customerId, $businessId);

        return $customer;
    }

    /**
     * Create customer with optional opening balance
     */
    public static function create(int $businessId, array $data, int $userId): int {
        TenantContext::assertMatches($businessId);

        $opBal = max(0.0, (float)($data['opening_balance'] ?? 0));
        $rawType = (string)($data['opening_balance_type'] ?? 'debit');
        $opType = in_array(strtolower($rawType), ['debit', 'credit'], true) ? strtolower($rawType) : 'debit';
        $currBal = ($opType === 'credit') ? -$opBal : $opBal;

        $addr = trim($data['billing_address'] ?? $data['billing_address_line1'] ?? $data['address'] ?? '');
        $city = trim($data['billing_city'] ?? $data['city'] ?? '');
        $state = trim($data['billing_state'] ?? $data['state'] ?? '');
        $pin = trim($data['billing_pincode'] ?? $data['billing_postal_code'] ?? $data['pincode'] ?? '');

        $customerId = Database::insert('customers', [
            'uuid' => generateUuidV4(),
            'business_id' => $businessId,
            'name' => trim($data['name']),
            'company_name' => trim($data['company_name'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'address' => $addr,
            'city' => $city,
            'state' => $state,
            'pincode' => $pin,
            'gstin' => strtoupper(trim($data['gstin'] ?? '')),
            'billing_address' => $addr,
            'billing_city' => $city,
            'billing_state' => $state,
            'billing_pincode' => $pin,
            'shipping_address' => trim($data['shipping_address'] ?? $data['shipping_address_line1'] ?? $addr),
            'shipping_city' => trim($data['shipping_city'] ?? $city),
            'shipping_state' => trim($data['shipping_state'] ?? $state),
            'shipping_pincode' => trim($data['shipping_pincode'] ?? $pin),
            'notes' => trim($data['notes'] ?? ''),
            'opening_balance' => $opBal,
            'opening_balance_type' => $opType,
            'current_balance' => $currBal,
            'status' => $data['status'] ?? 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($opBal > 0) {
            KhataService::setOpeningBalance(
                $customerId,
                $businessId,
                $opBal,
                $opType,
                trim($data['opening_balance_notes'] ?? 'Initial opening balance recorded at customer registration'),
                $userId
            );
        }

        return $customerId;
    }

    /**
     * Update customer
     */
    public static function update(int $customerId, int $businessId, array $data): bool {
        TenantContext::assertMatches($businessId);

        $addr = trim($data['billing_address'] ?? $data['billing_address_line1'] ?? $data['address'] ?? '');
        $city = trim($data['billing_city'] ?? $data['city'] ?? '');
        $state = trim($data['billing_state'] ?? $data['state'] ?? '');
        $pin = trim($data['billing_pincode'] ?? $data['billing_postal_code'] ?? $data['pincode'] ?? '');

        $updateData = [
            'name' => trim($data['name']),
            'company_name' => trim($data['company_name'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'address' => $addr,
            'city' => $city,
            'state' => $state,
            'pincode' => $pin,
            'gstin' => strtoupper(trim($data['gstin'] ?? '')),
            'billing_address' => $addr,
            'billing_city' => $city,
            'billing_state' => $state,
            'billing_pincode' => $pin,
            'notes' => trim($data['notes'] ?? ''),
            'status' => $data['status'] ?? 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return Database::update('customers', $updateData, 'id = ? AND business_id = ?', [$customerId, $businessId]) > 0;
    }

    /**
     * Archive/Delete customer
     */
    public static function delete(int $customerId, int $businessId): bool {
        TenantContext::assertMatches($businessId);

        return Database::update('customers', ['status' => 'inactive', 'updated_at' => date('Y-m-d H:i:s')], 'id = ? AND business_id = ?', [$customerId, $businessId]) > 0;
    }
}
