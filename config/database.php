<?php
/**
 * Konfigurasi Koneksi Basis Data PDO MySQL
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 */
declare(strict_types=1);

// Set zona waktu resmi sesuai operasional K3 Indonesia
date_default_timezone_set('Asia/Jakarta');

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'sipad_db';
const DB_USER = 'sipad';
const DB_PASS = 'Sipad_K3_2026!';
const DB_CHARSET = 'utf8mb4';

/**
 * Mengembalikan instance PDO tunggal / terkonfigurasi
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci"
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}
