<?php
/**
 * Gerbang Autentikasi Admin & Pengawas
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth_check.php';

startAppSession();

// Jika sudah login, langsung alihkan ke dashboard masing-masing
if (!empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: /admin/index.php');
        exit;
    } elseif ($_SESSION['role'] === 'pengawas') {
        header('Location: /pengawas/index.php');
        exit;
    }
}

$pdo = getDbConnection();
$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$currentClientIp = getCurrentClientIp();
$wifiStmt = $pdo->query("SELECT nama_jaringan, ip_address, is_wifi_check_enabled FROM wifi_settings LIMIT 1");
$wifiSettings = $wifiStmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan kata sandi wajib diisi.';
    } else {
        $authResult = authenticateUser($pdo, $username, $password);

        if (!$authResult['success']) {
            $error = $authResult['message'];
        } else {
            $user = $authResult['user'];

            // Validasi Khusus Pengawas: Pembatasan Jaringan WiFi Kantor (Jika Saklar ON)
            if ($user['role'] === 'pengawas') {
                $isWifiValid = validateWifiIp($pdo, $currentClientIp);
                if (!$isWifiValid) {
                    $error = 'Akses ditolak! Anda tidak terhubung ke jaringan WiFi Kantor K3 (' .
                        htmlspecialchars($wifiSettings['nama_jaringan'] ?? 'WiFi Kantor') . '). ' .
                        'IP koneksi Anda saat ini (' . htmlspecialchars($currentClientIp) . ') tidak cocok dengan IP terdaftar (' .
                        htmlspecialchars($wifiSettings['ip_address'] ?? '') . ').';
                }
            }

            // Jika tidak ada error validasi WiFi, set sesi dan redirect
            if ($error === null) {
                $_SESSION['user_id']      = $user['id_user'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['role']         = $user['role'];

                if ($user['role'] === 'admin') {
                    header('Location: /admin/index.php');
                } else {
                    header('Location: /pengawas/index.php');
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Petugas K3 - Sistem Inventaris & Peminjaman APD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-center items-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo & Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-300 text-slate-900 shadow-xl shadow-amber-500/20 mb-4">
                <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">SIPAD K3 OPERASIONAL</h1>
            <p class="text-sm text-slate-400 mt-1">Sistem Inventaris dan Otorisasi Peminjaman APD</p>
        </div>

        <!-- Card Login Container -->
        <div class="bg-slate-800/90 border border-slate-700/80 rounded-2xl shadow-2xl p-6 sm:p-8 backdrop-blur-sm">
            <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-700">
                <h2 class="text-lg font-semibold text-white">Portal Masuk Petugas</h2>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    Admin & Pengawas
                </span>
            </div>

            <?php if ($error): ?>
                <div class="mb-5 p-4 rounded-xl bg-red-500/15 border border-red-500/30 text-red-200 text-sm flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-5 p-4 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-sm flex items-center gap-3">
                    <svg class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <div><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-5">
                <div>
                    <label for="username" class="block text-sm font-medium text-slate-300 mb-1.5">Username Petugas</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <input type="text" id="username" name="username" required autocomplete="username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            placeholder="Contoh: admin atau pengawas_k3"
                            class="w-full pl-11 pr-4 py-2.5 bg-slate-900/80 border border-slate-600 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1.5">Kata Sandi</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            placeholder="••••••••"
                            class="w-full pl-11 pr-4 py-2.5 bg-slate-900/80 border border-slate-600 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                    </div>
                </div>

                <!-- WiFi Indicator Badge -->
                <div class="p-3 rounded-xl bg-slate-900/60 border border-slate-700/60 text-xs flex items-center justify-between text-slate-400">
                    <span class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full <?= ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1) ? 'bg-emerald-400 animate-pulse' : 'bg-slate-500' ?>"></span>
                        Filter WiFi: <strong class="text-slate-300"><?= ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1) ? 'AKTIF' : 'NONAKTIF' ?></strong>
                    </span>
                    <span class="text-slate-500">IP: <?= htmlspecialchars($currentClientIp) ?></span>
                </div>

                <button type="submit"
                    class="w-full py-3 px-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-semibold rounded-xl shadow-lg shadow-amber-500/20 active:scale-[0.99] transition-all">
                    Masuk ke Dashboard
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-slate-700/60 text-center">
                <a href="/index.php" class="text-sm text-slate-400 hover:text-amber-400 inline-flex items-center gap-1.5 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Kembali ke Halaman Peminjaman Publik
                </a>
            </div>
        </div>

        <p class="text-center text-xs text-slate-500 mt-6">
            Sistem Inventaris APD &copy; 2026 Tim K3 & Inventaris. Hak Akses Terlindungi.
        </p>
    </div>
</body>
</html>
