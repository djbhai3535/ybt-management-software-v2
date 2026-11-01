<?php
declare(strict_types=1);

/**
 * YBT Management Software — Stock Automation & Inventory Service
 * Manages atomic inventory adjustments, movement audit trail, oversell prevention,
 * purchase increments, and idempotent invoice deduction/restoration.
 */

class InsufficientStockException extends RuntimeException {}

class StockService {
    /**
     * Transaction helper: begins a transaction only if not already active
     */
    private static function beginTx(\PDO $db): bool {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            return true;
        }
        return false;
    }

    /**
     * Transaction helper: commits only if this call initiated the transaction
     */
    private static function commitTx(\PDO $db, bool $startedHere): void {
        if ($startedHere && $db->inTransaction()) {
            $db->commit();
        }
    }

    /**
     * Transaction helper: rolls back only if this call initiated the transaction
     */
    private static function rollbackTx(\PDO $db, bool $startedHere): void {
        if ($startedHere && $db->inTransaction()) {
            $db->rollBack();
        }
    }

    /**
     * Record a raw stock movement entry into stock_movements
     */
    public static function recordMovement(
        int $businessId,
        int $productId,
        string $type,
        float $quantity,
        float $qtyBefore,
        float $qtyAfter,
        ?string $refType = null,
        ?int $refId = null,
        ?string $refNum = null,
        ?string $notes = null,
        ?int $userId = null
    ): int {
        return Database::insert('stock_movements', [
            'business_id' => $businessId,
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'quantity_before' => $qtyBefore,
            'quantity_after' => $qtyAfter,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'reference_number' => $refNum,
            'notes' => $notes,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'sync_uuid' => generateUuidV4()
        ]);
    }

    /**
     * Adjust stock for a product (Add, subtract, recount, damage)
     */
    public static function adjustStock(
        int $businessId,
        int $productId,
        float $delta,
        string $type = 'adjustment',
        ?string $reason = null,
        ?int $userId = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $refNum = null
    ): array {
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            $product = Database::fetch(
                "SELECT id, name, stock_quantity, allow_negative_stock FROM products WHERE id = ? AND business_id = ?",
                [$productId, $businessId]
            );

            if (!$product) {
                throw new InvalidArgumentException("Product not found in this business.");
            }

            $currentStock = (float)($product['stock_quantity'] ?? 0);
            $newStock = $currentStock + $delta;

            // Strict Oversell & Negative Stock Guard
            if ($newStock < 0 && empty($product['allow_negative_stock'])) {
                throw new InsufficientStockException(
                    "Insufficient stock for '{$product['name']}': Current stock is {$currentStock}, requested change is {$delta}."
                );
            }

            Database::query(
                "UPDATE products SET stock_quantity = ?, updated_at = datetime('now') WHERE id = ?",
                [$newStock, $productId]
            );

            self::recordMovement(
                $businessId,
                $productId,
                $type,
                $delta,
                $currentStock,
                $newStock,
                $refType,
                $refId,
                $refNum,
                $reason,
                $userId
            );

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'product_id' => $productId,
                'product_name' => $product['name'],
                'quantity_before' => $currentStock,
                'quantity_after' => $newStock,
                'delta' => $delta
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Record a spare parts purchase / vendor restocking
     */
    public static function recordPurchase(
        int $businessId,
        int $productId,
        float $quantity,
        ?float $purchasePrice = null,
        ?string $supplierName = null,
        ?string $billNumber = null,
        ?int $userId = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Purchase quantity must be greater than zero.");
        }

        $notes = "Restocked from purchase" . ($billNumber ? " (Bill #{$billNumber})" : '') . ($supplierName ? " from {$supplierName}" : '');
        $res = self::adjustStock($businessId, $productId, $quantity, 'purchase', $notes, $userId, 'purchase', null, $billNumber);

        // Update purchase price or supplier if provided
        $updates = [];
        $params = [];
        if ($purchasePrice !== null && $purchasePrice >= 0) {
            $updates[] = "purchase_price = ?";
            $params[] = $purchasePrice;
        }
        if ($supplierName !== null && $supplierName !== '') {
            $updates[] = "supplier_name = ?";
            $params[] = $supplierName;
        }
        if (!empty($updates)) {
            $params[] = $productId;
            Database::query("UPDATE products SET " . implode(', ', $updates) . " WHERE id = ?", $params);
        }

        return $res;
    }

    /**
     * Record return of damaged or wrong parts back to supplier
     */
    public static function recordSupplierReturn(
        int $businessId,
        int $productId,
        float $quantity,
        string $reason,
        ?int $userId = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Supplier return quantity must be greater than zero.");
        }
        return self::adjustStock(
            $businessId,
            $productId,
            -$quantity,
            'return_supplier',
            "Returned to supplier: " . $reason,
            $userId,
            'return'
        );
    }

    /**
     * Deduct stock for all line items in an invoice (Idempotent & Atomic)
     */
    public static function deductForInvoice(int $businessId, int $invoiceId, ?int $userId = null): array {
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            $invoice = Database::fetch(
                "SELECT id, invoice_number, status, stock_deducted FROM invoices WHERE id = ? AND business_id = ?",
                [$invoiceId, $businessId]
            );

            if (!$invoice) {
                throw new InvalidArgumentException("Invoice not found.");
            }

            // IDEMPOTENCY GUARD: Do not deduct twice if already processed
            if (!empty($invoice['stock_deducted'])) {
                self::commitTx($db, $startedTx);
                return ['success' => true, 'already_deducted' => true, 'message' => 'Stock already deducted for this invoice.'];
            }

            $items = Database::fetchAll(
                "SELECT ii.*, p.name as product_name, p.stock_quantity, p.allow_negative_stock 
                 FROM invoice_items ii 
                 JOIN products p ON ii.product_id = p.id 
                 WHERE ii.invoice_id = ?",
                [$invoiceId]
            );

            // Phase 1: Pre-validation — check all items have sufficient stock
            foreach ($items as $item) {
                $reqQty = (float)$item['quantity'];
                $currStock = (float)($item['stock_quantity'] ?? 0);
                $allowNegative = !empty($item['allow_negative_stock']);

                if ($reqQty > $currStock && !$allowNegative) {
                    throw new InsufficientStockException(
                        "Insufficient stock for '{$item['product_name']}': Available {$currStock}, requested {$reqQty}."
                    );
                }
            }

            // Phase 2: Transactional deduction
            $deductedProducts = [];
            foreach ($items as $item) {
                $prodId = (int)$item['product_id'];
                $qty = (float)$item['quantity'];
                $currStock = (float)$item['stock_quantity'];
                $newStock = $currStock - $qty;

                Database::query(
                    "UPDATE products SET stock_quantity = ?, updated_at = datetime('now') WHERE id = ?",
                    [$newStock, $prodId]
                );

                self::recordMovement(
                    $businessId,
                    $prodId,
                    'sale',
                    -$qty,
                    $currStock,
                    $newStock,
                    'invoice',
                    $invoiceId,
                    $invoice['invoice_number'],
                    "Sold via invoice #{$invoice['invoice_number']}",
                    $userId
                );

                $deductedProducts[] = [
                    'product_id' => $prodId,
                    'name' => $item['product_name'],
                    'deducted' => $qty,
                    'stock_after' => $newStock
                ];
            }

            // Mark invoice stock_deducted = 1
            Database::query("UPDATE invoices SET stock_deducted = 1 WHERE id = ?", [$invoiceId]);

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'already_deducted' => false,
                'invoice_number' => $invoice['invoice_number'],
                'items_deducted' => $deductedProducts
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Restore stock when an invoice is cancelled (Idempotent & Exact)
     */
    public static function restoreForCancelledInvoice(int $businessId, int $invoiceId, ?int $userId = null): array {
        $db = Database::getInstance();
        $startedTx = self::beginTx($db);

        try {
            $invoice = Database::fetch(
                "SELECT id, invoice_number, status, stock_deducted, stock_restored FROM invoices WHERE id = ? AND business_id = ?",
                [$invoiceId, $businessId]
            );

            if (!$invoice) {
                throw new InvalidArgumentException("Invoice not found.");
            }

            // IDEMPOTENCY GUARD: If stock has already been restored, do not restore again!
            if (!empty($invoice['stock_restored'])) {
                self::commitTx($db, $startedTx);
                return ['success' => true, 'already_restored' => true, 'message' => 'Stock was already restored for this invoice.'];
            }

            // If stock was never deducted (e.g. draft cancelled), just mark cancelled
            if (empty($invoice['stock_deducted'])) {
                Database::query("UPDATE invoices SET status = 'cancelled', stock_restored = 1 WHERE id = ?", [$invoiceId]);
                self::commitTx($db, $startedTx);
                return ['success' => true, 'already_restored' => false, 'message' => 'Invoice marked cancelled (no stock deduction to restore).'];
            }

            // Restore all product quantities
            $items = Database::fetchAll(
                "SELECT ii.*, p.name as product_name, p.stock_quantity 
                 FROM invoice_items ii 
                 JOIN products p ON ii.product_id = p.id 
                 WHERE ii.invoice_id = ?",
                [$invoiceId]
            );

            $restoredProducts = [];
            foreach ($items as $item) {
                $prodId = (int)$item['product_id'];
                $qty = (float)$item['quantity'];
                $currStock = (float)$item['stock_quantity'];
                $newStock = $currStock + $qty;

                Database::query(
                    "UPDATE products SET stock_quantity = ?, updated_at = datetime('now') WHERE id = ?",
                    [$newStock, $prodId]
                );

                self::recordMovement(
                    $businessId,
                    $prodId,
                    'cancellation_restore',
                    $qty,
                    $currStock,
                    $newStock,
                    'invoice',
                    $invoiceId,
                    $invoice['invoice_number'],
                    "Restored stock from cancelled invoice #{$invoice['invoice_number']}",
                    $userId
                );

                $restoredProducts[] = [
                    'product_id' => $prodId,
                    'name' => $item['product_name'],
                    'restored' => $qty,
                    'stock_after' => $newStock
                ];
            }

            // Mark invoice as cancelled and stock_restored = 1
            Database::query("UPDATE invoices SET status = 'cancelled', stock_restored = 1 WHERE id = ?", [$invoiceId]);

            self::commitTx($db, $startedTx);

            return [
                'success' => true,
                'already_restored' => false,
                'invoice_number' => $invoice['invoice_number'],
                'items_restored' => $restoredProducts
            ];
        } catch (\Throwable $e) {
            self::rollbackTx($db, $startedTx);
            throw $e;
        }
    }

    /**
     * Get stock movement audit trail for a business/product
     */
    public static function getMovements(int $businessId, ?int $productId = null, int $limit = 50): array {
        $where = ["sm.business_id = ?"];
        $params = [$businessId];

        if ($productId !== null) {
            $where[] = "sm.product_id = ?";
            $params[] = $productId;
        }

        $whereClause = implode(' AND ', $where);
        return Database::fetchAll(
            "SELECT sm.*, p.name as product_name, p.sku, p.brand, p.model, u.name as user_name 
             FROM stock_movements sm 
             JOIN products p ON sm.product_id = p.id 
             LEFT JOIN users u ON sm.created_by = u.id 
             WHERE {$whereClause} 
             ORDER BY sm.id DESC LIMIT ?",
            array_merge($params, [$limit])
        );
    }

    /**
     * Get low stock alert items
     */
    public static function getLowStockAlerts(int $businessId): array {
        return Database::fetchAll(
            "SELECT id, name, sku, brand, model, part_type, stock_quantity, min_stock_alert, purchase_price, selling_price 
             FROM products 
             WHERE business_id = ? 
               AND status = 'active' 
               AND stock_quantity <= min_stock_alert 
             ORDER BY stock_quantity ASC",
            [$businessId]
        );
    }
}
