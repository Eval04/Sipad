<?php
/**
 * Gerbang Autentikasi Admin & Pengawas
 * Identitas Korporat PT Pelabuhan Indonesia (Pelindo) - Clean Light Enterprise Theme
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
<html lang="id" class="bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login Petugas K3 - PT Pelabuhan Indonesia (Pelindo)</title>
    <script src="/assets/js/tailwind.min.js"></script>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/assets/css/sipad-design-system.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex flex-col justify-between p-4 sm:p-6 pb-20 sm:pb-8 selection:bg-sky-500 selection:text-white">

    <!-- Top Bar Navigation for Mobile -->
    <header class="w-full max-w-md mx-auto pt-2 flex items-center justify-between">
        <a href="/index.php" class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 hover:text-[#005b96] transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Portal Peminjaman</span>
        </a>
        <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-slate-200/80 text-[11px] font-mono text-slate-700 font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-[#0080c0]"></span>
            <span>RESTRICTED</span>
        </div>
    </header>

    <!-- Center Card Container -->
    <main class="w-full max-w-md mx-auto my-auto py-6">
        
        <!-- Header Branding -->
        <div class="text-center mb-6 space-y-2">
            <div class="inline-flex items-center justify-center p-3 rounded-2xl bg-white shadow-sm border border-slate-200 mx-auto">
                <img src="/assets/img/pelindo-logo.svg" alt="PT Pelabuhan Indonesia (Pelindo)" class="h-8 sm:h-9 w-auto">
            </div>
            <h1 class="text-lg sm:text-xl font-black tracking-tight text-slate-900 uppercase">PORTAL PETUGAS K3</h1>
            <p class="text-xs text-slate-500">PT Pelabuhan Indonesia (Persero) &bull; Sistem Inventaris & Peminjaman APD</p>
        </div>

        <!-- Corporate Card -->
        <div class="bg-white rounded-2xl p-6 sm:p-7 border border-slate-200 shadow-md relative overflow-hidden">
            <!-- Top Pelindo Blue Line Indicator -->
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-[#003e74] via-[#005b96] to-[#0080c0]"></div>

            <div class="flex items-center justify-between pb-3.5 mb-5 border-b border-slate-100">
                <span class="text-xs font-bold text-slate-700 tracking-wider uppercase">Autentikasi Petugas</span>
                <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-100 text-[#005b96] border border-slate-200 font-bold">
                    SIPAD PELINDO
                </span>
            </div>

            <!-- Error Notification -->
            <?php if ($error): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs flex items-start gap-2.5">
                    <svg class="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="leading-relaxed font-medium"><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- Success Notification -->
            <?php if ($success): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <div class="leading-relaxed font-medium"><?= htmlspecialchars($success) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-4">
                <!-- Username Input -->
                <div>
                    <label for="username" class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Username Petugas
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <input type="text" id="username" name="username" required autocomplete="username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            placeholder="Contoh: admin atau pengawas_k3"
                            class="sipad-input w-full pl-10 pr-4 py-2.5 rounded-xl text-xs sm:text-sm text-slate-900 placeholder-slate-400">
                    </div>
                </div>

                <!-- Password Input -->
                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-700 mb-1.5">
                        Kata Sandi
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            placeholder="••••••••"
                            class="sipad-input w-full pl-10 pr-11 py-2.5 rounded-xl text-xs sm:text-sm text-slate-900 placeholder-slate-400">
                        <!-- Toggle Password Visibility -->
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors">
                            <svg id="eye-icon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- WiFi & IP Telemetry Pill -->
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between text-[11px]">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full <?= ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1) ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400' ?>"></span>
                        <span class="text-slate-600">Filter Jaringan:</span>
                        <strong class="text-slate-900"><?= ((int)($wifiSettings['is_wifi_check_enabled'] ?? 0) === 1) ? 'Wajib WiFi Kantor' : 'Bypass Bebas IP' ?></strong>
                    </div>
                    <div class="font-mono text-slate-500 truncate max-w-[120px]">
                        <?= htmlspecialchars($currentClientIp) ?>
                    </div>
                </div>

                <!-- Pelindo Blue Submit Button -->
                <button type="submit" class="btn-pelindo-primary w-full touch-target rounded-xl text-xs sm:text-sm flex items-center justify-center gap-2 mt-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                    </svg>
                    <span>Masuk ke Dashboard Otorisasi</span>
                </button>
            </form>

            <div class="mt-5 pt-4 border-t border-slate-100 text-center">
                <span class="text-[11px] text-slate-500 block">
                    Peminjam APD lapangan tidak memerlukan login akun.
                </span>
            </div>
        </div>
    </main>

    <!-- Footer Pelindo -->
    <footer class="w-full max-w-md mx-auto text-center text-[11px] text-slate-500 pb-2">
        PT Pelabuhan Indonesia (Persero) &bull; Hak Akses Terlindungi
    </footer>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav flex sm:hidden justify-around items-center">
        <a href="/index.php" class="mobile-nav-item">
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
        <a href="/login.php" class="mobile-nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <span>Petugas</span>
        </a>
    </nav>

    <script>
        function togglePasswordVisibility() {
            const pwd = document.getElementById('password');
            const eye = document.getElementById('eye-icon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                eye.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>';
            } else {
                pwd.type = 'password';
                eye.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }
    </script>
</body>
</html>
