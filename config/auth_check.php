<?php
/**
 * Middleware Autentikasi, Manajemen Sesi, dan Validasi IP WiFi Tanpa Akses Router
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
}

/**
 * Mendapatkan alamat IP publik klien dari variabel server PHP
 */
function getCurrentClientIp(): string {
    // Prioritaskan REMOTE_ADDR sesuai spesifikasi R&D
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Validasi apakah IP klien cocok dengan IP WiFi kantor saat saklar ON
 *
 * @param PDO $pdo
 * @param string|null $clientIp
 * @return bool
 */
function validateWifiIp(PDO $pdo, ?string $clientIp = null): bool {
    $ipToCheck = $clientIp ?? getCurrentClientIp();

    $stmt = $pdo->query("SELECT ip_address, is_wifi_check_enabled FROM wifi_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        return true;
    }

    // Jika saklar OFF (0), verifikasi IP dilewati (Zero-Friction / Emergency Bypass)
    if ((int)$settings['is_wifi_check_enabled'] === 0) {
        return true;
    }

    $registeredIp = trim((string)$settings['ip_address']);
    $currentIp    = trim((string)$ipToCheck);

    // Bandingkan IP koneksi dengan IP kantor yang terdaftar di basis data
    return ($registeredIp === $currentIp);
}

/**
 * Verifikasi kredensial login pengguna
 *
 * @param PDO $pdo
 * @param string $username
 * @param string $password
 * @return array{success: bool, user: ?array, message: string}
 */
function authenticateUser(PDO $pdo, string $username, string $password): array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'user' => null, 'message' => 'Username atau kata sandi tidak ditemukan.'];
    }

    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'user' => null, 'message' => 'Akun dinonaktifkan oleh Administrator.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'user' => null, 'message' => 'Username atau kata sandi tidak cocok.'];
    }

    return ['success' => true, 'user' => $user, 'message' => 'Autentikasi berhasil.'];
}

/**
 * Gatekeeper hak akses berdasarkan role pengguna
 *
 * @param array<string> $allowedRoles
 */
function checkAuth(array $allowedRoles): void {
    startAppSession();

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        $_SESSION['flash_error'] = 'Silakan login terlebih dahulu untuk mengakses menu ini.';
        header('Location: /login.php');
        exit;
    }

    $currentRole = (string)$_SESSION['role'];
    if (!in_array($currentRole, $allowedRoles, true)) {
        $_SESSION['flash_error'] = 'Anda tidak memiliki wewenang untuk membuka halaman tersebut.';
        if ($currentRole === 'admin') {
            header('Location: /admin/index.php');
        } elseif ($currentRole === 'pengawas') {
            header('Location: /pengawas/index.php');
        } else {
            header('Location: /index.php');
        }
        exit;
    }
}

/**
 * Mengambil data user yang sedang login
 */
function getLoggedInUser(): ?array {
    startAppSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id_user'      => $_SESSION['user_id'],
        'username'     => $_SESSION['username'] ?? '',
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? '',
        'role'         => $_SESSION['role'] ?? ''
    ];
}

/**
 * Memutus sesi login secara aman
 */
function logoutUser(): void {
    startAppSession();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}
