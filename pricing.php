<?php
/**
 * DJ CRM Software 5.2.2 — Public Pricing & SaaS Subscription Plans
 * Displays dynamic, real-time pricing plans directly from subscription_plans database table.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/subscription-service.php';

$pageTitle = 'Pricing & Plans — DJ CRM Software';
$plans = SubscriptionService::getPlans();
$isLoggedIn = Auth::check();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <!-- Tabler Icons -->
    <link rel="stylesheet" href="<?= asset('css/tabler-icons.min.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <!-- Core Stylesheets -->
    <link rel="stylesheet" href="<?= asset('css/global.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/themes.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/responsive.css') ?>">
    <style>
        body {
            background: #0b1120;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .pricing-nav {
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(148, 163, 184, 0.15);
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(8px);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .pricing-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: #f8fafc;
        }
        .pricing-brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
        }
        .pricing-hero {
            text-align: center;
            padding: 3.5rem 1rem 2rem 1rem;
            max-width: 800px;
            margin: 0 auto;
        }
        .pricing-hero h1 {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 0.75rem;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .pricing-hero p {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.6;
        }
        .billing-toggle-wrapper {
            display: inline-flex;
            align-items: center;
            background: rgba(30, 41, 59, 0.8);
            padding: 0.35rem;
            border-radius: 30px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            margin: 1.5rem auto 2.5rem auto;
            gap: 0.25rem;
        }
        .billing-toggle-btn {
            padding: 0.45rem 1.25rem;
            border-radius: 20px;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .billing-toggle-btn.active {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);
        }
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            max-width: 1280px;
            margin: 0 auto 4rem auto;
            padding: 0 1.5rem;
            width: 100%;
        }
        .pricing-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 14px;
            padding: 2rem 1.75rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .pricing-card:hover {
            transform: translateY(-4px);
            border-color: rgba(59, 130, 246, 0.4);
            box-shadow: 0 12px 30px -10px rgba(0, 0, 0, 0.5);
        }
        .pricing-card.featured {
            background: linear-gradient(180deg, rgba(30, 58, 138, 0.25), rgba(30, 41, 59, 0.7));
            border-color: #3b82f6;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.2);
        }
        .pricing-badge {
            position: absolute;
            top: -12px;
            right: 20px;
            background: #3b82f6;
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.65rem;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pricing-price {
            font-size: 2.2rem;
            font-weight: 800;
            color: #f8fafc;
            margin: 1rem 0 0.25rem 0;
            line-height: 1;
        }
        .pricing-period {
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
        }
        .pricing-feature-list {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
            font-size: 0.88rem;
            color: #cbd5e1;
        }
        .pricing-feature-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.65rem;
        }
        .pricing-feature-item i {
            color: #10b981;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .pricing-btn {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: center;
            text-decoration: none;
            display: block;
            transition: all 0.2s ease;
        }
        .btn-choose-plan {
            background: #3b82f6;
            color: #ffffff;
            border: none;
        }
        .btn-choose-plan:hover {
            background: #2563eb;
            color: #ffffff;
        }
        .btn-choose-outline {
            background: transparent;
            color: #f8fafc;
            border: 1px solid rgba(148, 163, 184, 0.3);
        }
        .btn-choose-outline:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #f8fafc;
        }
        .pricing-footer {
            margin-top: auto;
            border-top: 1px solid rgba(148, 163, 184, 0.15);
            padding: 2rem;
            text-align: center;
            color: #64748b;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <nav class="pricing-nav">
        <a href="<?= url('login.php') ?>" class="pricing-brand">
            <div class="pricing-brand-icon">
                <i class="ti ti-layout-dashboard"></i>
            </div>
            <div>
                <div style="font-weight: 800; font-size: 1.05rem; line-height: 1.1;">DJ CRM SOFTWARE</div>
                <div style="font-size: 0.7rem; color: #38bdf8; font-weight: 600;">SaaS Cloud & Offline Desktop</div>
            </div>
        </a>

        <div class="d-flex align-center gap-3">
            <?php if ($isLoggedIn): ?>
                <a href="<?= url('dashboard.php') ?>" class="btn btn-secondary btn-sm">
                    <i class="ti ti-arrow-left mr-1"></i> Dashboard
                </a>
            <?php else: ?>
                <a href="<?= url('login.php') ?>" class="btn btn-ghost btn-sm" style="color: #94a3b8;">Sign In</a>
                <a href="<?= url('register.php') ?>" class="btn btn-primary btn-sm">Start Free Trial</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="pricing-hero">
        <h1>Transparent, Fair Pricing for Every Shop</h1>
        <p>Run your mobile repair shop, spare parts inventory, and customer khata ledger offline on Windows desktop with real-time cloud sync.</p>

        <div class="billing-toggle-wrapper">
            <button type="button" class="billing-toggle-btn active" id="btn-monthly" onclick="setBillingCycle('monthly')">
                Monthly Billing
            </button>
            <button type="button" class="billing-toggle-btn" id="btn-annual" onclick="setBillingCycle('annual')">
                Annual License <span class="badge" style="background:#10b981; color:#fff; font-size:0.65rem; margin-left:4px;">Save ~15-20%</span>
            </button>
        </div>
    </div>

    <div class="pricing-grid">
        <?php foreach ($plans as $pl): 
            $isFeatured = !empty($pl['badge']) && in_array(strtolower($pl['badge']), ['popular', 'recommended', 'best value', 'growth'], true);
            $feats = !empty($pl['features_json']) ? json_decode($pl['features_json'], true) : [];
            $monthly = (float)$pl['price_monthly'];
            $annual = (float)$pl['price_annual'];
            $hasAds = !empty($pl['has_ads']);
            $actionUrl = $isLoggedIn 
                ? url('settings.php#subscription') 
                : url('register.php?plan=' . urlencode($pl['slug']));
            $btnText = $isLoggedIn ? 'Upgrade / Switch to Plan' : ($monthly == 0 ? 'Start Free Trial' : 'Choose ' . $pl['name']);
        ?>
        <div class="pricing-card <?= $isFeatured ? 'featured' : '' ?>">
            <?php if (!empty($pl['badge'])): ?>
                <div class="pricing-badge"><?= e($pl['badge']) ?></div>
            <?php endif; ?>

            <div>
                <h3 style="font-size: 1.35rem; font-weight: 800; margin-bottom: 0.4rem; color: #f8fafc;"><?= e($pl['name']) ?></h3>
                <p style="color: #94a3b8; font-size: 0.85rem; min-height: 42px; line-height: 1.5;"><?= e($pl['description']) ?></p>

                <div class="pricing-price price-monthly">
                    <?php if ($monthly == 0): ?>
                        Free
                    <?php else: ?>
                        Rs. <?= number_format($monthly, 0) ?>
                    <?php endif; ?>
                    <span class="pricing-period">/ month</span>
                </div>

                <div class="pricing-price price-annual" style="display: none;">
                    <?php if ($annual == 0): ?>
                        Free
                    <?php else: ?>
                        Rs. <?= number_format($annual, 0) ?>
                    <?php endif; ?>
                    <span class="pricing-period">/ year</span>
                </div>

                <ul class="pricing-feature-list">
                    <li class="pricing-feature-item">
                        <i class="ti ti-users"></i>
                        <span><strong><?= $pl['max_users'] ?></strong> Team User Seats (Owner + Staff)</span>
                    </li>
                    <li class="pricing-feature-item">
                        <i class="ti ti-user-check"></i>
                        <span>Up to <strong><?= number_format($pl['max_customers']) ?></strong> Customer Khatas</span>
                    </li>
                    <li class="pricing-feature-item">
                        <i class="ti ti-receipt"></i>
                        <span><strong><?= number_format($pl['max_invoices_per_month']) ?></strong> Invoices per month</span>
                    </li>
                    <li class="pricing-feature-item">
                        <i class="ti ti-device-desktop"></i>
                        <span><?= $pl['offline_sync_enabled'] ? 'Tauri Windows Offline Database' : 'Cloud Browser Only' ?></span>
                    </li>
                    <li class="pricing-feature-item">
                        <i class="ti <?= $pl['ai_enabled'] ? 'ti-sparkles text-primary' : 'ti-x text-muted' ?>"></i>
                        <span><?= $pl['ai_enabled'] ? ('Central AI Manager (' . $pl['ai_monthly_limit'] . ' queries/mo)') : 'AI Assistant Not Included' ?></span>
                    </li>
                    <li class="pricing-feature-item">
                        <i class="ti <?= $hasAds ? 'ti-ad text-warning' : 'ti-shield-check text-success' ?>"></i>
                        <span><?= $hasAds ? 'Sponsored Ad-Supported' : '100% Ad-Free Experience' ?></span>
                    </li>

                    <?php if (!empty($feats)): ?>
                        <?php foreach (array_slice($feats, 0, 4) as $f): ?>
                            <li class="pricing-feature-item">
                                <i class="ti ti-check"></i>
                                <span><?= e(ucwords(str_replace('_', ' ', $f))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div>
                <a href="<?= e($actionUrl) ?>" class="pricing-btn <?= $isFeatured ? 'btn-choose-plan' : 'btn-choose-outline' ?>">
                    <?= e($btnText) ?> <i class="ti ti-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Global Floating WhatsApp Support Button -->
    <a href="https://wa.me/<?= e(WHATSAPP_SUPPORT_NUMBER) ?>?text=<?= urlencode('Hello DJ CRM Support, I am inquiring about pricing plans on apkashow.com.') ?>" 
       target="_blank" 
       rel="noopener noreferrer"
       class="floating-whatsapp-btn" 
       title="Chat with DJ CRM Support on WhatsApp"
       aria-label="Chat on WhatsApp"
       style="position: fixed; bottom: 20px; right: 20px; z-index: 998; width: 50px; height: 50px; border-radius: 50%; background: #25D366; color: #FFFFFF; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(37, 211, 102, 0.4); text-decoration: none; font-size: 1.7rem; transition: transform 0.2s ease;">
        <i class="ti ti-brand-whatsapp"></i>
    </a>

    <footer class="pricing-footer">
        <div>&copy; <?= date('Y') ?> DJ CRM Software. All rights reserved. Built for professional retail, mobile parts, and customer khata management.</div>
    </footer>

    <script>
    function setBillingCycle(cycle) {
        const btnM = document.getElementById('btn-monthly');
        const btnA = document.getElementById('btn-annual');
        const monthlyPrices = document.querySelectorAll('.price-monthly');
        const annualPrices = document.querySelectorAll('.price-annual');

        if (cycle === 'annual') {
            btnA.classList.add('active');
            btnM.classList.remove('active');
            monthlyPrices.forEach(el => el.style.display = 'none');
            annualPrices.forEach(el => el.style.display = 'block');
        } else {
            btnM.classList.add('active');
            btnA.classList.remove('active');
            monthlyPrices.forEach(el => el.style.display = 'block');
            annualPrices.forEach(el => el.style.display = 'none');
        }
    }
    </script>
</body>
</html>
