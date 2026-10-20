<?php
/**
 * Modul Laporan Audit K3, Penyaringan Riwayat Sirkulasi & Ekspor PDF / Excel
 * Sistem Inventaris dan Peminjaman APD (Sipad)
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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Audit Sirkulasi APD - SIPAD K3</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .print-border { border: 1px solid #cbd5e1 !important; }
            .print-bg-gray { background-color: #f8fafc !important; color: #0f172a !important; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col">

    <!-- Navbar Navigasi (no-print) -->
    <header class="no-print bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-white block leading-tight">AUDIT & LAPORAN SIRKULASI</span>
                    <span class="text-[10px] text-amber-400 font-semibold tracking-wider uppercase">Standar Permenakertrans PER.08/MEN/VII/2010</span>
                </div>
            </div>

            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-slate-300">
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/index.php" class="hover:text-amber-400 transition-colors">Dashboard</a>
                    <a href="/admin/kelola_apd.php" class="hover:text-amber-400 transition-colors">Master APD</a>
                    <a href="/admin/kelola_pengawas.php" class="hover:text-amber-400 transition-colors">Kelola Pengawas</a>
                <?php else: ?>
                    <a href="/pengawas/index.php" class="hover:text-amber-400 transition-colors">Dashboard Pengawas</a>
                <?php endif; ?>
                <a href="/admin/laporan.php" class="text-amber-400">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="text-xs px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold transition-all flex items-center gap-1.5 shadow-md">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    <span>Cetak PDF</span>
                </button>
                <a href="/logout.php" class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white border border-slate-700 transition-all">
                    Keluar
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow space-y-8 w-full">

        <!-- FORM FILTER PENYARINGAN (no-print) -->
        <div class="no-print bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-4">
            <h2 class="text-base font-bold text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                </svg>
                <span>Filter Parameter Audit Sirkulasi APD</span>
            </h2>

            <form method="GET" action="laporan.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Tanggal Sampai</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Kategori APD</label>
                    <select name="id_category"
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                        <option value="0">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id_category'] ?>" <?= ($idCategory === (int)$cat['id_category']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nama_kategori']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Status Verifikasi</label>
                    <select name="status"
                        class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                        <option value="ALL" <?= ($status === 'ALL') ? 'selected' : '' ?>>Semua Status Transaksi</option>
                        <option value="DIPINJAM" <?= ($status === 'DIPINJAM') ? 'selected' : '' ?>>Sedang Dipinjam</option>
                        <option value="BAIK" <?= ($status === 'BAIK') ? 'selected' : '' ?>>Kembali - Kondisi Baik</option>
                        <option value="RUSAK" <?= ($status === 'RUSAK') ? 'selected' : '' ?>>Kembali - Kondisi Rusak/Hilang</option>
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-1 flex items-end gap-2">
                    <button type="submit"
                        class="flex-grow py-2 px-4 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-xl text-xs transition-all shadow-md">
                        Terapkan Filter
                    </button>
                    <?php
                        $exportUrl = "laporan.php?start_date=" . urlencode($startDate) .
                            "&end_date=" . urlencode($endDate) .
                            "&id_category=" . $idCategory .
                            "&status=" . urlencode($status) .
                            "&export=csv";
                    ?>
                    <a href="<?= $exportUrl ?>" title="Unduh Spreadsheet Excel / CSV"
                        class="py-2 px-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs flex items-center justify-center transition-all shadow-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </a>
                </div>
            </form>
        </div>

        <!-- HEADER RESMI KETIKA DICETAK (PRINT ONLY) -->
        <div class="hidden print:block text-slate-900 border-b-2 border-slate-900 pb-4 mb-6">
            <div class="text-center space-y-1">
                <h1 class="text-xl font-black uppercase tracking-tight">LAPORAN REKAPITULASI DAN AUDIT SIRKULASI APD</h1>
                <h2 class="text-sm font-bold tracking-wide">DIVISI KESELAMATAN DAN KESEHATAN KERJA (K3) &bull; SIPAD K3</h2>
                <p class="text-xs text-slate-600">
                    Periode Laporan: <strong><?= date('d M Y', strtotime($startDate)) ?></strong> s/d <strong><?= date('d M Y', strtotime($endDate)) ?></strong>
                </p>
            </div>
        </div>

        <!-- KARTU RINGKASAN METRIK AUDIT PERIODE -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-slate-900 print-border print-bg-gray border border-slate-800 rounded-2xl p-4">
                <span class="text-xs text-slate-400 block">Total Transaksi</span>
                <strong class="text-2xl font-bold text-white print:text-black mt-1 block"><?= $summary['total_transaksi'] ?></strong>
                <span class="text-[11px] text-slate-500"><?= $summary['total_unit'] ?> Total Unit Terkelola</span>
            </div>

            <div class="bg-slate-900 print-border print-bg-gray border border-slate-800 rounded-2xl p-4">
                <span class="text-xs text-slate-400 block">Sedang Dipinjam</span>
                <strong class="text-2xl font-bold text-amber-400 print:text-amber-700 mt-1 block"><?= $summary['total_dipinjam'] ?> Unit</strong>
                <span class="text-[11px] text-slate-500">Di area operasional</span>
            </div>

            <div class="bg-slate-900 print-border print-bg-gray border border-slate-800 rounded-2xl p-4">
                <span class="text-xs text-slate-400 block">Kembali Kondisi Baik</span>
                <strong class="text-2xl font-bold text-emerald-400 print:text-emerald-700 mt-1 block"><?= $summary['total_kembali_baik'] ?> Unit</strong>
                <span class="text-[11px] text-slate-500">Dipulihkan ke rak</span>
            </div>

            <div class="bg-slate-900 print-border print-bg-gray border border-slate-800 rounded-2xl p-4">
                <span class="text-xs text-slate-400 block">Kembali Rusak / Hilang</span>
                <strong class="text-2xl font-bold text-red-400 print:text-red-700 mt-1 block"><?= $summary['total_rusak'] ?> Unit</strong>
                <span class="text-[11px] text-slate-500">Dialihkan ke perbaikan</span>
            </div>
        </div>

        <!-- TABEL DATA AUDIT SIRKULASI -->
        <div class="bg-slate-900 print-border print:bg-white border border-slate-800 rounded-3xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300 print:text-slate-800">
                    <thead class="bg-slate-950/70 print-bg-gray text-slate-400 print:text-slate-900 uppercase tracking-wider border-b border-slate-800 print-border font-bold">
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
                    <tbody class="divide-y divide-slate-800 print:divide-slate-200">
                        <?php if (empty($loans)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-slate-500">
                                    Tidak ada data sirkulasi peminjaman yang sesuai dengan filter yang dipilih.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($loans as $l): ?>
                                <tr class="hover:bg-slate-800/30 print:hover:bg-transparent">
                                    <td class="px-4 py-3 text-slate-500"><?= $no++ ?></td>
                                    <td class="px-4 py-3 font-mono font-bold text-amber-400 print:text-slate-900">
                                        <?= htmlspecialchars($l['kode_pinjam']) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-white print:text-slate-900"><?= htmlspecialchars($l['nama_peminjam']) ?></div>
                                        <div class="text-[11px] text-slate-400 print:text-slate-600"><?= htmlspecialchars($l['bagian_unit']) ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-white print:text-slate-900 font-semibold"><?= htmlspecialchars($l['nama_apd']) ?></div>
                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($l['nama_kategori']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-center font-bold"><?= $l['jumlah_pinjam'] ?></td>
                                    <td class="px-4 py-3 text-slate-400 print:text-slate-700">
                                        <?= date('d/m/Y H:i', strtotime($l['waktu_pinjam'])) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($l['status_pinjam'] === 'DIPINJAM'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30 print:border-amber-700 print:text-amber-700">
                                                Dipinjam
                                            </span>
                                        <?php elseif ($l['kondisi_kembali'] === 'BAIK'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 print:border-emerald-700 print:text-emerald-700">
                                                Kembali (Baik)
                                            </span>
                                        <?php else: ?>
                                            <div>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-500/10 text-red-400 border border-red-500/30 print:border-red-700 print:text-red-700">
                                                    <?= htmlspecialchars($l['kondisi_kembali']) ?>
                                                </span>
                                                <?php if (!empty($l['catatan_kerusakan'])): ?>
                                                    <div class="text-[10px] text-slate-500 print:text-slate-600 mt-0.5 truncate max-w-xs">
                                                        Ket: <?= htmlspecialchars($l['catatan_kerusakan']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-400 print:text-slate-700">
                                        <?= htmlspecialchars($l['nama_pengawas'] ?? '-') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TANDA TANGAN AUDIT CETAK (PRINT ONLY) -->
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

    <footer class="no-print border-t border-slate-800 bg-slate-950 py-6 text-center text-xs text-slate-500">
        SIPAD K3 Operasional &bull; Modul Pelaporan & Ekspor Audit Inventaris &copy; 2026.
    </footer>
</body>
</html>
<?php
}
