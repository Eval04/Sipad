<?php
/**
 * Konfigurasi Koneksi Basis Data PDO MySQL
 * Sistem Inventaris dan Peminjaman APD (Sipad)
 * Mendukung File Konfigurasi .env & Kompatibel Penuh untuk Hosting (cPanel / VPS / Shared Hosting)
 */
declare(strict_types=1);

// Set zona waktu resmi sesuai operasional K3 Indonesia
date_default_timezone_set('Asia/Jakarta');

/**
 * Parser file .env mandiri tanpa dependensi eksternal (Zero-Dependency)
 */
function loadEnvConfig(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPaths = [
        __DIR__ . '/../.env',
        __DIR__ . '/.env'
    ];

    foreach ($envPaths as $path) {
        if (file_exists($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $trimmed = trim($line);
                // Abaikan komentar
                if ($trimmed === '' || $trimmed[0] === '#' || str_starts_with($trimmed, '//')) {
                    continue;
                }

                $parts = explode('=', $trimmed, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $val = trim($parts[1]);

                    // Hilangkan tanda kutip jika ada
                    if (strlen($val) >= 2 && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
                        $val = substr($val, 1, -1);
                    }

                    if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                        putenv("{$key}={$val}");
                        $_ENV[$key] = $val;
                        $_SERVER[$key] = $val;
                    }
                }
            }
            break;
        }
    }
}

// Jalankan pembacaan .env
loadEnvConfig();

/**
 * Mengambil parameter konfigurasi database (dengan prioritas .env > getenv > default hosting)
 */
function getDbConfig(): array {
    return [
        'host'    => getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? '127.0.0.1')),
        'port'    => (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? 3306))),
        'name'    => getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? 'sipad_db')),
        'user'    => getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? 'sipad')),
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? 'Sipad_K3_2026!')),
        'charset' => getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? ($_SERVER['DB_CHARSET'] ?? 'utf8mb4')),
    ];
}

/**
 * Mengembalikan instance PDO tunggal / terkonfigurasi.
 * Mengandung mekanisme Zero-Config Auto-Installer untuk hosting baru.
 *
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $cfg = getDbConfig();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $cfg['charset'] . " COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        } catch (PDOException $e) {
            // Jika host localhost gagal koneksi socket, coba otomatis fallback ke 127.0.0.1 (sangat umum di Linux/cPanel)
            if ($cfg['host'] === 'localhost') {
                try {
                    $dsnFallback = sprintf(
                        'mysql:host=127.0.0.1;port=%d;dbname=%s;charset=%s',
                        $cfg['port'],
                        $cfg['name'],
                        $cfg['charset']
                    );
                    $pdo = new PDO($dsnFallback, $cfg['user'], $cfg['pass'], $options);
                } catch (PDOException $e2) {
                    handleDbConnectionError($e, $cfg);
                }
            } else {
                handleDbConnectionError($e, $cfg);
            }
        }

        // Auto-Installer: Periksa apakah tabel 'users' sudah ada
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
            if (!$checkTable) {
                // Skema belum terpasang, pasang otomatis dari database.sql
                $sqlFile = __DIR__ . '/../database.sql';
                if (file_exists($sqlFile)) {
                    $sqlContent = file_get_contents($sqlFile);
                    if ($sqlContent !== false) {
                        $pdo->exec($sqlContent);
                    }
                }
            }
        } catch (Throwable $ignore) {
            // Biarkan lewat jika user MySQL hosting memiliki batasan hak akses DDL tertentu
        }
    }

    return $pdo;
}

/**
 * Menampilkan halaman petunjuk ramah konfigurasi jika koneksi basis data gagal di browser web
 */
function handleDbConnectionError(PDOException $e, array $cfg): never {
    // Jika dipanggil via CLI / Unit Test runner, lemparkan error asli
    if (php_sapi_name() === 'cli' || defined('STDIN')) {
        throw $e;
    }

    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Konfigurasi Basis Data Hosting - SIPAD K3</title>
        <script src="/assets/js/tailwind.min.js"></script>
    </head>
    <body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">
        <div class="max-w-xl w-full bg-white rounded-3xl p-6 sm:p-8 shadow-xl border border-slate-200 space-y-5">
            <div class="flex items-center gap-3 pb-3 border-b border-slate-200">
                <div class="w-12 h-12 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center font-bold">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base sm:text-lg font-bold text-slate-900">Gagal Terhubung ke Basis Data MySQL</h1>
                    <span class="text-xs text-slate-500">Sistem Inventaris & Peminjaman APD (SIPAD K3)</span>
                </div>
            </div>

            <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 font-mono leading-relaxed">
                <?= htmlspecialchars($e->getMessage()) ?>
            </div>

            <div class="space-y-3 text-xs text-slate-600">
                <p class="font-semibold text-slate-800">Petunjuk Pengaturan di Hosting (cPanel / VPS):</p>
                <ol class="list-decimal list-inside space-y-1.5 leading-relaxed bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <li>Buka File Manager di cPanel Anda.</li>
                    <li>Salin atau buat file <code class="bg-white px-1.5 py-0.5 rounded border font-bold text-[#003e74]">.env</code> di direktori root aplikasi.</li>
                    <li>Sesuaikan informasi database MySQL yang Anda buat di cPanel:</li>
                </ol>

                <div class="p-3 bg-slate-900 text-sky-300 rounded-xl font-mono text-[11px] space-y-1">
                    <div>DB_HOST=localhost</div>
                    <div>DB_PORT=3306</div>
                    <div>DB_NAME=nama_database_hosting</div>
                    <div>DB_USER=nama_pengguna_hosting</div>
                    <div>DB_PASS=kata_sandi_mysql</div>
                </div>

                <p class="text-[11px] text-slate-500 italic">
                    * Parameter yang sedang dicoba: Host <code class="font-bold"><?= htmlspecialchars($cfg['host']) ?></code>, Database <code class="font-bold"><?= htmlspecialchars($cfg['name']) ?></code>, User <code class="font-bold"><?= htmlspecialchars($cfg['user']) ?></code>.
                </p>
            </div>

            <div class="pt-2 text-center">
                <button onclick="window.location.reload()" class="px-5 py-2.5 rounded-xl bg-[#003e74] hover:bg-[#002f58] text-white font-bold text-xs shadow-sm transition-all">
                    Coba Hubungkan Ulang
                </button>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
