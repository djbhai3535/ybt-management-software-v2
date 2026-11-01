<?php
http_response_code(403);
$pageTitle = '403 — Access Denied';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Access Denied</title>
    <link rel="stylesheet" href="<?= function_exists('url') ? url('assets/css/main.css') : '/assets/css/main.css' ?>">
    <link rel="stylesheet" href="<?= function_exists('url') ? url('assets/css/fonts/tabler-icons.css') : '/assets/css/fonts/tabler-icons.css' ?>">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-bg, #0f172a);
            color: var(--color-text, #f8fafc);
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 1rem;
        }
        .error-card {
            background: var(--color-surface, #1e293b);
            border: 1px solid var(--color-border, #334155);
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        .error-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.5rem auto;
        }
        h1 {
            font-size: 1.5rem;
            margin: 0 0 0.5rem 0;
            font-weight: 700;
        }
        p {
            color: var(--color-text-muted, #94a3b8);
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 0 0 1.5rem 0;
        }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            background: #3b82f6;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="ti ti-shield-x"></i>
        </div>
        <h1>403 — Unauthorized Access</h1>
        <p>You do not have permission to access the Super Administrator Control Panel. This workspace is strictly restricted to platform owners.</p>
        <a href="<?= function_exists('url') ? url('dashboard.php') : '/dashboard.php' ?>" class="btn-home">
            <i class="ti ti-arrow-left"></i> Return to Dashboard
        </a>
    </div>
</body>
</html>
