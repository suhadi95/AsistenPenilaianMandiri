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

        // 3. Pastikan kolom drive_file_url dan kolom integritas ada di tabel grades
        try {
            $integrityCols = [
                'drive_file_url'        => ['type' => 'TEXT NULL', 'default' => 'NULL'],
                'similarity_score'      => ['type' => 'DECIMAL(5,2) NULL', 'default' => 'NULL'],
                'similarity_target_nim' => ['type' => 'VARCHAR(64) NULL', 'default' => 'NULL'],
                'similarity_details'    => ['type' => 'MEDIUMTEXT NULL', 'default' => 'NULL'],
                'ai_detected'           => ['type' => 'INT DEFAULT 0', 'default' => '0'],
                'ai_detected_details'   => ['type' => 'MEDIUMTEXT NULL', 'default' => 'NULL']
            ];

            if ($driver === 'mysql') {
                foreach ($integrityCols as $cName => $cDef) {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM grades LIKE '{$cName}'")->fetch();
                    if (empty($colCheck)) {
                        $pdo->exec("ALTER TABLE grades ADD COLUMN {$cName} {$cDef['type']}");
                    }
                }
            } else {
                $cols = $pdo->query("PRAGMA table_info(grades)")->fetchAll();
                $existingNames = array_column($cols, 'name');
                foreach ($integrityCols as $cName => $cDef) {
                    if (!in_array($cName, $existingNames, true)) {
                        $pdo->exec("ALTER TABLE grades ADD COLUMN {$cName} {$cDef['type']}");
                    }
                }
            }
        } catch (Exception $e) {
            // Kolom mungkin sudah ada
        }

        // 4. Pastikan ada akun Admin default dan migrasikan data eksisting
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

        // 5. Migrasi aman kredensial legacy dari tabel settings ke akun admin
        // dan bersihkan dari tabel settings agar TIDAK bocor ke akun dosen/pengguna lain
        try {
            $legacyApiKey = self::getSetting('GEMINI_API_KEY');
            $legacyGoogleAccess = self::getSetting('GOOGLE_ACCESS_TOKEN');
            $legacyGoogleRefresh = self::getSetting('GOOGLE_REFRESH_TOKEN');
            $legacyGoogleExpires = self::getSetting('GOOGLE_TOKEN_EXPIRES_AT');
            $legacyGoogleEmail = self::getSetting('GOOGLE_CONNECTED_EMAIL');

            if (!empty($legacyApiKey) || !empty($legacyGoogleAccess) || !empty($legacyGoogleRefresh)) {
                $adminUser = self::fetchOne("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1");
                if ($adminUser) {
                    if (empty($adminUser['gemini_api_key']) && !empty($legacyApiKey)) {
                        self::execute("UPDATE users SET gemini_api_key = ? WHERE id_user = ?", [$legacyApiKey, $adminUser['id_user']]);
                    }
                    if (empty($adminUser['google_access_token']) && !empty($legacyGoogleAccess)) {
                        self::execute(
                            "UPDATE users SET google_access_token = ?, google_refresh_token = ?, google_token_expires_at = ?, google_connected_email = ? WHERE id_user = ?",
                            [$legacyGoogleAccess, $legacyGoogleRefresh, (int)$legacyGoogleExpires, $legacyGoogleEmail, $adminUser['id_user']]
                        );
                    }
                }
            }

            // Hapus kredensial privat dari tabel settings global agar terisolasi penuh per akun
            self::execute("DELETE FROM settings WHERE setting_key IN ('GEMINI_API_KEY', 'GOOGLE_ACCESS_TOKEN', 'GOOGLE_REFRESH_TOKEN', 'GOOGLE_TOKEN_EXPIRES_AT', 'GOOGLE_CONNECTED_EMAIL')");
        } catch (Exception $e) {
            error_log("Credential isolation migration error: " . $e->getMessage());
        }

        // 6. Jalankan migrasi terstruktur dari folder database/migrations
        try {
            self::executePendingMigrations();
        } catch (Exception $e) {
            error_log("Structured migration error: " . $e->getMessage());
        }

        // 7. Pemeriksaan pengaman struktur skema otomatis
        try {
            self::syncSchemaSafetyCheck();
        } catch (Exception $e) {
            error_log("Schema safety sync error: " . $e->getMessage());
        }
    }

    /**
     * Memastikan tabel migrations sudah tercipta
     */
    public static function ensureMigrationsTable(): void {
        $pdo = self::getConnection();
        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');

        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL DEFAULT 1,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }
    }

    /**
     * Mengambil daftar nama file migrasi yang telah dieksekusi
     */
    public static function getExecutedMigrations(): array {
        self::ensureMigrationsTable();
        $rows = self::fetchAll("SELECT migration FROM migrations ORDER BY id ASC");
        return array_column($rows, 'migration');
    }

    /**
     * Mengambil daftar file migrasi yang belum dieksekusi
     */
    public static function getPendingMigrations(): array {
        $migrationsDir = __DIR__ . '/../database/migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $executed = self::getExecutedMigrations();
        $files = scandir($migrationsDir);
        $pending = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }
            $migrationName = pathinfo($file, PATHINFO_FILENAME);
            if (!in_array($migrationName, $executed, true)) {
                $pending[] = [
                    'file' => $file,
                    'name' => $migrationName,
                    'path' => $migrationsDir . '/' . $file
                ];
            }
        }

        usort($pending, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $pending;
    }

    /**
     * Mengeksekusi seluruh migrasi yang tertunda
     */
    public static function executePendingMigrations(): array {
        self::ensureMigrationsTable();
        $pending = self::getPendingMigrations();
        if (empty($pending)) {
            return [
                'count' => 0,
                'logs'  => ['Skema basis data sudah mutakhir. Tidak ada migrasi tertunda.']
            ];
        }

        $pdo = self::getConnection();
        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');

        // Tentukan batch migrasi berikutnya
        $latestBatchRow = self::fetchOne("SELECT MAX(batch) as max_batch FROM migrations");
        $nextBatch = ((int)($latestBatchRow['max_batch'] ?? 0)) + 1;

        $logs = [];
        $count = 0;

        foreach ($pending as $item) {
            $migrationClass = require_once $item['path'];
            if (is_object($migrationClass) && method_exists($migrationClass, 'up')) {
                try {
                    $migrationClass->up($pdo, $driver);
                    self::execute(
                        "INSERT INTO migrations (migration, batch, executed_at) VALUES (?, ?, ?)",
                        [$item['name'], $nextBatch, date('Y-m-d H:i:s')]
                    );
                    $logs[] = "✅ Migrasi [{$item['name']}] berhasil dieksekusi.";
                    $count++;
                } catch (Exception $e) {
                    $logs[] = "❌ Gagal migrasi [{$item['name']}]: " . $e->getMessage();
                    throw new Exception("Migrasi {$item['name']} terhenti: " . $e->getMessage());
                }
            } else {
                $logs[] = "⚠️ File migrasi [{$item['file']}] tidak mengembalikan objek migrasi yang valid.";
            }
        }

        return [
            'count' => $count,
            'logs'  => $logs
        ];
    }

    /**
     * Memindai integritas skema tabel dan kolom secara otomatis (Safety Net)
     * Menambahkan kolom yang terdefinisi pada database.sql jika belum ada di tabel aktif
     */
    public static function syncSchemaSafetyCheck(): array {
        $pdo = self::getConnection();
        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');
        $logs = [];

        // Definisi skema tabel & kolom yang wajib ada
        $expectedColumns = [
            'users' => [
                'id_user'                => 'VARCHAR(64)',
                'nama'                   => 'VARCHAR(255)',
                'email'                  => 'VARCHAR(255)',
                'password_hash'          => 'VARCHAR(255)',
                'role'                   => 'VARCHAR(32)',
                'status'                 => 'VARCHAR(32)',
                'gemini_api_key'         => 'TEXT NULL',
                'model_name'             => 'VARCHAR(64)',
                'google_access_token'    => 'TEXT NULL',
                'google_refresh_token'   => 'TEXT NULL',
                'google_token_expires_at'=> 'INT DEFAULT 0',
                'google_connected_email' => 'VARCHAR(255) NULL',
                'last_login_at'          => 'DATETIME NULL',
                'created_at'             => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ],
            'courses' => [
                'id_mata_kuliah'   => 'VARCHAR(64)',
                'id_user'          => 'VARCHAR(64) NULL',
                'kode_mk'          => 'VARCHAR(32)',
                'nama_mata_kuliah' => 'VARCHAR(255)',
                'semester'         => 'VARCHAR(64)',
                'created_at'       => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ],
            'tasks' => [
                'id_tugas'         => 'VARCHAR(64)',
                'id_mata_kuliah'   => 'VARCHAR(64)',
                'judul_tugas'      => 'VARCHAR(255)',
                'deskripsi'        => 'TEXT NULL',
                'task_file_ref'    => 'TEXT NULL',
                'drive_folder_id'  => 'VARCHAR(255) DEFAULT \'\'',
                'rubrik_text'      => 'MEDIUMTEXT NULL',
                'bobot_persentase' => 'DECIMAL(5,2) DEFAULT 0',
                'created_at'       => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ],
            'grades' => [
                'id_nilai'              => 'VARCHAR(64)',
                'id_tugas'              => 'VARCHAR(64)',
                'id_kelas'              => 'VARCHAR(64)',
                'nim'                   => 'VARCHAR(64)',
                'nama_mahasiswa'        => 'VARCHAR(255)',
                'file_name'             => 'VARCHAR(255) DEFAULT \'\'',
                'drive_file_url'        => 'TEXT NULL',
                'nilai_total'           => 'DECIMAL(5,2) NULL',
                'rincian_poin'          => 'MEDIUMTEXT NULL',
                'catatan_ai'            => 'MEDIUMTEXT NULL',
                'similarity_score'      => 'DECIMAL(5,2) NULL',
                'similarity_target_nim' => 'VARCHAR(64) NULL',
                'similarity_details'    => 'MEDIUMTEXT NULL',
                'ai_detected'           => 'INT DEFAULT 0',
                'ai_detected_details'   => 'MEDIUMTEXT NULL',
                'status'                => 'VARCHAR(255) DEFAULT \'Belum Dinilai\'',
                'waktu_penilaian'       => 'VARCHAR(64) DEFAULT \'\'',
                'created_at'            => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ],
            'settings' => [
                'setting_key'   => 'VARCHAR(128)',
                'setting_value' => 'MEDIUMTEXT NULL',
                'updated_at'    => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ]
        ];

        foreach ($expectedColumns as $tableName => $cols) {
            // Periksa apakah tabel ada
            $tableExists = false;
            try {
                if ($driver === 'mysql') {
                    $chk = $pdo->query("SHOW TABLES LIKE '{$tableName}'")->fetch();
                    $tableExists = !empty($chk);
                } else {
                    $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'")->fetch();
                    $tableExists = !empty($chk);
                }
            } catch (Exception $e) {
                $tableExists = false;
            }

            if (!$tableExists) {
                continue;
            }

            // Ambil kolom yang sudah ada
            $existingCols = [];
            try {
                if ($driver === 'mysql') {
                    $cRows = $pdo->query("SHOW COLUMNS FROM `{$tableName}`")->fetchAll(PDO::FETCH_ASSOC);
                    $existingCols = array_column($cRows, 'Field');
                } else {
                    $cRows = $pdo->query("PRAGMA table_info(`{$tableName}`)")->fetchAll(PDO::FETCH_ASSOC);
                    $existingCols = array_column($cRows, 'name');
                }
            } catch (Exception $e) {
                continue;
            }

            // Cari kolom yang belum ada dan tambahkan secara aman
            foreach ($cols as $colName => $colType) {
                if (!in_array($colName, $existingCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$colName}` {$colType}");
                        $logs[] = "Menambahkan kolom [{$colName}] ke tabel [{$tableName}].";
                    } catch (Exception $e) {
                        // Abaikan jika tidak didukung atau sudah ada
                    }
                }
            }
        }

        return $logs;
    }

    /**
     * Membuat cadangan (backup) basis data ke folder backups/
     */
    public static function backupDatabase(): array {
        $backupDir = __DIR__ . '/../backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        // Amankan folder backup dengan .htaccess
        $htaccess = $backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
        }

        $config = self::loadConfig();
        $driver = strtolower($config['db_driver'] ?? 'sqlite');
        $timestamp = date('Ymd_His');

        if ($driver === 'sqlite') {
            $sqlitePath = $config['sqlite']['path'] ?? (__DIR__ . '/../database.sqlite');
            if (!file_exists($sqlitePath)) {
                return [
                    'success' => false,
                    'message' => 'File database SQLite tidak ditemukan: ' . $sqlitePath
                ];
            }

            $destName = "db_backup_sqlite_{$timestamp}.sqlite";
            $destPath = $backupDir . '/' . $destName;

            if (!copy($sqlitePath, $destPath)) {
                throw new Exception("Gagal membuat salinan file SQLite ke {$destPath}");
            }

            $sizeBytes = filesize($destPath);
            $sizeFormatted = round($sizeBytes / 1024, 1) . ' KB';

            return [
                'success'     => true,
                'driver'      => 'sqlite',
                'backup_file' => $destName,
                'path'        => $destPath,
                'size'        => $sizeFormatted,
                'created_at'  => date('Y-m-d H:i:s')
            ];
        } else {
            // MySQL Snapshot Export via PDO
            $pdo = self::getConnection();
            $destName = "db_backup_mysql_{$timestamp}.sql";
            $destPath = $backupDir . '/' . $destName;

            $tables = ['users', 'courses', 'classes', 'students', 'tasks', 'grades', 'class_task_folders', 'settings', 'migrations'];
            $sqlDump = "-- Snapshot Cadangan Otomatis Asisten Penilaian Mandiri\n";
            $sqlDump .= "-- Waktu Pembuatan: " . date('Y-m-d H:i:s') . "\n";
            $sqlDump .= "-- Host: " . ($config['mysql']['host'] ?? 'localhost') . "\n\n";
            $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $tbl) {
                try {
                    $chk = $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetch();
                    if (!$chk) continue;

                    $createRow = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
                    if ($createRow && isset($createRow[1])) {
                        $sqlDump .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
                        $sqlDump .= $createRow[1] . ";\n\n";

                        $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($rows)) {
                            foreach ($rows as $r) {
                                $cols = array_map(fn($c) => "`{$c}`", array_keys($r));
                                $vals = array_map(function($v) use ($pdo) {
                                    return $v === null ? 'NULL' : $pdo->quote((string)$v);
                                }, array_values($r));
                                $sqlDump .= "INSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                            }
                            $sqlDump .= "\n";
                        }
                    }
                } catch (Exception $e) {
                    $sqlDump .= "-- Gagal mencadangkan tabel {$tbl}: " . $e->getMessage() . "\n";
                }
            }

            $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($destPath, $sqlDump);
            $sizeBytes = filesize($destPath);
            $sizeFormatted = round($sizeBytes / 1024, 1) . ' KB';

            return [
                'success'     => true,
                'driver'      => 'mysql',
                'backup_file' => $destName,
                'path'        => $destPath,
                'size'        => $sizeFormatted,
                'created_at'  => date('Y-m-d H:i:s')
            ];
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
