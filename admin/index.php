<?php
/**
 * Dashboard Eksekutif Administrator K3
 * Identitas Korporat PT Pelabuhan Indonesia (Pelindo) - Clean Light Enterprise Theme
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
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard Admin K3 - Pelindo</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Top Admin Header Pelindo -->
    <header class="bg-[#003e74] text-white sticky top-0 z-40 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="/admin/index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo" class="w-7 h-7">
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <img src="/assets/img/pelindo-logo-white.svg" alt="Pelindo" class="h-4 sm:h-5 w-auto">
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">ADMIN</span>
                    </div>
                    <span class="text-[10px] text-sky-200 font-mono tracking-wider uppercase block">PT Pelabuhan Indonesia (Persero)</span>
                </div>
            </a>

            <!-- Desktop Nav Menu -->
            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-sky-100">
                <a href="/admin/index.php" class="text-white font-bold border-b-2 border-sky-300 pb-1">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="hover:text-white transition-colors">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="hover:text-white transition-colors">Pengawas & WiFi</a>
                <a href="/admin/laporan.php" class="hover:text-white transition-colors">Laporan Audit</a>
            </nav>

            <!-- Header Action Controls -->
            <div class="flex items-center gap-2.5">
                <a href="/pengawas/index.php" class="text-xs px-3 py-1.5 rounded-lg bg-[#005b96] hover:bg-[#006fb3] text-white transition-colors flex items-center gap-1.5 font-medium border border-sky-300/30">
                    <svg class="w-3.5 h-3.5 text-sky-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="hidden sm:inline">Mode Pengawas</span>
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

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-grow space-y-6 w-full">

        <!-- 4 KARTU METRIK EKSEKUTIF PELINDO K3 (LIGHT THEME) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5">
            <!-- Total Aset -->
            <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm flex flex-col justify-between border-t-4 border-t-[#003e74]">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Total Aset APD</span>
                    <div class="w-8 h-8 rounded-lg bg-slate-100 text-[#003e74] flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-3">
                    <strong class="text-2xl sm:text-3xl font-extrabold text-slate-900 block leading-none"><?= $metrics['total_aset'] ?></strong>
                    <span class="text-[11px] text-slate-400 mt-1 block">Unit tercatat di gudang</span>
                </div>
            </div>

            <!-- Stok Siap Pakai -->
            <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm flex flex-col justify-between border-t-4 border-t-emerald-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Stok Siap Pakai</span>
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-3">
                    <strong class="text-2xl sm:text-3xl font-extrabold text-emerald-600 block leading-none"><?= $metrics['total_tersedia'] ?></strong>
                    <span class="text-[11px] text-slate-400 mt-1 block">Tersedia di rak siap kerja</span>
                </div>
            </div>

            <!-- Sedang Dipinjam -->
            <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm flex flex-col justify-between border-t-4 border-t-amber-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Sedang Dipinjam</span>
                    <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-3">
                    <strong class="text-2xl sm:text-3xl font-extrabold text-amber-600 block leading-none"><?= $totalPinjamAktif ?></strong>
                    <span class="text-[11px] text-slate-400 mt-1 block">Unit di area lapangan</span>
                </div>
            </div>

            <!-- Stok Rusak / Afkir -->
            <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-sm flex flex-col justify-between border-t-4 border-t-red-500">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Stok Rusak</span>
                    <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                </div>
                <div class="mt-3">
                    <strong class="text-2xl sm:text-3xl font-extrabold text-red-600 block leading-none"><?= $metrics['total_rusak'] ?></strong>
                    <span class="text-[11px] text-slate-400 mt-1 block">Perlu tindakan perbaikan</span>
                </div>
            </div>
        </div>

        <!-- GRID 2: OPERASIONAL KEAMANAN & DAFTAR UNIT RUSAK -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Widget Konfigurasi Operasional -->
            <div class="lg:col-span-5 bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#0080c0]"></span>
                        <h2 class="font-bold text-slate-900 text-sm sm:text-base">Pengamanan Operasional K3</h2>
                    </div>
                    <a href="/admin/kelola_pengawas.php" class="text-xs text-[#005b96] hover:underline font-semibold">Ubah &rarr;</a>
                </div>

                <div class="space-y-3 text-xs">
                    <!-- WiFi Status Item -->
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                        <div>
                            <span class="text-slate-500 block font-medium">Validasi WiFi Pengawas:</span>
                            <strong class="text-slate-900 text-sm truncate max-w-[180px] block"><?= htmlspecialchars($wifi['nama_jaringan'] ?? '-') ?></strong>
                            <span class="text-[11px] font-mono text-slate-500">IP: <?= htmlspecialchars($wifi['ip_address'] ?? '-') ?></span>
                        </div>
                        <div>
                            <?php if ((int)($wifi['is_wifi_check_enabled'] ?? 0) === 1): ?>
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                    <span>AKTIF</span>
                                </span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">
                                    BYPASS (OFF)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- PIN Ruang APD Item -->
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                        <div>
                            <span class="text-slate-500 block font-medium">PIN Fisik Ruang APD:</span>
                            <span class="text-[11px] text-slate-400">Wajib diinput peminjam di lokasi</span>
                        </div>
                        <div class="px-3 py-1 rounded-lg bg-white border border-slate-200 shadow-sm">
                            <strong class="text-2xl font-mono text-[#003e74] tracking-widest font-extrabold">
                                <?= htmlspecialchars($wifi['kode_pin_gudang'] ?? '4821') ?>
                            </strong>
                        </div>
                    </div>

                    <!-- Akun Pengawas Item -->
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                        <div>
                            <span class="text-slate-500 block font-medium">Pengawas K3 Aktif:</span>
                            <span class="text-[11px] text-slate-400">Diberi hak ACC verifikasi</span>
                        </div>
                        <strong class="text-base text-slate-900 font-bold">
                            <?= $totalPengawas ?> Petugas
                        </strong>
                    </div>
                </div>

                <div class="pt-2">
                    <a href="/admin/kelola_apd.php#poster-qr" class="w-full py-2.5 px-4 rounded-xl bg-[#005b96] hover:bg-[#006fb3] text-white text-xs font-semibold flex items-center justify-center gap-2 transition-colors shadow-sm">
                        <svg class="w-4 h-4 text-sky-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        <span>Cetak Poster QR & PIN Ruang APD</span>
                    </a>
                </div>
            </div>

            <!-- Widget Unit Rusak Memerlukan Pemulihan -->
            <div class="lg:col-span-7 bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse"></span>
                        <h2 class="font-bold text-slate-900 text-sm sm:text-base">Unit APD Rusak Memerlukan Tindakan</h2>
                    </div>
                    <a href="/admin/kelola_apd.php" class="text-xs text-[#005b96] hover:underline font-semibold">Kelola &rarr;</a>
                </div>

                <?php if (empty($damagedItems)): ?>
                    <div class="p-8 text-center text-xs text-slate-500 space-y-2">
                        <svg class="w-8 h-8 text-emerald-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-slate-800 font-semibold">Seluruh Peralatan APD dalam Kondisi Prima</p>
                        <p>Tidak ada unit cacat atau rusak yang menunggu pemulihan saat ini.</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($damagedItems as $dmg): ?>
                            <div class="py-3 flex items-center justify-between gap-3 text-xs">
                                <div>
                                    <strong class="text-slate-900 text-sm block"><?= htmlspecialchars($dmg['nama_apd']) ?></strong>
                                    <span class="text-slate-400 font-mono text-[11px]"><?= htmlspecialchars($dmg['kode_apd']) ?></span>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <span class="px-2.5 py-1 rounded-full font-bold bg-red-50 text-red-700 border border-red-200 text-[11px]">
                                        <?= $dmg['stok_rusak'] ?> Unit Rusak
                                    </span>
                                    <a href="/admin/kelola_apd.php" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] transition-colors shadow-sm">
                                        Perbaiki
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION: AKTIVITAS SIRKULASI TERKINI -->
        <section class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h2 class="font-bold text-slate-900 text-sm sm:text-base">Sirkulasi Peminjaman Terkini</h2>
                    <p class="text-xs text-slate-500 mt-0.5">8 Transaksi peminjaman terbaru di seluruh area pelabuhan</p>
                </div>
                <a href="/admin/laporan.php" class="text-xs text-[#005b96] hover:underline font-semibold">Lihat Audit &rarr;</a>
            </div>

            <!-- Mobile View: Compact Cards (<640px) -->
            <div class="block sm:hidden space-y-2.5">
                <?php if (empty($recentLoans)): ?>
                    <div class="text-center py-6 text-xs text-slate-500">Belum ada transaksi peminjaman.</div>
                <?php else: ?>
                    <?php foreach ($recentLoans as $ln): ?>
                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="font-mono font-bold text-[#005b96]"><?= htmlspecialchars($ln['kode_pinjam']) ?></span>
                                <?php if ($ln['status_pinjam'] === 'DIPINJAM'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        Dipinjam
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Selesai
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-slate-900 font-bold"><?= htmlspecialchars($ln['nama_peminjam']) ?> (<?= htmlspecialchars($ln['bagian_unit']) ?>)</div>
                            <div class="text-slate-600 text-[11px]"><?= htmlspecialchars($ln['nama_apd']) ?> &bull; <?= $ln['jumlah_pinjam'] ?> Unit</div>
                            <div class="text-[10px] text-slate-400 pt-1 border-t border-slate-200"><?= date('d M Y, H:i', strtotime($ln['waktu_pinjam'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Desktop View: Table (>=640px) -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider border-b border-slate-200 font-bold text-[11px]">
                        <tr>
                            <th class="px-4 py-3">Kode Pinjam</th>
                            <th class="px-4 py-3">Peminjam</th>
                            <th class="px-4 py-3">Peralatan APD</th>
                            <th class="px-4 py-3">Waktu Pinjam</th>
                            <th class="px-4 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($recentLoans)): ?>
                            <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Belum ada transaksi peminjaman.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentLoans as $ln): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-4 py-3 font-mono font-bold text-[#005b96]"><?= htmlspecialchars($ln['kode_pinjam']) ?></td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($ln['nama_peminjam']) ?></div>
                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($ln['bagian_unit']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-800"><?= htmlspecialchars($ln['nama_apd']) ?> (<?= $ln['jumlah_pinjam'] ?>)</td>
                                    <td class="px-4 py-3 text-slate-500"><?= date('d M Y, H:i', strtotime($ln['waktu_pinjam'])) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($ln['status_pinjam'] === 'DIPINJAM'): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                Dipinjam
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
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
        </section>

    </main>

    <!-- Mobile Bottom Navigation Bar for Admin -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/admin/index.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="/admin/kelola_apd.php" class="mobile-nav-item">
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
</body>
</html>
