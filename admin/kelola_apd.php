<?php
/**
 * Modul Master APD, Pemulihan Stok Rusak & Pembuat Poster QR Code Ruang APD
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 * Tema Korporat Resmi: PT Pelabuhan Indonesia (Pelindo) Light Palette
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

    // 4. Hapus Peralatan APD Satuan
    if ($action === 'delete_item') {
        $idApd = (int)($_POST['id_apd'] ?? 0);
        if ($idApd > 0) {
            $stmtCek = $pdo->prepare("SELECT nama_apd FROM apd_items WHERE id_apd = ?");
            $stmtCek->execute([$idApd]);
            $item = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $pdo->beginTransaction();
                try {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $stmtDelLogs = $pdo->prepare("DELETE cl FROM condition_logs cl JOIN loans l ON cl.id_loan = l.id_loan WHERE l.id_apd = ?");
                    $stmtDelLogs->execute([$idApd]);
                    $stmtDelLoans = $pdo->prepare("DELETE FROM loans WHERE id_apd = ?");
                    $stmtDelLoans->execute([$idApd]);
                    $stmtDelItem = $pdo->prepare("DELETE FROM apd_items WHERE id_apd = ?");
                    $stmtDelItem->execute([$idApd]);
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $pdo->commit();
                    $_SESSION['flash_success'] = "Peralatan APD '{$item['nama_apd']}' berhasil dihapus dari inventaris.";
                } catch (Throwable $e) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $pdo->rollBack();
                    $_SESSION['flash_error'] = "Gagal menghapus APD: " . $e->getMessage();
                }
            }
        }
        header('Location: /admin/kelola_apd.php');
        exit;
    }

    // 5. Kosongkan Seluruh Master APD (Kategori Tetap Aman)
    if ($action === 'wipe_apd') {
        $confirm = trim((string)($_POST['confirm_wipe_apd'] ?? ''));
        if (strtoupper($confirm) !== 'HAPUS') {
            $_SESSION['flash_error'] = 'Konfirmasi gagal. Ketik kata "HAPUS" untuk mengosongkan seluruh master APD.';
        } else {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec("DELETE FROM condition_logs");
                $pdo->exec("ALTER TABLE condition_logs AUTO_INCREMENT = 1");
                $pdo->exec("DELETE FROM loans");
                $pdo->exec("ALTER TABLE loans AUTO_INCREMENT = 1");
                $pdo->exec("DELETE FROM apd_items");
                $pdo->exec("ALTER TABLE apd_items AUTO_INCREMENT = 1");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $_SESSION['flash_success'] = "Seluruh data Master APD dan riwayat peminjaman berhasil dikosongkan. Kategori APD tetap utuh sehingga Anda dapat langsung menginput peralatan APD baru.";
            } catch (Throwable $e) {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $_SESSION['flash_error'] = "Gagal mengosongkan APD: " . $e->getMessage();
            }
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
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Kelola Master APD & Poster QR - Pelindo K3</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Top Admin Header Pelindo (no-print) -->
    <header class="no-print bg-[#003e74] text-white sticky top-0 z-40 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="/admin/index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo" class="w-7 h-7">
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <img src="/assets/img/pelindo-logo-white.svg" alt="Pelindo" class="h-4 sm:h-5 w-auto">
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">MASTER APD</span>
                    </div>
                    <span class="text-[10px] text-sky-200 font-mono tracking-wider uppercase block">PT Pelabuhan Indonesia (Persero)</span>
                </div>
            </a>

            <!-- Desktop Nav Menu -->
            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-sky-100">
                <a href="/admin/index.php" class="hover:text-white transition-colors">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="text-white font-bold border-b-2 border-sky-300 pb-1">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="hover:text-white transition-colors">Pengawas & WiFi</a>
                <a href="/admin/laporan.php" class="hover:text-white transition-colors">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-2.5">
                <a href="#poster-qr" class="text-xs px-3 py-1.5 rounded-lg bg-sky-500 hover:bg-sky-400 text-white font-bold transition-colors flex items-center gap-1.5 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span class="hidden sm:inline">Poster QR</span>
                </a>
                <a href="/logout.php" class="text-xs p-2 sm:px-3 sm:py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white transition-colors flex items-center gap-1 font-semibold" title="Keluar">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013 3v1"/>
                    </svg>
                    <span class="hidden sm:inline">Keluar</span>
                </a>
            </div>
        </div>
    </header>

    <main class="no-print max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-grow space-y-6 w-full">

        <!-- Flash Messages -->
        <?php if ($success): ?>
            <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm flex items-center gap-3 shadow-sm">
                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div class="leading-relaxed font-medium"><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs sm:text-sm flex items-center gap-3 shadow-sm">
                <svg class="w-5 h-5 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="leading-relaxed font-medium"><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: MASTER INVENTARIS APD & STOK REPAIR -->
        <section class="bg-white rounded-2xl p-5 sm:p-6 space-y-5 border border-slate-200 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900">Daftar Inventaris Alat Pelindung Diri</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Kelola kuantitas fisik, pantau unit rusak, dan pulihkan stok yang selesai diperbaiki</p>
                </div>
                <button onclick="document.getElementById('form-tambah-apd').classList.toggle('hidden')"
                    class="btn-pelindo-primary px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 self-start sm:self-auto font-bold shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>+ Tambah APD Baru</span>
                </button>
            </div>

            <!-- COLLAPSIBLE FORM TAMBAH APD BARU -->
            <div id="form-tambah-apd" class="hidden p-4 sm:p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                    <h3 class="text-xs sm:text-sm font-bold text-[#003e74] flex items-center gap-2">
                        <svg class="w-4 h-4 text-[#005b96]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        <span>Formulir Tambah Unit APD Baru</span>
                    </h3>
                    <button type="button" onclick="document.getElementById('form-tambah-apd').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xs font-semibold">
                        Tutup
                    </button>
                </div>

                <form action="kelola_apd.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <input type="hidden" name="action" value="add_item">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kode Unik APD</label>
                        <input type="text" name="kode_apd" required placeholder="Contoh: APD-HLM-03"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Nama Peralatan APD</label>
                        <input type="text" name="nama_apd" required placeholder="Contoh: Safety Helmet Biru"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kategori Perlindungan</label>
                        <select name="id_category" required class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id_category'] ?>"><?= htmlspecialchars($cat['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Ukuran / Spesifikasi</label>
                        <input type="text" name="ukuran_spesifikasi" placeholder="Contoh: Size L / ANSI Z87"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Stok Awal Masuk (Unit)</label>
                        <input type="number" name="stok_awal" min="0" value="10" required
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div class="sm:col-span-3 flex justify-end gap-2.5 pt-2">
                        <button type="button" onclick="document.getElementById('form-tambah-apd').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs font-semibold">
                            Batal
                        </button>
                        <button type="submit" class="btn-pelindo-primary px-5 py-2 rounded-xl text-xs font-bold shadow-sm">
                            Simpan ke Inventaris
                        </button>
                    </div>
                </form>
            </div>

            <!-- MOBILE VIEW: INVENTORY CARDS (<640px) -->
            <div class="block sm:hidden space-y-3">
                <?php foreach ($items as $itm): ?>
                    <div class="p-4 rounded-xl bg-white border border-slate-200 space-y-3 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($itm['nama_apd']) ?></h3>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="font-mono text-[11px] text-[#005b96] font-bold"><?= htmlspecialchars($itm['kode_apd']) ?></span>
                                    <span class="text-slate-400 text-[11px]">&bull; <?= htmlspecialchars($itm['nama_kategori']) ?></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] text-slate-600 px-2 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono">
                                    <?= htmlspecialchars($itm['ukuran_spesifikasi'] ?? 'Standard') ?>
                                </span>
                                <form action="kelola_apd.php" method="POST" class="inline" onsubmit="return confirm('Hapus peralatan APD \'<?= htmlspecialchars(addslashes($itm['nama_apd'])) ?>\' dari inventaris?');">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="id_apd" value="<?= $itm['id_apd'] ?>">
                                    <button type="submit" class="p-1 rounded-md text-red-500 hover:text-red-700 hover:bg-red-50" title="Hapus APD">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- 3 Stock Level Pills -->
                        <div class="grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="p-2 rounded-lg bg-slate-50 border border-slate-200">
                                <span class="text-[10px] text-slate-500 block uppercase">Total</span>
                                <strong class="text-slate-900 text-sm block font-bold"><?= $itm['stok_total'] ?></strong>
                            </div>
                            <div class="p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                                <span class="text-[10px] text-emerald-700 block uppercase font-medium">Siap Pakai</span>
                                <strong class="text-emerald-700 text-sm block font-bold"><?= $itm['stok_tersedia'] ?></strong>
                            </div>
                            <div class="p-2 rounded-lg <?= ($itm['stok_rusak'] > 0) ? 'bg-red-50 border border-red-200' : 'bg-slate-50 border border-slate-200' ?>">
                                <span class="text-[10px] <?= ($itm['stok_rusak'] > 0) ? 'text-red-700' : 'text-slate-500' ?> block uppercase font-medium">Rusak</span>
                                <strong class="<?= ($itm['stok_rusak'] > 0) ? 'text-red-700 font-bold' : 'text-slate-400' ?> text-sm block">
                                    <?= $itm['stok_rusak'] ?>
                                </strong>
                            </div>
                        </div>

                        <!-- Action if damaged -->
                        <?php if ($itm['stok_rusak'] > 0): ?>
                            <button onclick="openModalPulihkan('<?= $itm['id_apd'] ?>', '<?= htmlspecialchars(addslashes($itm['nama_apd'])) ?>', '<?= $itm['stok_rusak'] ?>')"
                                class="w-full py-2.5 rounded-xl text-xs flex items-center justify-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                <span>Pulihkan <?= $itm['stok_rusak'] ?> Unit Selesai Servis</span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- DESKTOP VIEW: STRUCTURED TABLE (>=640px) -->
            <div class="hidden sm:block overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider border-b border-slate-200 font-semibold text-[11px]">
                        <tr>
                            <th class="px-5 py-3">Kode & Nama APD</th>
                            <th class="px-5 py-3">Kategori</th>
                            <th class="px-5 py-3">Spesifikasi</th>
                            <th class="px-5 py-3 text-center">Stok Total</th>
                            <th class="px-5 py-3 text-center">Tersedia (Siap)</th>
                            <th class="px-5 py-3 text-center">Stok Rusak</th>
                            <th class="px-5 py-3 text-right">Aksi / Kontrol</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($items as $itm): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($itm['nama_apd']) ?></div>
                                    <div class="font-mono text-[11px] text-[#005b96] font-semibold"><?= htmlspecialchars($itm['kode_apd']) ?></div>
                                </td>
                                <td class="px-5 py-3.5"><?= htmlspecialchars($itm['nama_kategori']) ?></td>
                                <td class="px-5 py-3.5 text-slate-500"><?= htmlspecialchars($itm['ukuran_spesifikasi'] ?? '-') ?></td>
                                <td class="px-5 py-3.5 text-center font-bold text-slate-900"><?= $itm['stok_total'] ?></td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="px-2.5 py-0.5 rounded-full font-bold <?= ($itm['stok_tersedia'] > 0) ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $itm['stok_tersedia'] ?> Unit
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <?php if ($itm['stok_rusak'] > 0): ?>
                                        <span class="px-2.5 py-0.5 rounded-full font-bold bg-red-100 text-red-800 border border-red-200">
                                            <?= $itm['stok_rusak'] ?> Rusak
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if ($itm['stok_rusak'] > 0): ?>
                                            <button onclick="openModalPulihkan('<?= $itm['id_apd'] ?>', '<?= htmlspecialchars(addslashes($itm['nama_apd'])) ?>', '<?= $itm['stok_rusak'] ?>')"
                                                class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] shadow-sm transition-colors">
                                                Pulihkan
                                            </button>
                                        <?php endif; ?>
                                        <form action="kelola_apd.php" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus alat APD \'<?= htmlspecialchars(addslashes($itm['nama_apd'])) ?>\' dari inventaris?');">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="id_apd" value="<?= $itm['id_apd'] ?>">
                                            <button type="submit" class="p-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 transition-colors border border-red-200" title="Hapus Barang APD">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Danger Zone: Kosongkan Seluruh Master APD (Kategori Tetap Aman) -->
            <div class="pt-4 border-t border-slate-200">
                <details class="group bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs">
                    <summary class="font-bold text-slate-700 cursor-pointer flex items-center justify-between">
                        <span class="flex items-center gap-2 text-red-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            <span>Opsi Bersihkan: Kosongkan Seluruh Master APD (Kategori Tetap Aman)</span>
                        </span>
                        <span class="text-slate-400 group-open:rotate-180 transition-transform">&darr;</span>
                    </summary>
                    <form action="kelola_apd.php" method="POST" class="mt-3 pt-3 border-t border-slate-200 space-y-3" onsubmit="return confirm('PERINGATAN!\n\nSeluruh data peralatan APD akan dikosongkan. Kategori APD akan tetap disimpan utuh.\nLanjutkan?');">
                        <input type="hidden" name="action" value="wipe_apd">
                        <p class="text-[11px] text-slate-600 leading-relaxed">
                            Gunakan ini jika Anda ingin mengosongkan seluruh peralatan APD untuk mengisi ulang secara manual. <strong>Kategori APD tidak akan dihapus</strong> sehingga Anda tetap dapat langsung menambahkan alat baru dari dropdown kategori.
                        </p>
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <input type="text" name="confirm_wipe_apd" required placeholder="Ketik HAPUS untuk konfirmasi"
                                class="sipad-input px-3 py-2 rounded-xl text-xs bg-white text-slate-900 border border-red-300 font-mono font-bold uppercase placeholder:normal-case placeholder:font-sans">
                            <button type="submit" class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-xs shadow-sm transition-colors whitespace-nowrap">
                                Kosongkan Semua APD
                            </button>
                        </div>
                    </form>
                </details>
            </div>
        </section>

        <!-- SECTION 2: TEMPLATE CETAK POSTER QR CODE RUANG APD -->
        <section id="poster-qr" class="bg-white rounded-2xl p-5 sm:p-7 space-y-6 border border-slate-200 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-[#003e74] px-2 py-0.5 rounded bg-sky-100">
                        Media Cetak Pintu Gudang
                    </span>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 mt-1">Poster QR Code & PIN Pintu Ruang APD</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Cetak lembar A4 ini dan tempelkan di pintu ruang APD untuk peminjaman mandiri pekerja</p>
                </div>
                <button onclick="window.print()"
                    class="btn-pelindo-primary px-5 py-2.5 rounded-xl text-xs flex items-center gap-2 self-start sm:self-auto font-bold shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Cetak Poster (A4)</span>
                </button>
            </div>

            <!-- Preview Lembar Fisik Poster Pelindo Light Style -->
            <div class="max-w-md mx-auto bg-white text-slate-900 p-6 sm:p-8 rounded-2xl border-4 border-[#003e74] shadow-xl space-y-5 text-center">
                <div class="space-y-2 border-b-2 border-slate-200 pb-4">
                    <div class="flex justify-center mb-1">
                        <img src="/assets/img/pelindo-logo.svg" alt="PT Pelabuhan Indonesia (Persero)" class="h-8 sm:h-9 w-auto">
                    </div>
                    <h3 class="text-xl sm:text-2xl font-black tracking-tight text-[#003e74]">RUANG SIMPAN APD</h3>
                    <p class="text-xs text-slate-600 font-semibold">Peminjaman Mandiri Cepat Tanpa Login (Zero-Login)</p>
                </div>

                <div class="py-2 space-y-3">
                    <div class="w-44 h-44 mx-auto bg-slate-50 border-2 border-dashed border-slate-300 rounded-2xl flex items-center justify-center p-2">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?= urlencode($hostUrl) ?>"
                            alt="QR Code Peminjaman APD" class="w-36 h-36 rounded-lg">
                    </div>
                    <p class="text-xs text-slate-600 font-medium px-4">
                        Arahkan kamera ponsel Anda ke QR Code di atas untuk membuka formulir peminjaman APD mandiri.
                    </p>
                </div>

                <!-- Box Kode PIN Fisik -->
                <div class="bg-sky-50 border-2 border-[#005b96] rounded-xl p-4 space-y-1">
                    <span class="text-[10px] font-bold text-[#003e74] tracking-wider uppercase block">KODE PIN RUANG APD:</span>
                    <span class="text-4xl sm:text-5xl font-black font-mono text-[#003e74] tracking-widest block">
                        <?= htmlspecialchars($pinGudang) ?>
                    </span>
                    <span class="text-[10px] text-slate-600 block pt-1 font-medium">
                        Ketik 4 digit angka di atas pada formulir sebagai bukti kehadiran fisik di gudang.
                    </span>
                </div>

                <div class="text-[10px] text-slate-500 border-t border-slate-200 pt-3 leading-normal">
                    <strong>PENTING:</strong> Kembalikan APD kepada Pengawas K3 untuk verifikasi fisik saat jam kerja berakhir.
                </div>
            </div>
        </section>

    </main>

    <!-- Modal Pulihkan Stok Rusak (Light Theme) -->
    <div id="modal-pulihkan" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-5 sm:p-6 shadow-2xl space-y-4 border border-slate-200">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                <h3 class="text-sm sm:text-base font-bold text-emerald-700 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <span>Pemulihan Stok Selesai Diservis</span>
                </h3>
                <button type="button" onclick="closeModalPulihkan()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 bg-slate-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="text-xs text-slate-700 bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-1">
                <div>Barang: <strong id="modal-repair-nama" class="text-slate-900">-</strong></div>
                <div>Jumlah Unit Rusak Tercatat: <strong id="modal-repair-max" class="text-red-600 font-bold">0</strong> Unit</div>
            </div>

            <form action="kelola_apd.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="repair_item">
                <input type="hidden" id="modal-repair-id" name="id_apd" value="">

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Jumlah Unit yang Siap Dipulihkan ke Rak</label>
                    <input type="number" id="modal-repair-input" name="jumlah_pulih" min="1" max="1" value="1" required
                        class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-center font-bold text-lg text-slate-900 border-2 border-emerald-500 bg-white">
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeModalPulihkan()"
                        class="px-4 py-2.5 rounded-xl bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs font-semibold">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm">
                        Konfirmasi Pemulihan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Bottom Navigation for Admin (Pelindo Light) -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/admin/index.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="/admin/kelola_apd.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <span>Master APD</span>
        </a>
        <a href="/admin/kelola_pengawas.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span>Pengawas</span>
        </a>
        <a href="/admin/laporan.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span>Laporan</span>
        </a>
    </nav>

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
