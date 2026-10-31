<?php
/**
 * YBT Management Software — Staff Service
 * Multi-tenant safe staff invitation, user onboarding, role management, and status control.
 */

require_once __DIR__ . '/mailer-service.php';

class StaffService {
    /**
     * Invite a new staff member to join the business
     */
    public static function invite(int $businessId, int $invitedBy, string $email, string $name, int $roleId): array {
        $email = strtolower(trim($email));
        $name = trim($name);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }
        if (empty($name)) {
            return ['success' => false, 'error' => 'Staff member name is required.'];
        }

        // Validate role belongs to this business or is a valid system role
        $role = Database::fetch("SELECT id, name FROM roles WHERE id = ? AND (business_id = ? OR is_system = 1)", [$roleId, $businessId]);
        if (!$role) {
            return ['success' => false, 'error' => 'Selected role does not exist.'];
        }

        // Check if an active account already exists with this email
        $existingUser = Database::fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($existingUser) {
            return ['success' => false, 'error' => 'An active user account already exists with this email address.'];
        }

        // Cancel previous pending invitations for this email in this business
        Database::query(
            "UPDATE staff_invitations SET status = 'cancelled' WHERE business_id = ? AND email = ? AND status = 'pending'",
            [$businessId, $email]
        );

        $business = Database::fetch("SELECT name FROM businesses WHERE id = ?", [$businessId]);
        $shopName = $business['name'] ?? APP_NAME;

        // Generate cryptographically secure token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (48 * 3600)); // 48 hours

        $invitationId = Database::insert('staff_invitations', [
            'business_id' => $businessId,
            'role_id' => $roleId,
            'email' => $email,
            'name' => $name,
            'token_hash' => $tokenHash,
            'invited_by' => $invitedBy,
            'status' => 'pending',
            'expires_at' => $expiresAt
        ]);

        MailerService::sendStaffInvitationEmail($email, $name, $shopName, $rawToken);

        ActivityLog::log($businessId, $invitedBy, 'staff_invited', 'staff_invitation', $invitationId, "Invited staff member {$name} ({$email}) as {$role['name']}");

        return [
            'success' => true,
            'invitation_id' => $invitationId,
            'email' => $email,
            'name' => $name,
            'raw_token' => $rawToken
        ];
    }

    /**
     * Accept a staff invitation and create the user account
     */
    public static function acceptInvitation(string $rawToken, string $password, ?string $phone = null): array {
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
        }

        $tokenHash = hash('sha256', $rawToken);
        $invitation = Database::fetch(
            "SELECT si.*, b.name as business_name, r.slug as role_slug 
             FROM staff_invitations si
             JOIN businesses b ON si.business_id = b.id
             JOIN roles r ON si.role_id = r.id
             WHERE si.token_hash = ? AND si.status = 'pending'
             LIMIT 1",
            [$tokenHash]
        );

        if (!$invitation) {
            return ['success' => false, 'error' => 'Invalid or already accepted invitation link.'];
        }

        if (strtotime($invitation['expires_at']) < time()) {
            Database::query("UPDATE staff_invitations SET status = 'expired' WHERE id = ?", [$invitation['id']]);
            return ['success' => false, 'error' => 'This invitation has expired. Please ask the shop owner to resend it.'];
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // Mark invitation accepted
            Database::query(
                "UPDATE staff_invitations SET status = 'accepted', accepted_at = NOW() WHERE id = ?",
                [$invitation['id']]
            );

            // Create user
            $userId = Database::insert('users', [
                'business_id' => $invitation['business_id'],
                'role_id' => $invitation['role_id'],
                'name' => $invitation['name'],
                'email' => $invitation['email'],
                'phone' => $phone ?? '',
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_owner' => 0,
                'is_active' => 1,
                'email_verified_at' => date('Y-m-d H:i:s'),
                'verification_status' => 'verified',
                'onboarding_completed' => 1
            ]);

            $db->commit();

            ActivityLog::log(
                $invitation['business_id'],
                $userId,
                'invitation_accepted',
                'user',
                $userId,
                "Staff member {$invitation['name']} accepted invitation and registered."
            );

            return [
                'success' => true,
                'user_id' => $userId,
                'business_id' => $invitation['business_id'],
                'name' => $invitation['name'],
                'email' => $invitation['email'],
                'role_slug' => $invitation['role_slug'],
                'business_name' => $invitation['business_name']
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Accept invitation error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process invitation: ' . $e->getMessage()];
        }
    }

    /**
     * Update active / suspended status of staff member
     */
    public static function updateStatus(int $businessId, int $staffUserId, int $isActive): array {
        $user = Database::fetch(
            "SELECT id, is_owner, verification_status FROM users WHERE id = ? AND business_id = ?",
            [$staffUserId, $businessId]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Staff user not found in this business.'];
        }

        if ($user['is_owner']) {
            return ['success' => false, 'error' => 'Cannot modify owner account status.'];
        }

        $newVerif = $isActive ? 'verified' : 'suspended';
        Database::query(
            "UPDATE users SET is_active = ?, verification_status = ? WHERE id = ? AND business_id = ?",
            [$isActive ? 1 : 0, $newVerif, $staffUserId, $businessId]
        );

        // If deactivated, revoke all API tokens immediately
        if (!$isActive) {
            Database::query("DELETE FROM api_tokens WHERE user_id = ?", [$staffUserId]);
        }

        ActivityLog::log(
            $businessId,
            Auth::id() ?? $staffUserId,
            'staff_status_updated',
            'user',
            $staffUserId,
            "Staff user {$staffUserId} status updated to " . ($isActive ? 'Active' : 'Inactive')
        );

        return ['success' => true];
    }

    /**
     * List all staff members for business
     */
    public static function listStaff(int $businessId): array {
        return Database::fetchAll(
            "SELECT u.id, u.business_id, u.name, u.email, u.phone, u.role_id, r.name as role_name, r.slug as role_slug,
                    u.is_owner, u.is_active, u.verification_status, u.last_login_at, u.created_at
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.business_id = ?
             ORDER BY u.is_owner DESC, u.name ASC",
            [$businessId]
        );
    }

    /**
     * List all pending invitations
     */
    public static function listInvitations(int $businessId): array {
        return Database::fetchAll(
            "SELECT si.id, si.email, si.name, si.role_id, r.name as role_name, si.status, si.expires_at, si.created_at,
                    u.name as invited_by_name
             FROM staff_invitations si
             JOIN roles r ON si.role_id = r.id
             LEFT JOIN users u ON si.invited_by = u.id
             WHERE si.business_id = ?
             ORDER BY si.id DESC",
            [$businessId]
        );
    }
}
