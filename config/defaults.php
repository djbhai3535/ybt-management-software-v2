<?php
/**
 * YBT Management Software — Safe Defaults
 * Theme, typography, invoice, dashboard, regional, and permission defaults.
 */

return [
    // Theme defaults
    'theme' => [
        'mode'              => 'light', // light | dark | system
        'primary_color'     => '#0EA5E9',
        'secondary_color'   => '#075985',
        'accent_color'      => '#0284C7',
        'bg_color'          => '#F8FAFC',
        'sidebar_bg'        => '#FFFFFF',
        'card_bg'           => '#FFFFFF',
        'text_color'        => '#1E293B',
        'text_muted'        => '#64748B',
        'border_color'      => '#E2E8F0',
        'success_color'     => '#16A34A',
        'warning_color'     => '#EAB308',
        'error_color'       => '#DC2626',
        'info_color'        => '#0EA5E9',
        'dark_surface'      => '#111827',
        'dark_card'         => '#1F2937',
        'dark_border'       => '#374151',
        'dark_text'         => '#F1F5F9',
        'dark_text_muted'   => '#94A3B8',
    ],

    // Typography
    'typography' => [
        'font_family'   => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'base_size'     => '14px',
        'heading_scale' => 1.25,
        'line_height'   => 1.6,
        'preset'        => 'default', // compact | small | default | large | extra-large
    ],

    // Layout
    'layout' => [
        'sidebar_width'         => '260px',
        'sidebar_compact_width' => '72px',
        'content_max_width'     => '1400px',
        'card_spacing'          => '24px',
        'border_radius'         => '8px',
        'density'               => 'default', // compact | default | comfortable
    ],

    // Invoice defaults
    'invoice' => [
        'prefix'           => 'INV-',
        'next_number'      => 1,
        'due_days'         => 30,
        'currency'         => 'PKR',
        'currency_symbol'  => 'Rs.',
        'decimal_places'   => 2,
        'tax_enabled'      => true,
        'default_tax_rate' => 0.00,
        'show_logo'        => true,
        'show_signature'   => false,
        'amount_in_words'  => true,
        'default_notes'    => 'Thank you for your business.',
        'default_terms'    => 'Payment due within the specified period.',
    ],

    // Quotation defaults
    'quotation' => [
        'prefix'        => 'QT-',
        'next_number'   => 1,
        'validity_days' => 15,
    ],

    // Dashboard
    'dashboard' => [
        'default_period'   => 'this_month',
        'visible_widgets'  => [
            'kpi_financial', 'kpi_customer', 'kpi_sales', 'kpi_activity',
            'chart_revenue_trend', 'chart_invoice_status', 'chart_aging',
            'table_recent_invoices', 'table_recent_payments', 'table_overdue',
        ],
        'columns' => 3,
    ],

    // Pagination
    'pagination' => [
        'per_page'    => 25,
        'max_per_page' => 100,
    ],

    // Regional
    'regional' => [
        'country'          => 'Pakistan',
        'timezone'         => 'Asia/Karachi',
        'currency'         => 'PKR',
        'currency_symbol'  => 'Rs.',
        'decimal_separator'  => '.',
        'thousand_separator' => ',',
        'date_format'      => 'd M Y',
        'time_format'      => 'h:i A',
        'datetime_format'  => 'd M Y h:i A',
        'week_start'       => 1, // Monday
        'number_system'    => 'subcontinent', // subcontinent | international
    ],

    // Supported Currencies & Regional Presets
    'supported_currencies' => [
        'PKR' => ['name' => 'Pakistani Rupee', 'symbol' => 'Rs.', 'code' => 'PKR', 'decimals' => 2],
        'INR' => ['name' => 'Indian Rupee',    'symbol' => '₹',   'code' => 'INR', 'decimals' => 2],
        'USD' => ['name' => 'US Dollar',       'symbol' => '$',   'code' => 'USD', 'decimals' => 2],
        'AED' => ['name' => 'UAE Dirham',      'symbol' => 'AED', 'code' => 'AED', 'decimals' => 2],
        'SAR' => ['name' => 'Saudi Riyal',     'symbol' => 'SAR', 'code' => 'SAR', 'decimals' => 2],
        'EUR' => ['name' => 'Euro',            'symbol' => '€',   'code' => 'EUR', 'decimals' => 2],
        'GBP' => ['name' => 'British Pound',   'symbol' => '£',   'code' => 'GBP', 'decimals' => 2],
    ],

    // Supported Countries & Regional Tax/Banking Presets
    'supported_countries' => [
        'Pakistan' => [
            'name' => 'Pakistan',
            'code' => 'PK',
            'phone_prefix' => '+92',
            'currency' => 'PKR',
            'currency_symbol' => 'Rs.',
            'tax_label' => 'NTN / STRN',
            'tax_placeholder' => 'e.g. 1234567-8',
            'banking_label' => 'Bank Account / IBAN',
            'wallet_label' => 'Raast ID / Easypaisa / JazzCash',
            'number_system' => 'subcontinent',
            'provinces' => ['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu & Kashmir'],
        ],
        'India' => [
            'name' => 'India',
            'code' => 'IN',
            'phone_prefix' => '+91',
            'currency' => 'INR',
            'currency_symbol' => '₹',
            'tax_label' => 'GSTIN',
            'tax_placeholder' => 'e.g. 27AAAAA0000A1Z5',
            'banking_label' => 'Bank Account & IFSC',
            'wallet_label' => 'UPI ID',
            'number_system' => 'subcontinent',
            'provinces' => ['Maharashtra', 'Delhi', 'Gujarat', 'Karnataka', 'Tamil Nadu', 'Uttar Pradesh', 'Rajasthan', 'West Bengal', 'Punjab', 'Other'],
        ],
        'United Arab Emirates' => [
            'name' => 'United Arab Emirates',
            'code' => 'AE',
            'phone_prefix' => '+971',
            'currency' => 'AED',
            'currency_symbol' => 'AED ',
            'tax_label' => 'TRN (Tax Registration Number)',
            'tax_placeholder' => 'e.g. 100xxxxxxxxxxxx',
            'banking_label' => 'IBAN',
            'wallet_label' => 'Digital Wallet / Mobile Pay',
            'number_system' => 'international',
            'provinces' => ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Ras Al Khaimah', 'Fujairah', 'Umm Al Quwain'],
        ],
        'Saudi Arabia' => [
            'name' => 'Saudi Arabia',
            'code' => 'SA',
            'phone_prefix' => '+966',
            'currency' => 'SAR',
            'currency_symbol' => 'SAR ',
            'tax_label' => 'VAT Number',
            'tax_placeholder' => 'e.g. 300xxxxxxxxxxxx',
            'banking_label' => 'IBAN',
            'wallet_label' => 'STC Pay / Urpay / Mada',
            'number_system' => 'international',
            'provinces' => ['Riyadh', 'Makkah', 'Eastern Province', 'Madinah', 'Asir', 'Other'],
        ],
        'United States' => [
            'name' => 'United States',
            'code' => 'US',
            'phone_prefix' => '+1',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'tax_label' => 'EIN / Tax ID',
            'tax_placeholder' => 'e.g. 12-3456789',
            'banking_label' => 'Routing & Account Number',
            'wallet_label' => 'Zelle / CashApp / PayPal',
            'number_system' => 'international',
            'provinces' => [],
        ],
        'United Kingdom' => [
            'name' => 'United Kingdom',
            'code' => 'GB',
            'phone_prefix' => '+44',
            'currency' => 'GBP',
            'currency_symbol' => '£',
            'tax_label' => 'VAT Registration Number',
            'tax_placeholder' => 'e.g. GB123456789',
            'banking_label' => 'Sort Code & Account Number',
            'wallet_label' => 'Digital Wallet',
            'number_system' => 'international',
            'provinces' => [],
        ],
        'Other' => [
            'name' => 'Other / International',
            'code' => 'XX',
            'phone_prefix' => '+',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'tax_label' => 'Tax / VAT Number',
            'tax_placeholder' => 'Tax ID',
            'banking_label' => 'Bank Account / IBAN',
            'wallet_label' => 'Payment Handle',
            'number_system' => 'international',
            'provinces' => [],
        ],
    ],

    // Default permissions for Manager role
    'default_manager_permissions' => [
        'dashboard.view'       => true,
        'customers.view'       => true,
        'customers.create'     => true,
        'customers.edit'       => true,
        'leads.view'           => true,
        'leads.create'         => true,
        'leads.edit'           => true,
        'products.view'        => true,
        'tasks.view'           => true,
        'tasks.create'         => true,
        'quotations.view'      => true,
        'invoices.view'        => true,
        'payments.view'        => true,
        'expenses.view'        => true,
        'reports.view'         => false,
        'ai.access'            => false,
        'settings.access'      => false,
        'managers.manage'      => false,
        'backup.create'        => false,
    ],
];
