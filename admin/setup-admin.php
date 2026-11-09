<?php
/**
 * DJ CRM Software 5.2.1 — Platform Super Administrator First-Time Web Setup
 * 
 * SECURITY:
 * This page is automatically and PERMANENTLY LOCKED once any Super Administrator exists in the system.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Auto-heal schema compatibility (e.g. users.is_super_admin, ai_provider_configs.is_default)
Database::ensureSchemaCompatibility();

// Check if any Super Administrator already exists
$superAdminCount = 0;
try {
    $superAdminCount = (int)Database::fetchColumn(
        "SELECT COUNT(*) FROM users 
         WHERE is_super_admin = 1 
            OR role_id IN (SELECT id FROM roles WHERE slug = 'super_admin')"
    );
} catch (\Throwable $e) {}

// Permanent Lockout Guard
if ($superAdminCount > 0) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Permanently Locked — DJ CRM</title>
        <link rel="stylesheet" href="<?= asset('css/tabler-icons.min.css') ?>">
        <link rel="stylesheet" href="<?= asset('css/global.css') ?>">
        <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
        <style>
            body { background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .locked-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.5rem; max-width: 500px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
        </style>
    </head>
    <body>
        <div class="locked-card">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: #ef4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto; font-size: 2rem;">
                <i class="ti ti-lock"></i>
            </div>
            <h2 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.75rem;">Setup Permanently Locked</h2>
            <p style="color: #94a3b8; font-size: 0.95rem; line-height: 1.6; margin-bottom: 2rem;">
                A platform administrator account is already active on this installation. For security reasons, the first-time setup wizard is permanently disabled.
            </p>
            <div style="display: flex; gap: 0.75rem; justify-content: center;">
                <a href="<?= url('login.php') ?>" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 600;">Sign In</a>
                <a href="<?= url('admin/index.php') ?>" class="btn btn-secondary" style="padding: 0.75rem 1.5rem; font-weight: 600;">Admin Portal</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
        $error = 'Security validation token expired. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($name) || !$email || empty($password)) {
            $error = 'All fields are required. Please provide a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long for platform security.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Password confirmation does not match.';
        } else {
            try {
                // Ensure platform business entity
                $biz = Database::fetch("SELECT id FROM businesses ORDER BY id ASC LIMIT 1");
                if (!$biz) {
                    $bizUuid = bin2hex(random_bytes(16));
                    $bizId = Database::insert('businesses', [
                        'name' => 'DJ CRM Platform Administration',
                        'business_type' => 'other',
                        'country' => 'PK',
                        'currency' => 'PKR',
                        'default_currency' => 'PKR',
                        'status' => 'active',
                        'tenant_uuid' => $bizUuid,
                        'onboarding_step' => 5
                    ]);
                } else {
                    $bizId = (int)$biz['id'];
                }

                // Ensure super_admin role
                $role = Database::fetch("SELECT id FROM roles WHERE slug = 'super_admin' LIMIT 1");
                if (!$role) {
                    $roleId = Database::insert('roles', [
                        'business_id' => $bizId,
                        'name' => 'Super Administrator',
                        'slug' => 'super_admin',
                        'description' => 'Full unrestricted platform super admin privileges',
                        'is_system' => 1
                    ]);
                } else {
                    $roleId = (int)$role['id'];
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userId = Database::insert('users', [
                    'business_id' => $bizId,
                    'role_id' => $roleId,
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'is_super_admin' => 1,
                    'is_owner' => 1,
                    'is_active' => 1,
                    'verification_status' => 'verified',
                    'onboarding_completed' => 1,
                    'email_verified_at' => date('Y-m-d H:i:s')
                ]);

                // Create initial activity log
                try {
                    ActivityLog::log($bizId, $userId, 'admin_setup', 'user', $userId, 'Platform Super Administrator initialized');
                } catch (\Throwable $e) {}

                Flash::success('Super Administrator created successfully! You may now log in.');
                header('Location: ' . url('login.php'));
                exit;
            } catch (\Throwable $e) {
                $error = 'Error initializing administrator: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Administrator Setup — DJ CRM Software</title>
    <link rel="stylesheet" href="<?= asset('css/tabler-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/global.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/components.css') ?>">
    <style>
        body {
            background: #0b0f19;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 1.5rem;
        }
        .setup-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="text-center mb-4">
            <div style="width: 56px; height: 56px; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #ef4444); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1rem;">
                <i class="ti ti-shield-lock"></i>
            </div>
            <h1 style="font-size: 1.5rem; font-weight: 800; margin: 0;">DJ CRM Software 5.2.1</h1>
            <p style="color: #9ca3af; font-size: 0.875rem; margin-top: 0.5rem;">
                Initial Platform Super Administrator Setup
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger mb-4 d-flex align-center gap-2">
                <i class="ti ti-alert-circle"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= url('admin/setup-admin.php') ?>">
            <?= Csrf::field() ?>

            <div class="form-group mb-3">
                <label class="form-label required">Administrator Full Name</label>
                <input type="text" name="name" class="form-input" placeholder="e.g. Master Admin" value="<?= e($_POST['name'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group mb-3">
                <label class="form-label required">Administrator Email</label>
                <input type="email" name="email" class="form-input" placeholder="admin@apkashow.com" value="<?= e($_POST['email'] ?? '') ?>" required>
                <p class="form-help text-xs text-muted">This email will hold full unrestricted super administrator credentials.</p>
            </div>

            <div class="form-group mb-3">
                <label class="form-label required">Password</label>
                <input type="password" name="password" class="form-input" placeholder="••••••••••••" minlength="8" required>
                <p class="form-help text-xs text-muted">Must be at least 8 characters long.</p>
            </div>

            <div class="form-group mb-4">
                <label class="form-label required">Confirm Password</label>
                <input type="password" name="password_confirm" class="form-input" placeholder="••••••••••••" minlength="8" required>
            </div>

            <button type="submit" class="btn btn-warning w-100 font-bold" style="padding: 0.75rem;">
                <i class="ti ti-user-plus mr-1"></i> Create Super Administrator & Lock Setup
            </button>
        </form>

        <div class="mt-4 pt-3 text-center border-top text-xs text-muted">
            <i class="ti ti-lock mr-1"></i> This setup page will permanently self-lock immediately after creation.
        </div>
    </div>
</body>
</html>
