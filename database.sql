-- ============================================================
-- SISTEM INVENTARIS DAN PEMINJAMAN ALAT PELINDUNG DIRI (SIPAD)
-- DOKUMEN R&D VERSI 3.0 - SKEMA BASIS DATA MYSQL (6 TABEL INTI)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sipad_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sipad_db`;

-- 1. TABEL USERS (ADMIN & PENGAWAS RESMI)
CREATE TABLE IF NOT EXISTS `users` (
    `id_user` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `nama_lengkap` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'pengawas') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. TABEL WIFI_SETTINGS (DETEKSI IP WIFI & PIN GUDANG APD)
CREATE TABLE IF NOT EXISTS `wifi_settings` (
    `id_setting` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_jaringan` VARCHAR(100) NOT NULL DEFAULT 'WiFi Kantor K3',
    `ip_address` VARCHAR(45) NULL,
    `kode_pin_gudang` CHAR(4) NOT NULL DEFAULT '4821',
    `is_wifi_check_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_by_admin` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_wifi_admin` FOREIGN KEY (`updated_by_admin`) REFERENCES `users` (`id_user`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. TABEL APD_CATEGORIES (KATEGORI PERALATAN KESELAMATAN)
CREATE TABLE IF NOT EXISTS `apd_categories` (
    `id_category` INT AUTO_INCREMENT PRIMARY KEY,
    `kode_kategori` VARCHAR(20) NOT NULL UNIQUE,
    `nama_kategori` VARCHAR(100) NOT NULL,
    `keterangan` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. TABEL APD_ITEMS (MASTER INVENTARIS APD)
CREATE TABLE IF NOT EXISTS `apd_items` (
    `id_apd` INT AUTO_INCREMENT PRIMARY KEY,
    `id_category` INT NOT NULL,
    `kode_apd` VARCHAR(30) NOT NULL UNIQUE,
    `nama_apd` VARCHAR(120) NOT NULL,
    `ukuran_spesifikasi` VARCHAR(50) NULL,
    `stok_total` INT NOT NULL DEFAULT 0,
    `stok_tersedia` INT NOT NULL DEFAULT 0,
    `stok_rusak` INT NOT NULL DEFAULT 0,
    CONSTRAINT `fk_item_category` FOREIGN KEY (`id_category`) REFERENCES `apd_categories` (`id_category`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TABEL LOANS (TRANSAKSI PEMINJAMAN MANDIRI TANPA LOGIN)
CREATE TABLE IF NOT EXISTS `loans` (
    `id_loan` INT AUTO_INCREMENT PRIMARY KEY,
    `id_apd` INT NOT NULL,
    `id_pengawas_acc` INT NULL,
    `kode_pinjam` VARCHAR(25) NOT NULL UNIQUE,
    `nama_peminjam` VARCHAR(100) NOT NULL,
    `bagian_unit` VARCHAR(80) NOT NULL,
    `no_hp_peminjam` VARCHAR(20) NOT NULL,
    `jumlah_pinjam` INT NOT NULL DEFAULT 1,
    `waktu_pinjam` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `waktu_kembali` DATETIME NULL,
    `status_pinjam` ENUM('DIPINJAM', 'SUDAH_DIKEMBALIKAN') NOT NULL DEFAULT 'DIPINJAM',
    CONSTRAINT `fk_loan_apd` FOREIGN KEY (`id_apd`) REFERENCES `apd_items` (`id_apd`) ON DELETE RESTRICT,
    CONSTRAINT `fk_loan_pengawas` FOREIGN KEY (`id_pengawas_acc`) REFERENCES `users` (`id_user`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. TABEL CONDITION_LOGS (AUDIT KONDISI FISIK ACC PENGAWAS)
CREATE TABLE IF NOT EXISTS `condition_logs` (
    `id_log` INT AUTO_INCREMENT PRIMARY KEY,
    `id_loan` INT NOT NULL,
    `id_pengawas` INT NOT NULL,
    `kondisi_kembali` ENUM('BAIK', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG') NOT NULL,
    `keterangan` TEXT NULL,
    `waktu_acc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_log_loan` FOREIGN KEY (`id_loan`) REFERENCES `loans` (`id_loan`) ON DELETE CASCADE,
    CONSTRAINT `fk_log_pengawas` FOREIGN KEY (`id_pengawas`) REFERENCES `users` (`id_user`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEEDER DATA AWAL
-- ============================================================

-- Akun Superuser Admin Default (username: admin, password: admin123)
INSERT INTO `users` (`id_user`, `username`, `password_hash`, `nama_lengkap`, `role`, `is_active`)
VALUES (1, 'admin', '$2y$10$1vSEOc6sVq2ISo9zcNKUDOGaAilcGCnFFg2dQZxO44OPtwaBpvti6', 'Administrator K3 & Inventaris', 'admin', 1)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- Setting WiFi & PIN Gudang Awal
INSERT INTO `wifi_settings` (`id_setting`, `nama_jaringan`, `ip_address`, `kode_pin_gudang`, `is_wifi_check_enabled`, `updated_by_admin`)
VALUES (1, 'WiFi Kantor K3 Operasional', '127.0.0.1', '4821', 0, 1)
ON DUPLICATE KEY UPDATE `id_setting` = VALUES(`id_setting`);

-- Kategori APD Standar Permenakertrans
INSERT INTO `apd_categories` (`id_category`, `kode_kategori`, `nama_kategori`, `keterangan`) VALUES
(1, 'KAT-KEPALA', 'Alat Pelindung Kepala', 'Helm keselamatan (safety helmet), bump cap, penutup kepala'),
(2, 'KAT-MATA', 'Alat Pelindung Mata & Wajah', 'Kacamata safety (goggles), face shield pengelasan'),
(3, 'KAT-PERNAPASAN', 'Alat Pelindung Pernapasan', 'Masker respirator particulate, gas mask cartridge'),
(4, 'KAT-TUBUH', 'Alat Pelindung Tubuh', 'Rompi reflektor hi-vis, coverall wearpack tahan percikan'),
(5, 'KAT-TANGAN', 'Alat Pelindung Tangan', 'Sarung tangan safety kulit/karet/nitrile anti gores & zat kimia'),
(6, 'KAT-KAKI', 'Alat Pelindung Kaki', 'Sepatu safety boots steel toe cap standar EN ISO 20345'),
(7, 'KAT-KETINGGIAN', 'Alat Pelindung Ketinggian', 'Full body harness double lanyard shock absorber')
ON DUPLICATE KEY UPDATE `kode_kategori` = VALUES(`kode_kategori`);

-- Master APD Awal Siap Pakai
INSERT INTO `apd_items` (`id_apd`, `id_category`, `kode_apd`, `nama_apd`, `ukuran_spesifikasi`, `stok_total`, `stok_tersedia`, `stok_rusak`) VALUES
(1, 1, 'APD-HLM-01', 'Safety Helmet V-Gard Putih', 'All Size (Ratchet Suspension)', 20, 20, 0),
(2, 1, 'APD-HLM-02', 'Safety Helmet V-Gard Kuning', 'All Size (Ratchet Suspension)', 15, 15, 0),
(3, 2, 'APD-GGL-01', 'Safety Glasses Clear UV400', 'Standar ANSI Z87.1', 25, 25, 0),
(4, 3, 'APD-RSP-01', 'Respirator Half Face Double Cartridge', 'Medium (Cat. Gas/Vapor)', 10, 10, 0),
(5, 4, 'APD-VEST-01', 'Rompi Safety Jaring Hijau Stabilo', 'Ukuran L - XL (Scotchlite 3M)', 30, 30, 0),
(6, 5, 'APD-GLV-01', 'Sarung Tangan Impact & Cut Resistant', 'Size 9 / L', 25, 25, 0),
(7, 6, 'APD-BOOTS-01', 'Safety Shoes Steel Toe Mid-Cut Hitam', 'Ukuran 42 (PU Outsole)', 12, 12, 0),
(8, 7, 'APD-HRN-01', 'Full Body Harness Double Lanyard Big Hook', 'Universal (EN 361)', 8, 8, 0)
ON DUPLICATE KEY UPDATE `kode_apd` = VALUES(`kode_apd`);
