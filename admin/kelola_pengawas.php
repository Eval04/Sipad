<?php
/**
 * Modul Manajemen Akun Pengawas Terpusat & Kontrol Keamanan WiFi Kantor (Zero-Router)
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

checkAuth(['admin']);
$pdo = getDbConnection();
$currentUser = getLoggedInUser();

$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$currentClientIp = getCurrentClientIp();

// Handler POST Aksi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // 1. Buat Akun Pengawas Baru (TC-03)
    if ($action === 'create_pengawas') {
        $username    = trim((string)($_POST['username'] ?? ''));
        $namaLengkap = trim((string)($_POST['nama_lengkap'] ?? ''));
        $password    = (string)($_POST['password'] ?? '');

        if ($username === '' || $namaLengkap === '' || $password === '') {
            $_SESSION['flash_error'] = 'Semua kolom pembuatan akun Pengawas wajib diisi.';
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_error'] = 'Kata sandi minimal 6 karakter.';
        } else {
            // Cek apakah username sudah ada
            $stmtCek = $pdo->prepare("SELECT id_user FROM users WHERE username = ?");
            $stmtCek->execute([$username]);
            if ($stmtCek->fetch()) {
                $_SESSION['flash_error'] = "Username '{$username}' sudah terdaftar. Gunakan username lain.";
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtInsert = $pdo->prepare("
                    INSERT INTO users (username, password_hash, nama_lengkap, role, is_active)
                    VALUES (?, ?, ?, 'pengawas', 1)
                ");
                $stmtInsert->execute([$username, $passwordHash, $namaLengkap]);
                $_SESSION['flash_success'] = "Akun Pengawas '{$namaLengkap}' ({$username}) berhasil dibuat dan langsung aktif.";
            }
        }
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 2. Toggle Status Aktif/Nonaktif Akun Pengawas
    if ($action === 'toggle_status') {
        $idUser = (int)($_POST['id_user'] ?? 0);
        $currentStatus = (int)($_POST['current_status'] ?? 1);
        $newStatus = ($currentStatus === 1) ? 0 : 1;

        $stmtToggle = $pdo->prepare("UPDATE users SET is_active = ? WHERE id_user = ? AND role = 'pengawas'");
        $stmtToggle->execute([$newStatus, $idUser]);

        $_SESSION['flash_success'] = 'Status keaktifan akun Pengawas berhasil diperbarui.';
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 3. Reset Password Pengawas
    if ($action === 'reset_password') {
        $idUser = (int)($_POST['id_user'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            $_SESSION['flash_error'] = 'Kata sandi baru minimal 6 karakter.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmtReset = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id_user = ? AND role = 'pengawas'");
            $stmtReset->execute([$newHash, $idUser]);
            $_SESSION['flash_success'] = 'Kata sandi akun Pengawas berhasil direset.';
        }
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 4. 1-Klik Daftarkan IP WiFi Saat Ini (Level PHP Tanpa Router)
    if ($action === 'register_wifi_ip') {
        $ipToSave = trim((string)($_POST['ip_address'] ?? $currentClientIp));
        $namaJaringan = trim((string)($_POST['nama_jaringan'] ?? 'WiFi Kantor K3 Operasional'));

        $stmtUpdateWifi = $pdo->prepare("
            UPDATE wifi_settings 
            SET nama_jaringan = ?, ip_address = ?, updated_by_admin = ?, updated_at = NOW() 
            WHERE id_setting = 1
        ");
        $stmtUpdateWifi->execute([$namaJaringan, $ipToSave, $currentUser['id_user']]);

        $_SESSION['flash_success'] = "Berhasil mendaftarkan IP WiFi Kantor: '{$ipToSave}' ({$namaJaringan}).";
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 5. Saklar Toggle ON/OFF Pemeriksaan IP WiFi
    if ($action === 'toggle_wifi_check') {
        $stmtGet = $pdo->query("SELECT is_wifi_check_enabled FROM wifi_settings WHERE id_setting = 1");
        $curr = (int)$stmtGet->fetchColumn();
        $next = ($curr === 1) ? 0 : 1;

        $stmtSet = $pdo->prepare("UPDATE wifi_settings SET is_wifi_check_enabled = ?, updated_by_admin = ? WHERE id_setting = 1");
        $stmtSet->execute([$next, $currentUser['id_user']]);

        $statusText = ($next === 1) ? 'DIAKTIFKAN (Pengawas wajib terhubung WiFi Kantor)' : 'DINONAKTIFKAN (Mode Darurat / Bypass Bebas IP)';
        $_SESSION['flash_success'] = "Saklar pembatasan jaringan WiFi berhasil {$statusText}.";
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 6. Ubah PIN Fisik Ruang APD
    if ($action === 'update_pin') {
        $newPin = trim((string)($_POST['kode_pin_gudang'] ?? ''));
        if (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
            $_SESSION['flash_error'] = 'Kode PIN Ruang APD harus tepat 4 digit angka.';
        } else {
            $stmtPin = $pdo->prepare("UPDATE wifi_settings SET kode_pin_gudang = ?, updated_by_admin = ? WHERE id_setting = 1");
            $stmtPin->execute([$newPin, $currentUser['id_user']]);
            $_SESSION['flash_success'] = "Kode PIN Ruang APD berhasil diperbarui menjadi '{$newPin}'. Pastikan Anda mencetak poster baru.";
        }
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }
}

// Ambil konfigurasi wifi_settings terkini
$wifiStmt = $pdo->query("
    SELECT w.*, u.nama_lengkap AS updated_by_name 
    FROM wifi_settings w 
    LEFT JOIN users u ON w.updated_by_admin = u.id_user 
    WHERE w.id_setting = 1
");
$wifi = $wifiStmt->fetch(PDO::FETCH_ASSOC);

// Ambil seluruh akun Pengawas
$pengawasStmt = $pdo->query("SELECT * FROM users WHERE role = 'pengawas' ORDER BY id_user DESC");
$pengawasList = $pengawasStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pengawas & Keamanan WiFi - Administrator SIPAD</title>
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
                <div class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-white block leading-tight">PANEL ADMINISTRATOR</span>
                    <span class="text-[10px] text-amber-400 font-semibold tracking-wider uppercase">Kelola Pengawas & Keamanan Jaringan</span>
                </div>
            </div>

            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-slate-300">
                <a href="/admin/index.php" class="hover:text-amber-400 transition-colors">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="hover:text-amber-400 transition-colors">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="text-amber-400">Kelola Pengawas & WiFi</a>
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

        <!-- Flash Message -->
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

        <!-- GRID 1: KONTROL KEAMANAN WIFI & PIN RUANG APD -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- PANEL KONTROL WIFI 1-KLIK & SAKLAR ON/OFF -->
            <div class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 shadow-xl space-y-6">
                <div class="flex items-start justify-between gap-4 pb-4 border-b border-slate-800">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400 px-2 py-0.5 rounded bg-amber-400/10 border border-amber-400/20">
                            Zero-Router Security
                        </span>
                        <h2 class="text-xl font-bold text-white mt-1.5">Validasi Jaringan WiFi Kantor (1-Klik)</h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Membatasi login Pengawas hanya saat terhubung ke WiFi kantor tanpa butuh akses ke router.
                        </p>
                    </div>

                    <!-- Saklar Toggle ON / OFF -->
                    <form action="kelola_pengawas.php" method="POST">
                        <input type="hidden" name="action" value="toggle_wifi_check">
                        <?php $isOn = ((int)($wifi['is_wifi_check_enabled'] ?? 0) === 1); ?>
                        <button type="submit"
                            class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $isOn ? 'bg-emerald-500 text-slate-950 shadow-lg shadow-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700' ?>">
                            <span class="w-2.5 h-2.5 rounded-full <?= $isOn ? 'bg-slate-950 animate-ping' : 'bg-slate-500' ?>"></span>
                            <span>Filter WiFi: <strong><?= $isOn ? 'AKTIF (ON)' : 'NONAKTIF (OFF)' ?></strong></span>
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-1">
                        <span class="text-slate-500 block">IP WiFi Terdaftar Saat Ini:</span>
                        <strong class="text-base font-mono text-emerald-400 block"><?= htmlspecialchars($wifi['ip_address'] ?? 'Belum Diatur') ?></strong>
                        <span class="text-slate-400 block truncate"><?= htmlspecialchars($wifi['nama_jaringan'] ?? '-') ?></span>
                    </div>

                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-1">
                        <span class="text-slate-500 block">IP Koneksi Anda Sekarang:</span>
                        <strong class="text-base font-mono text-amber-400 block"><?= htmlspecialchars($currentClientIp) ?></strong>
                        <span class="text-[11px] <?= ($currentClientIp === ($wifi['ip_address'] ?? '')) ? 'text-emerald-400' : 'text-amber-300' ?>">
                            <?= ($currentClientIp === ($wifi['ip_address'] ?? '')) ? '✓ Cocok dengan IP terdaftar' : '! Berbeda dari IP terdaftar' ?>
                        </span>
                    </div>
                </div>

                <!-- Form 1-Klik Pendaftaran IP WiFi Saat Ini -->
                <form action="kelola_pengawas.php" method="POST" class="p-4 rounded-2xl bg-slate-950/70 border border-slate-800 space-y-3">
                    <input type="hidden" name="action" value="register_wifi_ip">
                    <input type="hidden" name="ip_address" value="<?= htmlspecialchars($currentClientIp) ?>">

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                        <div class="flex-grow">
                            <label class="block text-[11px] font-semibold text-slate-400 mb-1">Label Nama Titik Jaringan / WiFi</label>
                            <input type="text" name="nama_jaringan" required
                                value="<?= htmlspecialchars($wifi['nama_jaringan'] ?? 'WiFi Kantor K3 Operasional') ?>"
                                class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-xs focus:ring-1 focus:ring-amber-500">
                        </div>
                        <div class="sm:pt-4">
                            <button type="submit"
                                class="w-full py-2.5 px-4 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg text-xs shadow-md shadow-amber-500/10 active:scale-95 transition-all flex items-center justify-center gap-1.5 whitespace-nowrap">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                </svg>
                                <span>1-Klik Simpan IP Saat Ini</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500">
                        * Hubungkan laptop/perangkat Anda ke WiFi kantor, lalu tekan tombol di atas untuk menyinkronkan alamat IP publik kantor secara instan.
                    </p>
                </form>
            </div>

            <!-- PANEL GANTI PIN RUANG APD -->
            <div class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl space-y-5 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-amber-400 font-bold text-base mb-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <h3>PIN Ruang APD Fisik</h3>
                    </div>
                    <p class="text-xs text-slate-400">
                        Kode 4 digit yang wajib dimasukkan peminjam di form mandiri tanpa login sebagai bukti kehadiran fisik di gudang.
                    </p>

                    <div class="my-5 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-center">
                        <span class="text-[10px] text-slate-400 uppercase tracking-widest block font-semibold">PIN AKTIF SAAT INI</span>
                        <span class="text-4xl font-extrabold font-mono text-amber-400 tracking-widest mt-1 block">
                            <?= htmlspecialchars($wifi['kode_pin_gudang'] ?? '4821') ?>
                        </span>
                    </div>

                    <form action="kelola_pengawas.php" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="update_pin">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Perbarui PIN Gudang (4 Digit Angka)</label>
                            <input type="text" name="kode_pin_gudang" maxlength="4" pattern="[0-9]{4}" required
                                placeholder="Contoh: 5912"
                                class="w-full px-4 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white font-mono text-center tracking-widest text-base focus:ring-2 focus:ring-amber-500 focus:outline-none">
                        </div>
                        <button type="submit"
                            class="w-full py-2.5 px-4 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl text-xs border border-slate-700 transition-all">
                            Simpan Perubahan PIN
                        </button>
                    </form>
                </div>

                <div class="pt-3 border-t border-slate-800 text-center">
                    <a href="/admin/kelola_apd.php#poster-qr" class="text-xs text-amber-400 hover:underline inline-flex items-center gap-1">
                        <span>Lihat & Cetak Poster QR Pintu Gudang &rarr;</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- GRID 2: MANAJEMEN AKUN PENGAWAS (TC-03) -->
        <div class="bg-slate-900 border border-slate-800 rounded-3xl shadow-xl overflow-hidden p-6 sm:p-7 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
                <div>
                    <h2 class="text-xl font-bold text-white">Daftar Akun Pengawas Resmi</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Tata Kelola Terkendali Penuh: Seluruh akun Pengawas dibuat eksklusif oleh Admin (tidak ada registrasi publik liar).
                    </p>
                </div>
                <button onclick="document.getElementById('form-tambah-pengawas').classList.toggle('hidden')"
                    class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs shadow-md transition-all flex items-center gap-1.5 self-start">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>+ Tambah Akun Pengawas Baru</span>
                </button>
            </div>

            <!-- FORM TAMBAH PENGAWAS BARU (COLLAPSIBLE) -->
            <div id="form-tambah-pengawas" class="hidden p-5 rounded-2xl bg-slate-950 border border-slate-800 space-y-4">
                <h3 class="text-sm font-bold text-amber-400 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    <span>Formulir Pembuatan Akun Pengawas Resmi (TC-03)</span>
                </h3>
                <form action="kelola_pengawas.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <input type="hidden" name="action" value="create_pengawas">

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Nama Lengkap Petugas</label>
                        <input type="text" name="nama_lengkap" required placeholder="Contoh: Ival Permana"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Username Login</label>
                        <input type="text" name="username" required placeholder="Contoh: pengawas_k3"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Kata Sandi Awal</label>
                        <input type="password" name="password" required minlength="6" placeholder="Min. 6 Karakter"
                            class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white text-xs focus:ring-1 focus:ring-amber-500">
                    </div>

                    <div class="sm:col-span-3 flex justify-end gap-3 pt-2">
                        <button type="button" onclick="document.getElementById('form-tambah-pengawas').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold">
                            Batal
                        </button>
                        <button type="submit"
                            class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md">
                            Simpan Akun Pengawas
                        </button>
                    </div>
                </form>
            </div>

            <!-- TABEL DAFTAR PENGAWAS -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider border-b border-slate-800 font-semibold">
                        <tr>
                            <th class="px-5 py-3">Nama Lengkap</th>
                            <th class="px-5 py-3">Username</th>
                            <th class="px-5 py-3">Status Akun</th>
                            <th class="px-5 py-3">Tanggal Dibuat</th>
                            <th class="px-5 py-3 text-right">Aksi Manajemen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($pengawasList)): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500">
                                    Belum ada akun Pengawas yang dibuat. Klik tombol di atas untuk membuat akun pertama.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pengawasList as $p): ?>
                                <tr class="hover:bg-slate-800/40 transition-colors">
                                    <td class="px-5 py-3.5 font-bold text-white"><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                                    <td class="px-5 py-3.5 font-mono text-amber-400"><?= htmlspecialchars($p['username']) ?></td>
                                    <td class="px-5 py-3.5">
                                        <?php if ((int)$p['is_active'] === 1): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-500/10 text-red-400 border border-red-500/20">
                                                Nonaktif
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-500"><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></td>
                                    <td class="px-5 py-3.5 text-right space-x-2">
                                        <!-- Tombol Aktifkan / Nonaktifkan -->
                                        <form action="kelola_pengawas.php" method="POST" class="inline" onsubmit="return confirm('Ubah status keaktifan akun <?= htmlspecialchars(addslashes($p['username'])) ?>?')">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id_user" value="<?= $p['id_user'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $p['is_active'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-[11px] font-medium border border-slate-700">
                                                <?= ((int)$p['is_active'] === 1) ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>

                                        <!-- Tombol Reset Password -->
                                        <button onclick="promptResetPassword('<?= $p['id_user'] ?>', '<?= htmlspecialchars(addslashes($p['username'])) ?>')"
                                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-amber-500 hover:text-slate-950 text-slate-300 text-[11px] font-medium border border-slate-700 transition-colors">
                                            Reset Password
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Hidden Form for Reset Password Prompt -->
    <form id="form-reset-pwd" action="kelola_pengawas.php" method="POST" class="hidden">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" id="reset-id-user" name="id_user" value="">
        <input type="hidden" id="reset-new-password" name="new_password" value="">
    </form>

    <script>
        function promptResetPassword(idUser, username) {
            const newPass = prompt('Masukkan kata sandi baru untuk akun ' + username + ' (minimal 6 karakter):');
            if (newPass && newPass.length >= 6) {
                document.getElementById('reset-id-user').value = idUser;
                document.getElementById('reset-new-password').value = newPass;
                document.getElementById('form-reset-pwd').submit();
            } else if (newPass !== null) {
                alert('Kata sandi minimal 6 karakter.');
            }
        }
    </script>
</body>
</html>
