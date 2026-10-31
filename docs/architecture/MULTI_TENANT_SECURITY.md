# Multi-Tenant Security & Fail-Closed Boundary Architecture

## 1. Core Security Principle: Fail-Closed Isolation

Multi-tenancy in **YBT Management Software** is architected on the **Fail-Closed Principle**:
> If the system cannot unequivocally prove which business tenant is executing an operation, the operation is immediately denied with zero database access.

---

## 2. Threat Model & Mitigations

| Threat | Attack Vector | Mitigation Strategy |
| :--- | :--- | :--- |
| **IDOR (Cross-Tenant Access)** | Attacker changes `?id=10` to `?id=11` belonging to another shop. | Every query resolving an entity enforces `WHERE id = :id AND business_id = :tenant_id`. |
| **Tampered Client Input** | Client injects `{"business_id": 99}` in POST JSON. | `business_id` is completely stripped from user input and resolved strictly from server-side credentials. |
| **Unscoped Subqueries / Joins** | Nested `SELECT SUM(...) FROM invoices WHERE customer_id = c.id` leaks another tenant's invoice data if customer IDs collide or are mislinked. | Subqueries must explicitly join and filter on `business_id = :tenant_id`. |
| **Privilege Escalation** | A Manager from Shop A exploits global user accounts to manage Shop B. | User accounts are strictly scoped to a single `business_id`. Permissions (`Permissions::has(...)`) are evaluated solely within that tenant context. |
| **Malicious Restore / Reset** | A user triggers "Reset Demo Data" or imports a SQL file containing data for another tenant. | SQL restore processes are strictly parsed to assert matching `business_id`. Global drops/truncates are prohibited. |

---

## 3. The `TenantContext` Architectural Pattern

The `TenantContext` class acts as the single source of truth for the active tenant during the request lifecycle.

```mermaid
flowchart TD
    Req[Incoming Request] --> AuthCheck{Is User Authenticated?}
    AuthCheck -- No --> Deny[Deny 401 / Redirect Login]
    AuthCheck -- Yes --> Resolve[Resolve user.business_id from Session/Token]
    Resolve --> StatusCheck{Is Business Active & Verified?}
    StatusCheck -- No --> DenyBiz[Deny 403 Forbidden]
    StatusCheck -- Yes --> SetContext[TenantContext::setTenantId(id)]
    SetContext --> AppExec[Execute Tenant-Scoped Application Code]
    AppExec --> QueryExec[ScopedQuery / Database Query with business_id]
    
    subgraph FailClosed["Fail-Closed Guard"]
        QueryExec --> Assert{TenantContext::id() is Positive Integer?}
        Assert -- No --> ThrowException[Throw TenancyException: Unscoped Query Prohibited]
        Assert -- Yes --> RunSQL[Execute SQL against MySQL]
    end
```

### 3.1 `TenantContext` Contract
```php
final class TenantContext {
    private static ?int $currentTenantId = null;
    private static ?array $currentTenantData = null;

    public static function set(int $tenantId, ?array $tenantData = null): void {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException("Tenant ID must be a positive integer.");
        }
        self::$currentTenantId = $tenantId;
        self::$currentTenantData = $tenantData;
    }

    public static function id(): int {
        if (self::$currentTenantId === null || self::$currentTenantId <= 0) {
            throw new TenancyException("Fail-Closed: Attempted to access tenant resources without active TenantContext.");
        }
        return self::$currentTenantId;
    }

    public static function hasActiveTenant(): bool {
        return self::$currentTenantId !== null && self::$currentTenantId > 0;
    }

    public static function clear(): void {
        self::$currentTenantId = null;
        self::$currentTenantData = null;
    }

    /**
     * Executes a callback within a temporary scoped tenant context (useful for system/job execution)
     */
    public static function runAs(int $tenantId, callable $callback) {
        $previous = self::$currentTenantId;
        try {
            self::set($tenantId);
            return $callback();
        } finally {
            self::$currentTenantId = $previous;
        }
    }
}
```

---

## 4. Query Layer Scoping Guidelines

1. **Direct Queries:**
   ```php
   // CORRECT:
   $invoices = Database::fetchAll(
       "SELECT * FROM invoices WHERE business_id = ? AND status = ?",
       [TenantContext::id(), 'sent']
   );

   // PROHIBITED (Will fail audit):
   $invoices = Database::fetchAll("SELECT * FROM invoices WHERE status = 'sent'");
   ```

2. **Nested Subqueries (Audit Fix):**
   ```php
   // CORRECT (CustomerService subquery):
   $sql = "SELECT c.*, 
           (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND business_id = :bid) as invoice_count,
           (SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE customer_id = c.id AND business_id = :bid AND status != 'void') as total_billed
           FROM customers c 
           WHERE c.business_id = :bid ...";
   ```

3. **Insert Operations:**
   ```php
   // The insert helper automatically injects or validates business_id:
   $data['business_id'] = TenantContext::id();
   Database::insert('customers', $data);
   ```

---

## 5. Separation of Concerns: Tenancy vs Permissions

- **Tenant Isolation** establishes the data boundary (Can User A see Record B? No, unless Record B has `business_id == User A.business_id`).
- **Permissions (RBAC)** establishes functional authorization within the tenant (Can User A create an invoice? Yes, if User A has `invoices.create`).
- A user with Super-Admin permissions in Business 1 has **zero** permissions in Business 2.
