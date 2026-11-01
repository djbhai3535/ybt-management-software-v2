<?php
/**
 * YBT Management Software — Shared Utility Functions
 */

/**
 * Output-escape a string for HTML context
 */
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a URL relative to app root
 */
function url(string $path = ''): string {
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Get asset URL
 */
function asset(string $path): string {
    $fullPath = YBT_ROOT . '/assets/' . ltrim($path, '/');
    $version = file_exists($fullPath) ? filemtime($fullPath) : '1.0.0';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/**
 * Check if current page matches
 */
function isActivePage(string $page): bool {
    $current = basename($_SERVER['SCRIPT_NAME']);
    return $current === $page;
}

/**
 * Generate pagination HTML
 */
function paginate(int $totalItems, int $perPage, int $currentPage, string $baseUrl): string {
    $totalPages = max(1, (int)ceil($totalItems / $perPage));
    if ($totalPages <= 1) return '';

    $html = '<nav class="pagination" aria-label="Pagination">';
    $html .= '<ul class="pagination-list">';

    // Previous
    if ($currentPage > 1) {
        $html .= '<li><a href="' . e($baseUrl . '&page=' . ($currentPage - 1)) . '" class="pagination-link" aria-label="Previous">&laquo;</a></li>';
    }

    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li><a href="' . e($baseUrl . '&page=1') . '" class="pagination-link">1</a></li>';
        if ($start > 2) $html .= '<li><span class="pagination-ellipsis">&hellip;</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li><a href="' . e($baseUrl . '&page=' . $i) . '" class="pagination-link' . $active . '">' . $i . '</a></li>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li><span class="pagination-ellipsis">&hellip;</span></li>';
        $html .= '<li><a href="' . e($baseUrl . '&page=' . $totalPages) . '" class="pagination-link">' . $totalPages . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li><a href="' . e($baseUrl . '&page=' . ($currentPage + 1)) . '" class="pagination-link" aria-label="Next">&raquo;</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Get pagination offset
 */
function paginationOffset(int $page, int $perPage): int {
    return max(0, ($page - 1) * $perPage);
}

/**
 * Get current page from query string
 */
function currentPage(): int {
    return max(1, (int)($_GET['page'] ?? 1));
}

/**
 * Truncate text with ellipsis
 */
function truncate(string $text, int $length = 50): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '…';
}

/**
 * Generate a random string
 */
function randomString(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a standard UUID v4
 */
function generateUuidV4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Time ago helper
 */
function timeAgo(?string $datetime): string {
    if (empty($datetime)) return 'just now';
    $time = strtotime($datetime);
    if (!$time) return 'just now';
    $diff = time() - $time;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    if ($diff < 31536000) return floor($diff / 2592000) . 'mo ago';
    return floor($diff / 31536000) . 'y ago';
}

/**
 * Get status badge HTML
 */
function statusBadge(string $status, string $type = 'default'): string {
    $colorMap = [
        'active' => 'success', 'completed' => 'success', 'paid' => 'success', 'won' => 'success', 'accepted' => 'success',
        'pending' => 'warning', 'draft' => 'warning', 'in_progress' => 'warning', 'partially_paid' => 'warning', 'sent' => 'info',
        'overdue' => 'error', 'cancelled' => 'error', 'void' => 'error', 'lost' => 'error', 'rejected' => 'error', 'expired' => 'error',
        'archived' => 'muted', 'inactive' => 'muted', 'converted' => 'info', 'issued' => 'info', 'new' => 'info',
    ];
    $color = $colorMap[$status] ?? 'default';
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class=\"badge badge-{$color}\">{$label}</span>";
}

/**
 * Build query string preserving existing params
 */
function buildQueryString(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

/**
 * Check if request is POST
 */
function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * JSON response helper
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Redirect helper
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Get client IP address
 */
function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Encrypt sensitive string using AES-256-GCM (Authenticated Encryption)
 * Produces a version-prefixed, tamper-evident ciphertext.
 */
function encryptString(string $plain): string {
    if ($plain === '') return '';
    $config = require YBT_ROOT . '/config/config.php';
    $rawKey = $config['encryption_key'] ?? 'YBT_ENCRYPTION_SECRET';
    $key = hash('sha256', $rawKey, true);

    // 12-byte initialization vector recommended for GCM
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }

    // Prefix with 'gcm:' followed by base64(iv . tag . cipher)
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt sensitive string
 * Seamlessly handles both new AES-256-GCM payloads and legacy AES-256-CBC records.
 */
function decryptString(string $encoded): string {
    if ($encoded === '') return '';

    $config = require YBT_ROOT . '/config/config.php';
    $rawKey = $config['encryption_key'] ?? 'YBT_ENCRYPTION_SECRET';
    $key = hash('sha256', $rawKey, true);

    // 1. Authenticated AES-256-GCM Format
    if (str_starts_with($encoded, 'gcm:')) {
        $raw = base64_decode(substr($encoded, 4), true);
        if ($raw === false || strlen($raw) < 28) { // 12-byte IV + 16-byte tag = 28 bytes min
            return $encoded;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain !== false ? $plain : $encoded;
    }

    // 2. Legacy AES-256-CBC Fallback (Backward Compatibility)
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return $encoded; // fallback if not encrypted
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : $encoded;
}
