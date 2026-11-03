<?php
/**
 * @fileoverview Database Abstraction Layer berbasis PDO (Dukungan MySQL & SQLite)
 */

class Database {
    private static ?PDO $pdo = null;
    private static ?array $config = null;

    /**
     * Memuat konfigurasi aplikasi
     */
    public static function loadConfig(): array {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../config.php';
            if (!file_exists($configPath)) {
                $configPath = __DIR__ . '/../config.example.php';
            }
            self::$config = require $configPath;
        }
        return self::$config;
    }

    /**
     * Mengambil instance koneksi PDO aktif
     */
    public static function getConnection(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');

        try {
            if ($driver === 'mysql') {
                $mysql = $config['mysql'];
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $mysql['host'] ?? 'localhost',
                    $mysql['port'] ?? 3306,
                    $mysql['database'] ?? 'asisten_penilaian',
                    $mysql['charset'] ?? 'utf8mb4'
                );
                self::$pdo = new PDO($dsn, $mysql['username'] ?? 'root', $mysql['password'] ?? '', [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } else {
                // Default SQLite
                $sqlitePath = $config['sqlite']['path'] ?? (__DIR__ . '/../database.sqlite');
                $sqliteDir = dirname($sqlitePath);
                if (!is_dir($sqliteDir)) {
                    @mkdir($sqliteDir, 0755, true);
                }

                $dsn = 'sqlite:' . $sqlitePath;
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 10,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
                self::$pdo->exec('PRAGMA journal_mode = WAL;');
            }
        } catch (PDOException $e) {
            throw new Exception("Koneksi basis data gagal ({$driver}): " . $e->getMessage());
        }

        return self::$pdo;
    }

    /**
     * Menjalankan statement query dengan binding parameter
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Mengambil satu baris data (associative array)
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Mengambil seluruh baris data
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Menjalankan statement insert/update/delete dan mengembalikan status keberhasilan
     */
    public static function execute(string $sql, array $params = []): bool {
        $stmt = self::getConnection()->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Memulai transaksi basis data
     */
    public static function beginTransaction(): bool {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit transaksi
     */
    public static function commit(): bool {
        return self::getConnection()->commit();
    }

    /**
     * Rollback transaksi
     */
    public static function rollBack(): bool {
        return self::getConnection()->rollBack();
    }

    /**
     * Inisialisasi otomatis tabel basis data jika belum ada
     */
    public static function initDatabase(): array {
        $pdo = self::getConnection();
        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');

        // Periksa apakah tabel 'courses' sudah ada
        $tableExists = false;
        try {
            if ($driver === 'mysql') {
                $check = $pdo->query("SHOW TABLES LIKE 'courses'")->fetch();
                $tableExists = !empty($check);
            } else {
                $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='courses'")->fetch();
                $tableExists = !empty($check);
            }
        } catch (Exception $e) {
            $tableExists = false;
        }

        if (!$tableExists) {
            $schemaFile = __DIR__ . '/../database.sql';
            if (file_exists($schemaFile)) {
                $sqlContent = file_get_contents($schemaFile);
                // Pisahkan perintah query berdasarkan titik koma
                $queries = array_filter(array_map('trim', explode(';', $sqlContent)));
                foreach ($queries as $q) {
                    if (!empty($q)) {
                        try {
                            $pdo->exec($q);
                        } catch (PDOException $e) {
                            // Abaikan duplikasi indeks
                            if (!str_contains($e->getMessage(), 'already exists')) {
                                error_log("Migration warning: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        // Jalankan migrasi skema tabel jika diperlukan
        self::runMigrations();

        // Pastikan ada data contoh default
        self::seedDefaultData();

        return [
            'success' => true,
            'driver'  => $driver,
            'message' => 'Basis data siap digunakan.'
        ];
    }

    /**
     * Menjalankan migrasi skema multi-user & relasi pengguna
     */
    public static function runMigrations(): void {
        $pdo = self::getConnection();
        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');

        // 1. Buat tabel users jika belum ada
        $userTableExists = false;
        try {
            if ($driver === 'mysql') {
                $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                $userTableExists = !empty($check);
            } else {
                $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
                $userTableExists = !empty($check);
            }
        } catch (Exception $e) {
            $userTableExists = false;
        }

        if (!$userTableExists) {
            $userSql = "CREATE TABLE IF NOT EXISTS users (
                id_user VARCHAR(64) PRIMARY KEY,
                nama VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) DEFAULT 'dosen',
                status VARCHAR(32) DEFAULT 'active',
                gemini_api_key TEXT NULL,
                model_name VARCHAR(64) DEFAULT 'gemini-3.5-flash-lite',
                google_access_token TEXT NULL,
                google_refresh_token TEXT NULL,
                google_token_expires_at INT DEFAULT 0,
                google_connected_email VARCHAR(255) NULL,
                last_login_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
            $pdo->exec($userSql);
        }

        // 2. Pastikan kolom id_user ada di courses
        try {
            if ($driver === 'mysql') {
                $colCheck = $pdo->query("SHOW COLUMNS FROM courses LIKE 'id_user'")->fetch();
                if (empty($colCheck)) {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN id_user VARCHAR(64) NULL AFTER id_mata_kuliah");
                }
            } else {
                $cols = $pdo->query("PRAGMA table_info(courses)")->fetchAll();
                $hasIdUser = false;
                foreach ($cols as $col) {
                    if ($col['name'] === 'id_user') {
                        $hasIdUser = true;
                        break;
                    }
                }
                if (!$hasIdUser) {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN id_user VARCHAR(64) NULL");
                }
            }
        } catch (Exception $e) {
            // Kolom mungkin sudah ada
        }

        // 3. Pastikan ada akun Admin default dan migrasikan data eksisting
        try {
            $userCount = self::fetchOne("SELECT COUNT(*) as count FROM users");
            if (!$userCount || (int)$userCount['count'] === 0) {
                $adminId = 'USR-' . strtoupper(substr(uniqid(), -6));
                
                // Ambil data token dan email yang sudah tersimpan di settings
                $connectedEmail = self::getSetting('GOOGLE_CONNECTED_EMAIL') ?: 'suhadiparman@uinssc.ac.id';
                $geminiKey = self::getSetting('GEMINI_API_KEY') ?: '';
                $modelName = self::getSetting('MODEL_NAME') ?: 'gemini-3.5-flash-lite';
                $googleAccessToken = self::getSetting('GOOGLE_ACCESS_TOKEN') ?: null;
                $googleRefreshToken = self::getSetting('GOOGLE_REFRESH_TOKEN') ?: null;
                $googleTokenExpires = (int)(self::getSetting('GOOGLE_TOKEN_EXPIRES_AT') ?: 0);

                $adminPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);

                self::execute(
                    "INSERT INTO users (id_user, nama, email, password_hash, role, status, gemini_api_key, model_name, google_access_token, google_refresh_token, google_token_expires_at, google_connected_email, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $adminId,
                        'Suhadi Parman',
                        $connectedEmail,
                        $adminPasswordHash,
                        'admin',
                        'active',
                        $geminiKey,
                        $modelName,
                        $googleAccessToken,
                        $googleRefreshToken,
                        $googleTokenExpires,
                        $connectedEmail,
                        date('Y-m-d H:i:s')
                    ]
                );

                // Hubungkan semua mata kuliah yang belum berpemilik ke admin pertama
                self::execute("UPDATE courses SET id_user = ? WHERE id_user IS NULL OR id_user = ''", [$adminId]);
            }

            // Pastikan akun administrator utama selalu berstatus role 'admin'
            self::execute("UPDATE users SET role = 'admin' WHERE LOWER(email) = 'suhadiparman@uinssc.ac.id'");
        } catch (Exception $e) {
            error_log("Admin seed error: " . $e->getMessage());
        }
    }

    /**
     * Mengisi data awal (seeding) jika basis data masih kosong
     */
    public static function seedDefaultData(): void {
        $courseCount = self::fetchOne("SELECT COUNT(*) as count FROM courses");
        if ($courseCount && (int)$courseCount['count'] === 0) {
            $courseId = 'MK-' . strtoupper(substr(uniqid(), -6));
            $classAId = 'KLS-' . strtoupper(substr(uniqid(), -6));
            $classBId = 'KLS-' . strtoupper(substr(uniqid(), -6));

            self::execute(
                "INSERT INTO courses (id_mata_kuliah, kode_mk, nama_mata_kuliah, semester, created_at) VALUES (?, ?, ?, ?, ?)",
                [$courseId, 'IF210', 'Pemrograman Web', 'Ganjil 2026/2027', date('Y-m-d H:i:s')]
            );

            self::execute(
                "INSERT INTO classes (id_kelas, id_mata_kuliah, nama_kelas, semester, created_at) VALUES (?, ?, ?, ?, ?)",
                [$classAId, $courseId, 'Kelas A', 'Ganjil 2026/2027', date('Y-m-d H:i:s')]
            );

            self::execute(
                "INSERT INTO classes (id_kelas, id_mata_kuliah, nama_kelas, semester, created_at) VALUES (?, ?, ?, ?, ?)",
                [$classBId, $courseId, 'Kelas B', 'Ganjil 2026/2027', date('Y-m-d H:i:s')]
            );

            // Contoh mahasiswa untuk Kelas A
            $sampleStudents = [
                ['2024001', 'Budi Santoso'],
                ['2024002', 'Siti Rahmawati'],
                ['2024003', 'Ahmad Hidayat']
            ];

            foreach ($sampleStudents as $st) {
                $mhsId = 'MHS-' . strtoupper(substr(uniqid(), -6));
                self::execute(
                    "INSERT INTO students (id_mahasiswa, id_kelas, nim, nama_mahasiswa, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$mhsId, $classAId, $st[0], $st[1], date('Y-m-d H:i:s')]
                );
            }

            // Contoh tugas di tingkat mata kuliah
            $taskId = 'TGS-' . strtoupper(substr(uniqid(), -6));
            $rubrik = "## PANDUAN PORSI NILAI TUGAS (TOTAL: 100 POIN)\n\n1. Struktur HTML & Semantik (Porsi: 35 Poin)\n   - Kebersihan tag, struktur hierarki DOM, dan kelengkapan elemen.\n\n2. Tampilan & Responsif CSS (Porsi: 40 Poin)\n   - Kesesuaian styling, penggunaan flexbox/grid, responsivitas layar HP.\n\n3. Kerapian & Format (Porsi: 25 Poin)\n   - Indentasi kode, komentar, kerapian berkas.";

            self::execute(
                "INSERT INTO tasks (id_tugas, id_mata_kuliah, judul_tugas, deskripsi, task_file_ref, drive_folder_id, rubrik_text, bobot_persentase, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $taskId,
                    $courseId,
                    'Tugas 1 - Pemrograman Web Dasar',
                    'Tugas implementasi antarmuka HTML dan CSS responsif.',
                    '',
                    '',
                    $rubrik,
                    25,
                    date('Y-m-d H:i:s')
                ]
            );
        }
    }

    /**
     * Mengambil nilai pengaturan dari tabel settings
     */
    public static function getSetting(string $key): ?string {
        $row = self::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : null;
    }

    /**
     * Menyimpan nilai pengaturan ke tabel settings
     */
    public static function saveSetting(string $key, string $value): void {
        $exist = self::fetchOne("SELECT setting_key FROM settings WHERE setting_key = ?", [$key]);
        if ($exist) {
            self::execute("UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?", [$value, date('Y-m-d H:i:s'), $key]);
        } else {
            self::execute("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)", [$key, $value, date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Reset total seluruh basis data (hapus data semua tabel)
     */
    public static function resetDatabase(): array {
        $tables = ['grades', 'students', 'class_task_folders', 'tasks', 'classes', 'courses'];
        foreach ($tables as $tbl) {
            self::execute("DELETE FROM {$tbl}");
        }
        self::seedDefaultData();
        return [
            'success' => true,
            'message' => 'Basis data berhasil dibersihkan dan diinisialisasi ulang dengan struktur baru yang siap pakai.'
        ];
    }
}
