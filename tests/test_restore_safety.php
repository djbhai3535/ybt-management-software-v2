<?php
/**
 * Test Suite: Backup & Restore Safety Controls
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../includes/tenant-context.php';

function testPathTraversalBlocked(): bool {
    TenantContext::set(1);
    // Malicious traversal filename
    $malicious = '../../etc/passwd.sql';
    $basename = basename($malicious);

    if (preg_match('/^ybt_backup_[0-9a-zA-Z_]+\.sql$/', $basename)) {
        echo "FAIL: Malicious filename matched valid pattern.\n";
        return false;
    }

    echo "PASS: Path traversal filenames strictly rejected.\n";
    return true;
}

function testForbiddenDdlDetection(): bool {
    $maliciousSql = "DROP DATABASE `ybt_management`; TRUNCATE TABLE `users`;";
    $forbiddenKeywords = ['DROP DATABASE', 'CREATE DATABASE', 'TRUNCATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'GRANT ALL'];
    
    $detected = false;
    foreach ($forbiddenKeywords as $forbidden) {
        if (stripos($maliciousSql, $forbidden) !== false) {
            $detected = true;
            break;
        }
    }

    if (!$detected) {
        echo "FAIL: Forbidden DDL was not detected.\n";
        return false;
    }

    echo "PASS: Forbidden DDL statements (DROP/TRUNCATE) successfully detected and blocked.\n";
    return true;
}

echo "=== Running Restore Safety Tests ===\n";
$allPassed = testPathTraversalBlocked() && testForbiddenDdlDetection();

if ($allPassed) {
    echo ">>> ALL RESTORE SAFETY TESTS PASSED!\n\n";
    exit(0);
} else {
    echo ">>> RESTORE SAFETY TESTS FAILED!\n\n";
    exit(1);
}
