<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../services/staff-service.php';

echo "=== MILESTONE 4: STAFF INVITATION & ANTI-ESCALATION SUITE ===\n\n";

$businessId = 1;
$ownerId = 1;

// Clean up test records
Database::query("DELETE FROM staff_invitations WHERE email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");
Database::query("DELETE FROM users WHERE email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");
Database::query("DELETE FROM local_sessions WHERE user_email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");

// 1. Owner Invites Staff Member
echo "1. Testing Staff Member Invitation...\n";
$staffRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'staff'", [$businessId]);
$staffRoleId = $staffRole['id'] ?? 3;

$resStaff = StaffService::invite($businessId, $ownerId, 'test.staff@ybt.com', 'Bilal Ahmed', $staffRoleId);
if (!$resStaff['success']) {
    echo "[FAIL] Staff invitation failed: " . ($resStaff['error'] ?? '') . "\n";
    exit(1);
}
echo " [PASS] Staff invited successfully. Token: (Length " . strlen($resStaff['raw_token']) . ")\n";

// 2. Anti-Privilege Escalation Test: Attempting to invite an Owner
echo "\n2. Testing Anti-Privilege Escalation (Inviting as Owner)...\n";
$ownerRole = Database::fetch("SELECT id FROM roles WHERE business_id = ? AND slug = 'owner'", [$businessId]);
$ownerRoleId = $ownerRole['id'] ?? 1;

$resEscalate = StaffService::invite($businessId, $ownerId, 'escalation.staff@ybt.com', 'Malicious Actor', $ownerRoleId);
if ($resEscalate['success']) {
    echo "[FAIL] SECURITY BREACH: System allowed inviting a user as Owner!\n";
    exit(1);
}
echo " [PASS] Escalation blocked: " . $resEscalate['error'] . "\n";

// 3. Duplicate Invitation Handling
echo "\n3. Testing Duplicate Invitation Handling...\n";
$resDup = StaffService::invite($businessId, $ownerId, 'test.staff@ybt.com', 'Bilal Ahmed (Updated)', $staffRoleId);
if (!$resDup['success']) {
    echo "[FAIL] Re-inviting staff failed: " . ($resDup['error'] ?? '') . "\n";
    exit(1);
}
$cancelledCount = Database::fetchColumn("SELECT COUNT(*) FROM staff_invitations WHERE business_id = ? AND email = 'test.staff@ybt.com' AND status = 'cancelled'", [$businessId]);
if ((int)$cancelledCount !== 1) {
    echo "[FAIL] Previous pending invitation was not cancelled! Count: {$cancelledCount}\n";
    exit(1);
}
echo " [PASS] Old invitation was automatically cancelled; new active token issued.\n";

// 4. Staff Accepts Invitation Portal
echo "\n4. Testing Staff Member Invitation Acceptance...\n";
$acceptRes = StaffService::acceptInvitation($resDup['raw_token'], 'BilalPass@123', '+92 301 9876543');
if (!$acceptRes['success']) {
    echo "[FAIL] Staff acceptance failed: " . ($acceptRes['error'] ?? '') . "\n";
    exit(1);
}
$newStaffUser = Database::fetch("SELECT * FROM users WHERE id = ?", [$acceptRes['user_id']]);
if (!$newStaffUser || (int)$newStaffUser['is_owner'] !== 0) {
    echo "[FAIL] Staff user created incorrectly or with is_owner = 1!\n";
    exit(1);
}
echo " [PASS] Staff member accepted invitation. User ID: {$newStaffUser['id']}, is_owner: {$newStaffUser['is_owner']}, role: {$acceptRes['role_slug']}\n";

// 5. Account Status Control (Suspend / Reactivate)
echo "\n5. Testing Account Status Toggling...\n";
$suspRes = StaffService::updateStatus($businessId, (int)$newStaffUser['id'], 0);
if (!$suspRes['success']) {
    echo "[FAIL] Suspending staff failed.\n";
    exit(1);
}
$checkSusp = Database::fetch("SELECT is_active, verification_status FROM users WHERE id = ?", [$newStaffUser['id']]);
if ((int)$checkSusp['is_active'] !== 0 || $checkSusp['verification_status'] !== 'suspended') {
    echo "[FAIL] User was not marked suspended in database.\n";
    exit(1);
}
echo " [PASS] Staff member successfully suspended.\n";

// 6. Owner Protection Guard (Attempting to suspend the Owner)
echo "\n6. Testing Owner Protection (Attempting to suspend Owner)...\n";
$ownerSuspRes = StaffService::updateStatus($businessId, $ownerId, 0);
if ($ownerSuspRes['success']) {
    echo "[FAIL] SECURITY BREACH: System allowed suspending the Owner account!\n";
    exit(1);
}
echo " [PASS] Owner suspension blocked: " . $ownerSuspRes['error'] . "\n";

// Reactivate staff member
StaffService::updateStatus($businessId, (int)$newStaffUser['id'], 1);

// 7. Verify Sensitive Log Masking
echo "\n7. Verifying Raw Tokens Are Never Logged in Activity Logs...\n";
$logs = Database::fetchAll("SELECT description FROM activity_logs WHERE business_id = ? AND action IN ('staff_invited', 'invitation_accepted')", [$businessId]);
foreach ($logs as $l) {
    if (str_contains($l['description'], $resDup['raw_token'])) {
        echo "[FAIL] Raw invitation token leaked into activity log!\n";
        exit(1);
    }
}
echo " [PASS] Zero raw tokens exposed in activity logs.\n";

// Clean up test records
Database::query("DELETE FROM staff_invitations WHERE email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");
Database::query("DELETE FROM users WHERE email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");
Database::query("DELETE FROM local_sessions WHERE user_email IN ('test.staff@ybt.com', 'test.manager@ybt.com', 'escalation.staff@ybt.com')");

echo "\nMILESTONE 4 VERIFICATION 100% SUCCESSFUL!\n";
