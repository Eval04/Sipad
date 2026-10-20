# SIPAD K3 — Sistem Inventaris dan Peminjaman Alat Pelindung Diri (APD)

> **Dokumen R&D & Naskah Akademik Versi 3.0**  
> **Tema Visual Resmi:** PT Pelabuhan Indonesia (Pelindo) Corporate Light Maritime Palette  
> **Tumpukan Teknologi:** PHP Native 8.x (PDO MySQL), Basis Data Relasional MySQL, Tailwind CSS, Zero-Dependency `.env` Architecture

---

## 📌 Gambaran Umum & Paradigma Asimetri Kontrol

Sistem Inventaris dan Peminjaman APD (**SIPAD K3**) dirancang berdasarkan riset operasional keselamatan kerja maritim dan industri untuk mengatasi friksi login di lapangan sekaligus menjamin kepatuhan audit aset K3 sesuai **Permenakertrans Nomor PER.08/MEN/VII/2010** dan **ISO 45001:2018**.

Sistem menerapkan **Paradigma Asimetri Kontrol Tiga Peran**:
1. **Peminjam (Zero-Login Guest Self-Service Multi-Item)**:
   - Pekerja lapangan tidak perlu membuat akun atau login (mengeliminasi friksi lupa password dan antrean gudang).
   - Memilih **banyak jenis APD sekaligus** dalam satu transaksi dengan pengaturan jumlah unit dinamis (`- / +`).
   - Mengisi formulir mandiri (< 15 detik) berproteksi **4 Digit Kode PIN Ruang APD** yang tertempel secara fisik di pintu/meja gudang.
   - Otomatis menerbitkan **Bukti Peminjaman Digital (Kupon Sirkulasi)** dan mengurangi stok layak pakai secara atomik (`FOR UPDATE`).
2. **Pengawas (Wajib Login & Otorisasi ACC Pengembalian)**:
   - Akun dibuat secara terpusat oleh Admin (tidak ada pendaftaran publik liar).
   - Dilindungi fitur **Validasi IP Publik WiFi Kantor ($_SERVER['REMOTE_ADDR'])** tingkat aplikasi tanpa memerlukan akses ke router kantor (Zero-Router Dependency).
   - Memegang wewenang eksklusif pemeriksaan fisik saat APD dikembalikan:
     - `[✓] ACC Baik`: Unit layak pakai dikembalikan ke `stok_tersedia`.
     - `[!] ACC Rusak / Hilang`: Unit dialihkan ke `stok_rusak` (tidak dipulihkan ke stok siap pakai) untuk diafkir atau diperbaiki.
3. **Super Admin**:
   - CRUD Master APD & Kategori Perlindungan.
   - Pemulihan stok rusak menjadi siap pakai setelah selesai diservis/diperbaiki.
   - Pendaftaran IP WiFi kantor 1-klik & saklar Toggle ON/OFF (Mode Bypass Darurat).
   - **Hapus Akun Pengawas**: Super Admin dapat menghapus akun pengawas secara permanen dengan penanganan integritas relasi foreign key otomatis.
   - **Wipe & Pembersihan Basis Data (Factory Reset Data)**: Super Admin dapat mengosongkan seluruh data operasional dan master APD agar siap diisi data manual baru dari nol.
   - Generator & Cetak Poster QR Code Ruang APD (ukuran A4).
   - Laporan audit K3 komprehensif dengan filter tanggal/status serta ekspor Excel (CSV) dan cetak PDF.

---

## 🌐 Panduan Deployment di Hosting (cPanel / Niagahoster / Hostinger / VPS)

Aplikasi SIPAD K3 telah dilengkapi arsitektur **Zero-Dependency Hosting Ready**:

### Langkah 1: Unggah Berkas ke Hosting
Unggah seluruh berkas proyek ke direktori `public_html` (atau subdomain folder Anda) di File Manager cPanel hosting.

### Langkah 2: Buat Basis Data MySQL di Hosting
1. Masuk ke cPanel hosting Anda, buka menu **MySQL® Databases**.
2. Buat database baru (contoh: `u123456_sipad_db`).
3. Buat user database baru dan berikan hak akses penuh (*ALL PRIVILEGES*) ke database tersebut.

### Langkah 3: Konfigurasi File `.env`
Salin file `.env.example` menjadi `.env` di folder root hosting Anda, lalu sesuaikan kredensial MySQL hosting:
```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456_sipad_db
DB_USER=u123456_sipad_user
DB_PASS=KataSandiMySQLHostingAnda!
DB_CHARSET=utf8mb4
```

> [!TIP]
> **Zero-Config Auto-Installer:** Saat pertama kali diakses di hosting, sistem akan mendeteksi apakah tabel sudah terpasang. Jika belum, sistem otomatis menginisialisasi skema tabel dan akun admin awal langsung dari `database.sql` tanpa harus repot membuka phpMyAdmin!

---

## 🚀 Panduan Menjalankan Sistem di Lingkungan Lokal (Linux / XAMPP)

### Menjalankan Server Development PHP
Buka terminal di root project:
```bash
php -S localhost:8000
```

Aplikasi dapat langsung diakses melalui peramban web:
- **Halaman Peminjam (Publik Mandiri Tanpa Login)**: [http://localhost:8000/index.php](http://localhost:8000/index.php)
- **Cek Status Pinjam Mandiri**: [http://localhost:8000/status_pinjam.php](http://localhost:8000/status_pinjam.php)
- **Portal Login Petugas**: [http://localhost:8000/login.php](http://localhost:8000/login.php)
- **Dashboard Pengawas**: [http://localhost:8000/pengawas/index.php](http://localhost:8000/pengawas/index.php)
- **Dashboard Admin**: [http://localhost:8000/admin/index.php](http://localhost:8000/admin/index.php)
- **Manajemen Pengawas & Wipe Database**: [http://localhost:8000/admin/kelola_pengawas.php#zona-wipe](http://localhost:8000/admin/kelola_pengawas.php#zona-wipe)

---

## 🔐 Kredensial Akun & Parameter Bawaan

| Role / Parameter | Kredensial / Nilai Default | Keterangan |
| :--- | :--- | :--- |
| **Superuser Admin** | Username: `admin`<br>Password: `admin123` | Hak akses penuh atas master data, akun pengawas, laporan, dan wipe database |
| **Pengawas K3** | Dibuat melalui menu Admin (`admin/kelola_pengawas.php`) | Akun resmi petugas pemeriksa pengembalian barang |
| **PIN Ruang APD** | `4821` (Dapat diubah Admin) | 4 digit angka fisik yang wajib diinput peminjam di formulir publik |
| **Filter WiFi Kantor** | Default: **OFF (Bypass)** | Saat di-ON-kan oleh Admin, Pengawas wajib login via WiFi kantor |

---

## 🧹 Fitur Super Admin: Hapus User & Wipe Basis Data

Pada menu [admin/kelola_pengawas.php](file:///home/vals/project/Sipad/admin/kelola_pengawas.php), Super Admin memiliki otoritas penuh:
1. **Hapus Akun Pengawas (Delete User)**:
   - Menghapus akun pengawas secara permanen tanpa merusak integritas audit histori K3.
   - Dilindungi agar Super Admin tidak dapat menghapus akunnya sendiri yang sedang aktif.
2. **Pembersihan Total Basis Data (Factory Data Wipe)**:
   - **Mode 1 (Wipe Total)**: Mengosongkan seluruh sirkulasi peminjaman (`loans`), riwayat kondisi (`condition_logs`), katalog APD (`apd_items`), kategori (`apd_categories`), dan seluruh akun pengawas tambahan. Akun Super Admin tetap aktif sehingga pengguna dapat langsung menginput data manual baru dari nol.
   - **Mode 2 (Wipe Transaksi Saja)**: Mengosongkan hanya riwayat sirkulasi peminjaman dan mengembalikan stok APD ke kondisi awal, tanpa menghapus master APD atau akun pengawas.
   - Dilindungi dengan konfirmasi pengetikan kata **WIPE** sebelum eksekusi dijalankan.

---

## 🧪 Eksekusi Pengujian Fungsional Blackbox (TC-01 s/d TC-06)

Proyek ini dilengkapi dengan suite pengujian otomatis berbasis TDD yang mencakup seluruh skenario kritis:

```bash
php tests/run_all_tests.php
```

Hasil verifikasi:
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

======================================================================
HASIL AKHIR: 100% SKENARIO KRITIS LULUS DENGAN SUKSES.
```

---

## 📁 Struktur Direktori Aplikasi

```text
/home/vals/project/Sipad/
├── admin/
│   ├── index.php             # Dashboard analitik eksekutif K3
│   ├── kelola_apd.php        # CRUD master APD, hapus APD satuan/semua, pemulihan stok, poster QR A4
│   ├── kelola_pengawas.php   # Manajemen akun, hapus pengawas, kontrol WiFi & Wipe Pengawas
│   └── laporan.php           # Modul audit sirkulasi, cetak PDF & ekspor Excel/CSV
├── config/
│   ├── database.php          # Koneksi PDO MySQL dinamis (.env support & auto-installer)
│   └── auth_check.php        # Middleware auth, sesi & validasi IP WiFi kantor
├── pengawas/
│   ├── index.php             # Dashboard antrean pengembalian & aksi ACC pengawas
│   └── acc_proses.php        # Prosesor rekonsiliasi stok otomatis (Baik vs Rusak)
├── assets/
│   └── css/
│       └── sipad-design-system.css # Desain token korporat resmi Pelindo
├── tests/
│   ├── run_all_tests.php     # Test runner komprehensif TC-01 s/d TC-06
│   └── test_delete_wipe.php  # Verifikasi penghapusan user & pembersihan database
├── .env.example              # Template konfigurasi hosting cPanel / VPS
├── .env                      # Konfigurasi aktif lokal
├── database.sql              # Skema 6 tabel inti relasional & seeder
├── index.php                 # Form peminjaman mandiri multi-item tanpa login
├── login.php                 # Gerbang masuk login Admin & Pengawas
├── logout.php                # Pemutus sesi login aman
├── pinjam_proses.php         # Prosesor transaksi multi-item peminjaman mandiri
└── status_pinjam.php         # Pelacakan status & penampil bukti kupon sirkulasi
```

---
*SIPAD K3 Operasional &bull; Dikembangkan Berdasarkan Standar Akademik & Visual Presisi Pelindo &copy; 2026.*
