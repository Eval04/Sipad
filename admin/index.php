<?php
/**
 * Dashboard Eksekutif Administrator K3
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

checkAuth(['admin']);
$pdo = getDbConnection();
$currentUser = getLoggedInUser();

// Metrik ringkasan inventaris
$stmtMetrics = $pdo->query("
    SELECT 
        COALESCE(SUM(stok_total), 0) AS total_aset,
        COALESCE(SUM(stok_tersedia), 0) AS total_tersedia,
        COALESCE(SUM(stok_rusak), 0) AS total_rusak
    FROM apd_items
");
$metrics = $stmtMetrics->fetch(PDO::FETCH_ASSOC);

$totalPinjamAktif = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status_pinjam = 'DIPINJAM'")->fetchColumn();
$totalPengawas = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pengawas' AND is_active = 1")->fetchColumn();

// Status WiFi & PIN
$wifi = $pdo->query("SELECT * FROM wifi_settings WHERE id_setting = 1")->fetch(PDO::FETCH_ASSOC);

// Transaksi sirkulasi 8 terbaru
$recentLoans = $pdo->query("
    SELECT l.*, i.nama_apd, i.kode_apd, u.nama_lengkap AS nama_pengawas
    FROM loans l
    JOIN apd_items i ON l.id_apd = i.id_apd
    LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
    ORDER BY l.waktu_pinjam DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Item APD dengan stok rusak terbanyak
$damagedItems = $pdo->query("
    SELECT * FROM apd_items WHERE stok_rusak > 0 ORDER BY stok_rusak DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Eksekutif Admin - SIPAD K3</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col">

    <!-- Admin Top Navbar -->
    <header class="bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-bold shadow-md shadow-amber-500/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-white block leading-tight">DASHBOARD EKSEKUTIF</span>
                    <span class="text-[10px] text-amber-400 font-semibold tracking-wider uppercase">Sistem Inventaris & Sirkulasi APD</span>
                </div>
            </div>

            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-slate-300">
                <a href="/admin/index.php" class="text-amber-400">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="hover:text-amber-400 transition-colors">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="hover:text-amber-400 transition-colors">Kelola Pengawas & WiFi</a>
                <a href="/admin/laporan.php" class="hover:text-amber-400 transition-colors">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="/pengawas/index.php" class="text-xs px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 text-slate-300 hover:text-white transition-all">
                    Mode Pengawas
                </a>
                <a href="/logout.php" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/80 hover:bg-red-600 text-white font-semibold transition-all">
                    Keluar
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow space-y-8 w-full">

        <!-- 4 KARTU METRIK EKSEKUTIF K3 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 font-medium block">Total Aset APD</span>
                    <strong class="text-3xl font-extrabold text-white mt-1 block"><?= $metrics['total_aset'] ?></strong>
                    <span class="text-[11px] text-slate-500">Unit terdaftar</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-slate-800 text-slate-300 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 font-medium block">Stok Siap Pakai</span>
                    <strong class="text-3xl font-extrabold text-emerald-400 mt-1 block"><?= $metrics['total_tersedia'] ?></strong>
                    <span class="text-[11px] text-slate-500">Tersedia di rak gudang</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 font-medium block">Sedang Dipinjam</span>
                    <strong class="text-3xl font-extrabold text-amber-400 mt-1 block"><?= $totalPinjamAktif ?></strong>
                    <span class="text-[11px] text-slate-500">Unit di area lapangan</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 font-medium block">Stok Rusak / Afkir</span>
                    <strong class="text-3xl font-extrabold text-red-400 mt-1 block"><?= $metrics['total_rusak'] ?></strong>
                    <span class="text-[11px] text-slate-500">Perlu perbaikan/ganti</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-red-500/10 text-red-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- GRID 2: STATUS KEAMANAN & DAFTAR APD RUSAK -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- Widget Cepat Keamanan & Jaringan -->
            <div class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <h3 class="font-bold text-white text-base">Konfigurasi Operasional K3</h3>
                    <a href="/admin/kelola_pengawas.php" class="text-xs text-amber-400 hover:underline">Ubah Pengaturan &rarr;</a>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-slate-400 block font-medium">Validasi WiFi Pengawas:</span>
                            <strong class="text-white text-sm"><?= htmlspecialchars($wifi['nama_jaringan'] ?? '-') ?></strong>
                            <span class="text-[11px] font-mono text-slate-500 block">IP: <?= htmlspecialchars($wifi['ip_address'] ?? '-') ?></span>
                        </div>
                        <div>
                            <?php if ((int)($wifi['is_wifi_check_enabled'] ?? 0) === 1): ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">AKTIF</span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400 border border-slate-700">BYPASS (OFF)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-slate-400 block font-medium">PIN Fisik Ruang APD:</span>
                            <span class="text-[11px] text-slate-500">Wajib diinput pekerja saat meminjam</span>
                        </div>
                        <strong class="text-2xl font-mono text-amber-400 tracking-wider font-extrabold">
                            <?= htmlspecialchars($wifi['kode_pin_gudang'] ?? '4821') ?>
                        </strong>
                    </div>

                    <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-slate-400 block font-medium">Akun Pengawas Aktif:</span>
                            <span class="text-[11px] text-slate-500">Petugas berwenang ACC</span>
                        </div>
                        <strong class="text-lg text-white font-bold">
                            <?= $totalPengawas ?> Petugas
                        </strong>
                    </div>
                </div>

                <div class="pt-2">
                    <a href="/admin/kelola_apd.php#poster-qr" class="w-full py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 flex items-center justify-center gap-2 transition-all">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Cetak Poster QR & PIN Ruang APD</span>
                    </a>
                </div>
            </div>

            <!-- Widget Peringatan Unit Rusak Memerlukan Tindakan -->
            <div class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <h3 class="font-bold text-white text-base flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-400 animate-pulse"></span>
                        <span>Perhatian: Unit APD Rusak Memerlukan Perbaikan</span>
                    </h3>
                    <a href="/admin/kelola_apd.php" class="text-xs text-amber-400 hover:underline">Kelola Stok Rusak &rarr;</a>
                </div>

                <?php if (empty($damagedItems)): ?>
                    <div class="p-8 text-center text-xs text-slate-500 space-y-2">
                        <svg class="w-8 h-8 text-emerald-400 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-slate-300 font-semibold">Seluruh Peralatan APD dalam Kondisi Prima</p>
                        <p>Tidak ada unit cacat atau rusak yang menunggu perbaikan saat ini.</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-800">
                        <?php foreach ($damagedItems as $dmg): ?>
                            <div class="py-3 flex items-center justify-between text-xs">
                                <div>
                                    <strong class="text-white text-sm block"><?= htmlspecialchars($dmg['nama_apd']) ?></strong>
                                    <span class="text-slate-500 font-mono"><?= htmlspecialchars($dmg['kode_apd']) ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="px-2.5 py-1 rounded-full font-bold bg-red-500/20 text-red-300 border border-red-500/30">
                                        <?= $dmg['stok_rusak'] ?> Unit Rusak
                                    </span>
                                    <a href="/admin/kelola_apd.php" class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] transition-all">
                                        Perbaiki
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 3: AKTIVITAS SIRKULASI TERKINI -->
        <div class="bg-slate-900 border border-slate-800 rounded-3xl shadow-xl overflow-hidden p-6 sm:p-7 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                <h3 class="font-bold text-white text-base">Aktivitas Sirkulasi Peminjaman Terkini</h3>
                <a href="/admin/laporan.php" class="text-xs text-amber-400 hover:underline">Lihat Laporan Lengkap &rarr;</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider border-b border-slate-800 font-semibold">
                        <tr>
                            <th class="px-5 py-3">Kode Pinjam</th>
                            <th class="px-5 py-3">Peminjam</th>
                            <th class="px-5 py-3">Peralatan APD</th>
                            <th class="px-5 py-3">Waktu Pinjam</th>
                            <th class="px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($recentLoans)): ?>
                            <tr><td colspan="5" class="px-5 py-6 text-center text-slate-500">Belum ada transaksi peminjaman.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentLoans as $ln): ?>
                                <tr class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3 font-mono font-bold text-slate-200"><?= htmlspecialchars($ln['kode_pinjam']) ?></td>
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-white"><?= htmlspecialchars($ln['nama_peminjam']) ?></div>
                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($ln['bagian_unit']) ?></div>
                                    </td>
                                    <td class="px-5 py-3 text-white"><?= htmlspecialchars($ln['nama_apd']) ?> (<?= $ln['jumlah_pinjam'] ?>)</td>
                                    <td class="px-5 py-3 text-slate-400"><?= date('d M Y, H:i', strtotime($ln['waktu_pinjam'])) ?></td>
                                    <td class="px-5 py-3">
                                        <?php if ($ln['status_pinjam'] === 'DIPINJAM'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                Dipinjam
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                Selesai
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <footer class="border-t border-slate-800 bg-slate-950 py-6 text-center text-xs text-slate-500">
        SIPAD K3 Operasional &bull; Administrator Inventaris APD &copy; 2026.
    </footer>
</body>
</html>
