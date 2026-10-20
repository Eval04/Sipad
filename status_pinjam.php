<?php
/**
 * Pelacakan Status Peminjaman & Tampilan Bukti Digital Peminjaman APD
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

startAppSession();
$pdo = getDbConnection();

$searchQuery = trim((string)($_GET['q'] ?? ''));
$specificKode = trim((string)($_GET['kode'] ?? ''));

$successLoan = $_SESSION['flash_success_loan'] ?? null;
unset($_SESSION['flash_success_loan']);

// Ambil detail pinjaman spesifik jika kode disediakan
$highlightLoan = null;
if ($specificKode !== '') {
    $stmtHighlight = $pdo->prepare("
        SELECT l.*, i.nama_apd, i.kode_apd, i.ukuran_spesifikasi, u.nama_lengkap AS nama_pengawas
        FROM loans l
        JOIN apd_items i ON l.id_apd = i.id_apd
        LEFT JOIN users u ON l.id_pengawas_acc = u.id_user
        WHERE l.kode_pinjam = ?
        LIMIT 1
    ");
    $stmtHighlight->execute([$specificKode]);
    $highlightLoan = $stmtHighlight->fetch(PDO::FETCH_ASSOC);
}

// Ambil daftar riwayat peminjaman (aktif atau pencarian)
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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Peminjaman APD - SIPAD K3</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col">

    <!-- Navbar -->
    <header class="sticky top-0 z-50 bg-slate-900/80 backdrop-blur-md border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="/index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-amber-300 text-slate-950 flex items-center justify-center font-bold">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-lg font-extrabold tracking-tight text-white block leading-tight">SIPAD K3</span>
                    <span class="text-[10px] tracking-wider uppercase text-amber-400 font-semibold">Pelacakan Sirkulasi</span>
                </div>
            </a>

            <div class="flex items-center gap-3">
                <a href="/index.php" class="text-xs sm:text-sm px-3.5 py-1.5 rounded-lg border border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700 transition-all font-medium">
                    + Pinjam Baru
                </a>
                <a href="/login.php" class="text-xs sm:text-sm px-3.5 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold transition-all">
                    Login Petugas
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow w-full space-y-8">

        <!-- Banner Jika Baru Saja Berhasil Meminjam -->
        <?php if ($highlightLoan): ?>
            <div class="bg-gradient-to-br from-slate-900 via-slate-900 to-amber-950/40 border-2 border-amber-500/40 rounded-3xl p-6 sm:p-8 shadow-2xl relative overflow-hidden">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-800">
                    <div>
                        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold mb-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Bukti Peminjaman Digital Terbit
                        </div>
                        <h2 class="text-2xl font-extrabold text-white tracking-tight">Kupon Sirkulasi APD</h2>
                        <p class="text-xs text-slate-400">Tunjukkan bukti ini kepada Pengawas K3 saat serah terima barang</p>
                    </div>

                    <div class="text-right">
                        <span class="text-xs text-slate-400 block font-mono">KODE TRANSAKSI</span>
                        <span class="text-xl sm:text-2xl font-extrabold font-mono text-amber-400 tracking-wider">
                            <?= htmlspecialchars($highlightLoan['kode_pinjam']) ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 py-6 text-xs">
                    <div class="bg-slate-950/60 p-3.5 rounded-xl border border-slate-800">
                        <span class="text-slate-500 block mb-1">Nama Peminjam</span>
                        <strong class="text-sm text-white"><?= htmlspecialchars($highlightLoan['nama_peminjam']) ?></strong>
                        <span class="text-slate-400 block mt-0.5"><?= htmlspecialchars($highlightLoan['bagian_unit']) ?></span>
                    </div>
                    <div class="bg-slate-950/60 p-3.5 rounded-xl border border-slate-800">
                        <span class="text-slate-500 block mb-1">Peralatan APD</span>
                        <strong class="text-sm text-white"><?= htmlspecialchars($highlightLoan['nama_apd']) ?></strong>
                        <span class="text-slate-400 block mt-0.5">Jumlah: <strong><?= $highlightLoan['jumlah_pinjam'] ?> Unit</strong></span>
                    </div>
                    <div class="bg-slate-950/60 p-3.5 rounded-xl border border-slate-800">
                        <span class="text-slate-500 block mb-1">Waktu Pengambilan</span>
                        <strong class="text-sm text-white"><?= date('d M Y', strtotime($highlightLoan['waktu_pinjam'])) ?></strong>
                        <span class="text-slate-400 block mt-0.5"><?= date('H:i:s', strtotime($highlightLoan['waktu_pinjam'])) ?> WITA</span>
                    </div>
                    <div class="bg-slate-950/60 p-3.5 rounded-xl border border-slate-800">
                        <span class="text-slate-500 block mb-1">Status Verifikasi</span>
                        <?php if ($highlightLoan['status_pinjam'] === 'DIPINJAM'): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                SEDANG DIPINJAM
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                SUDAH DIKEMBALIKAN
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-200 flex items-center justify-between">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Simpan tangkapan layar (screenshot) kode ini sebagai tanda bukti resmi.
                    </span>
                    <button onclick="window.print()" class="px-3 py-1 rounded-lg bg-amber-500 text-slate-950 font-bold text-[11px] hover:bg-amber-400 transition-all">
                        Cetak / Simpan PDF
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Pencarian Peminjaman -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="GET" action="status_pinjam.php" class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-grow">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
                        placeholder="Cari berdasarkan Kode Pinjam (PINJ-XXXX), Nama Peminjam, atau Unit..."
                        class="w-full pl-11 pr-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm">
                </div>
                <button type="submit"
                    class="py-2.5 px-5 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-xl text-sm transition-all shadow-md">
                    Cari Data
                </button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="status_pinjam.php" class="py-2.5 px-4 bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium rounded-xl text-sm text-center transition-all">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabel Daftar Peminjaman Aktif & Terbaru -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                <h3 class="font-bold text-white text-base">Daftar Sirkulasi APD Terkini</h3>
                <span class="text-xs text-slate-400">Total: <?= count($loanList) ?> Transaksi</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950/60 text-xs text-slate-400 uppercase tracking-wider border-b border-slate-800 font-semibold">
                        <tr>
                            <th class="px-5 py-3.5">Kode Pinjam</th>
                            <th class="px-5 py-3.5">Peminjam</th>
                            <th class="px-5 py-3.5">Peralatan APD</th>
                            <th class="px-5 py-3.5">Waktu Pinjam</th>
                            <th class="px-5 py-3.5">Status & Otorisasi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($loanList)): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500">
                                    Tidak ada data peminjaman yang cocok dengan kriteria pencarian.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($loanList as $row): ?>
                                <tr class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-4 font-mono font-bold text-amber-400 text-xs">
                                        <a href="/status_pinjam.php?kode=<?= urlencode($row['kode_pinjam']) ?>" class="hover:underline">
                                            <?= htmlspecialchars($row['kode_pinjam']) ?>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-white"><?= htmlspecialchars($row['nama_peminjam']) ?></div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($row['bagian_unit']) ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-white"><?= htmlspecialchars($row['nama_apd']) ?></div>
                                        <div class="text-xs text-slate-400"><?= $row['jumlah_pinjam'] ?> Unit (<?= htmlspecialchars($row['kode_apd']) ?>)</div>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-slate-400">
                                        <?= date('d M Y, H:i', strtotime($row['waktu_pinjam'])) ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($row['status_pinjam'] === 'DIPINJAM'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400 mr-1.5 animate-pulse"></span>
                                                Dipinjam
                                            </span>
                                        <?php else: ?>
                                            <div>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                    Dikembalikan
                                                </span>
                                                <?php if (!empty($row['nama_pengawas'])): ?>
                                                    <div class="text-[10px] text-slate-400 mt-1">
                                                        ACC: <?= htmlspecialchars($row['nama_pengawas']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
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
        SIPAD K3 Operasional &bull; Sistem Inventaris dan Peminjaman APD &copy; 2026.
    </footer>
</body>
</html>
