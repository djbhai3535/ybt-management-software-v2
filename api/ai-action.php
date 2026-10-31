<?php
/**
 * YBT Management Software — AI Action Proposal API
 * Protected with Session Auth and Strict CSRF Validation.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/approval-service.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF Validation
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf_token'] ?? '');
if (!Csrf::validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token (CSRF).']);
    exit;
}

$proposalId = (int)($input['proposal_id'] ?? 0);
$action = $input['action'] ?? '';

if ($proposalId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid proposal ID.']);
    exit;
}

$businessId = Auth::businessId();
$userId = Auth::id();

if ($action === 'approve') {
    $res = ApprovalService::approve($proposalId, $businessId, $userId);
} elseif ($action === 'reject') {
    $res = ApprovalService::reject($proposalId, $businessId, $userId);
} else {
    http_response_code(422);
    $res = ['success' => false, 'error' => 'Invalid action. Must be approve or reject.'];
}

echo json_encode($res);
