<?php
/**
 * Logout Handler
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/auth_check.php';

logoutUser();
startAppSession();
$_SESSION['flash_success'] = 'Anda telah berhasil keluar dari sistem.';
header('Location: /login.php');
exit;
