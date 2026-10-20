# SIPAD K3 — Sistem Inventaris dan Peminjaman Alat Pelindung Diri (APD)

> **Dokumen R&D & Naskah Akademik Versi 3.0**  
> **Penyusun / Pelaksana:** Ival Permana (NIM: 230210501051) / Tim Pelaksana Program Kerja K3 & Inventaris  
> **Tumpukan Teknologi:** PHP Native 8.x (PDO MySQL), Basis Data Relasional MySQL, Tailwind CSS, QR Code

---

## 📌 Gambaran Umum & Paradigma Asimetri Kontrol

Sistem Inventaris dan Peminjaman APD (**SIPAD K3**) dirancang berdasarkan riset operasional keselamatan kerja untuk mengatasi friksi login di lapangan sekaligus menjamin kepatuhan audit aset K3 sesuai **Permenakertrans Nomor PER.08/MEN/VII/2010** dan **ISO 45001:2018**.

Sistem menerapkan **Paradigma Asimetri Kontrol Tiga Peran**:
1. **Peminjam (Zero-Login Guest Self-Service)**:
   - Pekerja lapangan tidak perlu membuat akun atau login (mengeliminasi friksi lupa password dan antrean gudang).
   - Mengisi form mandiri (< 15 detik) berproteksi **4 Digit Kode PIN Ruang APD** yang tertempel secara fisik di pintu/meja gudang.
   - Otomatis menerbitkan **Bukti Peminjaman Digital (Kupon Sirkulasi)** dan mengurangi stok layak pakai secara atomik.
2. **Pengawas (Wajib Login & Otorisasi ACC Pengembalian)**:
   - Akun dibuat secara terpusat oleh Admin (tidak ada pendaftaran publik liar).
   - Dilindungi fitur **Validasi IP Publik WiFi Kantor ($_SERVER['REMOTE_ADDR'])** tingkat aplikasi tanpa memerlukan akses ke router kantor (Zero-Router Dependency).
   - Memegang wewenang eksklusif pemeriksaan fisik saat APD dikembalikan:
     - `[✓] ACC Baik`: Unit layak pakai dikembalikan ke `stok_tersedia`.
     - `[!] ACC Rusak / Hilang`: Unit dialihkan ke `stok_rusak` (tidak dipulihkan ke stok siap pakai) untuk diafkir atau diperbaiki.
3. **Admin (Superuser)**:
   - CRUD Master APD & Kategori Perlindungan.
   - Pemulihan stok rusak menjadi siap pakai setelah selesai diservis/diperbaiki.
   - Pendaftaran IP WiFi kantor 1-klik & saklar Toggle ON/OFF (Mode Bypass Darurat).
   - Generator & Cetak Poster QR Code Ruang APD (ukuran A4).
   - Laporan audit K3 komprehensif dengan filter tanggal/status serta ekspor Excel (CSV) dan cetak PDF.

---

## ⚙️ Persyaratan Sistem & Tumpukan Teknologi

- **Sistem Operasi**: Linux (Ubuntu 24.04 LTS / Debian / RHEL)
- **Web Server & Interpreter**: PHP 8.2 / 8.3 Native (Ekstensi `pdo_mysql`, `mysqli`, `mbstring` aktif)
- **Basis Data**: MySQL 8.0 / MariaDB 10.4+
- **Antarmuka**: Tailwind CSS (Responsif Mobile-First) & Google Fonts (*Plus Jakarta Sans*)

---

## 🚀 Panduan Menjalankan Sistem di Lingkungan Lokal

### 1. Konfigurasi Basis Data
Database `sipad_db` dan user `sipad` telah terkonfigurasi di file [`config/database.php`](file:///home/vals/project/Sipad/config/database.php):
- **Host**: `127.0.0.1` (Port 3306)
- **Database**: `sipad_db`
- **Username**: `sipad`
- **Password**: `Sipad_K3_2026!`

Jika perlu mengimpor ulang skema basis data:
```bash
mysql -u sipad -p'Sipad_K3_2026!' sipad_db < database.sql
```

### 2. Menjalankan Server Development PHP
Buka terminal di root project (`/home/vals/project/Sipad`):
```bash
php -S localhost:8000
```

Aplikasi dapat langsung diakses melalui peramban web:
- **Halaman Peminjam (Publik Tanpa Login)**: [http://localhost:8000/index.php](http://localhost:8000/index.php)
- **Cek Status Pinjam Mandiri**: [http://localhost:8000/status_pinjam.php](http://localhost:8000/status_pinjam.php)
- **Portal Login Petugas**: [http://localhost:8000/login.php](http://localhost:8000/login.php)
- **Dashboard Pengawas**: [http://localhost:8000/pengawas/index.php](http://localhost:8000/pengawas/index.php)
- **Dashboard Admin**: [http://localhost:8000/admin/index.php](http://localhost:8000/admin/index.php)

---

## 🔐 Kredensial Akun & Parameter Bawaan

| Role / Parameter | Kredensial / Nilai Default | Keterangan |
| :--- | :--- | :--- |
| **Superuser Admin** | Username: `admin`<br>Password: `admin123` | Hak akses penuh atas master data, akun pengawas, dan laporan |
| **Pengawas K3** | Dibuat melalui menu Admin (`admin/kelola_pengawas.php`) | Akun resmi petugas pemeriksa pengembalian barang |
| **PIN Ruang APD** | `4821` (Dapat diubah Admin) | 4 digit angka fisik yang wajib diinput peminjam |
| **Filter WiFi Kantor** | Default: **OFF (Bypass)** | Saat di-ON-kan oleh Admin, Pengawas wajib login via WiFi kantor |

---

## 🧪 Eksekusi Pengujian Fungsional Blackbox (TC-01 s/d TC-06)

Proyek ini dilengkapi dengan suite pengujian otomatis berbasis TDD yang mencakup seluruh skenario kritis pada Tabel 4.1 Dokumen R&D:

Jalankan test suite lengkap:
```bash
php tests/run_all_tests.php
```

Hasil verifikasi seluruh skenario pengujian:
```text
======================================================================
  SIPAD K3 OPERASIONAL - COMPREHENSIVE BLACKBOX TEST SUITE (TC-01 s/d TC-06)
======================================================================

[TEST] TC-01: Peminjaman Mandiri Tanpa Login (PIN Valid)... LULUS (100% Valid)
[TEST] TC-02: Penolakan Entri saat PIN Salah ('0000')... LULUS (100% Valid)
[TEST] TC-03: Pembuatan Akun Pengawas Baru oleh Admin... LULUS (100% Valid)
[TEST] TC-04: Validasi Login via IP WiFi Kantor & Saklar ON/OFF... LULUS (100% Valid)
[TEST] TC-05: ACC Pengembalian APD Kondisi Baik... LULUS (100% Valid)
[TEST] TC-06: ACC Pengembalian APD Kondisi Rusak... LULUS (100% Valid)

HASIL AKHIR: 100% SKENARIO KRITIS LULUS DENGAN SUKSES.
```

---

## 📁 Struktur Direktori Aplikasi

```text
/home/vals/project/Sipad/
├── admin/
│   ├── index.php             # Dashboard analitik eksekutif K3
│   ├── kelola_apd.php        # CRUD master APD, pemulihan stok rusak, poster QR
│   ├── kelola_pengawas.php   # Manajemen akun pengawas & kontrol keamanan WiFi 1-klik
│   └── laporan.php           # Modul audit sirkulasi, cetak PDF & ekspor Excel/CSV
├── config/
│   ├── database.php          # Inisialisasi koneksi PDO MySQL & timezone Asia/Jakarta
│   └── auth_check.php        # Middleware auth, sesi & validasi IP WiFi kantor
├── pengawas/
│   ├── index.php             # Dashboard antrean pengembalian & aksi ACC pengawas
│   └── acc_proses.php        # Prosesor rekonsiliasi stok otomatis (Baik vs Rusak)
├── tests/
│   ├── run_all_tests.php     # Test runner komprehensif TC-01 s/d TC-06
│   ├── test_admin_management.php
│   ├── test_auth_and_wifi.php
│   ├── test_borrow_flow.php
│   ├── test_db_connection.php
│   ├── test_reporting.php
│   └── test_supervisor_acc.php
├── database.sql              # Skema 6 tabel inti & data seeder awal K3
├── index.php                 # Form peminjaman mandiri & katalog publik tanpa login
├── login.php                 # Gerbang masuk login Admin & Pengawas
├── logout.php                # Pemutus sesi login aman
├── pinjam_proses.php         # Prosesor transaksi peminjaman mandiri ber-PIN gudang
├── status_pinjam.php         # Pelacakan status & penampil bukti kupon sirkulasi
├── docs/                     # Rencana implementasi & dokumentasi arsitektur
└── RND_Sistem_Inventaris_Peminjaman_APD.docx # Naskah akademik spesifikasi R&D
```

---
*SIPAD K3 Operasional &bull; Dikembangkan Berdasarkan Standar Akademik & Visual Presisi &copy; 2026.*
