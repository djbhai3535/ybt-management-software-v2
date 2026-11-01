<?php
declare(strict_types=1);

/**
 * YBT Management Software — Payment Service
 * Handles recording payments, allocating to invoices, and customer khata integration.
 */

require_once __DIR__ . '/khata-service.php';

class PaymentService {
    /**
     * Record a payment, allocate to invoices, and record in Customer Khata
     */
    public static function record(int $businessId, array $data, int $userId): int {
        $res = KhataService::recordCustomerPayment($businessId, $data, $userId);
        return (int)$res['payment_id'];
    }

    /**
     * Allocate payment amount to an invoice and recalculate invoice status
     */
    public static function allocateToInvoice(int $paymentId, int $invoiceId, float $amount, int $businessId): void {
        $inv = Database::fetch("SELECT * FROM invoices WHERE id = ? AND business_id = ?", [$invoiceId, $businessId]);
        if (!$inv) return;

        // Insert allocation
        Database::insert('payment_allocations', [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Recompute invoice paid & outstanding
        $allocRow = Database::fetch("SELECT COALESCE(SUM(amount), 0) as total FROM payment_allocations WHERE invoice_id = ?", [$invoiceId]);
        $totalPaid = (float)($allocRow['total'] ?? 0);
        $outstanding = max(0.0, (float)$inv['grand_total'] - $totalPaid);

        $newStatus = 'sent';
        if ($outstanding <= 0.009) {
            $newStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        }

        Database::query(
            "UPDATE invoices SET amount_paid = ?, outstanding = ?, status = ?, updated_at = datetime('now') WHERE id = ?",
            [$totalPaid, $outstanding, $newStatus, $invoiceId]
        );
    }
}
