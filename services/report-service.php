<?php
/**
 * YBT Management Software — Report & Analytics Service
 * Computes P&L statements, monthly revenue trends, customer rankings, and GST reports
 */

class ReportService {
    /**
     * Get high-level executive financial metrics
     */
    public static function getExecutiveSummary(int $businessId): array {
        // Total Invoiced & Total Collected (Clean schema canonical columns)
        $invStats = Database::fetch(
            "SELECT 
                COALESCE(SUM(total_amount), 0) as total_invoiced,
                COALESCE(SUM(paid_amount), 0) as total_collected,
                COALESCE(SUM(balance_amount), 0) as total_outstanding,
                COUNT(*) as total_invoices
             FROM invoices 
             WHERE business_id = ? AND status != 'void'",
            [$businessId]
        );

        // Total Expenses (Clean schema uses is_deleted flag)
        $expStats = Database::fetch(
            "SELECT COALESCE(SUM(amount), 0) as total_expenses, COUNT(*) as expense_count
             FROM expenses 
             WHERE business_id = ? AND is_deleted = 0",
            [$businessId]
        );

        $revenue = (float)($invStats['total_collected'] ?? 0);
        $expenses = (float)($expStats['total_expenses'] ?? 0);
        $netProfit = $revenue - $expenses;
        $profitMargin = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;

        // Pipeline total
        $pipeline = Database::fetch(
            "SELECT COALESCE(SUM(deal_value), 0) as total_pipeline, COUNT(*) as active_leads
             FROM leads 
             WHERE business_id = ? AND stage NOT IN ('won', 'lost')",
            [$businessId]
        );

        return [
            'total_invoiced' => (float)$invStats['total_invoiced'],
            'total_collected' => $revenue,
            'total_outstanding' => (float)$invStats['total_outstanding'],
            'total_expenses' => $expenses,
            'net_profit' => $netProfit,
            'profit_margin' => round($profitMargin, 1),
            'total_pipeline' => (float)$pipeline['total_pipeline'],
            'active_leads' => (int)$pipeline['active_leads']
        ];
    }

    /**
     * Get monthly revenue & expense trends for past 6 or 12 months
     */
    public static function getMonthlyTrends(int $businessId, int $months = 6): array {
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-$i months"));
            $monthEnd = date('Y-m-t', strtotime("-$i months"));
            $label = date('M Y', strtotime($monthStart));

            $revRow = Database::fetch(
                "SELECT COALESCE(SUM(amount), 0) as rev FROM payments WHERE business_id = ? AND payment_date BETWEEN ? AND ?",
                [$businessId, $monthStart, $monthEnd]
            );

            $expRow = Database::fetch(
                "SELECT COALESCE(SUM(amount), 0) as exp FROM expenses WHERE business_id = ? AND expense_date BETWEEN ? AND ? AND is_deleted = 0",
                [$businessId, $monthStart, $monthEnd]
            );

            $result[] = [
                'month' => $label,
                'revenue' => (float)($revRow['rev'] ?? 0),
                'expenses' => (float)($expRow['exp'] ?? 0),
                'profit' => (float)($revRow['rev'] ?? 0) - (float)($expRow['exp'] ?? 0)
            ];
        }

        return $result;
    }

    /**
     * Get top revenue customers
     */
    public static function getTopCustomers(int $businessId, int $limit = 5): array {
        return Database::fetchAll(
            "SELECT c.id, c.name, c.company_name, 
                    COALESCE(SUM(i.total_amount), 0) as total_billed,
                    COALESCE(SUM(i.paid_amount), 0) as total_paid
             FROM customers c
             JOIN invoices i ON c.id = i.customer_id
             WHERE c.business_id = ? AND i.status != 'void'
             GROUP BY c.id, c.name, c.company_name
             ORDER BY total_billed DESC
             LIMIT {$limit}",
            [$businessId]
        );
    }

    /**
     * Get GST Tax Report Breakdown
     */
    public static function getGstReport(int $businessId, string $startDate, string $endDate): array {
        return Database::fetchAll(
            "SELECT 
                invoice_number, 
                issue_date as invoice_date, 
                '' as place_of_supply,
                subtotal, 
                0.00 as cgst_total, 
                0.00 as sgst_total, 
                0.00 as igst_total, 
                tax_amount as tax_total, 
                total_amount as grand_total
             FROM invoices
             WHERE business_id = ? AND issue_date BETWEEN ? AND ? AND status != 'void'
             ORDER BY issue_date ASC",
            [$businessId, $startDate, $endDate]
        );
    }
}
