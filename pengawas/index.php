<?php
/**
 * Dashboard Pengawas: Otorisasi Pengembalian Satu Pintu & Pemeriksaan Fisik APD
 * Identitas Korporat PT Pelabuhan Indonesia (Pelindo) - Clean Light Enterprise Theme
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

checkAuth(['pengawas', 'admin']);
$pdo = getDbConnection();
$currentUser = getLoggedInUser();

$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$currentClientIp = getCurrentClientIp();
$wifiStmt = $pdo->query("SELECT nama_jaringan, ip_address, is_wifi_check_enabled FROM wifi_settings LIMIT 1");
$wifiSettings = $wifiStmt->fetch(PDO::FETCH_ASSOC);

// Ambil daftar transaksi yang SEDANG DIPINJAM (Menunggu ACC Pengawas)
$stmtActive = $pdo->query("
    SELECT l.*, i.nama_apd, i.kode_apd, i.ukuran_spesifikasi
    FROM loans l
    JOIN apd_items i ON l.id_apd = i.id_apd
    WHERE l.status_pinjam = 'DIPINJAM'
    ORDER BY l.waktu_pinjam ASC
");
$activeLoans = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

// Ambil 10 transaksi terakhir yang sudah di-ACC
$stmtRecent = $pdo->query("
    SELECT l.*, i.nama_apd, c.kondisi_kembali, c.keterangan, c.waktu_acc, u.nama_lengkap AS nama_pengawas
    FROM loans l
    JOIN apd_items i ON l.id_apd = i.id_apd
    JOIN condition_logs c ON l.id_loan = c.id_loan
    LEFT JOIN users u ON c.id_pengawas = u.id_user
    WHERE l.status_pinjam = 'SUDAH_DIKEMBALIKAN'
    ORDER BY c.waktu_acc DESC
    LIMIT 10
");
$recentReturns = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

// Hitung metrik hari ini
$today = date('Y-m-d');
$stmtCountToday = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status_pinjam = 'SUDAH_DIKEMBALIKAN' AND DATE(waktu_kembali) = ?");
$stmtCountToday->execute([$today]);
$countReturnedToday = (int)$stmtCountToday->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard Pengawas K3 - Pelindo</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Top Sticky Corporate Header -->
    <header class="bg-[#003e74] text-white sticky top-0 z-40 shadow-md">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo" class="w-7 h-7">
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <img src="/assets/img/pelindo-logo-white.svg" alt="Pelindo" class="h-4 sm:h-5 w-auto">
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">PENGAWAS</span>
                    </div>
                    <span class="text-[10px] text-sky-200 font-mono tracking-wider uppercase block">Inspeksi Pengembalian Fisik APD</span>
                </div>
            </div>

            <!-- Header Right Actions -->
            <div class="flex items-center gap-3">
                <div class="hidden md:flex flex-col text-right text-xs">
                    <strong class="text-white"><?= htmlspecialchars($currentUser['nama_lengkap']) ?></strong>
                    <span class="text-sky-200 font-mono text-[10px] uppercase">Pengawas K3 Lapangan</span>
                </div>

                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/index.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#0080c0] hover:bg-[#006fb3] text-white text-xs font-bold transition-colors shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/>
                        </svg>
                        <span>Panel Admin</span>
                    </a>
                <?php endif; ?>

                <a href="/logout.php" class="p-2 sm:px-3 sm:py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white transition-colors flex items-center gap-1 text-xs font-semibold" title="Keluar">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="hidden sm:inline">Keluar</span>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 flex-grow space-y-6 w-full">

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

        <!-- Telemetry & Status Cards (Pelindo Corporate 3 Columns) -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
            
            <!-- Antrean Menunggu ACC -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between border-l-4 border-l-amber-500">
                <div>
                    <span class="text-xs text-slate-500 font-semibold block uppercase">Menunggu Pemeriksaan</span>
                    <strong class="text-3xl font-extrabold text-amber-600 mt-0.5 block"><?= count($activeLoans) ?></strong>
                    <span class="text-[11px] text-slate-400">Unit belum diverifikasi</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <!-- Di-ACC Hari Ini -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex items-center justify-between border-l-4 border-l-emerald-500">
                <div>
                    <span class="text-xs text-slate-500 font-semibold block uppercase">Selesai Di-ACC Hari Ini</span>
                    <strong class="text-3xl font-extrabold text-emerald-600 mt-0.5 block"><?= $countReturnedToday ?></strong>
                    <span class="text-[11px] text-slate-400">Inspeksi fisik tuntas</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <!-- Network Status -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm flex flex-col justify-between border-l-4 border-l-[#005b96]">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Jaringan WiFi Kantor</span>
                    <?php if ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span>AKTIF</span>
                        </span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                            BYPASS (OFF)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs">
                    <div class="font-bold text-slate-900 truncate"><?= htmlspecialchars($wifiSettings['nama_jaringan'] ?? 'WiFi Kantor') ?></div>
                    <div class="text-[11px] text-slate-500 font-mono mt-0.5">IP Anda: <?= htmlspecialchars($currentClientIp) ?></div>
                </div>
            </div>
        </div>

        <!-- SECTION: ANTREAN VERIFIKASI PEMINJAMAN AKTIF -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2">
                        <span>Antrean Pemeriksaan Fisik APD Lapangan</span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-amber-100 text-amber-800">
                            <?= count($activeLoans) ?>
                        </span>
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">Lakukan inspeksi visual & kelayakan saat pekerja mengembalikan barang di gudang K3</p>
                </div>
            </div>

            <?php if (empty($activeLoans)): ?>
                <!-- Empty State -->
                <div class="bg-white rounded-2xl p-8 sm:p-12 text-center space-y-3 border border-slate-200 shadow-sm">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 mx-auto flex items-center justify-center border border-emerald-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 text-sm sm:text-base">Tidak Ada Antrean Peminjaman Aktif</h3>
                    <p class="text-xs text-slate-500 max-w-sm mx-auto leading-relaxed">
                        Seluruh peralatan APD yang dipinjam saat ini sudah di-ACC pengembaliannya atau belum ada peminjaman baru dari pekerja lapangan.
                    </p>
                </div>
            <?php else: ?>
                <!-- List of Inspection Cards (Mobile-First) -->
                <div class="space-y-3">
                    <?php foreach ($activeLoans as $loan): ?>
                        <div class="bg-white rounded-2xl p-4 sm:p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 border border-slate-200 shadow-sm border-l-4 border-l-[#005b96]">
                            
                            <!-- Left: Loan Info -->
                            <div class="space-y-2 flex-grow">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2.5 py-0.5 rounded bg-slate-100 border border-slate-200 font-mono text-xs font-bold text-[#005b96]">
                                        <?= htmlspecialchars($loan['kode_pinjam']) ?>
                                    </span>
                                    <span class="text-[11px] text-slate-500 flex items-center gap-1 font-mono">
                                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Dipinjam: <?= date('d M, H:i', strtotime($loan['waktu_pinjam'])) ?> WITA
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-4 pt-1 text-xs">
                                    <!-- Peminjam -->
                                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
                                        <span class="text-slate-400 text-[10px] uppercase font-bold block">Karyawan</span>
                                        <strong class="text-sm text-slate-900 block mt-0.5 truncate"><?= htmlspecialchars($loan['nama_peminjam']) ?></strong>
                                        <span class="text-slate-600 text-[11px] block"><?= htmlspecialchars($loan['bagian_unit']) ?></span>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $loan['no_hp_peminjam']) ?>" target="_blank" class="text-[#005b96] hover:underline font-mono text-[11px] inline-flex items-center gap-1 mt-1 font-semibold">
                                            <span>WA: <?= htmlspecialchars($loan['no_hp_peminjam']) ?></span>
                                        </a>
                                    </div>

                                    <!-- APD Info -->
                                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
                                        <span class="text-slate-400 text-[10px] uppercase font-bold block">Peralatan APD</span>
                                        <strong class="text-sm text-slate-900 block mt-0.5"><?= htmlspecialchars($loan['nama_apd']) ?></strong>
                                        <span class="text-slate-600 text-[11px] block"><?= htmlspecialchars($loan['ukuran_spesifikasi'] ?? 'Standard K3') ?></span>
                                        <span class="text-slate-400 font-mono text-[10px] block mt-1"><?= htmlspecialchars($loan['kode_apd']) ?></span>
                                    </div>

                                    <!-- Kuantitas -->
                                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-between">
                                        <span class="text-slate-400 text-[10px] uppercase font-bold block">Jumlah Dipinjam</span>
                                        <strong class="text-2xl font-black text-[#005b96]"><?= $loan['jumlah_pinjam'] ?> <span class="text-xs text-slate-500 font-normal">Unit</span></strong>
                                        <span class="text-[10px] text-amber-700 font-semibold">Belum Kembali</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Action Buttons -->
                            <div class="flex flex-col sm:flex-row md:flex-col gap-2 flex-shrink-0 pt-2 md:pt-0 border-t md:border-t-0 border-slate-100">
                                
                                <!-- ACC Baik Button -->
                                <form action="acc_proses.php" method="POST" onsubmit="return confirm('Konfirmasi ACC Pengembalian Kondisi Baik untuk <?= htmlspecialchars(addslashes($loan['nama_peminjam'])) ?>? Stok akan langsung dipulihkan ke rak siap pakai.')" class="w-full">
                                    <input type="hidden" name="action" value="acc_return">
                                    <input type="hidden" name="id_loan" value="<?= $loan['id_loan'] ?>">
                                    <input type="hidden" name="kondisi_kembali" value="BAIK">
                                    <input type="hidden" name="keterangan" value="Fisik utuh, bersih, dan layak pakai">
                                    <button type="submit" class="btn-k3-green w-full touch-target px-4 py-2.5 rounded-xl text-xs flex items-center justify-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span>[✓] ACC Baik (Pulihkan)</span>
                                    </button>
                                </form>

                                <!-- ACC Rusak / Hilang Button (Opens Modal) -->
                                <button type="button" onclick="openModalRusak('<?= $loan['id_loan'] ?>', '<?= htmlspecialchars(addslashes($loan['nama_peminjam'])) ?>', '<?= htmlspecialchars(addslashes($loan['nama_apd'])) ?>', '<?= $loan['jumlah_pinjam'] ?>')"
                                    class="btn-k3-red w-full touch-target px-4 py-2.5 rounded-xl text-xs flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span>[!] ACC Rusak/Hilang</span>
                                </button>

                                <!-- Cancel False Entry Button -->
                                <form action="acc_proses.php" method="POST" onsubmit="return confirm('Batalkan entri peminjaman ini karena salah input atau fiktif? Stok barang akan segera dipulihkan.')" class="w-full">
                                    <input type="hidden" name="action" value="cancel_loan">
                                    <input type="hidden" name="id_loan" value="<?= $loan['id_loan'] ?>">
                                    <button type="submit" class="w-full py-2 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-800 text-[11px] font-semibold border border-slate-200 transition-colors flex items-center justify-center gap-1.5" title="Batalkan jika salah input">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        <span>Batal (Fiktif)</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- SECTION: RIWAYAT 10 ACC TERAKHIR -->
        <section class="space-y-4 pt-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Riwayat Verifikasi Pengembalian</h3>
                    <p class="text-xs text-slate-500 mt-0.5">10 Transaksi terakhir yang telah diinspeksi oleh Pengawas</p>
                </div>
            </div>

            <!-- Mobile View: Card List (<640px) -->
            <div class="block sm:hidden space-y-2.5">
                <?php if (empty($recentReturns)): ?>
                    <div class="bg-white rounded-2xl p-6 text-center text-xs text-slate-500 border border-slate-200">
                        Belum ada riwayat pengembalian yang tercatat.
                    </div>
                <?php else: ?>
                    <?php foreach ($recentReturns as $r): ?>
                        <div class="bg-white rounded-xl p-3.5 space-y-2 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-mono font-bold text-[#005b96]"><?= htmlspecialchars($r['kode_pinjam']) ?></span>
                                <?php if ($r['kondisi_kembali'] === 'BAIK'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        BAIK
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-700 border border-red-200">
                                        <?= htmlspecialchars($r['kondisi_kembali']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs">
                                <div class="font-bold text-slate-900"><?= htmlspecialchars($r['nama_peminjam']) ?> (<?= htmlspecialchars($r['bagian_unit']) ?>)</div>
                                <div class="text-slate-600 mt-0.5"><?= htmlspecialchars($r['nama_apd']) ?> &bull; <?= $r['jumlah_pinjam'] ?> Unit</div>
                            </div>
                            <?php if (!empty($r['keterangan'])): ?>
                                <div class="text-[11px] text-slate-600 bg-slate-50 p-2 rounded-lg border border-slate-200">
                                    Catatan: <?= htmlspecialchars($r['keterangan']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-[10px] text-slate-400 flex justify-between pt-1 border-t border-slate-100">
                                <span>ACC: <?= htmlspecialchars($r['nama_pengawas'] ?? 'Pengawas') ?></span>
                                <span><?= date('d/m H:i', strtotime($r['waktu_acc'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Desktop View: Table (>=640px) -->
            <div class="hidden sm:block bg-white rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider border-b border-slate-200 font-bold text-[11px]">
                            <tr>
                                <th class="px-5 py-3">Kode Pinjam</th>
                                <th class="px-5 py-3">Peminjam</th>
                                <th class="px-5 py-3">Peralatan APD</th>
                                <th class="px-5 py-3 text-center">Kondisi Fisik</th>
                                <th class="px-5 py-3">Catatan Audit</th>
                                <th class="px-5 py-3">Pengawas ACC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($recentReturns)): ?>
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-slate-500">Belum ada riwayat pengembalian yang tercatat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentReturns as $r): ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="px-5 py-3 font-mono font-bold text-[#005b96]"><?= htmlspecialchars($r['kode_pinjam']) ?></td>
                                        <td class="px-5 py-3">
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($r['nama_peminjam']) ?></div>
                                            <div class="text-[11px] text-slate-500"><?= htmlspecialchars($r['bagian_unit']) ?></div>
                                        </td>
                                        <td class="px-5 py-3 text-slate-800"><?= htmlspecialchars($r['nama_apd']) ?> (<?= $r['jumlah_pinjam'] ?>)</td>
                                        <td class="px-5 py-3 text-center">
                                            <?php if ($r['kondisi_kembali'] === 'BAIK'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">BAIK</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-700 border border-red-200">
                                                    <?= htmlspecialchars($r['kondisi_kembali']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-slate-600 max-w-xs truncate"><?= htmlspecialchars($r['keterangan'] ?? '-') ?></td>
                                        <td class="px-5 py-3 text-slate-600">
                                            <div><?= htmlspecialchars($r['nama_pengawas'] ?? 'Pengawas') ?></div>
                                            <div class="text-[10px] text-slate-400"><?= date('d/m/Y H:i', strtotime($r['waktu_acc'])) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- Modal Form ACC Rusak / Hilang -->
    <div id="modal-rusak" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 space-y-4 border border-slate-200 shadow-2xl animate-in fade-in duration-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2 text-red-600 font-bold text-sm sm:text-base">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>ACC Pengembalian: Rusak / Hilang</span>
                </div>
                <button type="button" onclick="closeModalRusak()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 bg-slate-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Target Item Summary -->
            <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs space-y-1">
                <div>Peminjam: <strong id="modal-nama" class="text-slate-900">-</strong></div>
                <div>Barang: <strong id="modal-apd" class="text-slate-900">-</strong> (<span id="modal-jumlah">1</span> Unit)</div>
                <p class="text-[11px] text-amber-700 pt-1 leading-relaxed">
                    * Perhatian: Unit ini akan dialihkan ke <strong>Stok Rusak</strong> dan dipisahkan dari inventaris siap pakai hingga diperbaiki oleh Admin.
                </p>
            </div>

            <form action="acc_proses.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="acc_return">
                <input type="hidden" id="modal-id-loan" name="id_loan" value="">

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Klasifikasi Kondisi Fisik</label>
                    <select name="kondisi_kembali" required class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs sm:text-sm">
                        <option value="RUSAK_RINGAN">Rusak Ringan (Bisa Diperbaiki)</option>
                        <option value="RUSAK_BERAT" selected>Rusak Berat (Afkir / Perlu Penggantian)</option>
                        <option value="HILANG">Barang Hilang di Lapangan</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Catatan Kerusakan / Kronologi Singkat</label>
                    <textarea name="keterangan" rows="3" required placeholder="Contoh: Tali pengikat helm putus, lensa visor kacamata retak, dsb."
                        class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs sm:text-sm placeholder-slate-400"></textarea>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModalRusak()" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 text-xs font-semibold">
                        Batal
                    </button>
                    <button type="submit" class="btn-k3-red px-5 py-2.5 rounded-xl text-xs font-bold shadow-md">
                        Simpan ACC Rusak
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Bottom Navigation for Pengawas -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/pengawas/index.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Verifikasi ACC</span>
        </a>
        <a href="/index.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Portal Publik</span>
        </a>
        <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="/admin/index.php" class="mobile-nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/>
                </svg>
                <span>Menu Admin</span>
            </a>
        <?php endif; ?>
        <a href="/logout.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013 3v1"/>
            </svg>
            <span>Keluar</span>
        </a>
    </nav>

    <script>
        function openModalRusak(idLoan, namaPeminjam, namaApd, jumlah) {
            document.getElementById('modal-id-loan').value = idLoan;
            document.getElementById('modal-nama').textContent = namaPeminjam;
            document.getElementById('modal-apd').textContent = namaApd;
            document.getElementById('modal-jumlah').textContent = jumlah;
            
            const modal = document.getElementById('modal-rusak');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeModalRusak() {
            const modal = document.getElementById('modal-rusak');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
</body>
</html>
