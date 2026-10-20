<?php
/**
 * Test Suite Komprehensif: Pengujian Fungsional Blackbox (TC-01 s/d TC-06)
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 * Berdasarkan Dokumen R&D Tabel 4.1
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../pinjam_proses.php';
require_once __DIR__ . '/../pengawas/acc_proses.php';
require_once __DIR__ . '/../admin/laporan.php';

function runBlackboxTestSuite(): void {
    echo "======================================================================\n";
    echo "  SIPAD K3 OPERASIONAL - COMPREHENSIVE BLACKBOX TEST SUITE (TC-01 s/d TC-06)\n";
    echo "======================================================================\n\n";

    $pdo = getDbConnection();
    $results = [];

    // Reset dan pastikan data awal siap
    $pdo->prepare("UPDATE wifi_settings SET ip_address = '127.0.0.1', kode_pin_gudang = '4821', is_wifi_check_enabled = 0 WHERE id_setting = 1")->execute();

    // Ambil item uji APD apa saja yang memiliki stok tersedia (atau buat fixture jika belum ada)
    $itemApd = $pdo->query("SELECT id_apd, stok_tersedia, stok_rusak FROM apd_items WHERE stok_tersedia > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$itemApd) {
        $firstCatId = (int)$pdo->query("SELECT id_category FROM apd_categories LIMIT 1")->fetchColumn();
        if (!$firstCatId) {
            $pdo->exec("INSERT INTO apd_categories (kode_kategori, nama_kategori) VALUES ('KAT-KEPALA', 'Alat Pelindung Kepala')");
            $firstCatId = (int)$pdo->lastInsertId();
        }
        $pdo->exec("INSERT INTO apd_items (id_category, kode_apd, nama_apd, ukuran_spesifikasi, stok_total, stok_tersedia, stok_rusak) VALUES ({$firstCatId}, 'APD-TEST-01', 'Safety Helmet Uji', 'All Size', 20, 20, 0)");
        $itemApd = $pdo->query("SELECT id_apd, stok_tersedia, stok_rusak FROM apd_items WHERE kode_apd = 'APD-TEST-01' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    $idApd = (int)$itemApd['id_apd'];

    // -------------------------------------------------------------
    // TC-01: Peminjaman Mandiri Tanpa Login (PIN Valid '4821')
    // -------------------------------------------------------------
    echo "[TEST] TC-01: Peminjaman Mandiri Tanpa Login (PIN Valid)... ";
    $stockBeforeTc01 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $payloadTc01 = [
        'nama_peminjam'  => 'Budi Santoso',
        'bagian_unit'    => 'Fabrikasi Lapangan',
        'no_hp_peminjam' => '082198765432',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 1,
        'pin_gudang'     => '4821'
    ];
    $resTc01 = processLoanSubmission($pdo, $payloadTc01);
    $stockAfterTc01 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $tc01Valid = ($resTc01['success'] === true && !empty($resTc01['kode_pinjam']) && $stockAfterTc01 === ($stockBeforeTc01 - 1));
    if ($tc01Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-01'] = 'LULUS (100% Valid)';
        $kodePinjamTc01 = $resTc01['kode_pinjam'];
    } else {
        echo "GAGAL!\n";
        $results['TC-01'] = 'GAGAL';
    }

    // -------------------------------------------------------------
    // TC-02: Penolakan Entri Tanpa Login saat PIN Gudang Salah ('0000')
    // -------------------------------------------------------------
    echo "[TEST] TC-02: Penolakan Entri saat PIN Salah ('0000')... ";
    $stockBeforeTc02 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $payloadTc02 = [
        'nama_peminjam'  => 'Penyusup Luar',
        'bagian_unit'    => 'Non-Karyawan',
        'no_hp_peminjam' => '080000000000',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 1,
        'pin_gudang'     => '0000' // PIN Salah
    ];
    $resTc02 = processLoanSubmission($pdo, $payloadTc02);
    $stockAfterTc02 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $tc02Valid = ($resTc02['success'] === false && $stockAfterTc02 === $stockBeforeTc02);
    if ($tc02Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-02'] = 'LULUS (100% Valid)';
    } else {
        echo "GAGAL!\n";
        $results['TC-02'] = 'GAGAL';
    }

    // -------------------------------------------------------------
    // TC-03: Pembuatan Akun Pengawas Baru oleh Admin
    // -------------------------------------------------------------
    echo "[TEST] TC-03: Pembuatan Akun Pengawas Baru oleh Admin... ";
    $usernameTc03 = 'pengawas_k3_' . bin2hex(random_bytes(3));
    $passwordTc03 = 'PengawasSuite123!';
    $namaPengawasTc03 = 'Pengawas K3 Wilayah 1';

    $hashTc03 = password_hash($passwordTc03, PASSWORD_BCRYPT);
    $stmtTc03 = $pdo->prepare("
        INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
        VALUES (?, ?, ?, 'pengawas', 1)
    ");
    $stmtTc03->execute([$usernameTc03, $hashTc03, $namaPengawasTc03]);
    $idPengawasSuite = (int)$pdo->lastInsertId();

    $authTc03 = authenticateUser($pdo, $usernameTc03, $passwordTc03);
    $tc03Valid = ($authTc03['success'] === true && $authTc03['user']['role'] === 'pengawas');

    if ($tc03Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-03'] = 'LULUS (100% Valid)';
    } else {
        echo "GAGAL!\n";
        $results['TC-03'] = 'GAGAL';
    }

    // -------------------------------------------------------------
    // TC-04: Validasi Login Pengawas via IP WiFi Kantor & Saklar ON/OFF
    // -------------------------------------------------------------
    echo "[TEST] TC-04: Validasi Login via IP WiFi Kantor & Saklar ON/OFF... ";
    $officeIp = '103.144.10.1';
    $cellularIp = '114.122.99.88';

    // A. Saklar ON, IP Luar -> Wajib Tolak
    $pdo->prepare("UPDATE wifi_settings SET ip_address = ?, is_wifi_check_enabled = 1 WHERE id_setting = 1")->execute([$officeIp]);
    $checkRejected = validateWifiIp($pdo, $cellularIp);

    // B. Saklar ON, IP Kantor -> Wajib Lulus
    $checkAccepted = validateWifiIp($pdo, $officeIp);

    // C. Saklar OFF, IP Luar -> Wajib Lulus (Emergency Bypass)
    $pdo->prepare("UPDATE wifi_settings SET is_wifi_check_enabled = 0 WHERE id_setting = 1")->execute();
    $checkBypass = validateWifiIp($pdo, $cellularIp);

    $tc04Valid = ($checkRejected === false && $checkAccepted === true && $checkBypass === true);
    if ($tc04Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-04'] = 'LULUS (100% Valid)';
    } else {
        echo "GAGAL!\n";
        $results['TC-04'] = 'GAGAL';
    }

    // -------------------------------------------------------------
    // TC-05: ACC Pengembalian APD Kondisi Baik oleh Pengawas
    // -------------------------------------------------------------
    echo "[TEST] TC-05: ACC Pengembalian APD Kondisi Baik... ";
    // Ambil id_loan dari transaksi TC-01
    $stmtLoan01 = $pdo->prepare("SELECT id_loan, id_apd, jumlah_pinjam FROM loans WHERE kode_pinjam = ?");
    $stmtLoan01->execute([$kodePinjamTc01]);
    $loanTc01 = $stmtLoan01->fetch(PDO::FETCH_ASSOC);

    $stockBeforeAcc05 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();
    $resTc05 = processReturnAcc($pdo, (int)$loanTc01['id_loan'], $idPengawasSuite, 'BAIK', 'Pemeriksaan fisik utuh');
    $stockAfterAcc05 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $stmtLoanCheck05 = $pdo->prepare("SELECT status_pinjam FROM loans WHERE id_loan = ?");
    $stmtLoanCheck05->execute([(int)$loanTc01['id_loan']]);
    $statusLoan05 = $stmtLoanCheck05->fetchColumn();

    $tc05Valid = ($resTc05['success'] === true && $statusLoan05 === 'SUDAH_DIKEMBALIKAN' && $stockAfterAcc05 === ($stockBeforeAcc05 + 1));
    if ($tc05Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-05'] = 'LULUS (100% Valid)';
    } else {
        echo "GAGAL!\n";
        $results['TC-05'] = 'GAGAL';
    }

    // -------------------------------------------------------------
    // TC-06: ACC Pengembalian APD Kondisi Rusak oleh Pengawas
    // -------------------------------------------------------------
    echo "[TEST] TC-06: ACC Pengembalian APD Kondisi Rusak... ";
    // Buat pinjaman baru untuk TC-06
    $payloadTc06 = [
        'nama_peminjam'  => 'Hendra Wijaya',
        'bagian_unit'    => 'Konstruksi Baja',
        'no_hp_peminjam' => '087712345678',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 1,
        'pin_gudang'     => '4821'
    ];
    $resLoan06 = processLoanSubmission($pdo, $payloadTc06);
    $stmtLoan06 = $pdo->prepare("SELECT id_loan FROM loans WHERE kode_pinjam = ?");
    $stmtLoan06->execute([$resLoan06['kode_pinjam']]);
    $idLoan06 = (int)$stmtLoan06->fetchColumn();

    $stockTersediaBefore06 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();
    $stockRusakBefore06    = (int)$pdo->query("SELECT stok_rusak FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $resTc06 = processReturnAcc($pdo, $idLoan06, $idPengawasSuite, 'RUSAK_BERAT', 'Visor pecah terbentur material');

    $stockTersediaAfter06 = (int)$pdo->query("SELECT stok_tersedia FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();
    $stockRusakAfter06    = (int)$pdo->query("SELECT stok_rusak FROM apd_items WHERE id_apd = {$idApd}")->fetchColumn();

    $tc06Valid = (
        $resTc06['success'] === true &&
        $stockTersediaAfter06 === $stockTersediaBefore06 && // Stok tersedia TIDAK pulih
        $stockRusakAfter06 === ($stockRusakBefore06 + 1)     // Stok rusak bertambah +1
    );

    if ($tc06Valid) {
        echo "LULUS (100% Valid)\n";
        $results['TC-06'] = 'LULUS (100% Valid)';
    } else {
        echo "GAGAL!\n";
        $results['TC-06'] = 'GAGAL';
    }

    echo "\n======================================================================\n";
    echo "  REKAPITULASI HASIL BLACKBOX TESTING (TABEL 4.1 R&D)\n";
    echo "======================================================================\n";
    foreach ($results as $code => $stat) {
        printf("  %-10s : %s\n", $code, $stat);
    }
    echo "======================================================================\n";

    if (count(array_filter($results, fn($s) => str_contains($s, 'LULUS'))) === 6) {
        echo "HASIL AKHIR: 100% SKENARIO KRITIS LULUS DENGAN SUKSES.\n\n";
    } else {
        throw new RuntimeException("Terdapat skenario pengujian yang gagal.");
    }
}

try {
    runBlackboxTestSuite();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
