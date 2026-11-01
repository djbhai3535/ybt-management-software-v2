<?php
/**
 * YBT Management Software — Formatting Helpers
 * Currency, date, and number formatting with multi-currency (PKR, INR, USD, etc.)
 * and flexible numbering system (subcontinent vs international) support.
 */

/**
 * Resolve currency symbol for given or active tenant currency code
 */
function getCurrencySymbol(?string $currencyCode = null): string {
    $defaults = require YBT_ROOT . '/config/defaults.php';
    if ($currencyCode === null) {
        $currencyCode = $_SESSION['default_currency'] 
            ?? (class_exists('TenantContext') && TenantContext::has() && isset(TenantContext::data()['default_currency']) ? TenantContext::data()['default_currency'] : null)
            ?? $defaults['regional']['currency'];
    }
    $currencyCode = strtoupper(trim((string)$currencyCode));
    if (isset($defaults['supported_currencies'][$currencyCode]['symbol'])) {
        return $defaults['supported_currencies'][$currencyCode]['symbol'];
    }
    return $defaults['regional']['currency_symbol'] ?? 'Rs.';
}

/**
 * Format currency amount with dynamic regional currency symbol and numbering system
 */
function formatCurrency(float $amount, ?string $symbol = null, ?string $numberSystem = null): string {
    $defaults = require YBT_ROOT . '/config/defaults.php';
    $regional = $defaults['regional'];
    $symbol = $symbol ?? getCurrencySymbol();

    // Spacing between alphabetic currency symbols and digits (e.g. "Rs. 1,500" vs "$1,500")
    $hasAlpha = preg_match('/[a-zA-Z\.]+$/', $symbol);
    $prefix = $symbol . ($hasAlpha && !str_ends_with($symbol, ' ') ? ' ' : '');

    $system = $numberSystem 
        ?? $_SESSION['number_system'] 
        ?? (class_exists('TenantContext') && TenantContext::has() && isset(TenantContext::data()['number_system']) ? TenantContext::data()['number_system'] : null)
        ?? $regional['number_system'] 
        ?? 'subcontinent';

    if ($system === 'subcontinent' || $system === 'indian') {
        return $prefix . formatIndianNumber($amount);
    }

    return $prefix . number_format($amount, 2, $regional['decimal_separator'], $regional['thousand_separator']);
}

/**
 * Format number in Indian/Subcontinent numbering system (1,23,456.78)
 */
function formatIndianNumber(float $number, int $decimals = 2): string {
    $isNegative = $number < 0;
    $number = abs($number);

    $parts = explode('.', number_format($number, $decimals, '.', ''));
    $intPart = $parts[0];
    $decPart = $parts[1] ?? str_repeat('0', $decimals);

    // Subcontinent grouping: last 3 digits, then groups of 2
    $len = strlen($intPart);
    if ($len <= 3) {
        $formatted = $intPart;
    } else {
        $lastThree = substr($intPart, -3);
        $remaining = substr($intPart, 0, $len - 3);
        $formatted = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining) . ',' . $lastThree;
    }

    $result = $formatted . ($decimals > 0 ? '.' . $decPart : '');
    return $isNegative ? '-' . $result : $result;
}

/**
 * Format date
 */
function formatDate(?string $date, ?string $format = null): string {
    if (!$date) return '—';
    $defaults = require YBT_ROOT . '/config/defaults.php';
    $format = $format ?? $defaults['regional']['date_format'];
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

/**
 * Format datetime
 */
function formatDateTime(?string $datetime, ?string $format = null): string {
    if (!$datetime) return '—';
    $defaults = require YBT_ROOT . '/config/defaults.php';
    $format = $format ?? $defaults['regional']['datetime_format'];
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '—';
}

/**
 * Format number
 */
function formatNumber(float $number, int $decimals = 0): string {
    $defaults = require YBT_ROOT . '/config/defaults.php';
    $regional = $defaults['regional'];

    $system = $_SESSION['number_system'] 
        ?? (class_exists('TenantContext') && TenantContext::has() && isset(TenantContext::data()['number_system']) ? TenantContext::data()['number_system'] : null)
        ?? $regional['number_system'] 
        ?? 'subcontinent';

    if ($system === 'subcontinent' || $system === 'indian') {
        return formatIndianNumber($number, $decimals);
    }

    return number_format($number, $decimals, $regional['decimal_separator'], $regional['thousand_separator']);
}

/**
 * Format percentage
 */
function formatPercent(float $value, int $decimals = 1): string {
    return number_format($value, $decimals) . '%';
}

/**
 * Convert amount to words (Subcontinent / International)
 */
function amountInWords(float $amount, string $currencyName = 'Rupees'): string {
    if ($amount == 0) return 'Zero ' . $currencyName;

    $isNegative = $amount < 0;
    $amount = abs($amount);
    $intPart = (int)floor($amount);
    $decPart = (int)round(($amount - $intPart) * 100);

    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $numToWords = function(int $n) use ($ones, $tens, &$numToWords): string {
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[(int)($n / 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        return $ones[(int)($n / 100)] . ' Hundred' . ($n % 100 ? ' and ' . $numToWords($n % 100) : '');
    };

    $result = '';
    if ($intPart >= 10000000) {
        $result .= $numToWords((int)($intPart / 10000000)) . ' Crore ';
        $intPart %= 10000000;
    }
    if ($intPart >= 100000) {
        $result .= $numToWords((int)($intPart / 100000)) . ' Lakh ';
        $intPart %= 100000;
    }
    if ($intPart >= 1000) {
        $result .= $numToWords((int)($intPart / 1000)) . ' Thousand ';
        $intPart %= 1000;
    }
    if ($intPart > 0) {
        $result .= $numToWords($intPart);
    }

    $result = trim($result);
    if ($decPart > 0) {
        $result .= ' and ' . $numToWords($decPart) . ' Cents/Paise';
    } else {
        $result .= ' Only';
    }

    return ($isNegative ? 'Minus ' : '') . $currencyName . ' ' . $result;
}

/**
 * Format number to words alias
 */
function formatNumberToWords(float $amount, string $currencyName = 'Rupees'): string {
    return amountInWords($amount, $currencyName);
}

/**
 * Format file size
 */
function formatFileSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
