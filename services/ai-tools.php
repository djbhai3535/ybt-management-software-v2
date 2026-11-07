<?php
/**
 * YBT Management Software — AI Assistant Tools Registry
 * Authorized tool definitions for querying business data and preparing safe mutation proposals
 */

class AITools {
    /**
     * Search Customers
     */
    public static function searchCustomers(int $businessId, string $query): array {
        $term = '%' . trim($query) . '%';
        return Database::fetchAll(
            "SELECT id, name, company_name, email, phone, billing_city, status,
                    (SELECT COALESCE(SUM(balance_amount), 0) FROM invoices WHERE customer_id = c.id AND status IN ('issued','partially_paid','sent','partial','overdue')) as balance
             FROM customers c
             WHERE business_id = ? AND (name LIKE ? OR company_name LIKE ? OR email LIKE ? OR billing_city LIKE ?)
             LIMIT 5",
            [$businessId, $term, $term, $term, $term]
        );
    }

    /**
     * Search Invoices
     */
    public static function searchInvoices(int $businessId, string $query): array {
        $term = '%' . trim($query) . '%';
        return Database::fetchAll(
            "SELECT i.id, i.invoice_number, 
                    i.issue_date as invoice_date, 
                    i.due_date, 
                    i.total_amount as grand_total, 
                    i.paid_amount as amount_paid, 
                    i.balance_amount as outstanding, 
                    i.status,
                    c.name as customer_name, c.company_name
             FROM invoices i
             JOIN customers c ON i.customer_id = c.id
             WHERE i.business_id = ? AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.company_name LIKE ? OR i.status = ?)
             ORDER BY i.issue_date DESC
             LIMIT 5",
            [$businessId, $term, $term, $term, trim($query)]
        );
    }

    /**
     * Get Overdue Invoices
     */
    public static function getOverdueInvoices(int $businessId): array {
        return Database::fetchAll(
            "SELECT i.id, i.invoice_number, i.due_date, 
                    i.balance_amount as outstanding, 
                    c.name as customer_name, c.phone, c.email
             FROM invoices i
             JOIN customers c ON i.customer_id = c.id
             WHERE i.business_id = ? AND (i.status = 'overdue' OR (i.status IN ('issued','partially_paid','sent','partial') AND i.due_date < CURDATE()))
             ORDER BY i.due_date ASC
             LIMIT 10",
            [$businessId]
        );
    }

    /**
     * Search Leads
     */
    public static function searchLeads(int $businessId, string $query = ''): array {
        $term = '%' . trim($query) . '%';
        return Database::fetchAll(
            "SELECT id, title, contact_name, company_name, deal_value, stage, source, phone
             FROM leads
             WHERE business_id = ? AND (title LIKE ? OR contact_name LIKE ? OR company_name LIKE ? OR stage LIKE ?)
             ORDER BY deal_value DESC
             LIMIT 5",
            [$businessId, $term, $term, $term, $term]
        );
    }

    /**
     * Get Financial Metrics
     */
    public static function getFinancialOverview(int $businessId): array {
        return ReportService::getExecutiveSummary($businessId);
    }

    /**
     * Propose Action (Stored in ai_action_proposals for Owner review)
     */
    public static function createProposal(int $businessId, int $userId, string $actionType, string $targetEntity, array $data, ?int $conversationId = null): int {
        return Database::insert('ai_action_proposals', [
            'business_id' => $businessId,
            'conversation_id' => $conversationId,
            'requested_by' => $userId,
            'action_type' => $actionType,
            'target_entity' => $targetEntity,
            'proposal_data' => json_encode($data),
            'status' => 'pending'
        ]);
    }
}
