# Sistem Inventaris dan Peminjaman APD (Sipad) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun Sistem Inventaris dan Peminjaman APD berbasis Web Responsif dengan arsitektur Asimetri Kontrol Tiga Peran (Peminjam Zero-Login, Pengawas Login ACC, Admin Superuser) secara presisi sesuai spesifikasi dokumen R&D.

**Architecture:** Arsitektur web tiga lapis (3-Layer Architecture) menggunakan PHP Native 8.x PDO dengan transaksi atomik dan prepared statements, MySQL relasional 6 tabel ternormalisasi, serta Tailwind CSS untuk antarmuka responsif mobile-first. Kontrol keamanan memisahkan kemudahan peminjaman mandiri ber-PIN gudang dengan otorisasi pengembalian satu pintu oleh Pengawas yang divalidasi jaringan WiFi kantor level aplikasi ($_SERVER['REMOTE_ADDR']).

**Tech Stack:** PHP 8.3 Native (PDO MySQL, strict types), MySQL 8.0, Tailwind CSS (Modern CDN / Vanilla CSS enhancements), HTML5, QR Code Generator API / SVG.

**Spec:** [RND_Sistem_Inventaris_Peminjaman_APD.docx](file:///home/vals/project/Sipad/RND_Sistem_Inventaris_Peminjaman_APD.docx) (Edisi Revisi Penuh Standar Akademik & Visual Presisi).

## Global Constraints

- PHP 8.x Native menggunakan PDO dengan error mode `ERRMODE_EXCEPTION` dan Prepared Statements untuk mencegah SQL Injection.
- Pemisahan 3 peran secara ketat:
  1. `Peminjam`: Akses publik tanpa login, validasi wajib PIN Ruang APD 4 digit (default: '4821').
  2. `Pengawas`: Wajib login (akun dibuat eksklusif oleh Admin), validasi IP WiFi jika saklar aktif, otorisasi tunggal ACC Pengembalian.
  3. `Admin`: Superuser untuk CRUD Master APD, akun Pengawas, saklar & set IP WiFi 1-klik, pemulihan stok rusak, dan pelaporan audit K3.
- Rekonsiliasi stok otomatis:
  - Peminjaman: kurangi `stok_tersedia`.
  - ACC Baik: tambah kembali `stok_tersedia`.
  - ACC Rusak: jangan tambah `stok_tersedia`, alihkan ke `stok_rusak`.
  - Perbaikan oleh Admin: pindahkan unit dari `stok_rusak` kembali ke `stok_tersedia`.
- Tampilan responsif menggunakan Tailwind CSS dengan estetika profesional K3/Industrial (palet Navy Blue `#0f172a`, Emerald Green `#059669`, Safety Amber `#d97706`, Crimson Red `#dc2626`).

## Review Focus

1. **Race Condition pada Peminjaman Paralel:** Jika dua peminjam mengirim form pada detik yang sama saat stok tersisa 1, transaksi atomik PDO dan validasi `stok_tersedia >= jumlah_pinjam` harus menggagalkan peminjaman kedua secara konsisten.
2. **Bypass PIN Ruang APD:** Form submit tanpa PIN atau dengan PIN salah harus di-reject di layer backend `pinjam_proses.php` tanpa memotong stok.
3. **Bypass Login Pengawas via Direct URL:** Mengakses `pengawas/*` atau `admin/*` secara langsung tanpa sesi login atau dengan role yang salah harus di-redirect ke `login.php` dengan pesan error.
4. **Perubahan IP Dinamis (ISP WiFi Kantor):** Ketika saklar Toggle WiFi aktif (ON) dan IP berubah/berbeda, login Pengawas ditolak dengan pesan jelas; ketika saklar diubah ke OFF oleh Admin, Pengawas dapat login kembali tanpa halangan.
5. **Integritas Rekonsiliasi Stok:** Tidak boleh ada transaksi pengembalian yang menambah `stok_tersedia` jika kondisi fisik ditandai RUSAK atau HILANG.

---

### Task 1: Skema Database & Database Connection (`config/database.php` & `database.sql`)

**Files:**
- Create: `database.sql`
- Create: `config/database.php`
- Test: `tests/test_db_connection.php`

**Interfaces:**
- Produces: `getDbConnection(): PDO` yang mengembalikan instance PDO terkonfigurasi ke basis data `sipad_db`.

- [ ] **Step 1: Tulis automated test untuk koneksi database**
  Membuat file `tests/test_db_connection.php` untuk memvalidasi koneksi PDO, mode error exception, dan keberadaan 6 tabel inti (`users`, `wifi_settings`, `apd_categories`, `apd_items`, `loans`, `condition_logs`).
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_db_connection.php` dan pastikan gagal karena tabel dan file koneksi belum dibuat.
- [ ] **Step 3: Implementasi `database.sql`**
  Tulis DDL lengkap untuk 6 tabel sesuai kamus data R&D, data awal kategori APD, master APD awal, setting wifi awal, dan akun superuser admin default (`admin` / hash bcrypt).
- [ ] **Step 4: Eksekusi migrasi ke MySQL `sipad_db`**
  Eksekusi `database.sql` ke database `sipad_db` dengan user `sipad`.
- [ ] **Step 5: Implementasi `config/database.php`**
  Buat fungsi koneksi `getDbConnection()` dengan PDO, setting charset utf8mb4, timezone `Asia/Jakarta`, dan konfigurasi credentials (`sipad` / `Sipad_K3_2026!`).
- [ ] **Step 6: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_db_connection.php` dan pastikan seluruh assertion tabel dan seeder lulus 100%.
- [ ] **Step 7: Commit Task 1**
  `git add config/ database.sql tests/ && git commit -m "feat: setup database schema and PDO connection"`

---

### Task 2: Modul Autentikasi, Middleware Sesi & Validasi IP WiFi (`config/auth_check.php`, `login.php`, `logout.php`)

**Files:**
- Create: `config/auth_check.php`
- Create: `login.php`
- Create: `logout.php`
- Test: `tests/test_auth_and_wifi.php`

**Interfaces:**
- Consumes: `getDbConnection()` dari `config/database.php`
- Produces:
  - `checkAuth(array $allowedRoles): void`
  - `validateWifiIp(PDO $pdo, string $clientIp): bool`
  - `getCurrentClientIp(): string`

- [ ] **Step 1: Tulis test verifikasi login kredensial, role guard, dan logika saklar IP WiFi**
  Buat `tests/test_auth_and_wifi.php` yang menguji:
  - Verifikasi password bcrypt untuk user valid.
  - Penolakan login jika password salah atau akun non-aktif.
  - Pengujian logika WiFi check: jika `is_wifi_check_enabled == 1` dan IP cocok -> izinkan; jika IP tidak cocok -> tolak; jika `is_wifi_check_enabled == 0` -> selalu izinkan (TC-04).
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_auth_and_wifi.php`.
- [ ] **Step 3: Implementasi `config/auth_check.php`**
  Buat session helper, fungsi `getCurrentClientIp()`, `validateWifiIp()`, `checkAuth($allowedRoles)`, dan `isLoggedIn()`.
- [ ] **Step 4: Implementasi UI & Logika `login.php`**
  Buat halaman login responsif Tailwind CSS dengan tabs/indikator role (Admin / Pengawas), pengecekan kredensial, pengecekan WiFi khusus role pengawas, dan feedback pesan error yang human-readable.
- [ ] **Step 5: Implementasi `logout.php`**
  Hancurkan session dengan aman dan redirect ke `login.php` dengan flash message sukses logout.
- [ ] **Step 6: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_auth_and_wifi.php` dan pastikan lulus 100%.
- [ ] **Step 7: Commit Task 2**
  `git add config/auth_check.php login.php logout.php tests/ && git commit -m "feat: implement auth middleware, login and zero-router wifi validation"`

---

### Task 3: Modul Sirkulasi Peminjaman Mandiri Tanpa Login (`index.php`, `pinjam_proses.php`, `status_pinjam.php`)

**Files:**
- Create: `index.php`
- Create: `pinjam_proses.php`
- Create: `status_pinjam.php`
- Test: `tests/test_borrow_flow.php`

**Interfaces:**
- Consumes: `getDbConnection()` dari `config/database.php`
- Produces:
  - Endpoint peminjaman cepat yang menghasilkan baris di tabel `loans` (status `DIPINJAM`) dan mengurangi `stok_tersedia` secara atomik.
  - Halaman publik pelacakan status peminjaman.

- [ ] **Step 1: Tulis test validasi peminjaman tanpa login (TC-01, TC-02 & Race Condition)**
  Buat `tests/test_borrow_flow.php` yang menguji:
  - Penolakan input jika PIN gudang salah (TC-02).
  - Peminjaman berhasil dengan PIN valid (TC-01), menghasilkan kode pinjam unik `PINJ-XXXX`, memotong `stok_tersedia`.
  - Penolakan jika jumlah pinjam melebihi `stok_tersedia`.
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_borrow_flow.php`.
- [ ] **Step 3: Implementasi `pinjam_proses.php`**
  Implementasi sanitasi input, verifikasi PIN dari tabel `wifi_settings`, atomic transaction PDO (`beginTransaction`, `FOR UPDATE` lock check, `INSERT INTO loans`, `UPDATE apd_items SET stok_tersedia = stok_tersedia - ?`, `commit`).
- [ ] **Step 4: Implementasi UI `index.php` (Mobile-First Tailwind CSS)**
  Tampilkan katalog stok APD real-time dengan status badge (Tersedia, Stok Menipis, Habis), formulir peminjaman cepat satu layar (Nama, Bagian/Unit, No. HP WhatsApp, Pilihan APD, Jumlah, dan 4 digit PIN Ruang APD), serta modal info PIN & panduan.
- [ ] **Step 5: Implementasi `status_pinjam.php`**
  Halaman publik untuk memantau status peminjaman aktif, fitur pencarian instan berdasarkan Nama atau Kode Pinjam, status badge (Sedang Dipinjam / Sudah Dikembalikan), dan instruksi serah-terima ke Pengawas.
- [ ] **Step 6: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_borrow_flow.php` dan pastikan lulus.
- [ ] **Step 7: Commit Task 3**
  `git add index.php pinjam_proses.php status_pinjam.php tests/ && git commit -m "feat: implement zero-login self-service loan workflow and public status tracker"`

---

### Task 4: Modul Verifikasi Pengembalian Satu Pintu oleh Pengawas (`pengawas/index.php`, `pengawas/acc_proses.php`)

**Files:**
- Create: `pengawas/index.php`
- Create: `pengawas/acc_proses.php`
- Test: `tests/test_supervisor_acc.php`

**Interfaces:**
- Consumes: `getDbConnection()`, `checkAuth(['pengawas'])`
- Produces: Logika rekonsiliasi pengembalian fisik:
  - Status `loans` diubah ke `SUDAH_DIKEMBALIKAN`.
  - Pencatatan log di tabel `condition_logs`.
  - Percabangan stok: jika BAIK -> `stok_tersedia + N`; jika RUSAK/HILANG -> `stok_rusak + N` (tanpa menambah `stok_tersedia`).

- [ ] **Step 1: Tulis test pengujian otorisasi ACC Pengawas (TC-05 & TC-06)**
  Buat `tests/test_supervisor_acc.php` yang menguji:
  - Eksekusi ACC Kondisi Baik memulihkan `stok_tersedia` (TC-05).
  - Eksekusi ACC Kondisi Rusak mengalihkan ke `stok_rusak` tanpa menambah `stok_tersedia` (TC-06).
  - Penolakan akses untuk sesi tanpa hak pengawas.
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_supervisor_acc.php`.
- [ ] **Step 3: Implementasi `pengawas/acc_proses.php`**
  Prosesor pengembalian dengan validasi CSRF/session, pengecekan role pengawas, transaksi atomik PDO: update loan, insert condition_logs, dan update stok bercabang (BAIK vs RUSAK/HILANG). Ditambah aksi pembatalan entri fiktif (Devil's Advocate Mitigation).
- [ ] **Step 4: Implementasi UI `pengawas/index.php`**
  Dashboard Pengawas dengan header indikator status WiFi (Terhubung Jaringan Sah / Mode Bypass), counter peminjaman aktif, tabel/kartu peminjaman menunggu pengembalian, tombol aksi kontras tinggi: Hijau `[✓] ACC Kembali - Baik` dan Merah `[!] ACC - Rusak/Hilang` (dengan modal input catatan kerusakan).
- [ ] **Step 5: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_supervisor_acc.php` dan pastikan lulus 100%.
- [ ] **Step 6: Commit Task 4**
  `git add pengawas/ tests/ && git commit -m "feat: implement supervisor return verification and stock reconciliation"`

---

### Task 5: Modul Manajemen Admin & Master Data APD (`admin/index.php`, `admin/kelola_apd.php`, `admin/kelola_pengawas.php`)

**Files:**
- Create: `admin/index.php`
- Create: `admin/kelola_apd.php`
- Create: `admin/kelola_pengawas.php`
- Test: `tests/test_admin_management.php`

**Interfaces:**
- Consumes: `getDbConnection()`, `checkAuth(['admin'])`
- Produces:
  - CRUD master kategori dan barang APD.
  - Aksi pemulihan stok rusak menjadi stok siap pakai.
  - Pembuatan dan manajemen akun Pengawas resmi (TC-03).
  - Pendaftaran IP WiFi 1-klik dan saklar Toggle ON/OFF WiFi validation.
  - Poster cetak QR Code Ruang APD.

- [ ] **Step 1: Tulis test operasi manajemen Admin (TC-03, Master APD, WiFi Settings)**
  Buat `tests/test_admin_management.php` untuk memvalidasi:
  - Pembuatan akun Pengawas baru oleh Admin (TC-03).
  - Perubahan saklar Toggle WiFi (ON/OFF) dan update IP WiFi 1-klik.
  - Aksi perbaikan barang rusak (memindahkan dari `stok_rusak` ke `stok_tersedia`).
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_admin_management.php`.
- [ ] **Step 3: Implementasi `admin/kelola_pengawas.php`**
  Halaman manajemen akun Pengawas (buat baru dengan hash bcrypt, aktif/nonaktifkan), panel '1-Klik Daftarkan IP WiFi Saat Ini' membaca `$_SERVER['REMOTE_ADDR']`, saklar Toggle ON/OFF pemeriksaan WiFi, dan ubah PIN Ruang APD.
- [ ] **Step 4: Implementasi `admin/kelola_apd.php`**
  CRUD master APD (nama, kategori, ukuran, stok awal), tombol 'Pulihkan / Selesai Diperbaiki' untuk item yang memiliki `stok_rusak > 0`, serta template cetak Poster QR Code & PIN Gudang untuk ditempel di pintu gudang.
- [ ] **Step 5: Implementasi `admin/index.php`**
  Dashboard analitik eksekutif dengan widget metrik total aset APD, unit sedang dipinjam, unit rusak memerlukan tindakan, grafik ringkas sirkulasi mingguan, dan log aktivitas terbaru.
- [ ] **Step 6: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_admin_management.php`.
- [ ] **Step 7: Commit Task 5**
  `git add admin/ tests/ && git commit -m "feat: implement admin dashboard, APD master management, and wifi control"`

---

### Task 6: Modul Pelaporan & Ekspor Audit K3 (`admin/laporan.php`)

**Files:**
- Create: `admin/laporan.php`
- Test: `tests/test_reporting.php`

**Interfaces:**
- Consumes: `getDbConnection()`, `checkAuth(['admin', 'pengawas'])`
- Produces: Laporan sirkulasi dan audit kelayakan APD dengan filter tanggal, status, kategori, dan tombol cetak PDF & ekspor Excel.

- [ ] **Step 1: Tulis test kueri dan filter laporan audit K3**
  Buat `tests/test_reporting.php` untuk memvalidasi filter periode tanggal, rekap status peminjaman, dan kalkulasi total unit keluar-masuk.
- [ ] **Step 2: Jalankan test dan verifikasi gagal (RED)**
  Jalankan `php tests/test_reporting.php`.
- [ ] **Step 3: Implementasi `admin/laporan.php`**
  Halaman filter riwayat sirkulasi dengan opsi rentang tanggal, filter kategori APD, dan filter status (Semua, Dipinjam, Selesai Baik, Selesai Rusak). Dilengkapi tombol cetak rapi (`window.print()` dengan `@media print` CSS profesional) serta ekspor tabel ke Excel/CSV.
- [ ] **Step 4: Jalankan test dan verifikasi sukses (GREEN)**
  Jalankan `php tests/test_reporting.php`.
- [ ] **Step 5: Commit Task 6**
  `git add admin/laporan.php tests/ && git commit -m "feat: implement K3 audit reporting and printable export"`

---

### Task 7: Pengujian Menyeluruh (Blackbox Test TC-01 s/d TC-06) & Verifikasi Sistem

**Files:**
- Create: `tests/run_all_tests.php`
- Create: `README.md`
- Test: `tests/run_all_tests.php`

- [ ] **Step 1: Implementasi test suite komprehensif `tests/run_all_tests.php`**
  Menggabungkan pengujian seluruh skenario kritis:
  - TC-01: Peminjaman mandiri tanpa login (PIN Valid)
  - TC-02: Penolakan entri peminjaman saat PIN salah
  - TC-03: Pembuatan akun Pengawas baru oleh Admin
  - TC-04: Validasi login Pengawas via IP WiFi & saklar ON/OFF
  - TC-05: ACC pengembalian APD kondisi baik
  - TC-06: ACC pengembalian APD kondisi rusak
- [ ] **Step 2: Jalankan test suite dan verifikasi 100% lulus**
  Jalankan `php tests/run_all_tests.php`.
- [ ] **Step 3: Buat dokumentasi petunjuk menjalankan aplikasi (`README.md`)**
  Dokumentasikan cara menjalankan aplikasi dengan `php -S localhost:8000`, akun default admin, panduan operasional 3 role, dan arsitektur sistem.
- [ ] **Step 4: Verifikasi di Browser / Dev Server**
  Jalankan server lokal, verifikasi responsivitas halaman `index.php`, `login.php`, `pengawas/index.php`, `admin/index.php`.
- [ ] **Step 5: Commit final Task 7**
  `git add tests/ README.md && git commit -m "feat: add comprehensive blackbox test suite and documentation"`
