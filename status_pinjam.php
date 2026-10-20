<?php
/**
 * Pelacakan Status & Tampilan Kupon Digital Peminjaman APD
 * Identitas Korporat PT Pelabuhan Indonesia (Pelindo) - Clean Light Enterprise Theme
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

startAppSession();
$pdo = getDbConnection();

$searchQuery = trim((string)($_GET['q'] ?? ''));
$specificKode = trim((string)($_GET['kode'] ?? ''));

// Ambil detail pinjaman spesifik jika ada (mendukung multi-item per kode)
$highlightLoans = [];
if ($specificKode !== '') {
    $stmtHighlight = $pdo->prepare("
        SELECT l.*, i.nama_apd, i.kode_apd, i.ukuran_spesifikasi, u.nama_lengkap AS nama_pengawas
        FROM loans l
        JOIN apd_items i ON l.id_apd = i.id_apd
        LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
        WHERE l.kode_pinjam = ?
        ORDER BY l.id_loan ASC
    ");
    $stmtHighlight->execute([$specificKode]);
    $highlightLoans = $stmtHighlight->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil daftar riwayat peminjaman
if ($searchQuery !== '') {
    $stmtList = $pdo->prepare("
        SELECT l.*, i.nama_apd, i.kode_apd, u.nama_lengkap AS nama_pengawas
        FROM loans l
        JOIN apd_items i ON l.id_apd = i.id_apd
        LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
        WHERE l.kode_pinjam LIKE ? OR l.nama_peminjam LIKE ? OR l.bagian_unit LIKE ? OR l.no_hp_peminjam LIKE ?
        ORDER BY l.waktu_pinjam DESC
        LIMIT 30
    ");
    $param = "%{$searchQuery}%";
    $stmtList->execute([$param, $param, $param, $param]);
} else {
    $stmtList = $pdo->query("
        SELECT l.*, i.nama_apd, i.kode_apd, u.nama_lengkap AS nama_pengawas
        FROM loans l
        JOIN apd_items i ON l.id_apd = i.id_apd
        LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
        ORDER BY (l.status_pinjam = 'DIPINJAM') DESC, l.waktu_pinjam DESC
        LIMIT 25
    ");
}
$loanList = $stmtList->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Kupon Tiket & Status Peminjaman APD - Pelindo</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Pelindo Corporate Header -->
    <header class="sticky top-0 z-40 bg-[#003e74] text-white shadow-md">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/index.php" class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo" class="w-6 h-6">
                </div>
                <div>
                    <span class="text-sm font-extrabold text-white block leading-tight">STATUS & KUPON APD</span>
                    <span class="text-[10px] text-sky-200 font-mono">PT Pelabuhan Indonesia (Persero)</span>
                </div>
            </a>

            <div class="flex items-center gap-2">
                <a href="/index.php" class="touch-target px-3.5 py-1.5 rounded-xl bg-white text-[#005b96] font-bold text-xs hover:bg-sky-50 transition-all shadow-sm">
                    + Pinjam Baru
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-6 py-6 flex-grow w-full space-y-6">

        <!-- KUPON DIGITAL (PASS CARD) JIKA KODE DIPILIH ATAU REDIRECT BARU -->
        <?php if (!empty($highlightLoans)): ?>
            <?php 
                $firstLoan = $highlightLoans[0];
                $allReturned = true;
                $totalUnitsInPass = 0;
                foreach ($highlightLoans as $hl) {
                    $totalUnitsInPass += (int)$hl['jumlah_pinjam'];
                    if ($hl['status_pinjam'] === 'DIPINJAM') {
                        $allReturned = false;
                    }
                }
            ?>
            <div class="bg-white rounded-3xl overflow-hidden border border-slate-200 shadow-lg relative">
                <!-- Header Kupon Pelindo -->
                <div class="bg-gradient-to-r from-[#003e74] via-[#005b96] to-[#0080c0] p-5 text-white flex items-center justify-between">
                    <div>
                        <span class="text-[10px] uppercase font-mono font-bold tracking-widest block text-sky-200">KUPON PEMINJAMAN APD RESMI</span>
                        <h2 class="text-lg font-black tracking-tight text-white">BUKTI PENGAMBILAN ALAT K3</h2>
                    </div>
                    <div class="h-9 px-2.5 py-1 rounded-xl bg-white flex items-center justify-center shadow-sm">
                        <img src="/assets/img/pelindo-logo.svg" alt="Pelindo" class="h-5 w-auto">
                    </div>
                </div>

                <div class="p-5 sm:p-6 space-y-5">
                    <!-- Kode Pinjam & Quick Copy -->
                    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-center sm:text-left">
                        <div>
                            <span class="text-[10px] uppercase font-mono text-slate-500 block font-semibold">KODE TRANSAKSI PEMINJAMAN</span>
                            <span id="kode-display" class="text-xl sm:text-2xl font-mono font-black text-[#005b96] tracking-wider">
                                <?= htmlspecialchars($firstLoan['kode_pinjam']) ?>
                            </span>
                        </div>
                        <button type="button" onclick="salinKode('<?= htmlspecialchars($firstLoan['kode_pinjam']) ?>')"
                            class="touch-target px-4 py-2 rounded-xl bg-white hover:bg-slate-100 text-[#005b96] text-xs font-bold border border-slate-300 flex items-center justify-center gap-1.5 self-center sm:self-auto shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                            </svg>
                            <span id="salin-text">Salin Kode</span>
                        </button>
                    </div>

                    <!-- Identitas Peminjam -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs bg-slate-50 p-4 rounded-xl border border-slate-200">
                        <div>
                            <span class="text-slate-500 block font-mono text-[10px] uppercase">Peminjam</span>
                            <strong class="text-sm text-slate-900 block mt-0.5"><?= htmlspecialchars($firstLoan['nama_peminjam']) ?></strong>
                            <span class="text-slate-600 block"><?= htmlspecialchars($firstLoan['bagian_unit']) ?></span>
                        </div>
                        <div>
                            <span class="text-slate-500 block font-mono text-[10px] uppercase">Waktu Pengambilan</span>
                            <strong class="text-slate-900 block mt-0.5"><?= date('d M Y, H:i', strtotime($firstLoan['waktu_pinjam'])) ?></strong>
                            <span class="text-slate-600 block">WA: <?= htmlspecialchars($firstLoan['no_hp_peminjam']) ?></span>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <span class="text-slate-500 block font-mono text-[10px] uppercase">Status Peminjaman</span>
                            <div class="mt-0.5">
                                <?php if (!$allReturned): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                        SEDANG DIPINJAM
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                        SEMUA KEMBALI
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Daftar Peralatan APD yang Dipinjam dalam Kupon Ini -->
                    <div class="space-y-2">
                        <span class="text-slate-700 block font-mono text-[11px] uppercase font-bold">
                            Daftar Peralatan APD Dipinjam (<?= count($highlightLoans) ?> Jenis &bull; <?= $totalUnitsInPass ?> Unit Total)
                        </span>
                        <div class="divide-y divide-slate-100 rounded-xl bg-white border border-slate-200 overflow-hidden shadow-sm">
                            <?php foreach ($highlightLoans as $item): ?>
                                <div class="p-3.5 flex items-center justify-between text-xs hover:bg-slate-50 transition-colors">
                                    <div class="space-y-0.5">
                                        <strong class="text-slate-900 text-sm block"><?= htmlspecialchars($item['nama_apd']) ?></strong>
                                        <div class="text-[11px] text-slate-500">
                                            <?= htmlspecialchars($item['ukuran_spesifikasi'] ?? 'Standard K3') ?> &bull; 
                                            <span class="font-mono text-slate-400"><?= htmlspecialchars($item['kode_apd']) ?></span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <strong class="text-sm font-black text-[#005b96] font-mono"><?= $item['jumlah_pinjam'] ?> Unit</strong>
                                        <div class="mt-1">
                                            <?php if ($item['status_pinjam'] === 'DIPINJAM'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                    Belum Kembali
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    Sudah ACC
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Instruksi Pengembalian -->
                    <div class="p-3.5 rounded-xl bg-sky-50 border border-sky-100 text-xs text-sky-900 leading-relaxed">
                        <strong>PROSEDUR K3:</strong> Kembalikan seluruh fisik APD ke Pengawas K3 saat pergantian shift. Pengawas akan melakukan verifikasi fisik kelayakan dan menutup status pinjam dengan tombol ACC.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Pencarian Real-time -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
            <form method="GET" action="status_pinjam.php" class="flex gap-2">
                <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
                    placeholder="Ketik Kode Pinjam (PINJ-...), Nama, atau Bagian..."
                    class="sipad-input flex-grow px-4 py-2.5 rounded-xl text-xs sm:text-sm text-slate-900 placeholder-slate-400">
                <button type="submit" class="btn-pelindo-primary touch-target px-5 rounded-xl text-xs font-bold flex-shrink-0">
                    Cari
                </button>
            </form>
        </div>

        <!-- Daftar Kartu Peminjaman (Mobile First Stacked Cards) -->
        <div class="space-y-3">
            <div class="flex items-center justify-between px-1">
                <h3 class="text-xs sm:text-sm font-bold text-slate-700">
                    <?= ($searchQuery !== '') ? "Hasil Pencarian: '" . htmlspecialchars($searchQuery) . "'" : "Daftar Transaksi Peminjaman Terkini" ?>
                </h3>
                <span class="text-[11px] text-slate-500 font-mono"><?= count($loanList) ?> Transaksi</span>
            </div>

            <?php if (empty($loanList)): ?>
                <div class="bg-white rounded-2xl p-8 text-center text-xs text-slate-500 border border-slate-200">
                    Tidak ada riwayat peminjaman yang cocok dengan kriteria pencarian Anda.
                </div>
            <?php else: ?>
                <?php foreach ($loanList as $l): ?>
                    <a href="status_pinjam.php?kode=<?= urlencode($l['kode_pinjam']) ?>"
                        class="bg-white rounded-xl p-4 block border border-slate-200 hover:border-[#0080c0] hover:shadow-md transition-all space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-mono font-bold text-[#005b96]"><?= htmlspecialchars($l['kode_pinjam']) ?></span>
                            <?php if ($l['status_pinjam'] === 'DIPINJAM'): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    Sedang Dipinjam
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Sudah Kembali
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="text-xs">
                            <div class="font-bold text-slate-900"><?= htmlspecialchars($l['nama_peminjam']) ?></div>
                            <div class="text-slate-500 text-[11px]"><?= htmlspecialchars($l['bagian_unit']) ?></div>
                        </div>

                        <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200 text-xs flex justify-between items-center">
                            <div>
                                <span class="text-slate-800 font-medium"><?= htmlspecialchars($l['nama_apd']) ?></span>
                                <span class="text-slate-400 text-[10px] block font-mono"><?= htmlspecialchars($l['kode_apd']) ?></span>
                            </div>
                            <span class="font-mono font-bold text-[#005b96]"><?= $l['jumlah_pinjam'] ?> Unit</span>
                        </div>

                        <div class="text-[10px] text-slate-400 flex justify-between pt-1">
                            <span>Waktu: <?= date('d M Y, H:i', strtotime($l['waktu_pinjam'])) ?></span>
                            <span class="text-[#005b96] font-semibold hover:underline">Lihat Rincian Tiket &rarr;</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/index.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>Pinjam APD</span>
        </a>
        <a href="/status_pinjam.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Cek Status</span>
        </a>
        <a href="/login.php" class="mobile-nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <span>Petugas</span>
        </a>
    </nav>

    <script>
        function salinKode(kode) {
            navigator.clipboard.writeText(kode).then(() => {
                const el = document.getElementById('salin-text');
                if (el) {
                    el.textContent = 'Tersalin!';
                    setTimeout(() => { el.textContent = 'Salin Kode'; }, 2000);
                }
            }).catch(() => {
                alert('Kode: ' + kode);
            });
        }
    </script>
</body>
</html>
