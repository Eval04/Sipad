<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

echo "=== TEST: DELETE USER & DATABASE WIPE ===\n";

// 1. Buat Pengawas Dummy untuk Dites Hapus
$dummyUser = 'pengawas_test_' . time();
$passHash = password_hash('password123', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, nama_lengkap, role, is_active) VALUES (?, ?, 'Tester Pengawas', 'pengawas', 1)");
$stmt->execute([$dummyUser, $passHash]);
$testId = (int)$pdo->lastInsertId();
echo "[OK] Dummy pengawas created with ID: {$testId}\n";

// 2. Test Hapus User
$stmtDel = $pdo->prepare("DELETE FROM users WHERE id_user = ?");
$stmtDel->execute([$testId]);

$stmtCek = $pdo->prepare("SELECT id_user FROM users WHERE id_user = ?");
$stmtCek->execute([$testId]);
if (!$stmtCek->fetch()) {
    echo "[PASS] User {$testId} successfully deleted permanently!\n";
} else {
    echo "[FAIL] User {$testId} was not deleted!\n";
    exit(1);
}

// 4. Test Hapus APD Satuan
echo "[INFO] Testing delete individual APD...\n";
$stmtApd = $pdo->prepare("INSERT INTO apd_items (id_category, kode_apd, nama_apd, ukuran_spesifikasi, stok_total, stok_tersedia, stok_rusak) VALUES (1, 'APD-TEST-DEL', 'Test APD Deletion', 'L', 5, 5, 0)");
$stmtApd->execute();
$delApdId = (int)$pdo->lastInsertId();

$pdo->prepare("DELETE FROM apd_items WHERE id_apd = ?")->execute([$delApdId]);
$checkApd = $pdo->query("SELECT id_apd FROM apd_items WHERE id_apd = {$delApdId}")->fetch();
if (!$checkApd) {
    echo "[PASS] APD item successfully deleted individually!\n";
} else {
    echo "[FAIL] APD item was not deleted!\n";
    exit(1);
}

// 5. Test Pastikan Kategori APD TIDAK Pernah Terhapus
$catCount = (int)$pdo->query("SELECT COUNT(*) FROM apd_categories")->fetchColumn();
if ($catCount > 0) {
    echo "[PASS] Categories preserved ({$catCount} categories active)!\n";
} else {
    echo "[FAIL] Categories were deleted!\n";
    exit(1);
}

// Pastikan item pengujian utama ada untuk test suite lainnya
$hasHlm = $pdo->query("SELECT id_apd FROM apd_items WHERE kode_apd = 'APD-HLM-01'")->fetch();
if (!$hasHlm) {
    $pdo->exec("INSERT INTO apd_items (id_category, kode_apd, nama_apd, ukuran_spesifikasi, stok_total, stok_tersedia, stok_rusak) VALUES (1, 'APD-HLM-01', 'Safety Helmet V-Gard Putih', 'All Size', 20, 20, 0)");
}

echo "=== ALL CUSTOM TESTS PASSED! ===\n";
