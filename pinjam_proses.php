<?php
/**
 * Prosesor Transaksi Peminjaman Mandiri Tanpa Login (Zero-Login Guest Self-Service)
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

/**
 * Logika inti pemrosesan form peminjaman dengan transaksi atomik PDO
 *
 * @param PDO $pdo
 * @param array $postData
 * @return array{success: bool, kode_pinjam?: string, message: string}
 */
function processLoanSubmission(PDO $pdo, array $postData): array {
    $namaPeminjam  = trim((string)($postData['nama_peminjam'] ?? ''));
    $bagianUnit    = trim((string)($postData['bagian_unit'] ?? ''));
    $noHpPeminjam  = trim((string)($postData['no_hp_peminjam'] ?? ''));
    $idApd         = (int)($postData['id_apd'] ?? 0);
    $jumlahPinjam  = (int)($postData['jumlah_pinjam'] ?? 1);
    $pinInput      = trim((string)($postData['pin_gudang'] ?? ''));

    // 1. Validasi Kelengkapan Masukan
    if ($namaPeminjam === '' || $bagianUnit === '' || $noHpPeminjam === '' || $idApd <= 0) {
        return [
            'success' => false,
            'message' => 'Harap lengkapi semua kolom formulir (Nama, Bagian, No. HP, dan Pilihan APD).'
        ];
    }

    if ($jumlahPinjam <= 0) {
        return [
            'success' => false,
            'message' => 'Jumlah barang yang dipinjam minimal 1 unit.'
        ];
    }

    // 2. Validasi Kode PIN Fisik Ruang APD (Mencegah Entri Fiktif dari Luar Gudang)
    $stmtPin = $pdo->query("SELECT kode_pin_gudang FROM wifi_settings LIMIT 1");
    $pinSetting = $stmtPin->fetch(PDO::FETCH_ASSOC);
    $validPin = trim((string)($pinSetting['kode_pin_gudang'] ?? '4821'));

    if ($pinInput !== $validPin) {
        return [
            'success' => false,
            'message' => 'Kode PIN Ruang APD salah! Pastikan Anda memasukkan 4 digit angka PIN yang tertempel di pintu/meja ruang APD.'
        ];
    }

    // 3. Eksekusi Transaksi Atomik Basis Data
    try {
        $pdo->beginTransaction();

        // Kunci baris APD dengan FOR UPDATE untuk mencegah race condition
        $stmtItem = $pdo->prepare("SELECT id_apd, nama_apd, stok_tersedia FROM apd_items WHERE id_apd = ? FOR UPDATE");
        $stmtItem->execute([$idApd]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Peralatan APD yang dipilih tidak ditemukan dalam sistem.'
            ];
        }

        $stokTersedia = (int)$item['stok_tersedia'];

        if ($stokTersedia < $jumlahPinjam) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => "Stok '{$item['nama_apd']}' tidak mencukupi (Tersisa: {$stokTersedia} unit, Diminta: {$jumlahPinjam} unit)."
            ];
        }

        // Generate Kode Pinjam Unik: PINJ-YYYYMMDD-XXXXX
        $kodePinjam = sprintf(
            'PINJ-%s-%s',
            date('Ymd'),
            strtoupper(substr(bin2hex(random_bytes(3)), 0, 5))
        );

        // Masukkan data transaksi ke tabel loans
        $stmtInsert = $pdo->prepare("
            INSERT INTO loans (
                id_apd, kode_pinjam, nama_peminjam, bagian_unit,
                no_hp_peminjam, jumlah_pinjam, waktu_pinjam, status_pinjam
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'DIPINJAM')
        ");
        $stmtInsert->execute([
            $idApd,
            $kodePinjam,
            $namaPeminjam,
            $bagianUnit,
            $noHpPeminjam,
            $jumlahPinjam
        ]);

        // Kurangi stok_tersedia secara serentak
        $stmtUpdateStock = $pdo->prepare("
            UPDATE apd_items 
            SET stok_tersedia = stok_tersedia - ? 
            WHERE id_apd = ?
        ");
        $stmtUpdateStock->execute([$jumlahPinjam, $idApd]);

        $pdo->commit();

        return [
            'success'     => true,
            'kode_pinjam' => $kodePinjam,
            'nama_apd'    => $item['nama_apd'],
            'jumlah'      => $jumlahPinjam,
            'message'     => 'Peminjaman APD berhasil dicatat! Simpan Kode Pinjam untuk pengecekan dan serah terima pengembalian.'
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Terjadi kesalahan sistem saat memproses peminjaman: ' . $e->getMessage()
        ];
    }
}

// Handler request HTTP dari form browser
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    startAppSession();
    $pdo = getDbConnection();

    $result = processLoanSubmission($pdo, $_POST);

    if ($result['success']) {
        $_SESSION['flash_success_loan'] = [
            'kode_pinjam' => $result['kode_pinjam'],
            'nama_apd'    => $result['nama_apd'] ?? '',
            'jumlah'      => $result['jumlah'] ?? 1,
            'peminjam'    => htmlspecialchars($_POST['nama_peminjam'] ?? '')
        ];
        header('Location: /status_pinjam.php?kode=' . urlencode($result['kode_pinjam']));
    } else {
        $_SESSION['flash_error'] = $result['message'];
        $_SESSION['form_old_input'] = $_POST;
        header('Location: /index.php#form-pinjam');
    }
    exit;
}
