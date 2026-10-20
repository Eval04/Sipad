<?php
/**
 * Test: Zero-Login Guest Self-Service Borrow Flow (TC-01 & TC-02)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../pinjam_proses.php';

function testBorrowFlow(): void {
    echo "Running testBorrowFlow...\n";
    $pdo = getDbConnection();

    // Pastikan ada item APD untuk testing
    $stmtItem = $pdo->query("SELECT id_apd, stok_tersedia FROM apd_items WHERE kode_apd = 'APD-HLM-01' LIMIT 1");
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new RuntimeException("FAIL: Test item 'APD-HLM-01' not found.");
    }

    $idApd = (int)$item['id_apd'];
    $initialStock = (int)$item['stok_tersedia'];

    // 1. TC-02: Penolakan entri peminjaman saat PIN salah
    $badPinPayload = [
        'nama_peminjam'  => 'Agus Pratama',
        'bagian_unit'    => 'Divisi Elektrikal',
        'no_hp_peminjam' => '081234567890',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 1,
        'pin_gudang'     => '0000' // PIN Salah
    ];

    $resultBadPin = processLoanSubmission($pdo, $badPinPayload);
    if ($resultBadPin['success'] === true) {
        throw new RuntimeException("FAIL (TC-02): System accepted loan submission with incorrect warehouse PIN.");
    }

    // Verifikasi stok tidak berubah
    $stmtCheck = $pdo->prepare("SELECT stok_tersedia FROM apd_items WHERE id_apd = ?");
    $stmtCheck->execute([$idApd]);
    $stockAfterReject = (int)$stmtCheck->fetchColumn();
    if ($stockAfterReject !== $initialStock) {
        throw new RuntimeException("FAIL (TC-02): Stock was decremented despite PIN rejection.");
    }

    // 2. TC-01: Peminjaman Mandiri Tanpa Login dengan PIN Valid (4821)
    $goodPayload = [
        'nama_peminjam'  => 'Budi Santoso',
        'bagian_unit'    => 'Seksi Fabrikasi Lapangan',
        'no_hp_peminjam' => '082198765432',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 1,
        'pin_gudang'     => '4821' // PIN Valid
    ];

    $resultGood = processLoanSubmission($pdo, $goodPayload);
    if ($resultGood['success'] !== true || empty($resultGood['kode_pinjam'])) {
        throw new RuntimeException("FAIL (TC-01): Valid loan submission failed: " . ($resultGood['message'] ?? ''));
    }

    $kodePinjam = $resultGood['kode_pinjam'];

    // Verifikasi database: tabel loans harus mencatat status DIPINJAM
    $stmtLoan = $pdo->prepare("SELECT * FROM loans WHERE kode_pinjam = ?");
    $stmtLoan->execute([$kodePinjam]);
    $loanRow = $stmtLoan->fetch(PDO::FETCH_ASSOC);

    if (!$loanRow || $loanRow['status_pinjam'] !== 'DIPINJAM') {
        throw new RuntimeException("FAIL (TC-01): Loan record not created with status DIPINJAM.");
    }

    // Verifikasi stok_tersedia berkurang tepat 1 unit
    $stmtCheck->execute([$idApd]);
    $stockAfterValid = (int)$stmtCheck->fetchColumn();
    if ($stockAfterValid !== ($initialStock - 1)) {
        throw new RuntimeException("FAIL (TC-01): stok_tersedia did not decrement correctly. Expected " . ($initialStock - 1) . ", got " . $stockAfterValid);
    }

    // 3. Test Overborrowing: jumlah_pinjam melebihi stok_tersedia
    $overPayload = [
        'nama_peminjam'  => 'Joko Tarub',
        'bagian_unit'    => 'Civil Engineering',
        'no_hp_peminjam' => '085211223344',
        'id_apd'         => $idApd,
        'jumlah_pinjam'  => 9999, // Melebihi stok
        'pin_gudang'     => '4821'
    ];

    $resultOver = processLoanSubmission($pdo, $overPayload);
    if ($resultOver['success'] === true) {
        throw new RuntimeException("FAIL: System allowed loan exceeding available stock.");
    }

    echo "PASS: Zero-login borrow flow verified (TC-01 and TC-02 passed).\n";
}

try {
    testBorrowFlow();
    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
