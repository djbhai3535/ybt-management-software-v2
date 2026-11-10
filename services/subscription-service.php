<?php
declare(strict_types=1);

/**
 * DJ CRM Software — SaaS Subscription & Plan Enforcement Service
 * Manages SaaS tiers (Basic, Standard, Premium, Enterprise), plan limits,
 * usage quotas, payment approvals, and server-side feature authorization.
 */

if (!defined('YBT_ROOT')) exit;

class SubscriptionService {
    private static function getDefaultPlansList(): array {
        return [
            [
                'id' => 1,
                'slug' => 'free',
                'name' => 'Free Starter',
                'badge' => 'Pilot',
                'description' => 'Standard offline desktop CRM with ledger and billing',
                'price_monthly' => 0.00,
                'price_annual' => 0.00,
                'currency' => 'PKR',
                'max_users' => 2,
                'max_customers' => 500,
                'max_invoices_per_month' => 100,
                'ai_enabled' => 0,
                'ai_monthly_limit' => 0,
                'offline_sync_enabled' => 1,
                'features_json' => '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync"]',
                'is_active' => 1,
                'sort_order' => 1
            ],
            [
                'id' => 2,
                'slug' => 'standard',
                'name' => 'Standard Business',
                'badge' => 'Popular',
                'description' => 'Ideal for single-shop retailers and billing terminals',
                'price_monthly' => 3000.00,
                'price_annual' => 30000.00,
                'currency' => 'PKR',
                'max_users' => 3,
                'max_customers' => 2000,
                'max_invoices_per_month' => 1000,
                'ai_enabled' => 0,
                'ai_monthly_limit' => 0,
                'offline_sync_enabled' => 1,
                'features_json' => '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync","whatsapp_billing"]',
                'is_active' => 1,
                'sort_order' => 2
            ],
            [
                'id' => 3,
                'slug' => 'professional',
                'name' => 'Professional Growth',
                'badge' => 'Growth',
                'description' => 'Unlimited invoicing, stock movements, and multi-terminal sync',
                'price_monthly' => 6000.00,
                'price_annual' => 60000.00,
                'currency' => 'PKR',
                'max_users' => 10,
                'max_customers' => 10000,
                'max_invoices_per_month' => 5000,
                'ai_enabled' => 1,
                'ai_monthly_limit' => 100,
                'offline_sync_enabled' => 1,
                'features_json' => '["all_starter_features","unlimited_invoices","stock_movements","thermal_print","priority_support"]',
                'is_active' => 1,
                'sort_order' => 3
            ],
            [
                'id' => 4,
                'slug' => 'enterprise',
                'name' => 'Enterprise Fleet',
                'badge' => 'VIP',
                'description' => 'Multi-branch sync, dedicated cloud instance and full support',
                'price_monthly' => 15000.00,
                'price_annual' => 150000.00,
                'currency' => 'PKR',
                'max_users' => 50,
                'max_customers' => 50000,
                'max_invoices_per_month' => 25000,
                'ai_enabled' => 1,
                'ai_monthly_limit' => 500,
                'offline_sync_enabled' => 1,
                'features_json' => '["all_pro_features","multi_branch","unlimited_terminals","dedicated_support"]',
                'is_active' => 1,
                'sort_order' => 4
            ]
        ];
    }

    /**
     * Get all active subscription plans
     */
    public static function getPlans(): array {
        try {
            $plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
            if (!empty($plans)) {
                return $plans;
            }
        } catch (\Throwable $e) {}
        return self::getDefaultPlansList();
    }

    /**
     * Get all plans including inactive for Super Admin
     */
    public static function getAllPlansForAdmin(): array {
        try {
            $plans = Database::fetchAll("SELECT * FROM subscription_plans ORDER BY sort_order ASC, id ASC");
            if (!empty($plans)) {
                return $plans;
            }
        } catch (\Throwable $e) {}
        return self::getDefaultPlansList();
    }

    /**
     * Get plan by slug
     */
    public static function getPlanBySlug(string $slug): ?array {
        try {
            $plan = Database::fetch("SELECT * FROM subscription_plans WHERE slug = ? LIMIT 1", [$slug]);
            if ($plan) {
                return $plan;
            }
        } catch (\Throwable $e) {}

        foreach (self::getDefaultPlansList() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Get plan by ID
     */
    public static function getPlanById(int $planId): ?array {
        try {
            $plan = Database::fetch("SELECT * FROM subscription_plans WHERE id = ? LIMIT 1", [$planId]);
            if ($plan) {
                return $plan;
            }
        } catch (\Throwable $e) {}

        foreach (self::getDefaultPlansList() as $p) {
            if ((int)$p['id'] === $planId) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Get current subscription and quota status for a business
     */
    public static function getBusinessPlan(int $businessId): array {
        try {
            $biz = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$businessId]);
        } catch (\Throwable $e) {
            $biz = null;
        }

        if (!$biz) {
            $biz = [
                'id' => $businessId,
                'name' => 'My Business',
                'current_plan_slug' => 'free',
                'subscription_status' => 'active',
                'subscription_expires_at' => null
            ];
        }

        $planSlug = $biz['current_plan_slug'] ?? 'free';
        $plan = self::getPlanBySlug($planSlug);
        if (!$plan) {
            $plan = self::getPlanBySlug('starter') ?? self::getPlanBySlug('free') ?? [
                'id' => 1,
                'slug' => 'free',
                'name' => 'Free Starter',
                'price_monthly' => 0.00,
                'price_annual' => 0.00,
                'currency' => 'PKR',
                'max_users' => 2,
                'max_customers' => 500,
                'max_invoices_per_month' => 100,
                'ai_enabled' => 0,
                'ai_monthly_limit' => 0,
                'offline_sync_enabled' => 1,
                'features_json' => '[]'
            ];
        }

        // Active user count
        $userCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE business_id = ? AND is_active = 1", [$businessId]);

        // Customer count
        $custCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM customers WHERE business_id = ?", [$businessId]);

        // Current month invoices count
        $startOfMonth = date('Y-m-01 00:00:00');
        $invCount = (int)Database::fetchColumn("SELECT COUNT(*) FROM invoices WHERE business_id = ? AND created_at >= ?", [$businessId, $startOfMonth]);

        // Expiry calculation
        $expiresAt = $biz['subscription_expires_at'] ?? null;
        $isExpired = false;
        $daysRemaining = 999;
        if (!empty($expiresAt)) {
            $ts = strtotime($expiresAt);
            if ($ts !== false) {
                $now = time();
                if ($ts < $now) {
                    $isExpired = true;
                    $daysRemaining = 0;
                } else {
                    $daysRemaining = (int)ceil(($ts - $now) / 86400);
                }
            }
        }

        $features = [];
        if (!empty($plan['features_json'])) {
            $decoded = json_decode($plan['features_json'], true);
            if (is_array($decoded)) $features = $decoded;
        }

        return [
            'business_id' => $businessId,
            'plan' => $plan,
            'plan_slug' => $plan['slug'],
            'plan_name' => $plan['name'],
            'status' => $biz['subscription_status'] ?? 'active',
            'is_expired' => $isExpired,
            'expires_at' => $expiresAt,
            'days_remaining' => $daysRemaining,
            'usage' => [
                'users' => $userCount,
                'max_users' => (int)$plan['max_users'],
                'customers' => $custCount,
                'max_customers' => (int)$plan['max_customers'],
                'invoices_this_month' => $invCount,
                'max_invoices' => (int)$plan['max_invoices_per_month']
            ],
            'features' => $features,
            'ai_enabled' => !empty($plan['ai_enabled']),
            'ai_limit' => (int)($plan['ai_monthly_limit'] ?? 0)
        ];
    }

    /**
     * Check if a business can add another user account
     */
    public static function canAddUser(int $businessId): bool {
        $info = self::getBusinessPlan($businessId);
        if ($info['is_expired']) return false;
        return $info['usage']['users'] < $info['usage']['max_users'];
    }

    /**
     * Check if a business can add another customer
     */
    public static function canAddCustomer(int $businessId): bool {
        $info = self::getBusinessPlan($businessId);
        if ($info['is_expired']) return false;
        return $info['usage']['customers'] < $info['usage']['max_customers'];
    }

    /**
     * Check if a business can create another invoice this month
     */
    public static function canCreateInvoice(int $businessId): bool {
        $info = self::getBusinessPlan($businessId);
        if ($info['is_expired']) return false;
        return $info['usage']['invoices_this_month'] < $info['usage']['max_invoices'];
    }

    /**
     * Check if AI features are authorized for this business
     */
    public static function isAiAuthorized(int $businessId): bool {
        $info = self::getBusinessPlan($businessId);
        if ($info['is_expired']) return false;
        return !empty($info['ai_enabled']);
    }

    /**
     * Shopkeeper submits proof of subscription payment for upgrade or renewal
     */
    public static function submitPaymentProof(int $businessId, array $data): int {
        $planId = (int)($data['plan_id'] ?? 2);
        $billingCycle = in_array($data['billing_cycle'] ?? '', ['monthly', 'annual'], true) ? $data['billing_cycle'] : 'monthly';
        $amountPaid = (float)($data['amount_paid'] ?? 0.0);
        $method = trim($data['payment_method'] ?? 'Bank Transfer');
        $reference = trim($data['payment_reference'] ?? '');
        $proof = trim($data['proof_attachment'] ?? '');
        $notes = trim($data['notes'] ?? '');

        return Database::insert('business_subscriptions', [
            'business_id' => $businessId,
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
            'status' => 'pending_approval',
            'amount_paid' => $amountPaid,
            'payment_method' => $method,
            'payment_reference' => $reference,
            'proof_attachment' => $proof,
            'notes' => $notes,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Super Admin approves pending subscription payment
     */
    public static function approveSubscription(int $subscriptionId, int $superAdminUserId): bool {
        $sub = Database::fetch("SELECT * FROM business_subscriptions WHERE id = ?", [$subscriptionId]);
        if (!$sub) return false;

        $plan = self::getPlanById((int)$sub['plan_id']);
        if (!$plan) return false;

        $now = date('Y-m-d H:i:s');
        $cycle = $sub['billing_cycle'] ?? 'monthly';
        $extensionDays = ($cycle === 'annual') ? 365 : 30;

        // Calculate new expiry date
        $biz = Database::fetch("SELECT * FROM businesses WHERE id = ?", [$sub['business_id']]);
        $currentExpiry = $biz['subscription_expires_at'] ?? null;
        $baseTs = (!empty($currentExpiry) && strtotime($currentExpiry) > time()) ? strtotime($currentExpiry) : time();
        $newExpiry = date('Y-m-d H:i:s', $baseTs + ($extensionDays * 86400));

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // Update subscription record
            Database::query(
                "UPDATE business_subscriptions SET status = 'active', starts_at = ?, expires_at = ?, approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?",
                [$now, $newExpiry, $superAdminUserId, $now, $now, $subscriptionId]
            );

            // Update business
            Database::update('businesses', [
                'current_plan_slug' => $plan['slug'],
                'subscription_status' => 'active',
                'subscription_expires_at' => $newExpiry
            ], 'id = ?', [$sub['business_id']]);

            $db->commit();

            ActivityLog::log(
                (int)$sub['business_id'],
                $superAdminUserId,
                'subscription_approved',
                'subscription',
                $subscriptionId,
                "Super Admin approved subscription: Plan '{$plan['name']}' ({$cycle}) valid until {$newExpiry}"
            );

            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Failed to approve subscription: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Super Admin directly assigns a plan to a business
     */
    public static function assignPlanToBusiness(int $businessId, string $planSlug, int $durationMonths, int $superAdminUserId): bool {
        $plan = self::getPlanBySlug($planSlug);
        if (!$plan) return false;

        $now = date('Y-m-d H:i:s');
        $newExpiry = date('Y-m-d H:i:s', time() + ($durationMonths * 30 * 86400));

        Database::update('businesses', [
            'current_plan_slug' => $plan['slug'],
            'subscription_status' => 'active',
            'subscription_expires_at' => $newExpiry
        ], 'id = ?', [$businessId]);

        Database::insert('business_subscriptions', [
            'business_id' => $businessId,
            'plan_id' => $plan['id'],
            'billing_cycle' => ($durationMonths >= 12) ? 'annual' : 'monthly',
            'status' => 'active',
            'amount_paid' => (float)$plan['price_monthly'] * $durationMonths,
            'starts_at' => $now,
            'expires_at' => $newExpiry,
            'approved_by' => $superAdminUserId,
            'approved_at' => $now,
            'notes' => 'Assigned directly by Super Admin',
            'created_at' => $now,
            'updated_at' => $now
        ]);

        ActivityLog::log(
            $businessId,
            $superAdminUserId,
            'plan_assigned',
            'business',
            $businessId,
            "Super Admin assigned plan '{$plan['name']}' valid until {$newExpiry}"
        );

        return true;
    }

    /**
     * Super Admin updates plan parameters
     */
    public static function updatePlan(int $planId, array $data): bool {
        $name = trim($data['name'] ?? '');
        if (empty($name)) return false;

        $features = isset($data['features']) && is_array($data['features']) ? json_encode(array_values(array_filter($data['features']))) : null;

        $update = [
            'name' => $name,
            'badge' => trim($data['badge'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'price_monthly' => max(0.0, (float)($data['price_monthly'] ?? 0)),
            'price_annual' => max(0.0, (float)($data['price_annual'] ?? 0)),
            'max_users' => max(1, (int)($data['max_users'] ?? 1)),
            'max_customers' => max(50, (int)($data['max_customers'] ?? 500)),
            'max_invoices_per_month' => max(10, (int)($data['max_invoices_per_month'] ?? 100)),
            'ai_enabled' => !empty($data['ai_enabled']) ? 1 : 0,
            'ai_monthly_limit' => max(0, (int)($data['ai_monthly_limit'] ?? 0)),
            'offline_sync_enabled' => !empty($data['offline_sync_enabled']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($features !== null) {
            $update['features_json'] = $features;
        }

        if (isset($data['has_ads'])) {
            $update['has_ads'] = !empty($data['has_ads']) ? 1 : 0;
        }
        if (isset($data['trial_days'])) {
            $update['trial_days'] = max(0, (int)$data['trial_days']);
        }

        return Database::update('subscription_plans', $update, 'id = ?', [$planId]);
    }

    /**
     * Create a new subscription plan (Super Admin only)
     */
    public static function createPlan(array $data): int {
        Database::ensureSchemaCompatibility();
        $name = trim($data['name'] ?? '');
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '-', $data['slug'] ?? $name))));
        if (empty($slug)) $slug = 'plan-' . time();

        $features = null;
        if (isset($data['features']) && is_array($data['features'])) {
            $features = json_encode(array_values(array_filter(array_map('trim', $data['features']))), JSON_UNESCAPED_SLASHES);
        }

        return Database::insert('subscription_plans', [
            'slug' => $slug,
            'name' => $name,
            'badge' => trim($data['badge'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'price_monthly' => max(0.0, (float)($data['price_monthly'] ?? 0)),
            'price_annual' => max(0.0, (float)($data['price_annual'] ?? 0)),
            'currency' => trim($data['currency'] ?? 'PKR') ?: 'PKR',
            'max_users' => max(1, (int)($data['max_users'] ?? 1)),
            'max_customers' => max(50, (int)($data['max_customers'] ?? 500)),
            'max_invoices_per_month' => max(10, (int)($data['max_invoices_per_month'] ?? 100)),
            'ai_enabled' => !empty($data['ai_enabled']) ? 1 : 0,
            'ai_monthly_limit' => max(0, (int)($data['ai_monthly_limit'] ?? 0)),
            'offline_sync_enabled' => !empty($data['offline_sync_enabled']) ? 1 : 0,
            'has_ads' => !empty($data['has_ads']) ? 1 : 0,
            'trial_days' => max(0, (int)($data['trial_days'] ?? 0)),
            'features_json' => $features ?: '[]',
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int)($data['sort_order'] ?? 0)
        ]);
    }

    /**
     * Get active shops subscribed to a plan
     */
    public static function getPlanSubscribers(int $planId): array {
        $plan = self::getPlanById($planId);
        if (!$plan) return [];
        
        $bizCols = Database::getTableColumns('businesses');
        $sel = ['id', 'name'];
        foreach (['legal_name', 'phone', 'email', 'city', 'subscription_status', 'subscription_expires_at', 'created_at'] as $col) {
            if (isset($bizCols[$col])) {
                $sel[] = "`$col`";
            }
        }
        $colsStr = implode(', ', $sel);
        return Database::fetchAll(
            "SELECT {$colsStr} 
             FROM businesses 
             WHERE current_plan_slug = ? 
             ORDER BY id DESC",
            [$plan['slug']]
        );
    }

    /**
     * Get pending approvals for Super Admin
     */
    public static function getPendingApprovals(): array {
        return Database::fetchAll(
            "SELECT s.*, b.name as business_name, b.phone as business_phone, p.name as plan_name, p.slug as plan_slug 
             FROM business_subscriptions s
             JOIN businesses b ON s.business_id = b.id
             JOIN subscription_plans p ON s.plan_id = p.id
             WHERE s.status = 'pending_approval'
             ORDER BY s.id DESC"
        );
    }
}
