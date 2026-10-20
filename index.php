<?php
/**
 * Halaman Utama: Katalog Ketersediaan Stok Real-time & Formulir Peminjaman Mandiri Tanpa Login
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

startAppSession();
$pdo = getDbConnection();

$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

$oldInput = $_SESSION['form_old_input'] ?? [];
unset($_SESSION['form_old_input']);

// Ambil daftar kategori APD
$categories = $pdo->query("SELECT * FROM apd_categories ORDER BY id_category ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar seluruh peralatan APD beserta nama kategorinya
$queryItems = "
    SELECT i.*, c.nama_kategori, c.kode_kategori 
    FROM apd_items i
    JOIN apd_categories c ON i.id_category = c.id_category
    ORDER BY c.id_category ASC, i.nama_apd ASC
";
$items = $pdo->query($queryItems)->fetchAll(PDO::FETCH_ASSOC);

// Metrik ringkas ketersediaan
$totalTersedia = array_sum(array_column($items, 'stok_tersedia'));
$totalItemApd = count($items);
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Mandiri APD - Sistem Inventaris K3 Operasional</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col selection:bg-amber-500 selection:text-slate-950">

    <!-- Navbar Navigasi Utama -->
    <header class="sticky top-0 z-50 bg-slate-900/80 backdrop-blur-md border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-300 text-slate-950 flex items-center justify-center font-bold shadow-lg shadow-amber-500/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-lg font-extrabold tracking-tight text-white block leading-tight">SIPAD K3</span>
                    <span class="text-[10px] tracking-wider uppercase text-amber-400 font-semibold">Zero-Login Self Service</span>
                </div>
            </div>

            <nav class="hidden md:flex items-center gap-6 text-sm font-medium text-slate-300">
                <a href="#katalog" class="hover:text-amber-400 transition-colors">Katalog APD</a>
                <a href="#form-pinjam" class="hover:text-amber-400 transition-colors">Formulir Peminjaman</a>
                <a href="/status_pinjam.php" class="hover:text-amber-400 transition-colors">Cek Status Pinjam</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="/status_pinjam.php" class="text-xs sm:text-sm px-3.5 py-1.5 rounded-lg border border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700 hover:text-white transition-all font-medium">
                    Status Pinjam
                </a>
                <a href="/login.php" class="text-xs sm:text-sm px-3.5 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold transition-all shadow-md shadow-amber-500/10">
                    Login Petugas
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Banner & Notifikasi Ruang APD -->
    <section class="relative overflow-hidden pt-10 pb-12 border-b border-slate-800/80 bg-gradient-to-b from-slate-900 to-slate-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                <div class="lg:col-span-7 space-y-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        Sistem Aktif & Terhubung MySQL Lokal
                    </div>
                    <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white tracking-tight leading-tight">
                        Peminjaman Mandiri APD <br>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-amber-400 to-amber-200">
                            Cepat, Akurat, Tanpa Friksi Login
                        </span>
                    </h1>
                    <p class="text-slate-400 text-base max-w-xl leading-relaxed">
                        Pekerja lapangan dapat langsung mencatat peminjaman alat keselamatan kerja dalam hitungan detik. Cukup masukkan identitas, pilih APD yang dibutuhkan, dan ketikkan <strong>4 Digit PIN Ruang APD</strong> yang terpasang di pintu gudang.
                    </p>
                    <div class="flex flex-wrap items-center gap-4 pt-2">
                        <a href="#form-pinjam" class="px-5 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-sm shadow-lg shadow-amber-500/20 transition-all flex items-center gap-2">
                            <span>Pinjam Sekarang</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </a>
                        <a href="#katalog" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-sm border border-slate-700 transition-all">
                            Lihat Stok APD (<?= $totalTersedia ?> Unit Tersedia)
                        </a>
                    </div>
                </div>

                <!-- Card Info Mekanisme K3 Asimetri Kontrol -->
                <div class="lg:col-span-5">
                    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden">
                        <div class="absolute -top-10 -right-10 w-40 h-40 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2 mb-3">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Alur Sirkulasi APD Terpadu
                        </h3>
                        <ul class="space-y-3 text-xs text-slate-300">
                            <li class="flex items-start gap-2.5">
                                <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 font-bold flex items-center justify-center flex-shrink-0 text-[10px]">1</span>
                                <div><strong>Ambil & Catat Mandiri:</strong> Pilih APD di rak gudang, isi form di bawah dengan PIN Ruang APD (waktu transaksi < 15 detik).</div>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 font-bold flex items-center justify-center flex-shrink-0 text-[10px]">2</span>
                                <div><strong>Simpan Kode Pinjam:</strong> Kode unik akan otomatis terbit sebagai bukti kepemilikan izin sirkulasi resmi.</div>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 font-bold flex items-center justify-center flex-shrink-0 text-[10px]">3</span>
                                <div><strong>Verifikasi Satu Pintu Pengawas:</strong> Saat selesai digunakan, serahkan fisik APD ke Pengawas K3 untuk diperiksa kondisi fisik (Baik vs Rusak) dan di-ACC resmi.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 flex-grow space-y-12">

        <!-- SECTION 1: FORMULIR PEMINJAMAN CEPAT -->
        <section id="form-pinjam" class="scroll-mt-20">
            <div class="max-w-3xl mx-auto bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl relative">
                
                <div class="text-center mb-8">
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-400 px-3 py-1 bg-amber-400/10 rounded-full border border-amber-400/20">
                        Formulir Peminjaman Cepat
                    </span>
                    <h2 class="text-2xl font-bold text-white mt-2">Peminjaman Mandiri APD Lapangan</h2>
                    <p class="text-slate-400 text-sm mt-1">Isi data di bawah ini tanpa perlu login akun</p>
                </div>

                <?php if ($flashError): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-500/15 border border-red-500/30 text-red-200 text-sm flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div><?= htmlspecialchars($flashError) ?></div>
                    </div>
                <?php endif; ?>

                <form action="/pinjam_proses.php" method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Nama Lengkap Peminjam -->
                        <div>
                            <label for="nama_peminjam" class="block text-sm font-semibold text-slate-300 mb-1.5">
                                Nama Lengkap Peminjam <span class="text-amber-400">*</span>
                            </label>
                            <input type="text" id="nama_peminjam" name="nama_peminjam" required
                                value="<?= htmlspecialchars($oldInput['nama_peminjam'] ?? '') ?>"
                                placeholder="Contoh: Budi Santoso"
                                class="w-full px-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all text-sm">
                        </div>

                        <!-- Bagian / Unit Kerja -->
                        <div>
                            <label for="bagian_unit" class="block text-sm font-semibold text-slate-300 mb-1.5">
                                Bagian / Unit Kerja <span class="text-amber-400">*</span>
                            </label>
                            <input type="text" id="bagian_unit" name="bagian_unit" required
                                value="<?= htmlspecialchars($oldInput['bagian_unit'] ?? '') ?>"
                                placeholder="Contoh: Fabrikasi / Elektrikal / Sipil"
                                class="w-full px-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- No. WhatsApp / HP Peminjam -->
                        <div>
                            <label for="no_hp_peminjam" class="block text-sm font-semibold text-slate-300 mb-1.5">
                                Nomor WhatsApp / HP Aktif <span class="text-amber-400">*</span>
                            </label>
                            <input type="tel" id="no_hp_peminjam" name="no_hp_peminjam" required
                                value="<?= htmlspecialchars($oldInput['no_hp_peminjam'] ?? '') ?>"
                                placeholder="Contoh: 082198765432"
                                class="w-full px-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all text-sm">
                        </div>

                        <!-- Jumlah Pinjam -->
                        <div>
                            <label for="jumlah_pinjam" class="block text-sm font-semibold text-slate-300 mb-1.5">
                                Jumlah Unit Dipinjam <span class="text-amber-400">*</span>
                            </label>
                            <input type="number" id="jumlah_pinjam" name="jumlah_pinjam" min="1" max="10" value="<?= htmlspecialchars((string)($oldInput['jumlah_pinjam'] ?? 1)) ?>" required
                                class="w-full px-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all text-sm">
                        </div>
                    </div>

                    <!-- Dropdown Pilihan APD -->
                    <div>
                        <label for="id_apd" class="block text-sm font-semibold text-slate-300 mb-1.5">
                            Pilih Peralatan APD yang Dipinjam <span class="text-amber-400">*</span>
                        </label>
                        <select id="id_apd" name="id_apd" required
                            class="w-full px-4 py-3 bg-slate-950 border border-slate-700 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all text-sm">
                            <option value="">-- Pilih Peralatan APD dari Daftar Rak --</option>
                            <?php foreach ($items as $itm): ?>
                                <?php $isHabis = ($itm['stok_tersedia'] <= 0); ?>
                                <option value="<?= $itm['id_apd'] ?>" <?= $isHabis ? 'disabled class="text-slate-500"' : '' ?>
                                    <?= ((int)($oldInput['id_apd'] ?? 0) === $itm['id_apd']) ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($itm['kode_apd']) ?>] <?= htmlspecialchars($itm['nama_apd']) ?> 
                                    - <?= htmlspecialchars($itm['ukuran_spesifikasi'] ?? 'Standard') ?> 
                                    (<?= $isHabis ? 'HABIS' : 'Sisa: ' . $itm['stok_tersedia'] . ' unit' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Input PIN Ruang APD (Lapis Keamanan Fisik) -->
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 space-y-2">
                        <div class="flex items-center justify-between">
                            <label for="pin_gudang" class="text-sm font-bold text-amber-300 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                4 Digit Kode PIN Ruang APD <span class="text-amber-400">*</span>
                            </label>
                            <span class="text-[11px] text-amber-400/80">Bukti Kehadiran di Gudang</span>
                        </div>
                        <input type="password" id="pin_gudang" name="pin_gudang" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required
                            placeholder="Ketik 4 digit PIN (Contoh: 4821)"
                            class="w-full px-4 py-2.5 bg-slate-950 border border-amber-500/40 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent text-sm tracking-widest font-mono">
                        <p class="text-[11px] text-slate-400 leading-normal">
                            PIN tertera secara fisik pada poster di pintu atau meja serah terima ruang penyimpanan APD.
                        </p>
                    </div>

                    <!-- Tombol Submit -->
                    <button type="submit"
                        class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-bold text-base shadow-xl shadow-amber-500/20 active:scale-[0.99] transition-all flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span>Konfirmasi & Terbitkan Bukti Peminjaman</span>
                    </button>
                </form>
            </div>
        </section>

        <!-- SECTION 2: KATALOG KETERSEDIAAN STOK REAL-TIME -->
        <section id="katalog" class="scroll-mt-20 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Katalog Peralatan APD Siap Pakai</h2>
                    <p class="text-slate-400 text-sm mt-0.5">Pemantauan ketersediaan unit keselamatan kerja secara waktu nyata</p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span> Siap Pakai
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span> Stok Menipis
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-red-500/10 text-red-400 border border-red-500/20">
                        <span class="w-2 h-2 rounded-full bg-red-400"></span> Habis
                    </span>
                </div>
            </div>

            <!-- Grid Kartu APD -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <?php foreach ($items as $apd): ?>
                    <?php
                        $stok = (int)$apd['stok_tersedia'];
                        if ($stok > 5) {
                            $badgeColor = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
                            $badgeText = "Tersedia ({$stok} unit)";
                        } elseif ($stok > 0) {
                            $badgeColor = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
                            $badgeText = "Sisa ({$stok} unit)";
                        } else {
                            $badgeColor = 'bg-red-500/10 text-red-400 border-red-500/30';
                            $badgeText = 'Stok Habis (0)';
                        }
                    ?>
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 hover:border-slate-700 transition-all flex flex-col justify-between group shadow-lg">
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">
                                    <?= htmlspecialchars($apd['kode_kategori']) ?>
                                </span>
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full border <?= $badgeColor ?>">
                                    <?= $badgeText ?>
                                </span>
                            </div>

                            <div>
                                <h3 class="font-bold text-white group-hover:text-amber-400 transition-colors line-clamp-2 text-base">
                                    <?= htmlspecialchars($apd['nama_apd']) ?>
                                </h3>
                                <p class="text-xs text-slate-400 mt-1">
                                    <?= htmlspecialchars($apd['ukuran_spesifikasi'] ?? 'Standar K3') ?>
                                </p>
                            </div>

                            <div class="text-[11px] text-slate-500 flex items-center justify-between border-t border-slate-800/80 pt-2.5">
                                <span>Kode: <?= htmlspecialchars($apd['kode_apd']) ?></span>
                                <span>Total Aset: <?= $apd['stok_total'] ?></span>
                            </div>
                        </div>

                        <div class="pt-4 mt-3">
                            <?php if ($stok > 0): ?>
                                <button onclick="pilihApd('<?= $apd['id_apd'] ?>')"
                                    class="w-full py-2 px-3 rounded-lg bg-slate-800 hover:bg-amber-500 hover:text-slate-950 text-slate-200 text-xs font-semibold border border-slate-700 transition-all text-center block">
                                    Pilih APD Ini
                                </button>
                            <?php else: ?>
                                <button disabled
                                    class="w-full py-2 px-3 rounded-lg bg-slate-900/60 text-slate-600 text-xs font-medium border border-slate-800 cursor-not-allowed text-center block">
                                    Tidak Dapat Dipinjam
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

    </main>

    <!-- Footer Standar Program Kerja K3 -->
    <footer class="border-t border-slate-800 bg-slate-950 py-8 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 space-y-2">
            <p class="font-medium text-slate-400">
                Sistem Inventaris dan Peminjaman Alat Pelindung Diri (SIPAD) &bull; Program Kerja K3 & Inventaris
            </p>
            <p>
                Dikembangkan Berdasarkan Naskah R&D Edisi Revisi Penuh Standar Akademik & Visual Presisi &copy; 2026.
            </p>
        </div>
    </footer>

    <script>
        function pilihApd(idApd) {
            const selectEl = document.getElementById('id_apd');
            if (selectEl) {
                selectEl.value = idApd;
                const formSection = document.getElementById('form-pinjam');
                if (formSection) {
                    formSection.scrollIntoView({ behavior: 'smooth' });
                    selectEl.focus();
                }
            }
        }
    </script>
</body>
</html>
