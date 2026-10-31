<?php
/**
 * YBT Management Software — REST API v1 Master Router & Front Controller
 * Enforces Fail-Closed Tenant Isolation, Bearer Token Auth, and Standard Envelopes.
 */

// Prevent session-blocking on API endpoints
define('YBT_API_REQUEST', true);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Global CORS & JSON Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-Id, X-Idempotency-Key, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Standard API Success Response
 */
function apiResponse(mixed $data = [], int $status = 200, array $meta = []): void {
    http_response_code($status);
    $response = [
        'success' => true,
        'data' => $data,
        'meta' => array_merge([
            'timestamp' => date('c'),
            'api_version' => '1.0.0',
            'tenant_id' => TenantContext::has() ? TenantContext::id() : null,
        ], $meta)
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Standard API Error Response
 */
function apiError(string $message, string $code = 'BAD_REQUEST', int $status = 400, array $details = []): void {
    http_response_code($status);
    $response = [
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
        'meta' => [
            'timestamp' => date('c'),
            'api_version' => '1.0.0',
        ]
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Resolve Route
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH) ?? '';
$prefix = '/api/v1';

// Support both path-based and query-based routing (e.g. ?route=/customers)
$route = $_GET['route'] ?? '';
if ($route === '' && str_contains($path, $prefix)) {
    $route = substr($path, strpos($path, $prefix) + strlen($prefix));
}
$route = '/' . trim($route, '/');

// Public Whitelisted Routes
$publicRoutes = [
    '/',
    '/health',
    '/auth/login',
    '/auth/refresh',
];

try {
    // 1. Health check routing
    if ($route === '/' || $route === '/health') {
        require __DIR__ . '/health.php';
        exit;
    }

    // 2. Authentication endpoints routing
    if (str_starts_with($route, '/auth/')) {
        require __DIR__ . '/auth.php';
        exit;
    }

    // 3. Bearer Token Verification for Protected Routes
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    $authenticatedUser = null;
    $authenticatedBusiness = null;

    if ($token !== '') {
        $tokenHash = hash('sha256', $token);
        $tokenRecord = Database::fetch(
            "SELECT t.*, u.name as user_name, u.email as user_email, u.is_owner, u.role_id, b.name as business_name, b.is_demo 
             FROM api_tokens t
             JOIN users u ON t.user_id = u.id
             JOIN businesses b ON t.business_id = b.id
             WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.is_active = 1
             LIMIT 1",
            [$tokenHash]
        );

        if ($tokenRecord) {
            $authenticatedUser = $tokenRecord;
            $authenticatedBusiness = [
                'id' => (int)$tokenRecord['business_id'],
                'name' => $tokenRecord['business_name'],
            ];
            // Establish fail-closed Tenant Context
            TenantContext::set((int)$tokenRecord['business_id'], $authenticatedBusiness);

            // Update last_used_at timestamp
            Database::query("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", [$tokenRecord['id']]);
        }
    } elseif (Auth::check()) {
        // Fallback for browser sessions accessing the API
        $authenticatedUser = Auth::user();
        TenantContext::set((int)Auth::businessId());
    }

    if (!TenantContext::has() && !in_array($route, $publicRoutes, true)) {
        apiError('Unauthorized: Missing or invalid Bearer token.', 'UNAUTHORIZED', 401);
    }

    // 4. Dispatch Protected Modules
    if (str_starts_with($route, '/customers')) {
        require __DIR__ . '/customers.php';
        exit;
    }

    if (str_starts_with($route, '/products')) {
        require __DIR__ . '/products.php';
        exit;
    }

    if (str_starts_with($route, '/invoices')) {
        require __DIR__ . '/invoices.php';
        exit;
    }

    if (str_starts_with($route, '/sync')) {
        require __DIR__ . '/sync.php';
        exit;
    }

    // Unmatched Route
    apiError("Route not found: {$route}", 'NOT_FOUND', 404);

} catch (TenancyException $te) {
    apiError($te->getMessage(), 'TENANCY_VIOLATION', 403);
} catch (Throwable $t) {
    error_log('API v1 Fatal: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
    $msg = APP_DEBUG ? $t->getMessage() : 'An internal server error occurred.';
    apiError($msg, 'INTERNAL_SERVER_ERROR', 500, APP_DEBUG ? ['file' => $t->getFile(), 'line' => $t->getLine()] : []);
}
