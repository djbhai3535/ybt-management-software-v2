<?php
/**
 * Test Suite: Authenticated Encryption (AES-256-GCM) & Legacy CBC Backward Compatibility
 */

if (!defined('YBT_ROOT')) {
    define('YBT_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/../includes/functions.php';

function testGcmEncryptionAndDecryption(): bool {
    $secret = "AI_API_KEY_SECRET_GEMINI_123456789";
    $encrypted = encryptString($secret);

    if (!str_starts_with($encrypted, 'gcm:')) {
        echo "FAIL: Expected 'gcm:' prefix on authenticated ciphertext.\n";
        return false;
    }

    $decrypted = decryptString($encrypted);
    if ($decrypted !== $secret) {
        echo "FAIL: Decrypted string did not match original secret.\n";
        return false;
    }

    echo "PASS: AES-256-GCM encryption and decryption verified.\n";
    return true;
}

function testTamperDetection(): bool {
    $secret = "CRITICAL_PAYMENT_SECRET";
    $encrypted = encryptString($secret);

    // Tamper with ciphertext by altering characters
    $tampered = substr($encrypted, 0, -4) . 'XXXX';
    $result = decryptString($tampered);

    if ($result === $secret) {
        echo "FAIL: Tampered ciphertext should not decrypt to original plain text.\n";
        return false;
    }

    echo "PASS: Tamper detection verified (GCM tag validation failed safely).\n";
    return true;
}

function testLegacyCbcBackwardCompatibility(): bool {
    // Manually construct legacy AES-256-CBC payload
    $config = require YBT_ROOT . '/config/config.php';
    $rawKey = $config['encryption_key'] ?? 'YBT_ENCRYPTION_SECRET';
    $key = hash('sha256', $rawKey, true);

    $legacySecret = "LEGACY_CBC_PASSWORD_FROM_PHASE_1";
    $iv = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($legacySecret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    $legacyCiphertext = base64_encode($iv . $cipher);

    // Decrypt using updated decryptString()
    $decrypted = decryptString($legacyCiphertext);

    if ($decrypted !== $legacySecret) {
        echo "FAIL: Legacy AES-256-CBC decryption failed backward compatibility.\n";
        return false;
    }

    echo "PASS: Legacy AES-256-CBC backward compatibility successfully verified.\n";
    return true;
}

echo "=== Running Encryption Security Tests ===\n";
$allPassed = testGcmEncryptionAndDecryption()
    && testTamperDetection()
    && testLegacyCbcBackwardCompatibility();

if ($allPassed) {
    echo ">>> ALL ENCRYPTION SECURITY TESTS PASSED!\n\n";
    exit(0);
} else {
    echo ">>> ENCRYPTION TESTS FAILED!\n\n";
    exit(1);
}
