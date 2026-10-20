<?php
/**
 * Dashboard Pengawas: Otorisasi Pengembalian Satu Pintu & Pemeriksaan Fisik APD
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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pengawas K3 - Otorisasi ACC Pengembalian</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col">

    <!-- Top Navigation -->
    <header class="bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-emerald-500 text-slate-950 flex items-center justify-center font-bold shadow-md shadow-emerald-500/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-white block leading-tight">DASHBOARD PENGAWAS</span>
                    <span class="text-[10px] text-emerald-400 font-semibold tracking-wider uppercase">Verifikasi Fisik & ACC Pengembalian</span>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div class="hidden sm:flex flex-col text-right text-xs">
                    <strong class="text-slate-200"><?= htmlspecialchars($currentUser['nama_lengkap']) ?></strong>
                    <span class="text-slate-400 capitalize">Role: <?= htmlspecialchars($currentUser['role']) ?></span>
                </div>

                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/index.php" class="text-xs px-3 py-1.5 rounded-lg bg-amber-500 text-slate-950 font-bold hover:bg-amber-400 transition-all">
                        Ke Menu Admin
                    </a>
                <?php endif; ?>

                <a href="/logout.php" class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:bg-red-500 hover:text-white border border-slate-700 transition-all flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Keluar
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow space-y-8 w-full">

        <!-- Banner Flash Alert -->
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

        <!-- Panel Status & Indikator Validasi WiFi -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 block font-medium">Menunggu Otorisasi ACC</span>
                    <strong class="text-3xl font-extrabold text-amber-400 mt-1 block"><?= count($activeLoans) ?></strong>
                    <span class="text-[11px] text-slate-500">Unit APD belum kembali</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-400 block font-medium">Selesai Di-ACC Hari Ini</span>
                    <strong class="text-3xl font-extrabold text-emerald-400 mt-1 block"><?= $countReturnedToday ?></strong>
                    <span class="text-[11px] text-slate-500">Pemeriksaan fisik rampung</span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-400 font-medium">Validasi Jaringan WiFi</span>
                    <?php if ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                            AKTIF
                        </span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 border border-slate-700">
                            NONAKTIF
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-slate-300">
                    <div class="font-semibold text-white truncate"><?= htmlspecialchars($wifiSettings['nama_jaringan'] ?? 'WiFi Kantor') ?></div>
                    <div class="text-[11px] text-slate-500 mt-0.5">IP Klien: <span class="font-mono text-slate-400"><?= htmlspecialchars($currentClientIp) ?></span></div>
                </div>
            </div>
        </div>

        <!-- SECTION DAFTAR APD SEDANG DIPINJAM (ACTION ACC) -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-900/60">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <span>Peminjaman Aktif Memerlukan Pemeriksaan</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400">
                            <?= count($activeLoans) ?> Antrean
                        </span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Lakukan inspeksi fisik saat peminjam menyerahkan barang di ruang APD</p>
                </div>
            </div>

            <?php if (empty($activeLoans)): ?>
                <div class="p-12 text-center space-y-3">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/10 text-emerald-400 mx-auto flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h4 class="font-bold text-white text-base">Tidak Ada Peminjaman Aktif</h4>
                    <p class="text-xs text-slate-400 max-w-sm mx-auto">
                        Seluruh unit APD yang dipinjam saat ini sudah dikembalikan atau belum ada peminjaman baru dari pekerja lapangan.
                    </p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-800">
                    <?php foreach ($activeLoans as $loan): ?>
                        <div class="p-5 sm:p-6 hover:bg-slate-800/30 transition-colors flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                            
                            <!-- Detail Info Peminjaman -->
                            <div class="space-y-2 flex-grow">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <span class="px-2.5 py-1 rounded-md bg-slate-800 border border-slate-700 font-mono text-xs font-bold text-amber-400">
                                        <?= htmlspecialchars($loan['kode_pinjam']) ?>
                                    </span>
                                    <span class="text-xs text-slate-400 flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Dipinjam: <?= date('d M Y, H:i', strtotime($loan['waktu_pinjam'])) ?> WITA
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1 text-xs">
                                    <div>
                                        <span class="text-slate-500 block">Identitas Peminjam</span>
                                        <strong class="text-sm text-white"><?= htmlspecialchars($loan['nama_peminjam']) ?></strong>
                                        <span class="text-slate-400 block"><?= htmlspecialchars($loan['bagian_unit']) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-slate-500 block">Peralatan APD</span>
                                        <strong class="text-sm text-white"><?= htmlspecialchars($loan['nama_apd']) ?></strong>
                                        <span class="text-slate-400 block"><?= htmlspecialchars($loan['ukuran_spesifikasi'] ?? 'Standard') ?></span>
                                    </div>
                                    <div>
                                        <span class="text-slate-500 block">Jumlah & Kontak</span>
                                        <strong class="text-sm text-amber-400"><?= $loan['jumlah_pinjam'] ?> Unit</strong>
                                        <span class="text-slate-400 block font-mono">WA: <?= htmlspecialchars($loan['no_hp_peminjam']) ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Tombol Aksi Otorisasi ACC (Kondisi Baik vs Rusak) -->
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 flex-shrink-0">
                                <!-- Tombol ACC Baik -->
                                <form action="acc_proses.php" method="POST" onsubmit="return confirm('Konfirmasi ACC Pengembalian Kondisi Baik untuk <?= htmlspecialchars(addslashes($loan['nama_peminjam'])) ?>? Stok akan dipulihkan ke rak siap pakai.')">
                                    <input type="hidden" name="action" value="acc_return">
                                    <input type="hidden" name="id_loan" value="<?= $loan['id_loan'] ?>">
                                    <input type="hidden" name="kondisi_kembali" value="BAIK">
                                    <input type="hidden" name="keterangan" value="Fisik utuh, bersih, dan layak pakai">
                                    <button type="submit"
                                        class="w-full sm:w-auto px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-lg shadow-emerald-600/20 active:scale-95 transition-all flex items-center justify-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span>[✓] ACC Baik</span>
                                    </button>
                                </form>

                                <!-- Tombol ACC Rusak / Hilang (Buka Modal) -->
                                <button type="button" onclick="openModalRusak('<?= $loan['id_loan'] ?>', '<?= htmlspecialchars(addslashes($loan['nama_peminjam'])) ?>', '<?= htmlspecialchars(addslashes($loan['nama_apd'])) ?>', '<?= $loan['jumlah_pinjam'] ?>')"
                                    class="px-4 py-2.5 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold shadow-lg shadow-red-600/20 active:scale-95 transition-all flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span>[!] ACC Rusak/Hilang</span>
                                </button>

                                <!-- Tombol Batalkan Entri Fiktif -->
                                <form action="acc_proses.php" method="POST" onsubmit="return confirm('Batalkan entri peminjaman ini karena salah input atau fiktif? Stok akan langsung dipulihkan.')">
                                    <input type="hidden" name="action" value="cancel_loan">
                                    <input type="hidden" name="id_loan" value="<?= $loan['id_loan'] ?>">
                                    <button type="submit" title="Batalkan jika entri fiktif/salah input"
                                        class="px-3 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-slate-200 text-xs border border-slate-700 transition-all flex items-center justify-center">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIWAYAT 10 ACC TERAKHIR -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                <h3 class="font-bold text-white text-base">Riwayat Verifikasi Pengembalian Terakhir</h3>
                <span class="text-xs text-slate-500">10 Entri Terbaru</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider border-b border-slate-800 font-semibold">
                        <tr>
                            <th class="px-5 py-3">Kode Pinjam</th>
                            <th class="px-5 py-3">Peminjam & Unit</th>
                            <th class="px-5 py-3">Peralatan APD</th>
                            <th class="px-5 py-3">Kondisi Fisik</th>
                            <th class="px-5 py-3">Catatan Audit</th>
                            <th class="px-5 py-3">Pengawas ACC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($recentReturns)): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-slate-500">Belum ada riwayat pengembalian yang tercatat.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentReturns as $r): ?>
                                <tr class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3 font-mono font-bold text-slate-300"><?= htmlspecialchars($r['kode_pinjam']) ?></td>
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-white"><?= htmlspecialchars($r['nama_peminjam']) ?></div>
                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($r['bagian_unit']) ?></div>
                                    </td>
                                    <td class="px-5 py-3 text-white"><?= htmlspecialchars($r['nama_apd']) ?> (<?= $r['jumlah_pinjam'] ?>)</td>
                                    <td class="px-5 py-3">
                                        <?php if ($r['kondisi_kembali'] === 'BAIK'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">BAIK</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-500/10 text-red-400 border border-red-500/20">
                                                <?= htmlspecialchars($r['kondisi_kembali']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-slate-400 max-w-xs truncate"><?= htmlspecialchars($r['keterangan'] ?? '-') ?></td>
                                    <td class="px-5 py-3 text-slate-400">
                                        <div><?= htmlspecialchars($r['nama_pengawas'] ?? 'Pengawas') ?></div>
                                        <div class="text-[10px] text-slate-500"><?= date('d/m/Y H:i', strtotime($r['waktu_acc'])) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Form ACC Rusak / Hilang -->
    <div id="modal-rusak" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl space-y-5 animate-in fade-in duration-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                <div class="flex items-center gap-2 text-red-400 font-bold text-base">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>ACC Pengembalian Fisik: Rusak / Hilang</span>
                </div>
                <button type="button" onclick="closeModalRusak()" class="text-slate-400 hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="bg-slate-950/70 p-3.5 rounded-xl border border-slate-800 text-xs space-y-1">
                <div>Peminjam: <strong id="modal-nama" class="text-white">-</strong></div>
                <div>Barang: <strong id="modal-apd" class="text-white">-</strong> (<span id="modal-jumlah">1</span> Unit)</div>
                <p class="text-[11px] text-amber-400 pt-1">
                    * Catatan: Unit ini akan dialihkan ke 'Stok Rusak' dan tidak akan dipulihkan ke stok siap pakai sebelum diperbaiki oleh Admin.
                </p>
            </div>

            <form action="acc_proses.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="acc_return">
                <input type="hidden" id="modal-id-loan" name="id_loan" value="">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Klasifikasi Kondisi Fisik</label>
                    <select name="kondisi_kembali" required
                        class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-red-500 focus:outline-none">
                        <option value="RUSAK_RINGAN">Rusak Ringan (Bisa Diperbaiki)</option>
                        <option value="RUSAK_BERAT" selected>Rusak Berat (Afkir / Perlu Penggantian)</option>
                        <option value="HILANG">Barang Hilang di Lapangan</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Catatan Kerusakan / Kronologi Singkat</label>
                    <textarea name="keterangan" rows="3" required
                        placeholder="Contoh: Tali penahan putus, visor kacamata retak, dsb."
                        class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs placeholder-slate-500 focus:ring-2 focus:ring-red-500 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModalRusak()"
                        class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs font-semibold">
                        Batal
                    </button>
                    <button type="submit"
                        class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold shadow-md shadow-red-600/30">
                        Simpan ACC Rusak
                    </button>
                </div>
            </form>
        </div>
    </div>

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
