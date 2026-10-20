<?php
/**
 * Prosesor Transaksi Peminjaman Mandiri Tanpa Login (Zero-Login Guest Self-Service)
 * Mendukung Pemilihan Multi-Item APD Sekaligus dalam 1 Transaksi
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

/**
 * Logika inti pemrosesan form peminjaman dengan transaksi atomik PDO
 * Mendukung peminjaman multi-item atau single-item secara serentak
 *
 * @param PDO $pdo
 * @param array $postData
 * @return array{success: bool, kode_pinjam?: string, item_count?: int, total_units?: int, message: string}
 */
function processLoanSubmission(PDO $pdo, array $postData): array {
    $namaPeminjam  = trim((string)($postData['nama_peminjam'] ?? ''));
    $bagianUnit    = trim((string)($postData['bagian_unit'] ?? ''));
    $noHpPeminjam  = trim((string)($postData['no_hp_peminjam'] ?? ''));
    $pinInput      = trim((string)($postData['pin_gudang'] ?? $postData['kode_pin'] ?? ''));

    // 1. Validasi Kelengkapan Identitas Peminjam
    if ($namaPeminjam === '' || $bagianUnit === '' || $noHpPeminjam === '') {
        return [
            'success' => false,
            'message' => 'Harap lengkapi semua data peminjam (Nama Lengkap, Bagian/Unit Kerja, dan No. WhatsApp).'
        ];
    }

    // 2. Parsing Peralatan APD yang Dipilih (Mendukung Multi-Item & Single-Item)
    $selectedItems = []; // Format: [id_apd => qty]

    if (!empty($postData['items']) && is_array($postData['items'])) {
        foreach ($postData['items'] as $rawId => $rawQty) {
            $id = (int)$rawId;
            $qty = (int)$rawQty;
            if ($id > 0 && $qty > 0) {
                $selectedItems[$id] = $qty;
            }
        }
    } elseif (!empty($postData['selected_apd']) && is_array($postData['selected_apd'])) {
        $qtyMap = is_array($postData['qty'] ?? null) ? $postData['qty'] : [];
        foreach ($postData['selected_apd'] as $rawId) {
            $id = (int)$rawId;
            $qty = isset($qtyMap[$id]) ? (int)$qtyMap[$id] : 1;
            if ($id > 0 && $qty > 0) {
                $selectedItems[$id] = $qty;
            }
        }
    } elseif (!empty($postData['id_apd'])) {
        // Fallback untuk single-item legacy & backward-compatibility blackbox test
        $id = (int)$postData['id_apd'];
        $qty = (int)($postData['jumlah_pinjam'] ?? 1);
        if ($id > 0 && $qty > 0) {
            $selectedItems[$id] = $qty;
        }
    }

    if (empty($selectedItems)) {
        return [
            'success' => false,
            'message' => 'Pilih minimal satu peralatan APD dan tentukan jumlah unit yang akan dipinjam.'
        ];
    }

    // 3. Validasi Kode PIN Fisik Ruang APD (Mencegah Entri Fiktif dari Luar Gudang)
    $stmtPin = $pdo->query("SELECT kode_pin_gudang FROM wifi_settings LIMIT 1");
    $pinSetting = $stmtPin->fetch(PDO::FETCH_ASSOC);
    $validPin = trim((string)($pinSetting['kode_pin_gudang'] ?? '4821'));

    if ($pinInput !== $validPin) {
        return [
            'success' => false,
            'message' => 'Kode PIN Ruang APD salah! Pastikan Anda memasukkan 4 digit angka PIN yang tertempel di pintu atau meja ruang APD.'
        ];
    }

    // 4. Eksekusi Transaksi Atomik Basis Data
    try {
        $pdo->beginTransaction();

        $itemsToInsert = [];

        // Validasi stok seluruh item yang dipilih secara atomik (FOR UPDATE)
        foreach ($selectedItems as $idApd => $jumlahPinjam) {
            $stmtItem = $pdo->prepare("SELECT id_apd, nama_apd, stok_tersedia FROM apd_items WHERE id_apd = ? FOR UPDATE");
            $stmtItem->execute([$idApd]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => "Peralatan APD dengan ID {$idApd} tidak ditemukan dalam sistem."
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

            $itemsToInsert[] = [
                'id_apd'       => $idApd,
                'nama_apd'     => $item['nama_apd'],
                'jumlah'       => $jumlahPinjam
            ];
        }

        // Generate Kode Pinjam Unik untuk batch transaksi ini: PINJ-YYYYMMDD-XXXXX
        $kodePinjam = sprintf(
            'PINJ-%s-%s',
            date('Ymd'),
            strtoupper(substr(bin2hex(random_bytes(3)), 0, 5))
        );

        $stmtInsert = $pdo->prepare("
            INSERT INTO loans (
                id_apd, kode_pinjam, nama_peminjam, bagian_unit,
                no_hp_peminjam, jumlah_pinjam, waktu_pinjam, status_pinjam
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'DIPINJAM')
        ");

        $stmtUpdateStock = $pdo->prepare("
            UPDATE apd_items 
            SET stok_tersedia = stok_tersedia - ? 
            WHERE id_apd = ?
        ");

        $totalUnits = 0;
        foreach ($itemsToInsert as $ins) {
            $stmtInsert->execute([
                $ins['id_apd'],
                $kodePinjam,
                $namaPeminjam,
                $bagianUnit,
                $noHpPeminjam,
                $ins['jumlah']
            ]);

            $stmtUpdateStock->execute([$ins['jumlah'], $ins['id_apd']]);
            $totalUnits += $ins['jumlah'];
        }

        $pdo->commit();

        return [
            'success'     => true,
            'kode_pinjam' => $kodePinjam,
            'item_count'  => count($itemsToInsert),
            'total_units' => $totalUnits,
            'nama_apd'    => $itemsToInsert[0]['nama_apd'] ?? '',
            'jumlah'      => $itemsToInsert[0]['jumlah'] ?? 1,
            'message'     => 'Peminjaman ' . count($itemsToInsert) . ' jenis APD (' . $totalUnits . ' unit) berhasil dicatat! Simpan Kode Pinjam untuk pengecekan dan serah terima pengembalian.'
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
            'item_count'  => $result['item_count'] ?? 1,
            'total_units' => $result['total_units'] ?? 1,
            'peminjam'    => htmlspecialchars($_POST['nama_peminjam'] ?? '')
        ];
        header('Location: /status_pinjam.php?kode=' . urlencode($result['kode_pinjam']));
    } else {
        $_SESSION['flash_error'] = $result['message'];
        $_SESSION['form_old_input'] = $_POST;
        header('Location: /index.php');
    }
    exit;
}
