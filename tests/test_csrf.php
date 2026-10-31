<?php
/**
 * Test Suite: CSRF Cryptographic Validation
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/csrf.php';

function testCsrfGenerationAndValidation(): bool {
    $token = Csrf::token();
    if (empty($token) || strlen($token) < 32) {
        echo "FAIL: Generated CSRF token is invalid.\n";
        return false;
    }

    if (!Csrf::validate($token)) {
        echo "FAIL: Valid CSRF token failed validation.\n";
        return false;
    }

    echo "PASS: CSRF token generation and validation verified.\n";
    return true;
}

function testCsrfRejectionOfTamperedTokens(): bool {
    if (Csrf::validate('invalid_token_12345')) {
        echo "FAIL: Invalid token was erroneously accepted.\n";
        return false;
    }

    if (Csrf::validate('')) {
        echo "FAIL: Empty token was erroneously accepted.\n";
        return false;
    }

    echo "PASS: Tampered and empty CSRF tokens successfully rejected.\n";
    return true;
}

echo "=== Running CSRF Protection Tests ===\n";
$allPassed = testCsrfGenerationAndValidation()
    && testCsrfRejectionOfTamperedTokens();

if ($allPassed) {
    echo ">>> ALL CSRF PROTECTION TESTS PASSED!\n\n";
    exit(0);
} else {
    echo ">>> CSRF TESTS FAILED!\n\n";
    exit(1);
}
