<?php
/**
 * Modul Laporan Audit K3, Penyaringan Riwayat Sirkulasi & Ekspor PDF / Excel
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 * Tema Korporat Resmi: PT Pelabuhan Indonesia (Pelindo) Light Palette
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

/**
 * Mengambil dataset laporan audit berdasarkan parameter filter
 *
 * @param PDO $pdo
 * @param array $filters
 * @return array{loans: array, summary: array}
 */
function fetchAuditReportData(PDO $pdo, array $filters): array {
    $startDate  = $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate    = $filters['end_date'] ?? date('Y-m-d');
    $status     = strtoupper((string)($filters['status'] ?? 'ALL'));
    $idCategory = (int)($filters['id_category'] ?? 0);

    $whereClauses = ["DATE(l.waktu_pinjam) BETWEEN ? AND ?"];
    $bindings = [$startDate, $endDate];

    if ($idCategory > 0) {
        $whereClauses[] = "i.id_category = ?";
        $bindings[] = $idCategory;
    }

    if ($status === 'DIPINJAM') {
        $whereClauses[] = "l.status_pinjam = 'DIPINJAM'";
    } elseif ($status === 'BAIK') {
        $whereClauses[] = "c.kondisi_kembali = 'BAIK'";
    } elseif ($status === 'RUSAK') {
        $whereClauses[] = "c.kondisi_kembali IN ('RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG')";
    }

    $whereSql = implode(' AND ', $whereClauses);

    $sql = "
        SELECT 
            l.id_loan, l.kode_pinjam, l.nama_peminjam, l.bagian_unit, l.no_hp_peminjam,
            l.jumlah_pinjam, l.waktu_pinjam, l.waktu_kembali, l.status_pinjam,
            i.kode_apd, i.nama_apd, i.ukuran_spesifikasi,
            cat.nama_kategori,
            c.kondisi_kembali, c.keterangan AS catatan_kerusakan, c.waktu_acc,
            u.nama_lengkap AS nama_pengawas
        FROM loans l
        JOIN apd_items i ON l.id_apd = i.id_apd
        JOIN apd_categories cat ON i.id_category = cat.id_category
        LEFT JOIN condition_logs c ON l.id_loan = c.id_loan
        LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
        WHERE {$whereSql}
        ORDER BY l.waktu_pinjam DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung ringkasan metrik periode
    $totalTransaksi = count($loans);
    $totalUnit = 0;
    $totalDipinjam = 0;
    $totalKembaliBaik = 0;
    $totalRusak = 0;

    foreach ($loans as $row) {
        $qty = (int)$row['jumlah_pinjam'];
        $totalUnit += $qty;

        if ($row['status_pinjam'] === 'DIPINJAM') {
            $totalDipinjam += $qty;
        } elseif ($row['kondisi_kembali'] === 'BAIK') {
            $totalKembaliBaik += $qty;
        } else {
            $totalRusak += $qty;
        }
    }

    $summary = [
        'total_transaksi'    => $totalTransaksi,
        'total_unit'         => $totalUnit,
        'total_dipinjam'     => $totalDipinjam,
        'total_kembali_baik' => $totalKembaliBaik,
        'total_rusak'        => $totalRusak
    ];

    return [
        'loans'   => $loans,
        'summary' => $summary
    ];
}

/**
 * Generator file CSV untuk ekspor Excel yang kompatibel
 */
function generateAuditCsv(array $loans): string {
    $fp = fopen('php://temp', 'r+');
    if (!$fp) {
        return '';
    }

    // Tulis UTF-8 BOM agar terbaca sempurna di Microsoft Excel
    fwrite($fp, "\xEF\xBB\xBF");

    // Header Kolom
    fputcsv($fp, [
        'NO',
        'KODE PINJAM',
        'NAMA PEMINJAM',
        'BAGIAN / UNIT',
        'NO. WHATSAPP',
        'KODE APD',
        'NAMA PERALATAN APD',
        'KATEGORI APD',
        'JUMLAH (UNIT)',
        'WAKTU PINJAM',
        'WAKTU KEMBALI',
        'STATUS',
        'KONDISI FISIK',
        'CATATAN AUDIT',
        'PENGAWAS ACC'
    ]);

    $no = 1;
    foreach ($loans as $row) {
        $kondisi = $row['kondisi_kembali'] ?? ($row['status_pinjam'] === 'DIPINJAM' ? 'SEDANG DIPINJAM' : 'SELESAI');
        fputcsv($fp, [
            $no++,
            $row['kode_pinjam'],
            $row['nama_peminjam'],
            $row['bagian_unit'],
            $row['no_hp_peminjam'],
            $row['kode_apd'],
            $row['nama_apd'],
            $row['nama_kategori'],
            $row['jumlah_pinjam'],
            $row['waktu_pinjam'],
            $row['waktu_kembali'] ?? '-',
            $row['status_pinjam'],
            $kondisi,
            $row['catatan_kerusakan'] ?? '-',
            $row['nama_pengawas'] ?? '-'
        ]);
    }

    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);

    return $csv ?: '';
}

// Router Eksekusi Web
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    checkAuth(['admin', 'pengawas']);
    $pdo = getDbConnection();
    $currentUser = getLoggedInUser();

    $startDate  = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
    $endDate    = trim((string)($_GET['end_date'] ?? date('Y-m-d')));
    $status     = trim((string)($_GET['status'] ?? 'ALL'));
    $idCategory = (int)($_GET['id_category'] ?? 0);
    $export     = (string)($_GET['export'] ?? '');

    $filters = [
        'start_date'  => $startDate,
        'end_date'    => $endDate,
        'status'      => $status,
        'id_category' => $idCategory
    ];

    $data = fetchAuditReportData($pdo, $filters);
    $loans = $data['loans'];
    $summary = $data['summary'];

    // Handler Ekspor CSV / Excel
    if ($export === 'csv') {
        $filename = sprintf('Laporan_Sirkulasi_APD_%s_%s.csv', str_replace('-', '', $startDate), str_replace('-', '', $endDate));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo generateAuditCsv($loans);
        exit;
    }

    $categories = $pdo->query("SELECT * FROM apd_categories ORDER BY id_category ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Laporan Audit Sirkulasi APD - Pelindo K3</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Top Navigation Pelindo (no-print) -->
    <header class="no-print bg-[#003e74] text-white sticky top-0 z-40 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="/admin/index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo" class="w-7 h-7">
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <img src="/assets/img/pelindo-logo-white.svg" alt="Pelindo" class="h-4 sm:h-5 w-auto">
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">AUDIT K3</span>
                    </div>
                    <span class="text-[10px] text-sky-200 font-mono tracking-wider uppercase block">PT Pelabuhan Indonesia (Persero)</span>
                </div>
            </a>

            <!-- Desktop Nav Menu -->
            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-sky-100">
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/index.php" class="hover:text-white transition-colors">Dashboard</a>
                    <a href="/admin/kelola_apd.php" class="hover:text-white transition-colors">Master APD</a>
                    <a href="/admin/kelola_pengawas.php" class="hover:text-white transition-colors">Pengawas & WiFi</a>
                <?php else: ?>
                    <a href="/pengawas/index.php" class="hover:text-white transition-colors">Verifikasi Pengawas</a>
                <?php endif; ?>
                <a href="/admin/laporan.php" class="text-white font-bold border-b-2 border-sky-300 pb-1">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-2.5">
                <button onclick="window.print()" class="px-3.5 py-1.5 rounded-lg text-xs flex items-center gap-1.5 bg-white/10 hover:bg-white/20 text-white font-semibold transition-colors border border-white/20 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Cetak PDF</span>
                </button>
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

        <!-- FORM FILTER PARAMETER AUDIT (no-print) -->
        <section class="no-print bg-white rounded-2xl p-5 sm:p-6 space-y-4 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                <h2 class="text-xs sm:text-sm font-bold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-[#005b96]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    <span>Filter Parameter Audit Sirkulasi APD</span>
                </h2>
                <span class="text-[11px] text-slate-500 font-mono"><?= count($loans) ?> Data Ditemukan</span>
            </div>

            <form method="GET" action="laporan.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"
                        class="sipad-input w-full px-3 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1">Tanggal Sampai</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                        class="sipad-input w-full px-3 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1">Kategori APD</label>
                    <select name="id_category" class="sipad-input w-full px-3 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                        <option value="0">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id_category'] ?>" <?= ($idCategory === (int)$cat['id_category']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nama_kategori']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-700 mb-1">Status Verifikasi</label>
                    <select name="status" class="sipad-input w-full px-3 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                        <option value="ALL" <?= ($status === 'ALL') ? 'selected' : '' ?>>Semua Status</option>
                        <option value="DIPINJAM" <?= ($status === 'DIPINJAM') ? 'selected' : '' ?>>Sedang Dipinjam</option>
                        <option value="BAIK" <?= ($status === 'BAIK') ? 'selected' : '' ?>>Kembali - Baik</option>
                        <option value="RUSAK" <?= ($status === 'RUSAK') ? 'selected' : '' ?>>Kembali - Rusak/Hilang</option>
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-1 flex items-end gap-2 pt-1 sm:pt-0">
                    <button type="submit" class="btn-pelindo-primary flex-grow py-2 px-3 rounded-xl text-xs font-bold shadow-sm">
                        Terapkan
                    </button>
                    <?php
                        $exportUrl = "laporan.php?start_date=" . urlencode($startDate) .
                            "&end_date=" . urlencode($endDate) .
                            "&id_category=" . $idCategory .
                            "&status=" . urlencode($status) .
                            "&export=csv";
                    ?>
                    <a href="<?= $exportUrl ?>" title="Unduh File Excel (.CSV)"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-3 rounded-xl text-xs flex items-center justify-center shadow-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </a>
                </div>
            </form>
        </section>

        <!-- OFFICIAL PRINT HEADER (ONLY VISIBLE ON PRINT / PDF) -->
        <div class="hidden print:block text-slate-900 border-b-2 border-slate-900 pb-4 mb-6">
            <div class="flex items-center justify-between gap-4 mb-2">
                <img src="/assets/img/pelindo-logo.svg" alt="Pelindo Logo" class="h-10 w-auto">
                <div class="text-right text-[10px] text-slate-500 font-mono">
                    Sistem SIPAD K3 Pelabuhan<br>
                    Dicetak: <?= date('d/m/Y H:i') ?> WIB
                </div>
            </div>
            <div class="text-center space-y-1">
                <div class="text-xs font-bold text-[#003e74] uppercase tracking-widest">PT PELABUHAN INDONESIA (PERSERO)</div>
                <h1 class="text-xl font-black uppercase tracking-tight text-slate-900">LAPORAN REKAPITULASI DAN AUDIT SIRKULASI APD</h1>
                <h2 class="text-xs font-semibold tracking-wide text-slate-600">DIVISI KESELAMATAN DAN KESEHATAN KERJA (K3) &bull; SIPAD K3</h2>
                <p class="text-xs text-slate-500 pt-1">
                    Periode Laporan: <strong><?= date('d M Y', strtotime($startDate)) ?></strong> s/d <strong><?= date('d M Y', strtotime($endDate)) ?></strong>
                </p>
            </div>
        </div>

        <!-- SUMMARY CARDS (LIGHT THEME) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-white rounded-2xl p-4 border border-slate-200 border-t-4 border-t-[#003e74] shadow-sm">
                <span class="text-xs text-slate-500 block font-semibold uppercase">Total Transaksi</span>
                <strong class="text-2xl font-black text-slate-900 mt-1 block"><?= $summary['total_transaksi'] ?></strong>
                <span class="text-[11px] text-slate-400"><?= $summary['total_unit'] ?> Unit Terkelola</span>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-slate-200 border-t-4 border-t-amber-500 shadow-sm">
                <span class="text-xs text-slate-500 block font-semibold uppercase">Sedang Dipinjam</span>
                <strong class="text-2xl font-black text-amber-600 mt-1 block"><?= $summary['total_dipinjam'] ?></strong>
                <span class="text-[11px] text-slate-400">Unit di lapangan</span>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-slate-200 border-t-4 border-t-emerald-500 shadow-sm">
                <span class="text-xs text-slate-500 block font-semibold uppercase">Kembali Baik</span>
                <strong class="text-2xl font-black text-emerald-600 mt-1 block"><?= $summary['total_kembali_baik'] ?></strong>
                <span class="text-[11px] text-slate-400">Dipulihkan ke rak</span>
            </div>

            <div class="bg-white rounded-2xl p-4 border border-slate-200 border-t-4 border-t-red-500 shadow-sm">
                <span class="text-xs text-slate-500 block font-semibold uppercase">Rusak / Hilang</span>
                <strong class="text-2xl font-black text-red-600 mt-1 block"><?= $summary['total_rusak'] ?></strong>
                <span class="text-[11px] text-slate-400">Dialihkan ke perbaikan</span>
            </div>
        </div>

        <!-- SECTION: DATA TRANSAKSI AUDIT -->
        <section class="space-y-4">
            
            <!-- MOBILE VIEW: CARD LIST (<640px) -->
            <div class="block sm:hidden space-y-3">
                <?php if (empty($loans)): ?>
                    <div class="bg-white rounded-2xl p-8 text-center text-xs text-slate-400 border border-slate-200 shadow-sm">
                        Tidak ada data sirkulasi yang sesuai dengan parameter filter.
                    </div>
                <?php else: ?>
                    <?php foreach ($loans as $l): ?>
                        <div class="bg-white rounded-xl p-4 space-y-2.5 border border-slate-200 shadow-sm">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-mono font-bold text-[#005b96]"><?= htmlspecialchars($l['kode_pinjam']) ?></span>
                                <?php if ($l['status_pinjam'] === 'DIPINJAM'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                        Dipinjam
                                    </span>
                                <?php elseif ($l['kondisi_kembali'] === 'BAIK'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                        Kembali (Baik)
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800 border border-red-200">
                                        <?= htmlspecialchars($l['kondisi_kembali']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="text-xs">
                                <div class="font-bold text-slate-900"><?= htmlspecialchars($l['nama_peminjam']) ?></div>
                                <div class="text-slate-500 text-[11px]"><?= htmlspecialchars($l['bagian_unit']) ?> &bull; WA: <?= htmlspecialchars($l['no_hp_peminjam']) ?></div>
                            </div>

                            <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200 text-xs flex justify-between items-center">
                                <div>
                                    <strong class="text-slate-900"><?= htmlspecialchars($l['nama_apd']) ?></strong>
                                    <div class="text-[10px] text-slate-500"><?= htmlspecialchars($l['nama_kategori']) ?></div>
                                </div>
                                <span class="font-mono font-bold text-[#003e74]"><?= $l['jumlah_pinjam'] ?> Unit</span>
                            </div>

                            <?php if (!empty($l['catatan_kerusakan'])): ?>
                                <div class="text-[11px] text-red-700 bg-red-50 border border-red-200 p-2 rounded-lg">
                                    Catatan: <?= htmlspecialchars($l['catatan_kerusakan']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="text-[10px] text-slate-500 flex justify-between pt-1 border-t border-slate-100">
                                <span>Waktu: <?= date('d/m/Y H:i', strtotime($l['waktu_pinjam'])) ?></span>
                                <span>ACC: <?= htmlspecialchars($l['nama_pengawas'] ?? '-') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- DESKTOP VIEW: STRUCTURED AUDIT TABLE (>=640px) -->
            <div class="hidden sm:block bg-white rounded-2xl overflow-hidden border border-slate-200 shadow-sm print:shadow-none print:border-slate-300">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-700">
                        <thead class="bg-slate-50 text-slate-700 uppercase tracking-wider border-b border-slate-200 font-bold text-[11px]">
                            <tr>
                                <th class="px-4 py-3">No</th>
                                <th class="px-4 py-3">Kode Pinjam</th>
                                <th class="px-4 py-3">Peminjam & Unit</th>
                                <th class="px-4 py-3">Peralatan APD</th>
                                <th class="px-4 py-3 text-center">Qty</th>
                                <th class="px-4 py-3">Waktu Pinjam</th>
                                <th class="px-4 py-3">Status / Kondisi</th>
                                <th class="px-4 py-3">Pengawas ACC</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 print:divide-slate-200">
                            <?php if (empty($loans)): ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                        Tidak ada data sirkulasi peminjaman yang sesuai dengan filter yang dipilih.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; foreach ($loans as $l): ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="px-4 py-3 text-slate-400"><?= $no++ ?></td>
                                        <td class="px-4 py-3 font-mono font-bold text-[#005b96]">
                                            <?= htmlspecialchars($l['kode_pinjam']) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($l['nama_peminjam']) ?></div>
                                            <div class="text-[11px] text-slate-500"><?= htmlspecialchars($l['bagian_unit']) ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-slate-900 font-semibold"><?= htmlspecialchars($l['nama_apd']) ?></div>
                                            <div class="text-[11px] text-slate-500"><?= htmlspecialchars($l['nama_kategori']) ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-center font-bold text-slate-900"><?= $l['jumlah_pinjam'] ?></td>
                                        <td class="px-4 py-3 text-slate-600">
                                            <?= date('d/m/Y H:i', strtotime($l['waktu_pinjam'])) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($l['status_pinjam'] === 'DIPINJAM'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                                    Dipinjam
                                                </span>
                                            <?php elseif ($l['kondisi_kembali'] === 'BAIK'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                    Kembali (Baik)
                                                </span>
                                            <?php else: ?>
                                                <div>
                                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800 border border-red-200">
                                                        <?= htmlspecialchars($l['kondisi_kembali']) ?>
                                                    </span>
                                                    <?php if (!empty($l['catatan_kerusakan'])): ?>
                                                        <div class="text-[10px] text-red-600 mt-0.5 truncate max-w-xs">
                                                            Ket: <?= htmlspecialchars($l['catatan_kerusakan']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600">
                                            <?= htmlspecialchars($l['nama_pengawas'] ?? '-') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- OFFICIAL SIGNATURE BLOCK (PRINT ONLY) -->
        <div class="hidden print:grid grid-cols-2 pt-12 text-center text-xs text-slate-900">
            <div class="space-y-16">
                <div>Diverifikasi oleh Pengawas K3,</div>
                <div>( .................................................. )</div>
            </div>
            <div class="space-y-16">
                <div>Mengetahui, Koordinator K3 & Inventaris</div>
                <div>( .................................................. )</div>
            </div>
        </div>

    </main>

    <!-- Mobile Bottom Navigation for Admin / Supervisor (Pelindo Light) -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="/admin/index.php" class="mobile-nav-item">
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
        <?php else: ?>
            <a href="/pengawas/index.php" class="mobile-nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Verifikasi</span>
            </a>
        <?php endif; ?>
        <a href="/admin/laporan.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span>Laporan</span>
        </a>
    </nav>
</body>
</html>
<?php
}
