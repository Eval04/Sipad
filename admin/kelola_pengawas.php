<?php
/**
 * Modul Manajemen Akun Pengawas Terpusat, Kontrol Keamanan WiFi (Zero-Router)
 * & Alat Pemeliharaan Sistem / Wipe Basis Data oleh Super Admin
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 * Tema Korporat Resmi: PT Pelabuhan Indonesia (Pelindo) Light Palette
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

    // 4. Hapus Akun User Pengawas Secara Permanen oleh Super Admin
    if ($action === 'delete_user') {
        $idUser = (int)($_POST['id_user'] ?? 0);

        if ($idUser <= 0) {
            $_SESSION['flash_error'] = 'ID pengguna tidak valid.';
        } elseif ($idUser === (int)$currentUser['id_user']) {
            $_SESSION['flash_error'] = 'Anda tidak dapat menghapus akun Anda sendiri saat sedang aktif masuk.';
        } else {
            $stmtUser = $pdo->prepare("SELECT nama_lengkap, username, role FROM users WHERE id_user = ?");
            $stmtUser->execute([$idUser]);
            $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $_SESSION['flash_error'] = 'Akun pengguna tidak ditemukan.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Reassign referensi foreign key di condition_logs ke null agar integritas audit terjaga
                    $stmtCond = $pdo->prepare("UPDATE condition_logs SET id_pengawas = NULL WHERE id_pengawas = ?");
                    $stmtCond->execute([$idUser]);

                    // Nullify di loans & wifi_settings jika ada referensi
                    $stmtLoans = $pdo->prepare("UPDATE loans SET id_pengawas_acc = NULL WHERE id_pengawas_acc = ?");
                    $stmtLoans->execute([$idUser]);

                    $stmtWifi = $pdo->prepare("UPDATE wifi_settings SET updated_by_admin = NULL WHERE updated_by_admin = ?");
                    $stmtWifi->execute([$idUser]);

                    // Hapus user secara permanen
                    $stmtDel = $pdo->prepare("DELETE FROM users WHERE id_user = ?");
                    $stmtDel->execute([$idUser]);

                    $pdo->commit();
                    $_SESSION['flash_success'] = "Akun Pengawas '{$targetUser['nama_lengkap']}' (@{$targetUser['username']}) berhasil dihapus secara permanen dari sistem.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $_SESSION['flash_error'] = 'Gagal menghapus akun: ' . $e->getMessage();
                }
            }
        }
        header('Location: /admin/kelola_pengawas.php');
        exit;
    }

    // 5. 1-Klik Daftarkan IP WiFi Saat Ini (Level PHP Tanpa Router)
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

    // 6. Saklar Toggle ON/OFF Pemeriksaan IP WiFi
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

    // 7. Ubah PIN Fisik Ruang APD
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

    // 8. Wipe & Reset Basis Data oleh Super Admin (Factory Data Wipe)
    if ($action === 'wipe_database') {
        $confirmText = trim((string)($_POST['confirm_wipe'] ?? ''));
        $wipeMode    = trim((string)($_POST['wipe_mode'] ?? 'all'));
        $adminPass   = (string)($_POST['admin_password'] ?? '');

        // Validasi konfirmasi (harus ketik WIPE atau verifikasi password admin aktif)
        $isConfirmed = false;
        if (strtoupper($confirmText) === 'WIPE') {
            $isConfirmed = true;
        } elseif ($adminPass !== '') {
            $stmtAdmin = $pdo->prepare("SELECT password_hash FROM users WHERE id_user = ?");
            $stmtAdmin->execute([$currentUser['id_user']]);
            $hash = (string)$stmtAdmin->fetchColumn();
            if (password_verify($adminPass, $hash)) {
                $isConfirmed = true;
            }
        }

        if (!$isConfirmed) {
            $_SESSION['flash_error'] = 'Konfirmasi gagal! Anda harus mengetik kata "WIPE" atau memasukkan kata sandi Admin dengan benar untuk menjalankan pembersihan data.';
            header('Location: /admin/kelola_pengawas.php#zona-wipe');
            exit;
        }

        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            if ($wipeMode === 'loans_only') {
                // Bersihkan hanya sirkulasi & log
                $pdo->exec("DELETE FROM condition_logs");
                $pdo->exec("ALTER TABLE condition_logs AUTO_INCREMENT = 1");
                $pdo->exec("DELETE FROM loans");
                $pdo->exec("ALTER TABLE loans AUTO_INCREMENT = 1");
                $pdo->exec("UPDATE apd_items SET stok_tersedia = stok_total, stok_rusak = 0");
                $msg = "Seluruh riwayat transaksi peminjaman & log kondisi berhasil di-wipe bersih. Seluruh stok APD telah dipulihkan ke stok awal.";
            } elseif ($wipeMode === 'users_only') {
                // Hapus seluruh akun pengawas saja (hanya user saja, master APD & kategori tetap aman)
                $pdo->exec("UPDATE loans SET id_pengawas_acc = NULL");
                $pdo->exec("UPDATE condition_logs SET id_pengawas = NULL");
                $pdo->exec("UPDATE wifi_settings SET updated_by_admin = NULL");
                $stmtDelUsers = $pdo->prepare("DELETE FROM users WHERE role = 'pengawas'");
                $stmtDelUsers->execute();
                $msg = "Seluruh akun pengawas berhasil dihapus. Akun Super Admin, data master APD, dan kategori APD tetap aman.";
            } else {
                // Hapus seluruh akun pengawas dan bersihkan sirkulasi (Master APD & Kategori TETAP UTUH)
                $pdo->exec("DELETE FROM condition_logs");
                $pdo->exec("ALTER TABLE condition_logs AUTO_INCREMENT = 1");
                $pdo->exec("DELETE FROM loans");
                $pdo->exec("ALTER TABLE loans AUTO_INCREMENT = 1");
                $pdo->exec("UPDATE apd_items SET stok_tersedia = stok_total, stok_rusak = 0");

                $pdo->exec("UPDATE wifi_settings SET updated_by_admin = NULL");
                $stmtDelUsers = $pdo->prepare("DELETE FROM users WHERE role = 'pengawas'");
                $stmtDelUsers->execute();

                $msg = "Seluruh akun pengawas dan riwayat transaksi berhasil dibersihkan. Master APD dan Kategori APD tetap utuh.";
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $_SESSION['flash_success'] = $msg;
        } catch (Throwable $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $_SESSION['flash_error'] = 'Gagal melakukan wipe basis data: ' . $e->getMessage();
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

// Hitung total data untuk status wipe
$totalLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM apd_items")->fetchColumn();
$totalCats  = (int)$pdo->query("SELECT COUNT(*) FROM apd_categories")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Kelola Pengawas & Keamanan WiFi - Pelindo K3</title>
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
                        <span class="text-xs sm:text-sm font-black tracking-tight text-white border-l border-sky-400/40 pl-2">PENGAWAS & WIFI</span>
                    </div>
                    <span class="text-[10px] text-sky-200 font-mono tracking-wider uppercase block">PT Pelabuhan Indonesia (Persero)</span>
                </div>
            </a>

            <!-- Desktop Nav Menu -->
            <nav class="hidden md:flex items-center gap-5 text-xs font-semibold text-sky-100">
                <a href="/admin/index.php" class="hover:text-white transition-colors">Dashboard</a>
                <a href="/admin/kelola_apd.php" class="hover:text-white transition-colors">Master APD</a>
                <a href="/admin/kelola_pengawas.php" class="text-white font-bold border-b-2 border-sky-300 pb-1">Pengawas & WiFi</a>
                <a href="/admin/laporan.php" class="hover:text-white transition-colors">Laporan Audit</a>
            </nav>

            <div class="flex items-center gap-2.5">
                <a href="#zona-wipe" class="text-xs px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold transition-colors flex items-center gap-1.5 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span class="hidden sm:inline">Wipe Database</span>
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

        <!-- GRID 1: ZERO-ROUTER WIFI & PIN GUDANG -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- PANEL KONTROL WIFI 1-KLIK & SAKLAR ON/OFF -->
            <div class="lg:col-span-7 bg-white rounded-2xl p-5 sm:p-6 space-y-5 border border-slate-200 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 pb-4 border-b border-slate-200">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#003e74] px-2 py-0.5 rounded bg-sky-100">
                            Zero-Router Security
                        </span>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 mt-1.5">Validasi Jaringan WiFi Kantor (1-Klik Sync)</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Pengawas hanya dapat login ACC saat terhubung ke WiFi kantor tanpa konfigurasi router yang rumit.
                        </p>
                    </div>

                    <!-- Saklar Toggle ON / OFF -->
                    <form action="kelola_pengawas.php" method="POST" class="self-start">
                        <input type="hidden" name="action" value="toggle_wifi_check">
                        <?php $isOn = ((int)($wifi['is_wifi_check_enabled'] ?? 0) === 1); ?>
                        <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?= $isOn ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 border border-slate-300' ?>">
                            <span class="w-2.5 h-2.5 rounded-full <?= $isOn ? 'bg-white' : 'bg-slate-400' ?>"></span>
                            <span>Filter WiFi: <strong><?= $isOn ? 'ON (Wajib)' : 'OFF (Bypass)' ?></strong></span>
                        </button>
                    </form>
                </div>

                <!-- 2 Status Boxes -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-1">
                        <span class="text-slate-500 text-[10px] uppercase font-bold block">IP WiFi Terdaftar:</span>
                        <strong class="text-base font-mono text-emerald-700 block"><?= htmlspecialchars($wifi['ip_address'] ?? 'Belum Diatur') ?></strong>
                        <span class="text-slate-500 block truncate"><?= htmlspecialchars($wifi['nama_jaringan'] ?? '-') ?></span>
                    </div>

                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-1">
                        <span class="text-slate-500 text-[10px] uppercase font-bold block">IP Koneksi Anda Sekarang:</span>
                        <strong class="text-base font-mono text-[#005b96] block"><?= htmlspecialchars($currentClientIp) ?></strong>
                        <span class="text-[11px] font-semibold <?= ($currentClientIp === ($wifi['ip_address'] ?? '')) ? 'text-emerald-700' : 'text-amber-700' ?>">
                            <?= ($currentClientIp === ($wifi['ip_address'] ?? '')) ? '✓ Cocok dengan IP terdaftar' : '! Berbeda dari IP terdaftar' ?>
                        </span>
                    </div>
                </div>

                <!-- 1-Click Form Register IP -->
                <form action="kelola_pengawas.php" method="POST" class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                    <input type="hidden" name="action" value="register_wifi_ip">
                    <input type="hidden" name="ip_address" value="<?= htmlspecialchars($currentClientIp) ?>">

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                        <div class="flex-grow">
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Nama Jaringan / Titik Akses</label>
                            <input type="text" name="nama_jaringan" required
                                value="<?= htmlspecialchars($wifi['nama_jaringan'] ?? 'WiFi Kantor K3 Operasional') ?>"
                                class="sipad-input w-full px-3 py-2 rounded-lg text-xs bg-white text-slate-900 border border-slate-300">
                        </div>
                        <div class="sm:pt-5">
                            <button type="submit" class="btn-pelindo-primary w-full px-4 py-2 rounded-lg text-xs flex items-center justify-center gap-1.5 whitespace-nowrap font-bold shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                </svg>
                                <span>1-Klik Daftarkan IP Ini</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-[10px] text-slate-500">
                        * Hubungkan laptop/perangkat ke WiFi kantor K3, lalu tekan tombol di atas untuk mendaftarkan IP secara instan.
                    </p>
                </form>
            </div>

            <!-- PANEL GANTI PIN RUANG APD -->
            <div class="lg:col-span-5 bg-white rounded-2xl p-5 sm:p-6 space-y-4 flex flex-col justify-between border border-slate-200 shadow-sm">
                <div>
                    <div class="flex items-center gap-2 text-[#003e74] font-bold text-sm sm:text-base mb-1">
                        <svg class="w-5 h-5 text-[#005b96]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <h3>PIN Fisik Ruang APD</h3>
                    </div>
                    <p class="text-xs text-slate-500">
                        Kode 4 digit yang wajib dimasukkan peminjam pada formulir publik mandiri sebagai bukti kehadiran di gudang.
                    </p>

                    <!-- Large Pelindo Corporate PIN Box -->
                    <div class="my-4 p-4 rounded-xl bg-sky-50 border-2 border-[#005b96] text-center">
                        <span class="text-[10px] text-slate-600 uppercase tracking-widest block font-bold">PIN GUDANG AKTIF</span>
                        <span class="text-4xl font-extrabold font-mono text-[#003e74] tracking-widest mt-1 block">
                            <?= htmlspecialchars($wifi['kode_pin_gudang'] ?? '4821') ?>
                        </span>
                    </div>

                    <form action="kelola_pengawas.php" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="update_pin">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Perbarui PIN Gudang (4 Digit Angka)</label>
                            <input type="text" name="kode_pin_gudang" maxlength="4" pattern="[0-9]{4}" required placeholder="Contoh: 5912"
                                class="sipad-input w-full px-4 py-2.5 rounded-xl text-center font-mono text-base tracking-widest font-bold text-[#003e74] bg-white border border-slate-300">
                        </div>
                        <button type="submit" class="w-full py-2.5 px-4 bg-[#005b96] hover:bg-[#003e74] text-white font-semibold rounded-xl text-xs transition-colors shadow-sm">
                            Simpan Perubahan PIN
                        </button>
                    </form>
                </div>

                <div class="pt-3 border-t border-slate-200 text-center">
                    <a href="/admin/kelola_apd.php#poster-qr" class="text-xs text-[#005b96] hover:text-[#003e74] hover:underline inline-flex items-center gap-1 font-semibold">
                        <span>Cetak Poster QR Pintu Gudang &rarr;</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- GRID 2: MANAJEMEN AKUN PENGAWAS & HAPUS USER (TC-03) -->
        <section class="bg-white rounded-2xl p-5 sm:p-6 space-y-5 border border-slate-200 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900">Akun Pengawas K3 Resmi</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Super Admin dapat membuat akun baru, mereset sandi, mengaktifkan/menonaktifkan, atau menghapus user</p>
                </div>
                <button onclick="document.getElementById('form-tambah-pengawas').classList.toggle('hidden')"
                    class="btn-pelindo-primary px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 self-start sm:self-auto font-bold shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>+ Tambah Akun Pengawas</span>
                </button>
            </div>

            <!-- COLLAPSIBLE FORM TAMBAH PENGAWAS BARU -->
            <div id="form-tambah-pengawas" class="hidden p-4 sm:p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                    <h3 class="text-xs sm:text-sm font-bold text-[#003e74] flex items-center gap-2">
                        <svg class="w-4 h-4 text-[#005b96]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        <span>Formulir Pembuatan Akun Pengawas Resmi (TC-03)</span>
                    </h3>
                    <button type="button" onclick="document.getElementById('form-tambah-pengawas').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xs font-semibold">
                        Tutup
                    </button>
                </div>

                <form action="kelola_pengawas.php" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <input type="hidden" name="action" value="create_pengawas">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Nama Lengkap Petugas</label>
                        <input type="text" name="nama_lengkap" required placeholder="Contoh: Ival Permana"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Username Login</label>
                        <input type="text" name="username" required placeholder="Contoh: pengawas_k3"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kata Sandi Awal</label>
                        <input type="password" name="password" required minlength="6" placeholder="Min. 6 Karakter"
                            class="sipad-input w-full px-3.5 py-2 rounded-xl text-xs bg-white text-slate-900 border border-slate-300">
                    </div>

                    <div class="sm:col-span-3 flex justify-end gap-2.5 pt-2">
                        <button type="button" onclick="document.getElementById('form-tambah-pengawas').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs font-semibold">
                            Batal
                        </button>
                        <button type="submit" class="btn-pelindo-primary px-5 py-2 rounded-xl text-xs font-bold shadow-sm">
                            Simpan Akun Pengawas
                        </button>
                    </div>
                </form>
            </div>

            <!-- MOBILE VIEW: ACCOUNT CARDS (<640px) -->
            <div class="block sm:hidden space-y-3">
                <?php if (empty($pengawasList)): ?>
                    <div class="text-center py-6 text-xs text-slate-400">Belum ada akun pengawas terdaftar.</div>
                <?php else: ?>
                    <?php foreach ($pengawasList as $p): ?>
                        <div class="p-4 rounded-xl bg-white border border-slate-200 space-y-3 shadow-sm">
                            <div class="flex items-start justify-between">
                                <div>
                                    <strong class="text-sm text-slate-900 block"><?= htmlspecialchars($p['nama_lengkap']) ?></strong>
                                    <span class="font-mono text-xs text-[#005b96] block mt-0.5">@<?= htmlspecialchars($p['username']) ?></span>
                                </div>
                                <div>
                                    <?php if ((int)$p['is_active'] === 1): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                            Aktif
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800">
                                            Nonaktif
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 pt-2 border-t border-slate-100">
                                <!-- Toggle Status -->
                                <form action="kelola_pengawas.php" method="POST" class="flex-grow" onsubmit="return confirm('Ubah status keaktifan akun <?= htmlspecialchars(addslashes($p['username'])) ?>?')">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id_user" value="<?= $p['id_user'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $p['is_active'] ?>">
                                    <button type="submit" class="w-full py-2 px-2.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-semibold hover:bg-slate-200 transition-colors">
                                        <?= ((int)$p['is_active'] === 1) ? 'Nonaktif' : 'Aktif' ?>
                                    </button>
                                </form>

                                <!-- Reset Password -->
                                <button onclick="promptResetPassword('<?= $p['id_user'] ?>', '<?= htmlspecialchars(addslashes($p['username'])) ?>')"
                                    class="flex-grow py-2 px-2.5 rounded-lg bg-slate-100 hover:bg-[#003e74] hover:text-white text-slate-700 text-xs font-semibold transition-colors">
                                    Reset Sandi
                                </button>

                                <!-- Hapus Akun User -->
                                <form action="kelola_pengawas.php" method="POST" onsubmit="return confirm('HAPUS PERMANEN akun Pengawas <?= htmlspecialchars(addslashes($p['nama_lengkap'])) ?> (@<?= htmlspecialchars(addslashes($p['username'])) ?>)?\n\nTindakan ini tidak dapat dibatalkan.')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="id_user" value="<?= $p['id_user'] ?>">
                                    <button type="submit" class="p-2 rounded-lg bg-red-50 hover:bg-red-600 hover:text-white text-red-600 border border-red-200 transition-colors" title="Hapus Pengawas Secara Permanen">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- DESKTOP VIEW: STRUCTURED TABLE (>=640px) -->
            <div class="hidden sm:block overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 uppercase tracking-wider border-b border-slate-200 font-semibold text-[11px]">
                        <tr>
                            <th class="px-5 py-3">Nama Lengkap</th>
                            <th class="px-5 py-3">Username</th>
                            <th class="px-5 py-3 text-center">Status Akun</th>
                            <th class="px-5 py-3">Tanggal Dibuat</th>
                            <th class="px-5 py-3 text-right">Aksi Manajemen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($pengawasList)): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-400">
                                    Belum ada akun Pengawas yang dibuat. Klik tombol di atas untuk membuat akun pertama.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pengawasList as $p): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3.5 font-bold text-slate-900"><?= htmlspecialchars($p['nama_lengkap']) ?></td>
                                    <td class="px-5 py-3.5 font-mono text-[#005b96] font-semibold"><?= htmlspecialchars($p['username']) ?></td>
                                    <td class="px-5 py-3.5 text-center">
                                        <?php if ((int)$p['is_active'] === 1): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800">
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
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-semibold transition-colors">
                                                <?= ((int)$p['is_active'] === 1) ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>

                                        <!-- Tombol Reset Password -->
                                        <button onclick="promptResetPassword('<?= $p['id_user'] ?>', '<?= htmlspecialchars(addslashes($p['username'])) ?>')"
                                            class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-[#003e74] hover:text-white text-slate-700 text-[11px] font-semibold transition-colors">
                                            Reset Password
                                        </button>

                                        <!-- Tombol Hapus User -->
                                        <form action="kelola_pengawas.php" method="POST" class="inline" onsubmit="return confirm('HAPUS PERMANEN akun Pengawas <?= htmlspecialchars(addslashes($p['nama_lengkap'])) ?> (@<?= htmlspecialchars(addslashes($p['username'])) ?>)?\n\nTindakan ini tidak dapat dibatalkan.')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id_user" value="<?= $p['id_user'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 hover:bg-red-600 hover:text-white text-red-600 text-[11px] font-semibold border border-red-200 transition-colors" title="Hapus Akun Pengawas">
                                                Hapus
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ZONA SISTEM: RESET & PEMBERSIHAN BASIS DATA (DATABASE WIPE) -->
        <section id="zona-wipe" class="bg-white rounded-2xl p-5 sm:p-6 space-y-5 border-2 border-red-200 shadow-sm">
            <div class="flex items-center gap-3 pb-3 border-b border-red-100">
                <div class="w-10 h-10 rounded-xl bg-red-100 text-red-600 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-red-900 leading-tight">Pembersihan Akun Pengawas (Supervisor Data Wipe)</h2>
                    <p class="text-xs text-red-600 mt-0.5">Fitur khusus Super Admin untuk mereset akun pengawas. Data Master APD & Kategori tetap aman.</p>
                </div>
            </div>

            <!-- Metric Status Sebelum Wipe -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-200">
                    <span class="text-[10px] text-slate-500 uppercase font-semibold block">Transaksi Pinjam</span>
                    <strong class="text-slate-900 text-base font-bold block"><?= $totalLoans ?> Baris</strong>
                </div>
                <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    <span class="text-[10px] text-emerald-700 uppercase font-semibold block">Master APD (Aman)</span>
                    <strong class="text-emerald-800 text-base font-bold block"><?= $totalItems ?> Item</strong>
                </div>
                <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    <span class="text-[10px] text-emerald-700 uppercase font-semibold block">Kategori APD (Aman)</span>
                    <strong class="text-emerald-800 text-base font-bold block"><?= $totalCats ?> Kategori</strong>
                </div>
                <div class="p-3 rounded-xl bg-red-50 border border-red-200">
                    <span class="text-[10px] text-red-700 uppercase font-semibold block">Akun Pengawas Target</span>
                    <strong class="text-red-700 text-base font-bold block"><?= count($pengawasList) ?> Akun</strong>
                </div>
            </div>

            <form action="kelola_pengawas.php" method="POST" class="p-4 sm:p-5 rounded-2xl bg-red-50/50 border border-red-200 space-y-4" onsubmit="return confirm('PERINGATAN TINGKAT TINGGI!\n\nApakah Anda benar-benar yakin ingin melakukan pembersihan data ini?\nTindakan ini akan menghapus data yang dipilih secara permanen.');">
                <input type="hidden" name="action" value="wipe_database">

                <div>
                    <label class="block text-xs font-bold text-slate-900 mb-1.5">Pilih Cakupan Pembersihan:</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <label class="p-3.5 rounded-xl bg-white border border-red-300 flex items-start gap-3 cursor-pointer hover:border-red-500 transition-colors">
                            <input type="radio" name="wipe_mode" value="users_only" checked class="mt-0.5 text-red-600 focus:ring-red-500">
                            <div>
                                <strong class="text-slate-900 block font-bold">Wipe Semua Akun Pengawas (Hanya User Saja)</strong>
                                <span class="text-[11px] text-slate-500 block mt-0.5 leading-relaxed">
                                    Menghapus seluruh akun pengawas yang terdaftar. Data master APD, kategori APD, dan akun Super Admin tetap aman dan tidak akan terhapus.
                                </span>
                            </div>
                        </label>

                        <label class="p-3.5 rounded-xl bg-white border border-slate-300 flex items-start gap-3 cursor-pointer hover:border-slate-500 transition-colors">
                            <input type="radio" name="wipe_mode" value="loans_only" class="mt-0.5 text-[#003e74] focus:ring-[#003e74]">
                            <div>
                                <strong class="text-slate-900 block font-bold">Wipe Riwayat Transaksi Saja</strong>
                                <span class="text-[11px] text-slate-500 block mt-0.5 leading-relaxed">
                                    Hanya mengosongkan riwayat sirkulasi peminjaman dan log pengembalian, serta mereset stok siap pakai ke stok total. Akun pengawas dan Master APD tetap ada.
                                </span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="pt-2 border-t border-red-200 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-900 mb-1">
                            Konfirmasi Keamanan (Ketik kata <span class="text-red-600 font-mono">WIPE</span>):
                        </label>
                        <input type="text" name="confirm_wipe" required placeholder="Ketik WIPE dengan huruf besar"
                            class="sipad-input w-full px-3.5 py-2.5 rounded-xl text-xs bg-white text-slate-900 border-2 border-red-300 font-mono font-bold uppercase placeholder:normal-case placeholder:font-sans">
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full py-2.5 px-5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-xs shadow-md transition-all flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            <span>Wipe & Kosongkan Data Sekarang</span>
                        </button>
                    </div>
                </div>
            </form>
        </section>

    </main>

    <!-- Hidden Form for Reset Password Prompt -->
    <form id="form-reset-pwd" action="kelola_pengawas.php" method="POST" class="hidden">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" id="reset-id-user" name="id_user" value="">
        <input type="hidden" id="reset-new-password" name="new_password" value="">
    </form>

    <!-- Mobile Bottom Navigation for Admin (Pelindo Light) -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
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
        <a href="/admin/kelola_pengawas.php" class="mobile-nav-item active">
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
