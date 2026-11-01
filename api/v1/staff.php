<?php
/**
 * YBT Management Software — REST API v1 Staff Management & Invitations
 * Multi-tenant safe staff invitation, user onboarding, role management, and status control.
 */

require_once dirname(__DIR__, 2) . '/services/staff-service.php';

$method = $_SERVER['REQUEST_METHOD'];
$subRoute = substr($route, strlen('/staff'));
$subRoute = '/' . trim($subRoute, '/');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$tenantId = TenantContext::id();
$userId = (int)($authenticatedUser['user_id'] ?? $authenticatedUser['id'] ?? 0);

// 1. GET /staff — List staff members and invitations
if (($subRoute === '' || $subRoute === '/') && $method === 'GET') {
    $staff = StaffService::listStaff($tenantId);
    $invitations = StaffService::listInvitations($tenantId);

    apiResponse([
        'staff' => $staff,
        'invitations' => $invitations
    ]);
}

// 2. POST /staff/invite — Send staff invitation
if ($subRoute === '/invite' && $method === 'POST') {
    if (empty($authenticatedUser['is_owner'])) {
        apiError('Only the business owner can invite staff members.', 'FORBIDDEN', 403);
    }

    $email = strtolower(trim($input['email'] ?? ''));
    $name = trim($input['name'] ?? '');
    $roleId = (int)($input['role_id'] ?? 0);

    if ($email === '' || $name === '' || $roleId <= 0) {
        apiError('Name, email, and role_id are required.', 'VALIDATION_FAILED', 422);
    }

    $result = StaffService::invite($tenantId, $userId, $email, $name, $roleId);
    if (!$result['success']) {
        $status = str_contains($result['error'], 'Cannot assign Owner') ? 403 : 400;
        apiError($result['error'], 'INVITATION_FAILED', $status);
    }

    apiResponse([
        'message' => "Invitation dispatched successfully to {$email}.",
        'invitation_id' => $result['invitation_id'],
        'email' => $email,
        'name' => $name,
        'role_slug' => $result['role_slug']
    ], 201);
}

// 3. PUT /staff/status — Activate / Suspend staff member
if ($subRoute === '/status' && ($method === 'PUT' || $method === 'POST')) {
    if (empty($authenticatedUser['is_owner'])) {
        apiError('Only the business owner can modify staff account status.', 'FORBIDDEN', 403);
    }

    $targetUserId = (int)($input['user_id'] ?? 0);
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 0;

    if ($targetUserId <= 0) {
        apiError('Valid user_id is required.', 'VALIDATION_FAILED', 422);
    }

    $result = StaffService::updateStatus($tenantId, $targetUserId, $isActive);
    if (!$result['success']) {
        apiError($result['error'], 'UPDATE_FAILED', 400);
    }

    apiResponse([
        'message' => 'Staff account status updated successfully.',
        'user_id' => $targetUserId,
        'is_active' => (bool)$isActive
    ]);
}

// 4. POST /staff/cancel-invitation — Revoke pending invitation
if ($subRoute === '/cancel-invitation' && $method === 'POST') {
    if (empty($authenticatedUser['is_owner'])) {
        apiError('Only the business owner can cancel invitations.', 'FORBIDDEN', 403);
    }

    $invitationId = (int)($input['invitation_id'] ?? 0);
    if ($invitationId <= 0) {
        apiError('Valid invitation_id is required.', 'VALIDATION_FAILED', 422);
    }

    $result = StaffService::cancelInvitation($tenantId, $invitationId);
    if (!$result['success']) {
        apiError($result['error'], 'CANCEL_FAILED', 400);
    }

    apiResponse([
        'message' => 'Invitation cancelled successfully.',
        'invitation_id' => $invitationId
    ]);
}

apiError('Invalid staff endpoint.', 'NOT_FOUND', 404);
