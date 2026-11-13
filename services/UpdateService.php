<?php
/**
 * @fileoverview Layanan Pembaruan & Deployment Otomatis (GitHub & cPanel Multi-Tier)
 * Menangani pengecekan commit, backup otomatis, deployment aman, dan migrasi skema database.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/UserService.php';

class UpdateService {
    public const DEFAULT_REPO = 'suhadi95/AsistenPenilaianMandiri';
    public const DEFAULT_BRANCH = 'master';

    /**
     * Mengambil konfigurasi repository dan branch yang tersimpan
     */
    public static function getRepoConfig(): array {
        $repo = Database::getSetting('APP_DEPLOY_REPO') ?: self::DEFAULT_REPO;
        $branch = Database::getSetting('APP_DEPLOY_BRANCH') ?: self::DEFAULT_BRANCH;
        $token = Database::getSetting('APP_GITHUB_TOKEN') ?: '';
        $cpanelPath = Database::getSetting('APP_CPANEL_REPO_PATH') ?: self::detectCpanelRepoPath();

        return [
            'repo'        => trim($repo),
            'branch'      => trim($branch),
            'token'       => trim($token),
            'cpanel_path' => trim($cpanelPath)
        ];
    }

    /**
     * Deteksi otomatis lokasi repositori cPanel jika berjalan di server hosting
     */
    public static function detectCpanelRepoPath(): string {
        $rootDir = realpath(__DIR__ . '/..');
        // Pola umum cPanel: /home/USER/repositories/REPO_NAME
        $possiblePaths = [
            realpath($rootDir . '/../../../repositories/AsistenPenilaianMandiri'),
            realpath($rootDir . '/../../repositories/AsistenPenilaianMandiri'),
            '/home/suhadipc/repositories/AsistenPenilaianMandiri'
        ];

        foreach ($possiblePaths as $path) {
            if ($path && is_dir($path) && (is_dir($path . '/.git') || file_exists($path . '/.cpanel.yml'))) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Memeriksa status lengkap versi lokal dan pembaruan remote di GitHub
     */
    public static function getStatus(): array {
        UserService::requireAdmin();

        $config = self::getRepoConfig();
        $rootDir = realpath(__DIR__ . '/..');

        // 1. Dapatkan informasi versi lokal
        $localVersion = self::getLocalVersion();

        // 2. Dapatkan informasi commit terbaru dari GitHub API
        $remoteInfo = self::fetchRemoteCommitInfo($config['repo'], $config['branch'], $config['token']);

        // 3. Bandingkan versi lokal dan remote
        $currentSha = strtolower(trim($localVersion['commit'] ?? ''));
        $remoteSha = strtolower(trim($remoteInfo['sha'] ?? ''));

        $isUpToDate = false;
        if (!empty($currentSha) && !empty($remoteSha)) {
            $isUpToDate = ($currentSha === $remoteSha) 
                || str_starts_with($remoteSha, $currentSha) 
                || str_starts_with($currentSha, $remoteSha);
        }

        // 4. Ambil daftar commit yang belum terpasang (jika berbeda)
        $pendingCommits = [];
        if (!$isUpToDate && !empty($remoteSha)) {
            $pendingCommits = self::fetchRecentCommits($config['repo'], $config['branch'], $config['token'], 5);
        }

        // 5. Cek migrasi database yang belum dieksekusi
        $pendingMigrations = Database::getPendingMigrations();

        // 6. Diagnostik kesiapan lingkungan hosting
        $env = [
            'is_writable'        => is_writable($rootDir) && is_writable($rootDir . '/core') && is_writable($rootDir . '/services'),
            'zip_enabled'        => class_exists('ZipArchive'),
            'curl_enabled'       => function_exists('curl_init'),
            'opcache_enabled'    => function_exists('opcache_reset'),
            'cpanel_repo_found'  => !empty($config['cpanel_path']) && is_dir($config['cpanel_path']),
            'git_cli_available'  => self::isGitCliAvailable(),
            'php_version'        => PHP_VERSION,
            'db_driver'          => strtolower(Database::loadConfig()['db_driver'] ?? 'sqlite')
        ];

        return [
            'success'            => true,
            'is_up_to_date'      => $isUpToDate,
            'current'            => $localVersion,
            'remote'             => $remoteInfo,
            'pending_commits'    => $pendingCommits,
            'pending_migrations' => $pendingMigrations,
            'migration_count'    => count($pendingMigrations),
            'environment'        => $env,
            'config'             => [
                'repo'        => $config['repo'],
                'branch'      => $config['branch'],
                'cpanel_path' => $config['cpanel_path'],
                'has_token'   => !empty($config['token'])
            ]
        ];
    }

    /**
     * Membaca informasi versi lokal aktif dari version.json atau basis data
     */
    public static function getLocalVersion(): array {
        $rootDir = realpath(__DIR__ . '/..');
        $versionFile = $rootDir . '/version.json';
        $data = [];

        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            $data = json_decode($content, true) ?: [];
        }

        // Ambil data override dari database settings jika ada
        $dbCommit = Database::getSetting('APP_CURRENT_COMMIT');
        $dbMessage = Database::getSetting('APP_COMMIT_MESSAGE');
        $dbDeployedAt = Database::getSetting('APP_LAST_DEPLOYED_AT');

        if (!empty($dbCommit)) {
            $data['commit'] = $dbCommit;
        }
        if (!empty($dbMessage)) {
            $data['message'] = $dbMessage;
        }
        if (!empty($dbDeployedAt)) {
            $data['deployed_at'] = $dbDeployedAt;
        }

        // Fallback jika belum pernah dicatat
        if (empty($data['commit'])) {
            $data['commit'] = 'd215089dd92851bddfa3bc05bb4ea1738fa0adbc'; // Snapshot awal cPanel
            $data['short_commit'] = 'd215089';
            $data['message'] = 'Pembaruan awal via cPanel Git Version Control';
            $data['deployed_at'] = date('Y-m-d H:i:s');
        }

        if (empty($data['short_commit']) && !empty($data['commit'])) {
            $data['short_commit'] = substr($data['commit'], 0, 7);
        }

        if (empty($data['version'])) {
            $data['version'] = '2.0.0';
        }

        return $data;
    }

    /**
     * Mengambil informasi commit terbaru dari GitHub API
     */
    public static function fetchRemoteCommitInfo(string $repo, string $branch, string $token = ''): array {
        $url = "https://api.github.com/repos/{$repo}/commits/{$branch}";
        $response = self::callGitHubApi($url, $token);

        if (!$response || !isset($response['sha'])) {
            $errMsg = $response['message'] ?? 'Tidak dapat terhubung ke GitHub API.';
            return [
                'sha'          => '',
                'short_sha'    => '',
                'message'      => "Gagal memeriksa remote: {$errMsg}",
                'author'       => '-',
                'date'         => '-',
                'html_url'     => "https://github.com/{$repo}/tree/{$branch}",
                'error'        => true,
                'errorMessage' => $errMsg
            ];
        }

        $msg = trim($response['commit']['message'] ?? '');
        $firstLine = strtok($msg, "\r\n");

        return [
            'sha'       => $response['sha'],
            'short_sha' => substr($response['sha'], 0, 7),
            'message'   => $firstLine,
            'full_msg'  => $msg,
            'author'    => $response['commit']['author']['name'] ?? ($response['commit']['committer']['name'] ?? 'Pengembang'),
            'date'      => isset($response['commit']['author']['date']) ? date('d/m/Y H:i', strtotime($response['commit']['author']['date'])) : '-',
            'raw_date'  => $response['commit']['author']['date'] ?? '',
            'html_url'  => $response['html_url'] ?? "https://github.com/{$repo}/commit/{$response['sha']}",
            'error'     => false
        ];
    }

    /**
     * Mengambil daftar commit terbaru dari GitHub
     */
    public static function fetchRecentCommits(string $repo, string $branch, string $token = '', int $limit = 5): array {
        $url = "https://api.github.com/repos/{$repo}/commits?sha={$branch}&per_page={$limit}";
        $response = self::callGitHubApi($url, $token);

        if (!is_array($response) || isset($response['message'])) {
            return [];
        }

        $commits = [];
        foreach ($response as $item) {
            if (!isset($item['sha'])) continue;
            $msg = trim($item['commit']['message'] ?? '');
            $firstLine = strtok($msg, "\r\n");
            $commits[] = [
                'sha'       => $item['sha'],
                'short_sha' => substr($item['sha'], 0, 7),
                'message'   => $firstLine,
                'author'    => $item['commit']['author']['name'] ?? 'Pengembang',
                'date'      => isset($item['commit']['author']['date']) ? date('d/m/Y H:i', strtotime($item['commit']['author']['date'])) : '-',
                'html_url'  => $item['html_url'] ?? '#'
            ];
        }

        return $commits;
    }

    /**
     * Melakukan HTTP request ke GitHub API
     */
    private static function callGitHubApi(string $url, string $token = ''): ?array {
        $ch = curl_init($url);
        $headers = [
            'User-Agent: AsistenPenilaianMandiri-Updater/2.0',
            'Accept: application/vnd.github.v3+json'
        ];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$body || ($httpCode >= 400 && $httpCode !== 404)) {
            $err = json_decode((string)$body, true);
            return $err ?: ['message' => "HTTP {$httpCode} saat mengakses {$url}"];
        }

        return json_decode($body, true);
    }

    /**
     * Memeriksa apakah Git CLI dapat dipanggil melalui shell PHP
     */
    public static function isGitCliAvailable(): bool {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = explode(',', ini_get('disable_functions') ?: '');
        $disabled = array_map('trim', $disabled);
        if (in_array('shell_exec', $disabled, true) || in_array('exec', $disabled, true)) {
            return false;
        }

        try {
            $out = @shell_exec('git --version 2>&1');
            return !empty($out) && str_contains(strtolower($out), 'git version');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Menyimpan konfigurasi repository deployment
     */
    public static function saveConfig(array $data): array {
        UserService::requireAdmin();

        $repo = trim($data['repo'] ?? self::DEFAULT_REPO);
        $branch = trim($data['branch'] ?? self::DEFAULT_BRANCH);
        $token = trim($data['token'] ?? '');
        $cpanelPath = trim($data['cpanel_path'] ?? '');

        Database::saveSetting('APP_DEPLOY_REPO', $repo);
        Database::saveSetting('APP_DEPLOY_BRANCH', $branch);
        if (isset($data['token'])) {
            Database::saveSetting('APP_GITHUB_TOKEN', $token);
        }
        if (isset($data['cpanel_path'])) {
            Database::saveSetting('APP_CPANEL_REPO_PATH', $cpanelPath);
        }

        return [
            'success' => true,
            'message' => 'Pengaturan repositori & deployment berhasil disimpan.'
        ];
    }

    /**
     * Eksekusi Pipeline Deployment Lengkap & Aman
     */
    public static function deployUpdate(): array {
        UserService::requireAdmin();

        @set_time_limit(300); // 5 Menit
        @ini_set('memory_limit', '512M');

        $logs = [];
        $startTime = microtime(true);
        $logs[] = "🚀 [Langkah 1/6] Memulai pipeline update & deploy otomatis...";

        $config = self::getRepoConfig();
        $rootDir = realpath(__DIR__ . '/..');
        $backupDir = $rootDir . '/backups';

        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        // Cek ekstensi ZipArchive
        if (!class_exists('ZipArchive')) {
            throw new Exception("Ekstensi PHP 'zip' (ZipArchive) tidak aktif di server ini. Hubungi penyedia hosting Anda.");
        }

        // Pengecekan izin tulis direktori
        if (!is_writable($rootDir)) {
            throw new Exception("Direktori root website ({$rootDir}) tidak memiliki izin tulis (write permission). Pastikan permission folder disetel ke 755.");
        }

        // ==========================================
        // 1. Ambil Info Commit Target dari GitHub
        // ==========================================
        $logs[] = "🔍 [Langkah 2/6] Memverifikasi commit target di GitHub ({$config['repo']} @ branch {$config['branch']})...";
        $remote = self::fetchRemoteCommitInfo($config['repo'], $config['branch'], $config['token']);
        if (empty($remote['sha'])) {
            throw new Exception("Gagal mendapatkan commit terbaru dari GitHub: " . ($remote['errorMessage'] ?? 'Unknown error'));
        }
        $targetSha = $remote['sha'];
        $shortTargetSha = $remote['short_sha'];
        $logs[] = "📌 Target commit: [{$shortTargetSha}] {$remote['message']} ({$remote['author']}, {$remote['date']})";

        // ==========================================
        // 2. Buat Cadangan Basis Data (Safety Net)
        // ==========================================
        $logs[] = "💾 [Langkah 3/6] Membuat snapshot cadangan basis data sebelum pembaruan...";
        try {
            $dbBackup = Database::backupDatabase();
            if ($dbBackup['success']) {
                $logs[] = "✅ Basis data berhasil dicadangkan: {$dbBackup['backup_file']} ({$dbBackup['size']})";
            } else {
                $logs[] = "⚠️ Peringatan backup db: " . ($dbBackup['message'] ?? 'Gagal membuat cadangan db');
            }
        } catch (Exception $e) {
            $logs[] = "⚠️ Peringatan backup db: " . $e->getMessage();
        }

        // ==========================================
        // 3. Unduh Paket Pembaruan ZIP dari GitHub
        // ==========================================
        $timestamp = date('Ymd_His');
        $zipDownloadPath = $backupDir . "/update_{$shortTargetSha}_{$timestamp}.zip";
        $extractPath = $backupDir . "/extracted_{$shortTargetSha}_{$timestamp}";

        $downloadUrl = "https://github.com/{$config['repo']}/archive/refs/heads/{$config['branch']}.zip";
        $logs[] = "⬇️ [Langkah 4/6] Mengunduh paket kode terbaru dari GitHub...";

        self::downloadFile($downloadUrl, $zipDownloadPath, $config['token']);
        $logs[] = "✅ Paket update berhasil diunduh (" . round(filesize($zipDownloadPath) / 1024, 1) . " KB).";

        // ==========================================
        // 4. Ekstraksi & Deployment Berkas Terproteksi
        // ==========================================
        $logs[] = "📦 [Langkah 5/6] Mengekstrak paket dan menerapkan berkas baru secara aman...";
        $zip = new ZipArchive();
        if ($zip->open($zipDownloadPath) !== true) {
            throw new Exception("Gagal membuka berkas zip update yang telah diunduh.");
        }

        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }
        $zip->extractTo($extractPath);
        $zip->close();

        // Cari folder root di dalam arsip hasil ekstraksi (biasanya {repo}-{branch})
        $extractedItems = scandir($extractPath);
        $sourceDir = $extractPath;
        foreach ($extractedItems as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($extractPath . '/' . $item)) {
                $sourceDir = $extractPath . '/' . $item;
                break;
            }
        }

        // Salin berkas baru dengan PROTEKSI KETAT berkas runtime
        $copiedCount = self::deployFilesSafely($sourceDir, $rootDir, $logs);
        $logs[] = "✅ Berhasil memperbarui {$copiedCount} berkas & aset sistem ke web root.";

        // Sinkronisasi repositori cPanel jika folder ada dan dapat diakses
        if (!empty($config['cpanel_path']) && is_dir($config['cpanel_path'])) {
            $logs[] = "🔄 Mendeteksi repositori cPanel lokal di: {$config['cpanel_path']}";
            try {
                if (self::isGitCliAvailable()) {
                    $cmd = "cd " . escapeshellarg($config['cpanel_path']) . " && git pull origin " . escapeshellarg($config['branch']) . " 2>&1";
                    $gitOut = @shell_exec($cmd);
                    $logs[] = "🌿 cPanel Git sync: " . trim((string)$gitOut);
                } else {
                    // Fallback copy ke repo cPanel agar HEAD commit sinkron
                    self::deployFilesSafely($sourceDir, $config['cpanel_path'], $logs, false);
                    $logs[] = "🌿 Berkas repositori cPanel berhasil disinkronkan secara aman.";
                }
            } catch (Exception $e) {
                $logs[] = "⚠️ Sinkronisasi repositori cPanel diabaikan: " . $e->getMessage();
            }
        }

        // ==========================================
        // 5. Eksekusi Migrasi Database Terstruktur
        // ==========================================
        $logs[] = "🛠️ [Langkah 6/6] Memeriksa dan mengeksekusi migrasi struktur basis data...";
        try {
            $migrationResult = Database::executePendingMigrations();
            if ($migrationResult['count'] > 0) {
                foreach ($migrationResult['logs'] as $mLog) {
                    $logs[] = "  " . $mLog;
                }
                $logs[] = "✅ Sebanyak {$migrationResult['count']} migrasi database sukses diterapkan.";
            } else {
                $logs[] = "✅ Struktur tabel dan kolom database sudah sesuai dan mutakhir.";
            }
        } catch (Exception $e) {
            $logs[] = "❌ Gagal migrasi database: " . $e->getMessage();
            throw new Exception("Pembaruan berkas sukses, namun migrasi database menemui kendala: " . $e->getMessage());
        }

        // Safety net sync check untuk kolom baru tambahan
        try {
            $safetyLogs = Database::syncSchemaSafetyCheck();
            if (!empty($safetyLogs)) {
                foreach ($safetyLogs as $sLog) {
                    $logs[] = "  ➕ [Schema Check] " . $sLog;
                }
            }
        } catch (Exception $e) {
            // Non-blocking
        }

        // ==========================================
        // 6. Finalisasi: Metadata, OPcache, Bersih-bersih
        // ==========================================
        $deployedAt = date('Y-m-d H:i:s');
        Database::saveSetting('APP_CURRENT_COMMIT', $targetSha);
        Database::saveSetting('APP_COMMIT_MESSAGE', $remote['message']);
        Database::saveSetting('APP_LAST_DEPLOYED_AT', $deployedAt);

        // Perbarui version.json
        $versionPayload = [
            'version'      => '2.0.0',
            'commit'       => $targetSha,
            'short_commit' => $shortTargetSha,
            'message'      => $remote['message'],
            'author'       => $remote['author'],
            'deployed_at'  => $deployedAt
        ];
        @file_put_contents($rootDir . '/version.json', json_encode($versionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Flush OPcache bytecode cache jika tersedia
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $logs[] = "⚡ OPcache PHP berhasil direset (kode baru segera aktif).";
        }
        @clearstatcache(true);

        // Bersihkan berkas temporer unduhan & ekstraksi
        self::deleteDirectoryRecursive($extractPath);
        @unlink($zipDownloadPath);

        $elapsed = round(microtime(true) - $startTime, 2);
        $logs[] = "🎉 DEPLOYMENT SELESAI DENGAN SUKSES! (Waktu: {$elapsed} detik)";

        return [
            'success'      => true,
            'commit'       => $targetSha,
            'short_commit' => $shortTargetSha,
            'message'      => $remote['message'],
            'deployed_at'  => $deployedAt,
            'elapsed_sec'  => $elapsed,
            'logs'         => $logs
        ];
    }

    /**
     * Mengunduh berkas melalui cURL secara streaming
     */
    private static function downloadFile(string $url, string $destPath, string $token = ''): void {
        $fp = fopen($destPath, 'w+');
        if (!$fp) {
            throw new Exception("Tidak dapat membuka file tujuan untuk menulis: {$destPath}");
        }

        $ch = curl_init($url);
        $headers = [
            'User-Agent: AsistenPenilaianMandiri-Updater/2.0'
        ];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400) {
            @unlink($destPath);
            throw new Exception("Gagal mengunduh berkas dari {$url} (HTTP {$httpCode}): {$err}");
        }
    }

    /**
     * Menyalin berkas baru dengan proteksi mutlak terhadap berkas pengguna & database runtime
     */
    private static function deployFilesSafely(string $sourceDir, string $targetDir, array &$logs, bool $verbose = true): int {
        // DAFTAR HITAM: Berkas/Folder ini TIDAK BOLEH DITIMPA atau DIHAPUS
        $protected = [
            'config.php',
            'database.sqlite',
            'uploads',
            'backups',
            '.env',
            '.git'
        ];

        $copied = 0;
        $items = scandir($sourceDir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Lewati berkas terproteksi
            if (in_array(strtolower($item), $protected, true)) {
                continue;
            }

            $src = $sourceDir . '/' . $item;
            $dst = $targetDir . '/' . $item;

            if (is_dir($src)) {
                $copied += self::copyDirectoryRecursive($src, $dst, $protected);
            } else {
                if (copy($src, $dst)) {
                    @chmod($dst, 0644);
                    $copied++;
                }
            }
        }

        return $copied;
    }

    /**
     * Salin direktori secara rekursif dengan filter proteksi
     */
    private static function copyDirectoryRecursive(string $src, string $dst, array $protected): int {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }

        $count = 0;
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (in_array(strtolower($item), $protected, true)) {
                continue;
            }

            $sourceItem = $src . '/' . $item;
            $targetItem = $dst . '/' . $item;

            if (is_dir($sourceItem)) {
                $count += self::copyDirectoryRecursive($sourceItem, $targetItem, $protected);
            } else {
                if (copy($sourceItem, $targetItem)) {
                    @chmod($targetItem, 0644);
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Hapus direktori secara rekursif
     */
    private static function deleteDirectoryRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                self::deleteDirectoryRecursive($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
