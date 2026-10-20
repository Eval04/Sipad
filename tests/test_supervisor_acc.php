<?php
/**
 * Test: Supervisor Return ACC & Stock Reconciliation Logic (TC-05 & TC-06)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../pengawas/acc_proses.php';

function testSupervisorAcc(): void {
    echo "Running testSupervisorAcc...\n";
    $pdo = getDbConnection();

    // 1. Siapkan akun pengawas untuk pengujian
    $stmtPengawas = $pdo->prepare("SELECT id_user FROM users WHERE role = 'pengawas' LIMIT 1");
    $stmtPengawas->execute();
    $idPengawas = $stmtPengawas->fetchColumn();

    if (!$idPengawas) {
        // Buat akun pengawas dummy jika belum ada
        $pdo->prepare("
            INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
            VALUES ('pengawas_test', 'hash', 'Pengawas Uji K3', 'pengawas', 1)
        ")->execute();
        $idPengawas = (int)$pdo->lastInsertId();
    } else {
        $idPengawas = (int)$idPengawas;
    }

    // Ambil item APD untuk testing (misal APD-VEST-01)
    $stmtItem = $pdo->query("SELECT id_apd, stok_tersedia, stok_rusak FROM apd_items WHERE kode_apd = 'APD-VEST-01' LIMIT 1");
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new RuntimeException("FAIL: Test item 'APD-VEST-01' not found.");
    }
    $idApd = (int)$item['id_apd'];

    // -------------------------------------------------------------
    // UJI 1: TC-05 (ACC Pengembalian APD Kondisi BAIK)
    // -------------------------------------------------------------
    $stockBeforeLoan1 = (int)$item['stok_tersedia'];

    // Buat pinjaman buatan (1 unit)
    $kode1 = 'TEST-ACC-BAIK-' . time();
    $pdo->prepare("
        INSERT INTO loans (id_apd, kode_pinjam, nama_peminjam, bagian_unit, no_hp_peminjam, jumlah_pinjam, status_pinjam)
        VALUES (?, ?, 'Peminjam Uji Baik', 'Unit Lapangan', '0812345', 1, 'DIPINJAM')
    ")->execute([$idApd, $kode1]);
    $idLoan1 = (int)$pdo->lastInsertId();

    // Kurangi stok buatan
    $pdo->prepare("UPDATE apd_items SET stok_tersedia = stok_tersedia - 1 WHERE id_apd = ?")->execute([$idApd]);

    // Eksekusi ACC Kondisi Baik oleh Pengawas
    $resBaik = processReturnAcc($pdo, $idLoan1, $idPengawas, 'BAIK', 'Kondisi fisik bersih dan layak pakai');
    if (!$resBaik['success']) {
        throw new RuntimeException("FAIL (TC-05): ACC Baik failed: " . ($resBaik['message'] ?? ''));
    }

    // Verifikasi loan sudah status SUDAH_DIKEMBALIKAN
    $stmtCheck1 = $pdo->prepare("SELECT status_pinjam, id_pengawas_acc, waktu_kembali FROM loans WHERE id_loan = ?");
    $stmtCheck1->execute([$idLoan1]);
    $loan1 = $stmtCheck1->fetch(PDO::FETCH_ASSOC);
    if ($loan1['status_pinjam'] !== 'SUDAH_DIKEMBALIKAN' || (int)$loan1['id_pengawas_acc'] !== $idPengawas || empty($loan1['waktu_kembali'])) {
        throw new RuntimeException("FAIL (TC-05): Loan table not updated with SUDAH_DIKEMBALIKAN or inspector id.");
    }

    // Verifikasi log kondisi fisik
    $stmtLog1 = $pdo->prepare("SELECT kondisi_kembali FROM condition_logs WHERE id_loan = ?");
    $stmtLog1->execute([$idLoan1]);
    $log1 = $stmtLog1->fetch(PDO::FETCH_ASSOC);
    if (!$log1 || $log1['kondisi_kembali'] !== 'BAIK') {
        throw new RuntimeException("FAIL (TC-05): condition_logs record not found or condition is not BAIK.");
    }

    // Verifikasi stok_tersedia bertambah kembali (+1)
    $stmtStockCheck1 = $pdo->prepare("SELECT stok_tersedia FROM apd_items WHERE id_apd = ?");
    $stmtStockCheck1->execute([$idApd]);
    $stockAfterAccBaik = (int)$stmtStockCheck1->fetchColumn();
    if ($stockAfterAccBaik !== $stockBeforeLoan1) {
        throw new RuntimeException("FAIL (TC-05): stok_tersedia not restored. Expected {$stockBeforeLoan1}, got {$stockAfterAccBaik}");
    }

    // -------------------------------------------------------------
    // UJI 2: TC-06 (ACC Pengembalian APD Kondisi RUSAK)
    // -------------------------------------------------------------
    $stockBeforeLoan2 = $stockAfterAccBaik;
    $stmtRusakBefore = $pdo->prepare("SELECT stok_rusak FROM apd_items WHERE id_apd = ?");
    $stmtRusakBefore->execute([$idApd]);
    $stokRusakBefore = (int)$stmtRusakBefore->fetchColumn();

    // Buat pinjaman buatan kedua
    $kode2 = 'TEST-ACC-RUSAK-' . time();
    $pdo->prepare("
        INSERT INTO loans (id_apd, kode_pinjam, nama_peminjam, bagian_unit, no_hp_peminjam, jumlah_pinjam, status_pinjam)
        VALUES (?, ?, 'Peminjam Uji Rusak', 'Unit Pengelasan', '0898765', 1, 'DIPINJAM')
    ")->execute([$idApd, $kode2]);
    $idLoan2 = (int)$pdo->lastInsertId();

    // Kurangi stok buatan
    $pdo->prepare("UPDATE apd_items SET stok_tersedia = stok_tersedia - 1 WHERE id_apd = ?")->execute([$idApd]);

    // Eksekusi ACC Kondisi Rusak oleh Pengawas
    $resRusak = processReturnAcc($pdo, $idLoan2, $idPengawas, 'RUSAK_BERAT', 'Rompi sobek parah terkena gerinda');
    if (!$resRusak['success']) {
        throw new RuntimeException("FAIL (TC-06): ACC Rusak failed: " . ($resRusak['message'] ?? ''));
    }

    // Verifikasi status loan sudah selesai
    $stmtCheck2 = $pdo->prepare("SELECT status_pinjam FROM loans WHERE id_loan = ?");
    $stmtCheck2->execute([$idLoan2]);
    $loan2 = $stmtCheck2->fetch(PDO::FETCH_ASSOC);
    if ($loan2['status_pinjam'] !== 'SUDAH_DIKEMBALIKAN') {
        throw new RuntimeException("FAIL (TC-06): Loan status not marked completed.");
    }

    // Verifikasi stok_tersedia TIDAK bertambah kembali (tetap berkurang 1 dari stockBeforeLoan2)
    $stmtStockCheck2 = $pdo->prepare("SELECT stok_tersedia, stok_rusak FROM apd_items WHERE id_apd = ?");
    $stmtStockCheck2->execute([$idApd]);
    $stockAfterAccRusak = $stmtStockCheck2->fetch(PDO::FETCH_ASSOC);

    if ((int)$stockAfterAccRusak['stok_tersedia'] !== ($stockBeforeLoan2 - 1)) {
        throw new RuntimeException("FAIL (TC-06): stok_tersedia was erroneously restored for damaged item.");
    }

    // Verifikasi stok_rusak bertambah +1
    if ((int)$stockAfterAccRusak['stok_rusak'] !== ($stokRusakBefore + 1)) {
        throw new RuntimeException("FAIL (TC-06): stok_rusak was not incremented.");
    }

    echo "PASS: Supervisor ACC return and stock reconciliation verified (TC-05 and TC-06 passed).\n";
}

try {
    testSupervisorAcc();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
