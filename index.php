<?php
/**
 * Halaman Utama: Formulir Mandiri Peminjaman APD K3
 * Identitas Korporat PT Pelabuhan Indonesia (Pelindo) - Clean Light Enterprise Theme
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

// Ambil data kategori APD
$categories = $pdo->query("SELECT * FROM apd_categories ORDER BY id_category ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ambil seluruh master peralatan APD
$queryItems = "
    SELECT i.*, c.nama_kategori, c.kode_kategori 
    FROM apd_items i
    JOIN apd_categories c ON i.id_category = c.id_category
    ORDER BY c.id_category ASC, i.nama_apd ASC
";
$items = $pdo->query($queryItems)->fetchAll(PDO::FETCH_ASSOC);

$totalTersedia = array_sum(array_column($items, 'stok_tersedia'));
$totalItemApd = count($items);
?>
<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Peminjaman APD K3 - PT Pelabuhan Indonesia (Pelindo)</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
    <style>
        .apd-item-card.is-selected {
            border-color: #0080c0 !important;
            background-color: #f0f9ff !important;
            box-shadow: 0 0 0 1px #0080c0;
        }
        .apd-item-card.is-disabled {
            opacity: 0.55;
            background-color: #f8fafc;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col pb-24 sm:pb-12 selection:bg-sky-500 selection:text-white">

    <!-- Pelindo Corporate Header -->
    <header class="sticky top-0 z-40 bg-[#003e74] text-white shadow-md">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-sm flex-shrink-0">
                    <img src="/assets/img/pelindo-emblem.svg" alt="Pelindo Emblem" class="w-7 h-7">
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <img src="/assets/img/pelindo-logo-white.svg" alt="Pelindo" class="h-4 sm:h-5 w-auto">
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">K3</span>
                    </div>
                    <span class="text-[10px] text-sky-200 block">Sistem Inventaris & Peminjaman APD</span>
                </div>
            </a>

            <!-- Quick Action Links -->
            <div class="flex items-center gap-2">
                <a href="/status_pinjam.php" class="text-xs px-3 py-1.5 rounded-lg bg-[#005b96] hover:bg-[#006fb3] text-white border border-sky-300/30 transition-colors flex items-center gap-1.5 font-medium shadow-sm">
                    <svg class="w-3.5 h-3.5 text-sky-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span>Cek Status</span>
                </a>
                <a href="/login.php" class="text-xs px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white transition-colors font-medium">
                    Petugas
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 w-full py-6 flex-grow space-y-6">

        <!-- Banner Flash Error -->
        <?php if ($flashError): ?>
            <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs sm:text-sm flex items-start gap-3 shadow-sm">
                <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="leading-relaxed font-medium"><?= htmlspecialchars($flashError) ?></div>
            </div>
        <?php endif; ?>

        <!-- Form Peminjaman Mandiri Minimalis -->
        <form action="/pinjam_proses.php" method="POST" id="main-loan-form" class="space-y-6" onsubmit="return validateSelection()">

            <!-- BAGIAN 1: IDENTITAS PEMINJAM -->
            <section class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-[#005b96] text-white font-bold text-xs flex items-center justify-center">1</span>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900">Data Identitas Karyawan</h2>
                    </div>
                    <span class="text-[11px] text-slate-500 font-mono">Wajib Diisi</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <!-- Nama Lengkap -->
                    <div>
                        <label for="nama_peminjam" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Nama Lengkap <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="nama_peminjam" name="nama_peminjam" required
                            value="<?= htmlspecialchars($oldInput['nama_peminjam'] ?? '') ?>"
                            placeholder="Contoh: Budi Santoso"
                            class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs sm:text-sm text-slate-900 placeholder-slate-400">
                    </div>

                    <!-- Bagian / Unit Kerja -->
                    <div>
                        <label for="bagian_unit" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Divisi / Bagian Kerja <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="bagian_unit" name="bagian_unit" required
                            value="<?= htmlspecialchars($oldInput['bagian_unit'] ?? '') ?>"
                            placeholder="Contoh: Terminal Petikemas, Operasional, K3"
                            class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs sm:text-sm text-slate-900 placeholder-slate-400">
                    </div>

                    <!-- No WhatsApp / HP -->
                    <div>
                        <label for="no_hp_peminjam" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            No. WhatsApp Aktif <span class="text-red-500">*</span>
                        </label>
                        <input type="tel" id="no_hp_peminjam" name="no_hp_peminjam" required inputmode="numeric"
                            value="<?= htmlspecialchars($oldInput['no_hp_peminjam'] ?? '') ?>"
                            placeholder="0812xxxxxxxx"
                            class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs sm:text-sm font-mono text-slate-900 placeholder-slate-400">
                    </div>
                </div>
            </section>

            <!-- BAGIAN 2: PILIH PERALATAN APD (MULTI-SELECT DENGAN KUANTITAS INDIVIDUAL) -->
            <section class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-[#005b96] text-white font-bold text-xs flex items-center justify-center">2</span>
                        <div>
                            <h2 class="text-sm sm:text-base font-bold text-slate-900">Pilih Peralatan APD & Tentukan Kuantitas</h2>
                            <p class="text-xs text-slate-500">Centang beberapa alat keselamatan sekaligus sesuai aktivitas kerja Anda</p>
                        </div>
                    </div>
                    <!-- Live Counter Pill -->
                    <div id="selection-summary-badge" class="self-start sm:self-auto px-3 py-1 rounded-full text-xs font-mono font-bold bg-slate-100 text-slate-600 border border-slate-200">
                        0 Barang Dipilih
                    </div>
                </div>

                <!-- Category Filter Chips -->
                <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar py-1">
                    <button type="button" onclick="filterKat('ALL')" class="cat-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#005b96] text-white whitespace-nowrap transition-colors shadow-sm">
                        Semua APD (<?= count($items) ?>)
                    </button>
                    <?php foreach ($categories as $cat): ?>
                        <button type="button" onclick="filterKat('<?= $cat['kode_kategori'] ?>')" class="cat-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 border border-slate-200 whitespace-nowrap transition-colors">
                            <?= htmlspecialchars($cat['nama_kategori']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Grid Daftar APD -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                    <?php foreach ($items as $apd): ?>
                        <?php
                            $id = (int)$apd['id_apd'];
                            $stok = (int)$apd['stok_tersedia'];
                            $isHabis = ($stok <= 0);
                        ?>
                        <div class="apd-item-card p-4 rounded-xl border border-slate-200 bg-white hover:border-[#0080c0]/60 transition-all flex flex-col justify-between shadow-sm <?= $isHabis ? 'is-disabled' : '' ?>"
                            id="card-apd-<?= $id ?>"
                            data-cat="<?= htmlspecialchars($apd['kode_kategori']) ?>">

                            <div>
                                <!-- Top Row: Checkbox & Stock Status -->
                                <div class="flex items-start justify-between gap-2">
                                    <label class="flex items-start gap-2.5 cursor-pointer select-none">
                                        <input type="checkbox" name="selected_apd[]" value="<?= $id ?>"
                                            id="check-apd-<?= $id ?>"
                                            <?= $isHabis ? 'disabled' : '' ?>
                                            onchange="handleItemToggle(<?= $id ?>, <?= $stok ?>)"
                                            class="w-4 h-4 mt-0.5 rounded border-slate-300 text-[#005b96] focus:ring-[#0080c0] cursor-pointer">
                                        <div>
                                            <strong class="text-xs sm:text-sm text-slate-900 font-bold block leading-snug">
                                                <?= htmlspecialchars($apd['nama_apd']) ?>
                                            </strong>
                                            <span class="text-[11px] text-slate-500 block mt-0.5">
                                                <?= htmlspecialchars($apd['ukuran_spesifikasi'] ?? 'Standard K3') ?>
                                            </span>
                                            <span class="text-[10px] text-slate-400 font-mono block">
                                                <?= htmlspecialchars($apd['kode_apd']) ?> &bull; <?= htmlspecialchars($apd['nama_kategori']) ?>
                                            </span>
                                        </div>
                                    </label>

                                    <!-- Stock Pill -->
                                    <div class="flex-shrink-0 text-right">
                                        <?php if ($stok > 0): ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Sisa <?= $stok ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-slate-100 text-slate-400 border border-slate-200">
                                                Habis
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Row: Stepper Kuantitas (Aktif saat dicentang) -->
                            <?php if (!$isHabis): ?>
                                <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                                    <span class="text-[11px] text-slate-600 font-medium">Jumlah Pinjam:</span>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" id="btn-minus-<?= $id ?>" onclick="adjustItemQty(<?= $id ?>, -1, <?= $stok ?>)" disabled
                                            class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-sm transition-colors active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                                            -
                                        </button>
                                        <input type="number" id="qty-input-<?= $id ?>" name="qty[<?= $id ?>]" value="1" min="1" max="<?= $stok ?>" disabled
                                            onchange="handleManualQtyChange(<?= $id ?>, <?= $stok ?>)"
                                            class="w-12 h-8 rounded-lg bg-white border border-slate-300 text-center font-mono font-bold text-xs text-slate-900 focus:outline-none focus:border-[#0080c0] disabled:opacity-40">
                                        <button type="button" id="btn-plus-<?= $id ?>" onclick="adjustItemQty(<?= $id ?>, 1, <?= $stok ?>)" disabled
                                            class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-sm transition-colors active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                                            +
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- BAGIAN 3: VERIFIKASI PIN RUANG APD -->
            <section class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="w-6 h-6 rounded-full bg-[#005b96] text-white font-bold text-xs flex items-center justify-center">3</span>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900">Verifikasi PIN Gudang APD</h2>
                    </div>
                    <span class="text-[11px] text-[#005b96] font-mono font-bold">4 Digit Angka</span>
                </div>

                <div class="max-w-md mx-auto space-y-2 text-center py-2">
                    <label for="pin_gudang" class="block text-xs font-semibold text-slate-700">
                        Ketik 4 digit PIN yang tertera pada lembar poster di pintu/meja ruang APD:
                    </label>
                    <input type="password" id="pin_gudang" name="pin_gudang" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required
                        placeholder="••••"
                        class="pin-input-segment w-48 mx-auto py-2.5 text-center font-mono text-2xl font-bold tracking-[0.5em] block shadow-inner">
                    <p class="text-[11px] text-slate-500">
                        Verifikasi PIN membuktikan Anda hadir langsung di lokasi ruang penyimpanan APD Pelindo.
                    </p>
                </div>
            </section>

            <!-- SUBMIT BUTTON & SELECTED SUMMARY BAR -->
            <div class="bg-white rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row items-center justify-between gap-4 border border-slate-200 shadow-md">
                <div class="text-center sm:text-left">
                    <span class="text-xs text-slate-500 block font-medium">Ringkasan Peminjaman:</span>
                    <strong id="bottom-summary-text" class="text-sm sm:text-base text-[#003e74] block mt-0.5">
                        Belum ada peralatan yang dipilih
                    </strong>
                </div>

                <button type="submit" id="btn-submit-loan"
                    class="btn-pelindo-primary w-full sm:w-auto touch-target px-7 py-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span id="btn-submit-text">Konfirmasi & Ambil APD</span>
                </button>
            </div>

        </form>

    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/index.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>Pinjam APD</span>
        </a>
        <a href="/status_pinjam.php" class="mobile-nav-item">
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

    <!-- Footer Korporat -->
    <footer class="hidden sm:block border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">
        PT Pelabuhan Indonesia (Persero) &bull; Divisi K3 & Operasional Pelabuhan &copy; 2026.
    </footer>

    <!-- JavaScript Interactive Logic -->
    <script>
        const selectedState = {};

        function handleItemToggle(id, maxStock) {
            const checkbox = document.getElementById('check-apd-' + id);
            const card = document.getElementById('card-apd-' + id);
            const inputQty = document.getElementById('qty-input-' + id);
            const btnMinus = document.getElementById('btn-minus-' + id);
            const btnPlus = document.getElementById('btn-plus-' + id);

            if (checkbox.checked) {
                card.classList.add('is-selected');
                inputQty.disabled = false;
                btnMinus.disabled = false;
                btnPlus.disabled = false;
                
                const currentVal = parseInt(inputQty.value || '1', 10);
                selectedState[id] = Math.max(1, Math.min(maxStock, currentVal));
                inputQty.value = selectedState[id];
            } else {
                card.classList.remove('is-selected');
                inputQty.disabled = true;
                btnMinus.disabled = true;
                btnPlus.disabled = true;
                delete selectedState[id];
            }

            updateSummary();
        }

        function adjustItemQty(id, delta, maxStock) {
            const inputQty = document.getElementById('qty-input-' + id);
            if (!inputQty || inputQty.disabled) return;

            let val = parseInt(inputQty.value || '1', 10);
            val = Math.max(1, Math.min(maxStock, val + delta));
            inputQty.value = val;
            selectedState[id] = val;

            updateSummary();
        }

        function handleManualQtyChange(id, maxStock) {
            const inputQty = document.getElementById('qty-input-' + id);
            if (!inputQty || inputQty.disabled) return;

            let val = parseInt(inputQty.value || '1', 10);
            if (isNaN(val) || val < 1) val = 1;
            if (val > maxStock) val = maxStock;
            inputQty.value = val;
            selectedState[id] = val;

            updateSummary();
        }

        function updateSummary() {
            const ids = Object.keys(selectedState);
            const totalKinds = ids.length;
            let totalUnits = 0;
            ids.forEach(id => {
                totalUnits += selectedState[id];
            });

            const badge = document.getElementById('selection-summary-badge');
            const bottomText = document.getElementById('bottom-summary-text');
            const btnText = document.getElementById('btn-submit-text');

            if (totalKinds === 0) {
                badge.textContent = '0 Barang Dipilih';
                badge.className = 'self-start sm:self-auto px-3 py-1 rounded-full text-xs font-mono font-bold bg-slate-100 text-slate-500 border border-slate-200';
                bottomText.textContent = 'Belum ada peralatan yang dipilih';
                btnText.textContent = 'Konfirmasi & Ambil APD';
            } else {
                badge.textContent = totalKinds + ' Jenis APD (' + totalUnits + ' Unit)';
                badge.className = 'self-start sm:self-auto px-3 py-1 rounded-full text-xs font-mono font-bold bg-sky-50 text-[#005b96] border border-sky-200';
                bottomText.textContent = totalKinds + ' jenis peralatan dipilih (' + totalUnits + ' unit total)';
                btnText.textContent = 'Konfirmasi Pinjam (' + totalUnits + ' Unit)';
            }
        }

        function validateSelection() {
            const ids = Object.keys(selectedState);
            if (ids.length === 0) {
                alert('Silakan centang minimal satu peralatan APD yang akan dipinjam.');
                return false;
            }

            const pin = document.getElementById('pin_gudang').value.trim();
            if (pin.length !== 4) {
                alert('Kode PIN Ruang APD harus tepat 4 digit angka.');
                document.getElementById('pin_gudang').focus();
                return false;
            }

            return true;
        }

        function filterKat(kodeKat) {
            const buttons = document.querySelectorAll('.cat-filter-btn');
            buttons.forEach(btn => {
                btn.classList.remove('bg-[#005b96]', 'text-white', 'shadow-sm');
                btn.classList.add('bg-slate-100', 'text-slate-600');
            });
            event.target.classList.remove('bg-slate-100', 'text-slate-600');
            event.target.classList.add('bg-[#005b96]', 'text-white', 'shadow-sm');

            const cards = document.querySelectorAll('.apd-item-card');
            cards.forEach(card => {
                if (kodeKat === 'ALL' || card.getAttribute('data-cat') === kodeKat) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
