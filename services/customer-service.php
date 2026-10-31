<?php
/**
 * YBT Management Software — Customer Service
 * Handles customer CRUD, ledger balance calculations, and history
 * Strictly scoped by TenantContext to prevent cross-tenant leakage.
 */

class CustomerService {
    /**
     * Get paginated customer list with filters
     */
    public static function getAll(int $businessId, array $filters = [], int $page = 1, int $perPage = 15): array {
        TenantContext::assertMatches($businessId);

        $where = ["c.business_id = ?"];
        $params = [$businessId];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(c.name LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.gstin LIKE ?)";
            $params = array_merge($params, [$term, $term, $term, $term, $term]);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "c.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['city'])) {
            $where[] = "c.billing_city = ?";
            $params[] = $filters['city'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countRow = Database::fetch("SELECT COUNT(*) as cnt FROM customers c WHERE {$whereClause}", $params);
        $total = $countRow['cnt'] ?? 0;

        // Fetch paginated with strictly tenant-scoped invoice subqueries
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT c.*, 
                (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id) as invoice_count,
                (SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_billed,
                (SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_paid,
                (SELECT COALESCE(SUM(outstanding), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status IN ('sent', 'partial', 'overdue')) as total_outstanding
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
     * Get single customer with detailed stats and invoices
     */
    public static function getById(int $customerId, int $businessId): ?array {
        TenantContext::assertMatches($businessId);

        $customer = Database::fetch(
            "SELECT c.*,
             (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id) as invoice_count,
             (SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_billed,
             (SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status != 'void') as total_paid,
             (SELECT COALESCE(SUM(outstanding), 0) FROM invoices WHERE customer_id = c.id AND business_id = c.business_id AND status IN ('sent', 'partial', 'overdue')) as total_outstanding
             FROM customers c 
             WHERE c.id = ? AND c.business_id = ?",
            [$customerId, $businessId]
        );

        if (!$customer) return null;

        // Invoices
        $customer['invoices'] = Database::fetchAll(
            "SELECT * FROM invoices WHERE customer_id = ? AND business_id = ? ORDER BY invoice_date DESC LIMIT 10",
            [$customerId, $businessId]
        );

        // Quotations
        $customer['quotations'] = Database::fetchAll(
            "SELECT * FROM quotations WHERE customer_id = ? AND business_id = ? ORDER BY quotation_date DESC LIMIT 10",
            [$customerId, $businessId]
        );

        // Payments
        $customer['payments'] = Database::fetchAll(
            "SELECT p.*, i.invoice_number 
             FROM payments p 
             LEFT JOIN payment_allocations pa ON p.id = pa.payment_id
             LEFT JOIN invoices i ON pa.invoice_id = i.id
             WHERE p.customer_id = ? AND p.business_id = ? 
             ORDER BY p.payment_date DESC LIMIT 10",
            [$customerId, $businessId]
        );

        return $customer;
    }

    /**
     * Create customer
     */
    public static function create(int $businessId, array $data, int $userId): int {
        TenantContext::assertMatches($businessId);

        return Database::insert('customers', [
            'business_id' => $businessId,
            'name' => trim($data['name']),
            'company_name' => trim($data['company_name'] ?? ''),
            'contact_person' => trim($data['contact_person'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'gstin' => strtoupper(trim($data['gstin'] ?? '')),
            'pan' => strtoupper(trim($data['pan'] ?? '')),
            'billing_address_line1' => trim($data['billing_address_line1'] ?? ''),
            'billing_address_line2' => trim($data['billing_address_line2'] ?? ''),
            'billing_city' => trim($data['billing_city'] ?? ''),
            'billing_state' => trim($data['billing_state'] ?? ''),
            'billing_postal_code' => trim($data['billing_postal_code'] ?? ''),
            'billing_country' => trim($data['billing_country'] ?? 'India'),
            'shipping_address_line1' => trim($data['shipping_address_line1'] ?? ''),
            'shipping_city' => trim($data['shipping_city'] ?? ''),
            'shipping_state' => trim($data['shipping_state'] ?? ''),
            'notes' => trim($data['notes'] ?? ''),
            'status' => $data['status'] ?? 'active',
            'created_by' => $userId
        ]);
    }

    /**
     * Update customer
     */
    public static function update(int $customerId, int $businessId, array $data): bool {
        TenantContext::assertMatches($businessId);

        return Database::update('customers', [
            'name' => trim($data['name']),
            'company_name' => trim($data['company_name'] ?? ''),
            'contact_person' => trim($data['contact_person'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'gstin' => strtoupper(trim($data['gstin'] ?? '')),
            'pan' => strtoupper(trim($data['pan'] ?? '')),
            'billing_address_line1' => trim($data['billing_address_line1'] ?? ''),
            'billing_address_line2' => trim($data['billing_address_line2'] ?? ''),
            'billing_city' => trim($data['billing_city'] ?? ''),
            'billing_state' => trim($data['billing_state'] ?? ''),
            'billing_postal_code' => trim($data['billing_postal_code'] ?? ''),
            'billing_country' => trim($data['billing_country'] ?? 'India'),
            'notes' => trim($data['notes'] ?? ''),
            'status' => $data['status'] ?? 'active'
        ], 'id = ? AND business_id = ?', [$customerId, $businessId]) > 0;
    }

    /**
     * Archive/Delete customer
     */
    public static function delete(int $customerId, int $businessId): bool {
        TenantContext::assertMatches($businessId);

        // Soft delete / archive
        return Database::update('customers', ['status' => 'inactive'], 'id = ? AND business_id = ?', [$customerId, $businessId]) > 0;
    }
}
