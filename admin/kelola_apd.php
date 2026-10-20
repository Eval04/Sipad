<?php
/**
 * Modul Master APD, Pemulihan Stok Rusak & Pembuat Poster QR Code Ruang APD
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

checkAuth(['admin']);
$pdo = getDbConnection();
$currentUser = getLoggedInUser();

$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

// Handler POST Aksi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // 1. Tambah Master APD Baru
    if ($action === 'add_item') {
        $kodeApd     = trim((string)($_POST['kode_apd'] ?? ''));
        $namaApd     = trim((string)($_POST['nama_apd'] ?? ''));
        $idCategory  = (int)($_POST['id_category'] ?? 0);
        $ukuran      = trim((string)($_POST['ukuran_spesifikasi'] ?? ''));
        $stokAwal    = (int)($_POST['stok_awal'] ?? 0);

        if ($kodeApd === '' || $namaApd === '' || $idCategory <= 0 || $stokAwal < 0) {
            $_SESSION['flash_error'] = 'Lengkapi seluruh data peralatan APD dengan benar.';
        } else {
            // Cek keunikan kode_apd
            $stmtCek = $pdo->prepare("SELECT id_apd FROM apd_items WHERE kode_apd = ?");
            $stmtCek->execute([$kodeApd]);
            if ($stmtCek->fetch()) {
                $_SESSION['flash_error'] = "Kode APD '{$kodeApd}' sudah digunakan.";
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO apd_items (id_category, kode_apd, nama_apd, ukuran_spesifikasi, stok_total, stok_tersedia, stok_rusak)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $stmtInsert->execute([$idCategory, $kodeApd, $namaApd, $ukuran, $stokAwal, $stokAwal]);
                $_SESSION['flash_success'] = "Master APD '{$namaApd}' berhasil ditambahkan ke inventaris.";
            }
        }
        header('Location: /admin/kelola_apd.php');
        exit;
    }

    // 2. Pemulihan Stok Rusak Menjadi Siap Pakai (Repair Restoration)
    if ($action === 'repair_item') {
        $idApd       = (int)($_POST['id_apd'] ?? 0);
        $unitPulih   = (int)($_POST['jumlah_pulih'] ?? 0);

        if ($idApd <= 0 || $unitPulih <= 0) {
            $_SESSION['flash_error'] = 'Jumlah unit yang dipulihkan harus minimal 1 unit.';
        } else {
            // Ambil stok rusak saat ini
            $stmtCek = $pdo->prepare("SELECT nama_apd, stok_rusak FROM apd_items WHERE id_apd = ?");
            $stmtCek->execute([$idApd]);
            $item = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if (!$item || (int)$item['stok_rusak'] < $unitPulih) {
                $_SESSION['flash_error'] = 'Jumlah unit pemulihan melebihi jumlah stok rusak yang tercatat.';
            } else {
                $stmtRepair = $pdo->prepare("
                    UPDATE apd_items 
                    SET stok_rusak = stok_rusak - ?, stok_tersedia = stok_tersedia + ? 
                    WHERE id_apd = ? AND stok_rusak >= ?
                ");
                $stmtRepair->execute([$unitPulih, $unitPulih, $idApd, $unitPulih]);
                $_SESSION['flash_success'] = "Sebanyak {$unitPulih} unit '{$item['nama_apd']}' selesai diperbaiki dan telah dipulihkan ke stok siap pakai.";
            }
        }
        header('Location: /admin/kelola_apd.php');
        exit;
    }

    // 3. Tambah Kategori Baru
    if ($action === 'add_category') {
        $kodeKat = trim((string)($_POST['kode_kategori'] ?? ''));
        $namaKat = trim((string)($_POST['nama_kategori'] ?? ''));
        $ket     = trim((string)($_POST['keterangan'] ?? ''));

        if ($kodeKat === '' || $namaKat === '') {
            $_SESSION['flash_error'] = 'Kode dan nama kategori wajib diisi.';
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO apd_categories (kode_kategori, nama_kategori, keterangan) VALUES (?, ?, ?)");
            $stmtInsert->execute([$kodeKat, $namaKat, $ket]);
            $_SESSION['flash_success'] = "Kategori '{$namaKat}' berhasil ditambahkan.";
        }
        header('Location: /admin/kelola_apd.php');
        exit;
    }
}

// Ambil data kategori & inventaris
$categories = $pdo->query("SELECT * FROM apd_categories ORDER BY id_category ASC")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("
    SELECT i.*, c.nama_kategori, c.kode_kategori 
    FROM apd_items i 
    JOIN apd_categories c ON i.id_category = c.id_category 
    ORDER BY i.stok_rusak DESC, c.id_category ASC, i.nama_apd ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Ambil PIN gudang untuk template cetak poster
$wifiRow = $pdo->query("SELECT kode_pin_gudang FROM wifi_settings WHERE id_setting = 1")->fetch(PDO::FETCH_ASSOC);
$pinGudang = $wifiRow['kode_pin_gudang'] ?? '4821';

// URL web lokal
$hostUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000') . "/index.php";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Master APD & Cetak Poster QR - Administrator SIPAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            body { background: white !important; color: black !important; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col">

    <!-- Admin Top Navbar (no-print) -->
    <header class="no-print bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-white block leading-tight">MASTER INVENTARIS APD</span>
                    <span class="text-[10px] text-amber-400 font-semibold tracking-wider uppercase">Katalog Peralatan & Pemulihan Stok</span>
                </div>
            </div>

            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-slate-300">
                <a href="/admin/index.php" class="hover:text-amber-400 transition-colors">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="text-amber-400">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="hover:text-amber-400 transition-colors">Kelola Pengawas & WiFi</a>
                <a href="/admin/laporan.php" class="hover:text-amber-400 transition-colors">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="#poster-qr" class="text-xs px-3 py-1.5 rounded-lg bg-amber-500 text-slate-950 font-bold hover:bg-amber-400 transition-all">
                    Cetak Poster Pintu
                </a>
                <a href="/logout.php" class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white border border-slate-700 transition-all">
                    Keluar
                </a>
            </div>
        </div>
    </header>

    <main class="no-print max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow space-y-8 w-full">

        <!-- Flash Alert -->
        <?php if ($success): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-sm flex items-center gap-3">
                <svg class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-red-500/15 border border-red-500/30 text-red-200 text-sm flex items-center gap-3">
                <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: DAFTAR MASTER APD & PEMULIHAN STOK RUSAK -->
        <div class="bg-slate-900 border border-slate-800 rounded-3xl shadow-xl overflow-hidden p-6 sm:p-7 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
                <div>
                    <h2 class="text-xl font-bold text-white">Master Peralatan APD Operasional</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Kelola stok total, stok layak pakai (tersedia), dan pulihkan unit yang telah selesai diperbaiki.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button onclick="document.getElementById('form-tambah-apd').classList.toggle('hidden')"
                        class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs shadow-md transition-all flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>+ Tambah APD Baru</span>
                    </button>
                </div>
            </div>

            <!-- FORM TAMBAH APD BARU (COLLAPSIBLE) -->
            <div id="form-tambah-apd" class="hidden p-5 rounded-2xl bg-slate-950 border border-slate-800 space-y-4">
                <h3 class="text-sm font-bold text-amber-400">Tambah Peralatan APD Baru ke Gudang</h3>
                <form action="kelola_apd.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <input type="hidden" name="action" value="add_item">

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Kode Unik APD</label>
                        <input type="text" name="kode_apd" required placeholder="Contoh: APD-HLM-03"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Nama Peralatan APD</label>
                        <input type="text" name="nama_apd" required placeholder="Contoh: Safety Helmet Biru"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Kategori Perlindungan</label>
                        <select name="id_category" required
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id_category'] ?>"><?= htmlspecialchars($cat['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Ukuran / Spesifikasi</label>
                        <input type="text" name="ukuran_spesifikasi" placeholder="Contoh: Size L / ANSI Z87"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Stok Awal Masuk</label>
                        <input type="number" name="stok_awal" min="0" value="10" required
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div class="sm:col-span-3 flex justify-end gap-3 pt-2">
                        <button type="button" onclick="document.getElementById('form-tambah-apd').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">
                            Batal
                        </button>
                        <button type="submit"
                            class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md">
                            Simpan ke Inventaris
                        </button>
                    </div>
                </form>
            </div>

            <!-- TABEL MASTER APD -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider border-b border-slate-800 font-semibold">
                        <tr>
                            <th class="px-5 py-3">Kode & Nama APD</th>
                            <th class="px-5 py-3">Kategori</th>
                            <th class="px-5 py-3">Spesifikasi</th>
                            <th class="px-5 py-3 text-center">Stok Total</th>
                            <th class="px-5 py-3 text-center">Tersedia (Baik)</th>
                            <th class="px-5 py-3 text-center">Stok Rusak</th>
                            <th class="px-5 py-3 text-right">Aksi Pemulihan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($items as $itm): ?>
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-white"><?= htmlspecialchars($itm['nama_apd']) ?></div>
                                    <div class="font-mono text-[11px] text-amber-400"><?= htmlspecialchars($itm['kode_apd']) ?></div>
                                </td>
                                <td class="px-5 py-3.5"><?= htmlspecialchars($itm['nama_kategori']) ?></td>
                                <td class="px-5 py-3.5 text-slate-400"><?= htmlspecialchars($itm['ukuran_spesifikasi'] ?? '-') ?></td>
                                <td class="px-5 py-3.5 text-center font-bold text-slate-200"><?= $itm['stok_total'] ?></td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full font-bold <?= ($itm['stok_tersedia'] > 0) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                                        <?= $itm['stok_tersedia'] ?> Unit
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <?php if ($itm['stok_rusak'] > 0): ?>
                                        <span class="px-2.5 py-0.5 rounded-full font-bold bg-red-500/20 text-red-300 border border-red-500/40 animate-pulse">
                                            <?= $itm['stok_rusak'] ?> Unit Rusak
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-500">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <?php if ($itm['stok_rusak'] > 0): ?>
                                        <button onclick="openModalPulihkan('<?= $itm['id_apd'] ?>', '<?= htmlspecialchars(addslashes($itm['nama_apd'])) ?>', '<?= $itm['stok_rusak'] ?>')"
                                            class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] shadow-sm transition-all">
                                            Pulihkan Stok Rusak
                                        </button>
                                    <?php else: ?>
                                        <span class="text-[11px] text-slate-500 italic">Kondisi Prima</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SECTION 2: TEMPLATE CETAK POSTER QR CODE RUANG APD -->
        <div id="poster-qr" class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400 px-2 py-0.5 rounded bg-amber-400/10 border border-amber-400/20">
                        Media Cetak Gudang K3
                    </span>
                    <h2 class="text-xl font-bold text-white mt-1">Poster QR Code & PIN Pintu Gudang APD</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Cetak lembar ini dan tempelkan pada pintu atau meja serah terima ruang penyimpanan APD.
                    </p>
                </div>
                <button onclick="window.print()"
                    class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs shadow-lg shadow-amber-500/20 flex items-center gap-2 self-start">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Cetak Poster Ini (A4)</span>
                </button>
            </div>

            <!-- Preview Tampilan Poster Fisik -->
            <div class="max-w-xl mx-auto bg-white text-slate-900 p-8 rounded-3xl border-4 border-amber-500 shadow-2xl space-y-6 text-center">
                <div class="space-y-1 border-b-2 border-slate-200 pb-4">
                    <div class="inline-block px-3 py-1 rounded bg-amber-500 text-slate-950 font-extrabold text-xs tracking-widest uppercase">
                        SISTEM KESELAMATAN & KESEHATAN KERJA (K3)
                    </div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-950">RUANG ALAT PELINDUNG DIRI</h3>
                    <p class="text-xs text-slate-600 font-semibold">Peminjaman Mandiri Cepat Tanpa Login (Zero-Login Self Service)</p>
                </div>

                <div class="py-4 space-y-4">
                    <div class="w-48 h-48 mx-auto bg-slate-100 border-2 border-dashed border-slate-400 rounded-2xl flex flex-col items-center justify-center p-3">
                        <!-- QR Code Generator via SVG API / QuickChart -->
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($hostUrl) ?>"
                            alt="QR Code Peminjaman APD" class="w-40 h-40 rounded-lg">
                    </div>
                    <p class="text-xs text-slate-600 font-medium">
                        Scan QR Code di atas menggunakan kamera ponsel Anda untuk membuka form peminjaman mandiri.
                    </p>
                </div>

                <!-- Box Kode PIN Fisik -->
                <div class="bg-amber-50 border-2 border-amber-500 rounded-2xl p-5 space-y-1">
                    <span class="text-xs font-bold text-amber-900 tracking-wider uppercase block">KODE PIN RUANG APD:</span>
                    <span class="text-5xl font-black font-mono text-amber-600 tracking-widest block">
                        <?= htmlspecialchars($pinGudang) ?>
                    </span>
                    <span class="text-[11px] text-amber-800 block pt-1">
                        Ketik 4 digit angka di atas pada form peminjaman sebagai bukti kehadiran di gudang.
                    </span>
                </div>

                <div class="pt-2 text-[11px] text-slate-500 border-t border-slate-200 leading-normal">
                    <strong>PENTING:</strong> Kembalikan seluruh APD yang dipinjam kepada Pengawas K3 untuk diperiksa kondisi kelayakan fisiknya saat jam kerja berakhir.
                </div>
            </div>
        </div>

    </main>

    <!-- Modal Pulihkan Stok Rusak -->
    <div id="modal-pulihkan" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-emerald-400 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span>Pemulihan APD Selesai Diperbaiki</span>
            </h3>

            <div class="text-xs text-slate-300">
                Barang: <strong id="modal-repair-nama" class="text-white">-</strong><br>
                Jumlah Unit Rusak Saat Ini: <strong id="modal-repair-max" class="text-red-400">0</strong> Unit
            </div>

            <form action="kelola_apd.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="repair_item">
                <input type="hidden" id="modal-repair-id" name="id_apd" value="">

                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Jumlah Unit yang Siap Dipulihkan ke Rak</label>
                    <input type="number" id="modal-repair-input" name="jumlah_pulih" min="1" max="1" value="1" required
                        class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-emerald-500">
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModalPulihkan()"
                        class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">
                        Batal
                    </button>
                    <button type="submit"
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md">
                        Konfirmasi Pemulihan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModalPulihkan(idApd, namaApd, maxRusak) {
            document.getElementById('modal-repair-id').value = idApd;
            document.getElementById('modal-repair-nama').textContent = namaApd;
            document.getElementById('modal-repair-max').textContent = maxRusak;
            const inputEl = document.getElementById('modal-repair-input');
            inputEl.max = maxRusak;
            inputEl.value = maxRusak;

            const modal = document.getElementById('modal-pulihkan');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModalPulihkan() {
            const modal = document.getElementById('modal-pulihkan');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
</body>
</html>
