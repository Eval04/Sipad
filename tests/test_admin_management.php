<?php
/**
 * Test: Admin Management Operations (TC-03, WiFi Controls & Stock Repair)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

function testAdminManagement(): void {
    echo "Running testAdminManagement...\n";
    $pdo = getDbConnection();

    // -------------------------------------------------------------
    // 1. TC-03: Pembuatan Akun Pengawas Baru oleh Admin
    // -------------------------------------------------------------
    $newUsername = 'pengawas_k3_' . time();
    $rawPassword = 'Password_Pengawas_123!';
    $namaLengkap = 'Ival Permana (Pengawas Lapangan)';

    // Hash kata sandi dengan bcrypt
    $passwordHash = password_hash($rawPassword, PASSWORD_BCRYPT);

    $stmtCreate = $pdo->prepare("
        INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
        VALUES (?, ?, ?, 'pengawas', 1)
    ");
    $stmtCreate->execute([$newUsername, $passwordHash, $namaLengkap]);
    $newId = (int)$pdo->lastInsertId();

    // Verifikasi akun tersimpan di tabel users
    $stmtCheck = $pdo->prepare("SELECT * FROM users WHERE id_user = ?");
    $stmtCheck->execute([$newId]);
    $createdUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$createdUser || $createdUser['role'] !== 'pengawas' || (int)$createdUser['is_active'] !== 1) {
        throw new RuntimeException("FAIL (TC-03): Pengawas account creation failed or not active.");
    }

    // Verifikasi akun yang baru dibuat bisa login secara sah
    $authCheck = authenticateUser($pdo, $newUsername, $rawPassword);
    if (!$authCheck['success']) {
        throw new RuntimeException("FAIL (TC-03): Newly created Pengawas could not authenticate with their password.");
    }

    // -------------------------------------------------------------
    // 2. Pengujian Pendaftaran IP WiFi 1-Klik & Saklar Toggle ON/OFF
    // -------------------------------------------------------------
    $simulatedOfficeIp = '103.245.10.22';

    // Update IP & Toggle ON
    $stmtWifiUpdate = $pdo->prepare("
        UPDATE wifi_settings 
        SET ip_address = ?, is_wifi_check_enabled = 1, updated_at = NOW() 
        WHERE id_setting = 1
    ");
    $stmtWifiUpdate->execute([$simulatedOfficeIp]);

    $stmtGetWifi = $pdo->query("SELECT ip_address, is_wifi_check_enabled FROM wifi_settings WHERE id_setting = 1");
    $wifiRow = $stmtGetWifi->fetch(PDO::FETCH_ASSOC);

    if ($wifiRow['ip_address'] !== $simulatedOfficeIp || (int)$wifiRow['is_wifi_check_enabled'] !== 1) {
        throw new RuntimeException("FAIL: 1-Click WiFi update or Toggle ON failed.");
    }

    // Toggle OFF
    $pdo->prepare("UPDATE wifi_settings SET is_wifi_check_enabled = 0 WHERE id_setting = 1")->execute();
    $toggleStatus = (int)$pdo->query("SELECT is_wifi_check_enabled FROM wifi_settings WHERE id_setting = 1")->fetchColumn();
    if ($toggleStatus !== 0) {
        throw new RuntimeException("FAIL: Toggle OFF failed.");
    }

    // -------------------------------------------------------------
    // 3. Pengujian Fitur Pemulihan Stok Rusak (Repair / Restorasi Admin)
    // -------------------------------------------------------------
    // Ambil item APD
    $stmtItem = $pdo->query("SELECT id_apd, stok_tersedia, stok_rusak FROM apd_items LIMIT 1");
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
    $idApd = (int)$item['id_apd'];

    // Simulasikan ada 2 stok rusak
    $pdo->prepare("UPDATE apd_items SET stok_rusak = stok_rusak + 2 WHERE id_apd = ?")->execute([$idApd]);
    $stokTersediaBefore = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();
    $stokRusakBefore    = (int)$pdo->query("SELECT stok_rusak FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    // Aksi pemulihan 2 unit yang telah selesai diperbaiki
    $unitRepaired = 2;
    $pdo->prepare("
        UPDATE apd_items 
        SET stok_rusak = stok_rusak - ?, stok_tersedia = stok_tersedia + ? 
        WHERE id_apd = ? AND stok_rusak >= ?
    ")->execute([$unitRepaired, $unitRepaired, $idApd, $unitRepaired]);

    $stokTersediaAfter = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();
    $stokRusakAfter    = (int)$pdo->query("SELECT stok_rusak FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    if ($stokTersediaAfter !== ($stokTersediaBefore + 2) || $stokRusakAfter !== ($stokRusakBefore - 2)) {
        throw new RuntimeException("FAIL: Repair action failed to transfer stock from stok_rusak to stok_tersedia.");
    }

    echo "PASS: Admin management, Pengawas creation (TC-03), WiFi controls, and stock restoration verified.\n";
}

try {
    testAdminManagement();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
