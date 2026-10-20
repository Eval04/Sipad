<?php
/**
 * Test: Database Connection & Schema Integrity
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function testDatabaseConnection(): void {
    echo "Running testDatabaseConnection...\n";
    $pdo = getDbConnection();
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException("FAIL: getDbConnection() did not return PDO instance.");
    }

    // Verify error mode
    $errorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    if ($errorMode !== PDO::ERRMODE_EXCEPTION) {
        throw new RuntimeException("FAIL: PDO error mode is not ERRMODE_EXCEPTION.");
    }

    // Verify 6 core tables exist
    $expectedTables = [
        'users',
        'wifi_settings',
        'apd_categories',
        'apd_items',
        'loans',
        'condition_logs'
    ];

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expectedTables as $expected) {
        if (!in_array($expected, $tables, true)) {
            throw new RuntimeException("FAIL: Table '{$expected}' does not exist in database.");
        }
    }

    // Verify default admin user exists
    $stmtAdmin = $pdo->prepare("SELECT id_user, username, role FROM users WHERE username = 'admin'");
    $stmtAdmin->execute();
    $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$admin || $admin['role'] !== 'admin') {
        throw new RuntimeException("FAIL: Default superuser 'admin' not found in users table.");
    }

    // Verify initial wifi_settings row exists
    $stmtWifi = $pdo->query("SELECT id_setting, kode_pin_gudang, is_wifi_check_enabled FROM wifi_settings LIMIT 1");
    $wifi = $stmtWifi->fetch(PDO::FETCH_ASSOC);
    if (!$wifi || $wifi['kode_pin_gudang'] !== '4821') {
        throw new RuntimeException("FAIL: Initial wifi_settings row with default PIN '4821' not found.");
    }

    echo "PASS: Database connection, 6 tables, admin account, and wifi_settings verified.\n";
}

try {
    testDatabaseConnection();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
