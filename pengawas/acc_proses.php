<?php
/**
 * Prosesor Otorisasi Verifikasi Pengembalian Satu Pintu (ACC Pengawas) & Rekonsiliasi Stok K3
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

/**
 * Logika ACC Pengembalian oleh Pengawas dengan rekonsiliasi stok otomatis
 *
 * @param PDO $pdo
 * @param int $idLoan
 * @param int $idPengawas
 * @param string $kondisi 'BAIK' | 'RUSAK_RINGAN' | 'RUSAK_BERAT' | 'HILANG'
 * @param string|null $keterangan
 * @return array{success: bool, message: string}
 */
function processReturnAcc(
    PDO $pdo,
    int $idLoan,
    int $idPengawas,
    string $kondisi,
    ?string $keterangan = null
): array {
    $allowedConditions = ['BAIK', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG'];
    if (!in_array($kondisi, $allowedConditions, true)) {
        return [
            'success' => false,
            'message' => 'Status kondisi fisik tidak valid. Pilihan: BAIK, RUSAK_RINGAN, RUSAK_BERAT, atau HILANG.'
        ];
    }

    try {
        $pdo->beginTransaction();

        // 1. Kunci dan validasi transaksi peminjaman
        $stmtLoan = $pdo->prepare("
            SELECT l.*, i.nama_apd, i.id_apd 
            FROM loans l
            JOIN apd_items i ON l.id_apd = i.id_apd
            WHERE l.id_loan = ? FOR UPDATE
        ");
        $stmtLoan->execute([$idLoan]);
        $loan = $stmtLoan->fetch(PDO::FETCH_ASSOC);

        if (!$loan) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Data transaksi peminjaman tidak ditemukan.'];
        }

        if ($loan['status_pinjam'] !== 'DIPINJAM') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Transaksi ini sudah selesai atau telah di-ACC sebelumnya.'];
        }

        $idApd = (int)$loan['id_apd'];
        $jumlahPinjam = (int)$loan['jumlah_pinjam'];

        // 2. Perbarui status pinjam di tabel loans
        $stmtUpdateLoan = $pdo->prepare("
            UPDATE loans 
            SET status_pinjam = 'SUDAH_DIKEMBALIKAN', 
                waktu_kembali = NOW(), 
                id_pengawas_acc = ? 
            WHERE id_loan = ?
        ");
        $stmtUpdateLoan->execute([$idPengawas, $idLoan]);

        // 3. Catat riwayat audit fisik di tabel condition_logs
        $stmtInsertLog = $pdo->prepare("
            INSERT INTO condition_logs (id_loan, id_pengawas, kondisi_kembali, keterangan, waktu_acc)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtInsertLog->execute([
            $idLoan,
            $idPengawas,
            $kondisi,
            $keterangan !== null ? trim($keterangan) : 'Pemeriksaan fisik standar oleh Pengawas'
        ]);

        // 4. Rekonsiliasi Stok Bercabang
        if ($kondisi === 'BAIK') {
            // Kondisi Baik: Tambah kembali ke stok_tersedia (+jumlah_pinjam)
            $stmtRestore = $pdo->prepare("
                UPDATE apd_items 
                SET stok_tersedia = stok_tersedia + ? 
                WHERE id_apd = ?
            ");
            $stmtRestore->execute([$jumlahPinjam, $idApd]);
        } else {
            // Kondisi Rusak/Hilang: Jangan pulihkan stok_tersedia, alihkan ke stok_rusak
            $stmtDamaged = $pdo->prepare("
                UPDATE apd_items 
                SET stok_rusak = stok_rusak + ? 
                WHERE id_apd = ?
            ");
            $stmtDamaged->execute([$jumlahPinjam, $idApd]);
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Persetujuan (ACC) pengembalian '{$loan['nama_apd']}' berhasil disimpan. Stok telah direkonsiliasi secara otomatis."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan sistem saat memproses ACC: ' . $e->getMessage()
        ];
    }
}

/**
 * Batalkan entri peminjaman fiktif atau salah input (Mitigasi Devil's Advocate Seat 5)
 */
function cancelFictitiousLoan(PDO $pdo, int $idLoan, int $idPengawas): array {
    try {
        $pdo->beginTransaction();

        $stmtLoan = $pdo->prepare("SELECT * FROM loans WHERE id_loan = ? FOR UPDATE");
        $stmtLoan->execute([$idLoan]);
        $loan = $stmtLoan->fetch(PDO::FETCH_ASSOC);

        if (!$loan || $loan['status_pinjam'] !== 'DIPINJAM') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Transaksi tidak valid untuk dibatalkan.'];
        }

        $idApd = (int)$loan['id_apd'];
        $jumlahPinjam = (int)$loan['jumlah_pinjam'];

        // Kembalikan stok_tersedia
        $stmtRestore = $pdo->prepare("UPDATE apd_items SET stok_tersedia = stok_tersedia + ? WHERE id_apd = ?");
        $stmtRestore->execute([$jumlahPinjam, $idApd]);

        // Hapus atau tandai loan
        $stmtDelete = $pdo->prepare("DELETE FROM loans WHERE id_loan = ?");
        $stmtDelete->execute([$idLoan]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Entri peminjaman fiktif/salah berhasil dibatalkan dan {$jumlahPinjam} unit stok telah dipulihkan."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Gagal membatalkan entri: ' . $e->getMessage()];
    }
}

// Handler request HTTP dari dashboard Pengawas
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    checkAuth(['pengawas', 'admin']);
    $pdo = getDbConnection();
    $currentUser = getLoggedInUser();

    $action      = (string)($_POST['action'] ?? 'acc_return');
    $idLoan      = (int)($_POST['id_loan'] ?? 0);
    $idPengawas  = (int)($currentUser['id_user'] ?? 0);

    if ($action === 'cancel_loan') {
        $result = cancelFictitiousLoan($pdo, $idLoan, $idPengawas);
    } else {
        $kondisi     = (string)($_POST['kondisi_kembali'] ?? 'BAIK');
        $keterangan  = trim((string)($_POST['keterangan'] ?? ''));
        $result = processReturnAcc($pdo, $idLoan, $idPengawas, $kondisi, $keterangan ?: null);
    }

    if ($result['success']) {
        $_SESSION['flash_success'] = $result['message'];
    } else {
        $_SESSION['flash_error'] = $result['message'];
    }

    header('Location: /pengawas/index.php');
    exit;
}
