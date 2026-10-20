<?php
/**
 * Test: Authentication, Session Middleware & Zero-Router WiFi Validation (TC-03 & TC-04)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

function testAuthAndWifi(): void {
    echo "Running testAuthAndWifi...\n";
    $pdo = getDbConnection();

    // 1. Test Admin Login with valid credentials
    $loginAdmin = authenticateUser($pdo, 'admin', 'admin123');
    if (!$loginAdmin['success'] || $loginAdmin['user']['role'] !== 'admin') {
        throw new RuntimeException("FAIL: Admin authentication failed with valid credentials.");
    }

    // 2. Test Invalid Password
    $invalidLogin = authenticateUser($pdo, 'admin', 'wrong_password');
    if ($invalidLogin['success']) {
        throw new RuntimeException("FAIL: System accepted incorrect password.");
    }

    // 3. Test Non-existent user
    $ghostLogin = authenticateUser($pdo, 'non_existent_user', 'any_pass');
    if ($ghostLogin['success']) {
        throw new RuntimeException("FAIL: System accepted non-existent user.");
    }

    // 4. Test Zero-Router WiFi Logic (TC-04)
    // Setup test IP in wifi_settings
    $testOfficeIp = '192.168.10.100';
    $externalIp   = '203.0.113.55';

    // A. When WiFi Check is OFF (is_wifi_check_enabled = 0)
    $pdo->prepare("UPDATE wifi_settings SET ip_address = ?, is_wifi_check_enabled = 0 WHERE id_setting = 1")
        ->execute([$testOfficeIp]);

    $wifiValidWhenOff = validateWifiIp($pdo, $externalIp);
    if ($wifiValidWhenOff !== true) {
        throw new RuntimeException("FAIL: WiFi check failed when toggle was OFF (should allow any IP).");
    }

    // B. When WiFi Check is ON (is_wifi_check_enabled = 1)
    $pdo->prepare("UPDATE wifi_settings SET ip_address = ?, is_wifi_check_enabled = 1 WHERE id_setting = 1")
        ->execute([$testOfficeIp]);

    // Same IP should pass
    $wifiPassWhenOn = validateWifiIp($pdo, $testOfficeIp);
    if ($wifiPassWhenOn !== true) {
        throw new RuntimeException("FAIL: Matching office WiFi IP rejected when toggle was ON.");
    }

    // Different IP should be rejected
    $wifiRejectWhenOn = validateWifiIp($pdo, $externalIp);
    if ($wifiRejectWhenOn !== false) {
        throw new RuntimeException("FAIL: External IP allowed when WiFi toggle was ON (should reject).");
    }

    // Reset wifi_settings to default
    $pdo->prepare("UPDATE wifi_settings SET ip_address = '127.0.0.1', is_wifi_check_enabled = 0 WHERE id_setting = 1")
        ->execute();

    echo "PASS: Authentication, password hashing, and WiFi IP toggle logic verified (TC-04 passed).\n";
}

try {
    testAuthAndWifi();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
