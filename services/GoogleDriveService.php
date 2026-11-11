<?php
/**
 * @fileoverview Layanan Integrasi Google OAuth 2.0 & Google Drive API v3
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/FileService.php';

class GoogleDriveService {
    const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const DRIVE_API_URL   = 'https://www.googleapis.com/drive/v3';
    const USERINFO_URL    = 'https://www.googleapis.com/oauth2/v2/userinfo';
    const SCOPES          = 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email';

    /**
     * Mendeteksi URL redirect resmi sistem
     */
    public static function getRedirectUri(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'apm.suhadip.com';

        // Deteksi sub-path jika aplikasi berada di subdirektori
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($dir === '/' || $dir === '') {
            return "{$scheme}://{$host}/oauth_callback.php";
        }
        return "{$scheme}://{$host}{$dir}/oauth_callback.php";
    }

    /**
     * Mengambil ID Pengguna yang menjadi target operasi token
     */
    public static function getTargetUserId(?string $userId = null): ?string {
        if (!empty($userId)) return $userId;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return null;
    }

    /**
     * Membuat tautan otorisasi Google OAuth 2.0
     */
    public static function getAuthUrl(?string $redirectUri = null, ?string $userId = null): string {
        $clientId = Database::getSetting('GOOGLE_CLIENT_ID') ?: '';
        if (empty($clientId)) {
            throw new Exception("Google Client ID belum dikonfigurasi oleh Administrator di Pengaturan Sistem.");
        }

        $uri = $redirectUri ?: self::getRedirectUri();
        $targetUser = self::getTargetUserId($userId);

        $params = [
            'client_id'              => $clientId,
            'redirect_uri'           => $uri,
            'response_type'          => 'code',
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true'
        ];

        if (!empty($targetUser)) {
            $params['state'] = $targetUser;
        }

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Menukarkan authorization code dengan access_token & refresh_token
     */
    public static function exchangeCode(string $code, ?string $redirectUri = null, ?string $userId = null): array {
        $clientId = Database::getSetting('GOOGLE_CLIENT_ID') ?: '';
        $clientSecret = Database::getSetting('GOOGLE_CLIENT_SECRET') ?: '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception("Google Client ID atau Client Secret sistem belum diatur oleh Administrator.");
        }

        $uri = $redirectUri ?: self::getRedirectUri();
        $targetUser = self::getTargetUserId($userId);

        $body = [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $uri,
            'grant_type'    => 'authorization_code'
        ];

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30
        ]);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($status !== 200 || empty($json['access_token'])) {
            $msg = $json['error_description'] ?? ($json['error'] ?? 'Gagal menukarkan kode otorisasi.');
            throw new Exception("Google OAuth Error: {$msg}");
        }

        // Ambil email akun Google terhubung
        $userEmail = self::fetchConnectedEmail($json['access_token']);
        $expiresAt = time() + ($json['expires_in'] ?? 3600);
        $refreshToken = $json['refresh_token'] ?? null;

        // Simpan token khusus ke profil pengguna aktif (terisolasi per akun)
        if (!empty($targetUser)) {
            if (!empty($refreshToken)) {
                Database::execute(
                    "UPDATE users SET google_access_token = ?, google_refresh_token = ?, google_token_expires_at = ?, google_connected_email = ? WHERE id_user = ?",
                    [$json['access_token'], $refreshToken, $expiresAt, $userEmail, $targetUser]
                );
            } else {
                Database::execute(
                    "UPDATE users SET google_access_token = ?, google_token_expires_at = ?, google_connected_email = ? WHERE id_user = ?",
                    [$json['access_token'], $expiresAt, $userEmail, $targetUser]
                );
            }
        }

        return [
            'success' => true,
            'email'   => $userEmail,
            'message' => 'Berhasil menghubungkan akun Google Drive!'
        ];
    }

    /**
     * Mengambil profil email dari token aktif
     */
    public static function fetchConnectedEmail(string $accessToken): ?string {
        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_TIMEOUT        => 15
        ]);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            $json = json_decode($res, true);
            return $json['email'] ?? null;
        }

        return null;
    }

    /**
     * Memperoleh Access Token yang valid (auto-refresh jika kedaluwarsa)
     */
    public static function getValidAccessToken(?string $userId = null): ?string {
        $targetUser = self::getTargetUserId($userId);
        if (empty($targetUser)) {
            return null;
        }

        $token = null;
        $expiresAt = 0;
        $refreshToken = null;

        // Cek EKSKLUSIF di profil pengguna aktif (tanpa fallback ke akun pengguna lain)
        $u = Database::fetchOne("SELECT google_access_token, google_token_expires_at, google_refresh_token FROM users WHERE id_user = ?", [$targetUser]);
        if ($u) {
            $token = $u['google_access_token'] ?: null;
            $expiresAt = (int)($u['google_token_expires_at'] ?: 0);
            $refreshToken = $u['google_refresh_token'] ?: null;
        }

        if (empty($token) && empty($refreshToken)) {
            return null;
        }

        // Jika token masih berlaku dengan sisa waktu > 60 detik
        if (!empty($token) && ($expiresAt - time() > 60)) {
            return $token;
        }

        // Segarkan token menggunakan refresh_token milik pengguna ini
        if (!empty($refreshToken)) {
            return self::refreshAccessToken($refreshToken, $targetUser);
        }

        return null;
    }

    /**
     * Memperbarui Access Token dari Refresh Token
     */
    private static function refreshAccessToken(string $refreshToken, ?string $userId = null): ?string {
        $clientId = Database::getSetting('GOOGLE_CLIENT_ID');
        $clientSecret = Database::getSetting('GOOGLE_CLIENT_SECRET');

        if (!$clientId || !$clientSecret) {
            return null;
        }

        $body = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token'
        ];

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30
        ]);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($status === 200 && !empty($json['access_token'])) {
            $newAccess = $json['access_token'];
            $expiresAt = time() + ($json['expires_in'] ?? 3600);

            if (!empty($userId)) {
                Database::execute(
                    "UPDATE users SET google_access_token = ?, google_token_expires_at = ? WHERE id_user = ?",
                    [$newAccess, $expiresAt, $userId]
                );
            }
            return $newAccess;
        }

        return null;
    }

    /**
     * Memeriksa apakah Google Drive terhubung
     */
    public static function isConnected(?string $userId = null): bool {
        return !empty(self::getValidAccessToken($userId));
    }

    /**
     * Memutuskan integrasi Google Drive (hapus token pengguna aktif)
     */
    public static function disconnect(?string $userId = null): bool {
        $targetUser = self::getTargetUserId($userId);
        if (!empty($targetUser)) {
            Database::execute(
                "UPDATE users SET google_access_token = NULL, google_refresh_token = NULL, google_token_expires_at = 0, google_connected_email = NULL WHERE id_user = ?",
                [$targetUser]
            );
        }

        // Hapus juga dari tabel settings untuk mencegah residu legacy
        Database::execute("DELETE FROM settings WHERE setting_key IN ('GOOGLE_ACCESS_TOKEN', 'GOOGLE_REFRESH_TOKEN', 'GOOGLE_TOKEN_EXPIRES_AT', 'GOOGLE_CONNECTED_EMAIL')");

        return true;
    }

    /**
     * Mengambil ringkasan status integrasi OAuth
     */
    public static function getOAuthStatus(?string $userId = null): array {
        $targetUser = self::getTargetUserId($userId);
        $connected = false;
        $email = '';

        if (!empty($targetUser)) {
            $connected = self::isConnected($targetUser);
            if ($connected) {
                $u = Database::fetchOne("SELECT google_connected_email FROM users WHERE id_user = ?", [$targetUser]);
                $email = $u['google_connected_email'] ?? '';
            }
        }

        $clientId = Database::getSetting('GOOGLE_CLIENT_ID') ?: '';
        $hasSecret = !empty(Database::getSetting('GOOGLE_CLIENT_SECRET'));

        return [
            'isConnected'   => $connected,
            'email'         => $email,
            'clientId'      => $clientId,
            'hasSecret'     => $hasSecret,
            'redirectUri'   => self::getRedirectUri()
        ];
    }

    /**
     * Membaca daftar berkas pada suatu folder Google Drive
     */
    public static function listFilesInFolder(string $folderId, ?string $userId = null): array {
        $token = self::getValidAccessToken($userId);
        if (!$token) {
            throw new Exception("Akun Google Drive belum terhubung atau token otorisasi kedaluwarsa. Silakan hubungkan akun Google Anda di tab Pengaturan.");
        }

        $cleanId = Helper::extractDriveFolderId($folderId);
        if (empty($cleanId)) {
            throw new Exception("ID Folder Google Drive tidak valid ('{$folderId}').");
        }

        $query = "'{$cleanId}' in parents and trashed = false";
        $fields = 'files(id, name, mimeType, size, modifiedTime)';
        $params = [
            'q'                         => $query,
            'fields'                    => $fields,
            'pageSize'                  => 1000,
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true'
        ];
        $url = self::DRIVE_API_URL . "/files?" . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Accept: application/json"
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($res === false || !empty($curlErr)) {
            throw new Exception("Gagal menghubungi Google Drive API: {$curlErr}");
        }

        $json = json_decode($res, true);
        if ($status !== 200) {
            $msg = $json['error']['message'] ?? "Kesalahan HTTP {$status} saat membaca folder Drive.";
            $targetUser = self::getTargetUserId($userId);
            $userEmail = 'akun Google Anda';
            if (!empty($targetUser)) {
                $u = Database::fetchOne("SELECT google_connected_email FROM users WHERE id_user = ?", [$targetUser]);
                if (!empty($u['google_connected_email'])) {
                    $userEmail = $u['google_connected_email'];
                }
            }
            if ($status === 403 && (str_contains(strtolower($msg), 'drive api') || str_contains(strtolower($msg), 'not been used') || str_contains(strtolower($msg), 'disabled'))) {
                $msg .= " (Harap aktifkan 'Google Drive API' di Google Cloud Console pada menu APIs & Services > Enabled APIs & Services lalu tunggu 1-2 menit).";
            } elseif ($status === 404) {
                $msg = "Folder tidak ditemukan di Google Drive atau akun {$userEmail} belum memiliki izin akses ke folder tersebut. ID Folder: {$cleanId}";
            }
            throw new Exception("Google Drive Error: {$msg}");
        }

        return $json['files'] ?? [];
    }

    /**
     * Mengunduh konten satu berkas dari Google Drive langsung
     */
    public static function downloadFile(string $fileId, string $targetPath, string $mimeType = '', ?string $userId = null): bool {
        $token = self::getValidAccessToken($userId);
        if (!$token) return false;

        // Jika Google Docs atau Sheets, ekspor ke PDF
        if ($mimeType === 'application/vnd.google-apps.document' || $mimeType === 'application/vnd.google-apps.spreadsheet') {
            $downloadUrl = self::DRIVE_API_URL . "/files/{$fileId}/export?" . http_build_query([
                'mimeType'          => 'application/pdf',
                'supportsAllDrives' => 'true'
            ]);
        } else {
            $downloadUrl = self::DRIVE_API_URL . "/files/{$fileId}?" . http_build_query([
                'alt'               => 'media',
                'supportsAllDrives' => 'true'
            ]);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($targetPath, 'w+');
        if (!$fp) return false;

        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);
        curl_close($ch);

        if ($code >= 200 && $code < 300 && file_exists($targetPath) && filesize($targetPath) > 0) {
            return true;
        }

        @unlink($targetPath);
        return false;
    }

    /**
     * Sinkronisasi seluruh isi folder Google Drive ke folder server lokal untuk kelas tertentu
     */
    public static function syncDriveFolderToLocal(string $taskId, string $classId, string $folderId, ?string $userId = null): array {
        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $cleanFolderId = Helper::extractDriveFolderId($folderId);
        if (empty($cleanFolderId)) {
            throw new Exception("ID Folder Google Drive tidak valid ('{$folderId}').");
        }

        $destDir = FileService::getSubmissionsDir($taskId, $classId);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        $sharedDir = FileService::getSharedSubmissionsDir($taskId);

        // Ambil daftar pemetaan lama sebelum download untuk mendeteksi berkas yang telah dihapus di Drive
        $oldMap = self::getDriveFileMap($taskId, $classId);

        $files = self::listFilesInFolder($cleanFolderId, $userId);
        if (!is_array($files)) {
            $files = [];
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $syncedFiles = [];
        $driveMap = [];
        $seenFiles = [];
        $currentDriveKeysLower = [];

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $fileId = $file['id'] ?? '';
            $mimeType = $file['mimeType'] ?? '';
            $driveSize = isset($file['size']) ? (int)$file['size'] : null;
            $modifiedTime = $file['modifiedTime'] ?? null;

            if (empty($fileId) || empty($name)) continue;

            // Abaikan subfolder
            if ($mimeType === 'application/vnd.google-apps.folder') {
                continue;
            }

            $cleanName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);

            // Jika Google Docs, ekspor ke format PDF
            if ($mimeType === 'application/vnd.google-apps.document') {
                $cleanName = preg_replace('/\.docx?$/i', '', $cleanName) . '.pdf';
            }

            // Jika ada berkas kembar dengan nama yang sama, berikan nomor versi agar semua versi terunduh
            if (isset($seenFiles[$cleanName])) {
                $seenFiles[$cleanName]++;
                $pi = pathinfo($cleanName);
                $extPart = !empty($pi['extension']) ? '.' . $pi['extension'] : '';
                $cleanName = $pi['filename'] . " ({$seenFiles[$cleanName]})" . $extPart;
            } else {
                $seenFiles[$cleanName] = 0;
            }

            $driveUrl = ($mimeType === 'application/vnd.google-apps.document')
                ? "https://docs.google.com/document/d/{$fileId}/edit"
                : "https://drive.google.com/file/d/{$fileId}/view";

            $driveMap[$cleanName] = [
                'fileId'       => $fileId,
                'name'         => $cleanName,
                'driveUrl'     => $driveUrl,
                'mimeType'     => $mimeType,
                'modifiedTime' => $modifiedTime
            ];
            $currentDriveKeysLower[strtolower($cleanName)] = true;

            $targetPath = "{$destDir}/{$cleanName}";

            // Jika berkas sudah ada secara lokal dengan ukuran yang valid, perbarui timestamp dan lewati download ulang
            if (file_exists($targetPath) && filesize($targetPath) > 0) {
                if ($driveSize === null || filesize($targetPath) === $driveSize) {
                    if (!empty($modifiedTime)) {
                        $mtime = strtotime($modifiedTime);
                        if ($mtime > 0) @touch($targetPath, $mtime);
                    }
                    $syncedCount++;
                    $syncedFiles[] = $cleanName;
                    continue;
                }
            }

            // Unduh berkas
            if (self::downloadFile($fileId, $targetPath, $mimeType, $userId)) {
                if (!empty($modifiedTime)) {
                    $mtime = strtotime($modifiedTime);
                    if ($mtime > 0) @touch($targetPath, $mtime);
                }
                $syncedCount++;
                $syncedFiles[] = $cleanName;
            } else {
                $skippedCount++;
            }
        }

        // Deteksi dan bersihkan berkas lokal yang telah dihapus di Google Drive
        $deletedCount = 0;
        $deletedFileNames = [];

        // 1. Periksa berkas yang ada di folder lokal $destDir
        $localFiles = is_dir($destDir) ? array_diff(scandir($destDir), ['.', '..', 'drive_files_map.json']) : [];
        $oldMapLower = [];
        foreach ($oldMap as $k => $v) {
            $oldMapLower[strtolower($k)] = $k;
        }

        foreach ($localFiles as $lf) {
            $lfLower = strtolower($lf);
            // Jika berkas lokal ini tidak ada di Google Drive saat ini
            if (!isset($currentDriveKeysLower[$lfLower])) {
                // Pastikan berkas ini memang berasal dari Drive (ada di oldMap atau grades memiliki drive_file_url)
                $isFromDrive = isset($oldMapLower[$lfLower]);
                if (!$isFromDrive) {
                    $gradeCheck = Database::fetchOne(
                        "SELECT id_nilai FROM grades WHERE id_tugas = ? AND id_kelas = ? AND LOWER(file_name) = ? AND drive_file_url IS NOT NULL AND drive_file_url != ''",
                        [$taskId, $classId, $lfLower]
                    );
                    if ($gradeCheck) {
                        $isFromDrive = true;
                    }
                }

                if ($isFromDrive) {
                    @unlink("{$destDir}/{$lf}");
                    $deletedFileNames[] = $lf;
                    $deletedCount++;
                }
            }
        }

        // 2. Periksa juga jika ada entri di oldMap yang berkasnya sudah terhapus di Drive
        foreach ($oldMap as $oldName => $oldItem) {
            $oldLower = strtolower($oldName);
            if (!isset($currentDriveKeysLower[$oldLower])) {
                if (!in_array($oldName, $deletedFileNames, true)) {
                    $deletedFileNames[] = $oldName;
                }
                $targetOld = "{$destDir}/{$oldName}";
                if (file_exists($targetOld)) {
                    @unlink($targetOld);
                    $deletedCount++;
                }
            }
        }

        // 3. Bersihkan juga dari sharedDir jika ada berkas yang telah dihapus
        if (is_dir($sharedDir) && !empty($deletedFileNames)) {
            $sharedFiles = array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']);
            foreach ($deletedFileNames as $df) {
                foreach ($sharedFiles as $sf) {
                    if (strcasecmp($sf, $df) === 0) {
                        @unlink("{$sharedDir}/{$sf}");
                    }
                }
            }
        }

        // 4. Perbarui catatan nilai mahasiswa di database untuk berkas yang terhapus dari Drive
        if (!empty($deletedFileNames)) {
            foreach ($deletedFileNames as $df) {
                $affected = Database::fetchAll(
                    "SELECT id_nilai, nim, nama_mahasiswa FROM grades WHERE id_tugas = ? AND id_kelas = ? AND LOWER(file_name) = LOWER(?)",
                    [$taskId, $classId, $df]
                );

                Database::execute(
                    "UPDATE grades 
                     SET file_name = '',
                         drive_file_url = NULL,
                         nilai_total = NULL,
                         rincian_poin = '',
                         catatan_ai = 'Berkas tugas telah dihapus dari Google Drive.',
                         similarity_score = NULL,
                         similarity_target_nim = NULL,
                         similarity_details = NULL,
                         ai_detected = 0,
                         ai_detected_details = NULL,
                         status = 'Belum Mengumpulkan',
                         waktu_penilaian = ''
                     WHERE id_tugas = ? AND id_kelas = ? AND LOWER(file_name) = LOWER(?)",
                    [$taskId, $classId, $df]
                );

                // Cek apakah mahasiswa yang bersangkutan masih memiliki berkas lain di folder yang masih tersisa
                foreach ($affected as $st) {
                    $nim = $st['nim'];
                    $nama = $st['nama_mahasiswa'];
                    $remainingFile = self::findRemainingLocalFileForStudent($destDir, $nim, $nama);
                    if ($remainingFile) {
                        $remDriveUrl = $driveMap[$remainingFile]['driveUrl'] ?? null;
                        Database::execute(
                            "UPDATE grades 
                             SET file_name = ?,
                                 drive_file_url = ?,
                                 status = 'Belum Dinilai',
                                 catatan_ai = ''
                             WHERE id_tugas = ? AND id_kelas = ? AND nim = ?",
                            [$remainingFile, $remDriveUrl, $taskId, $classId, $nim]
                        );
                    }
                }
            }
        }

        // 5. Simpan pemetaan Drive URL ke disk
        self::saveDriveFileMap($taskId, $classId, $driveMap, $deletedFileNames);

        // 6. Perbarui drive_file_url untuk berkas-berkas yang aktif
        foreach ($driveMap as $fn => $item) {
            Database::execute(
                "UPDATE grades SET drive_file_url = ? WHERE id_tugas = ? AND id_kelas = ? AND LOWER(file_name) = LOWER(?)",
                [$item['driveUrl'], $taskId, $classId, $fn]
            );
        }

        $msg = "Berhasil menyinkronkan {$syncedCount} berkas dari Google Drive!";
        if ($deletedCount > 0) {
            $msg .= " ({$deletedCount} berkas yang telah dihapus di Drive berhasil dibersihkan dari server).";
        }
        if (empty($files) && $deletedCount === 0) {
            $msg = "Folder Google Drive terhubung, namun saat ini belum ada berkas tugas mahasiswa di dalamnya.";
        }

        return [
            'success'      => true,
            'totalInDrive' => count($files),
            'syncedCount'  => $syncedCount,
            'skippedCount' => $skippedCount,
            'deletedCount' => $deletedCount,
            'files'        => $syncedFiles,
            'message'      => $msg
        ];
    }

    /**
     * Sinkronisasi seluruh isi folder Google Drive tugas utama dan mendistribusikannya ke seluruh kelas
     */
    public static function syncDriveFolderForTaskAllClasses(string $taskId, string $courseId, string $folderId, ?string $userId = null): array {
        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $cleanFolderId = Helper::extractDriveFolderId($folderId);
        if (empty($cleanFolderId)) {
            throw new Exception("ID Folder Google Drive tugas belum diisi atau tidak valid.");
        }

        // Simpan ke direktori bersama (shared)
        $sharedDir = FileService::getSharedSubmissionsDir($taskId);
        if (!is_dir($sharedDir)) {
            @mkdir($sharedDir, 0755, true);
        }

        $oldMap = self::getDriveFileMap($taskId, null);

        $files = self::listFilesInFolder($cleanFolderId, $userId);
        if (!is_array($files)) {
            $files = [];
        }

        $syncedCount = 0;
        $syncedFiles = [];
        $driveMap = [];
        $seenFiles = [];
        $currentDriveKeysLower = [];

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $fileId = $file['id'] ?? '';
            $mimeType = $file['mimeType'] ?? '';
            $driveSize = isset($file['size']) ? (int)$file['size'] : null;
            $modifiedTime = $file['modifiedTime'] ?? null;

            if (empty($fileId) || empty($name)) continue;
            if ($mimeType === 'application/vnd.google-apps.folder') continue;

            $cleanName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);
            if ($mimeType === 'application/vnd.google-apps.document') {
                $cleanName = preg_replace('/\.docx?$/i', '', $cleanName) . '.pdf';
            }

            // Jika ada berkas kembar dengan nama yang sama, berikan nomor versi agar semua versi terunduh
            if (isset($seenFiles[$cleanName])) {
                $seenFiles[$cleanName]++;
                $pi = pathinfo($cleanName);
                $extPart = !empty($pi['extension']) ? '.' . $pi['extension'] : '';
                $cleanName = $pi['filename'] . " ({$seenFiles[$cleanName]})" . $extPart;
            } else {
                $seenFiles[$cleanName] = 0;
            }

            $driveUrl = ($mimeType === 'application/vnd.google-apps.document')
                ? "https://docs.google.com/document/d/{$fileId}/edit"
                : "https://drive.google.com/file/d/{$fileId}/view";

            $driveMap[$cleanName] = [
                'fileId'       => $fileId,
                'name'         => $cleanName,
                'driveUrl'     => $driveUrl,
                'mimeType'     => $mimeType,
                'modifiedTime' => $modifiedTime
            ];
            $currentDriveKeysLower[strtolower($cleanName)] = true;

            $targetPath = "{$sharedDir}/{$cleanName}";

            if (file_exists($targetPath) && filesize($targetPath) > 0) {
                if ($driveSize === null || filesize($targetPath) === $driveSize) {
                    if (!empty($modifiedTime)) {
                        $mtime = strtotime($modifiedTime);
                        if ($mtime > 0) @touch($targetPath, $mtime);
                    }
                    $syncedCount++;
                    $syncedFiles[] = $cleanName;
                    continue;
                }
            }

            if (self::downloadFile($fileId, $targetPath, $mimeType, $userId)) {
                if (!empty($modifiedTime)) {
                    $mtime = strtotime($modifiedTime);
                    if ($mtime > 0) @touch($targetPath, $mtime);
                }
                $syncedCount++;
                $syncedFiles[] = $cleanName;
            }
        }

        // Ambil kelas-kelas di bawah mata kuliah ini
        $classes = Database::fetchAll("SELECT id_kelas, nama_kelas FROM classes WHERE id_mata_kuliah = ?", [$courseId]);

        // Deteksi dan bersihkan berkas yang telah dihapus di Drive
        $deletedCount = 0;
        $deletedFileNames = [];

        // 1. Periksa berkas di $sharedDir
        $existingShared = is_dir($sharedDir) ? array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']) : [];
        foreach ($existingShared as $sf) {
            $sfLower = strtolower($sf);
            if (!isset($currentDriveKeysLower[$sfLower])) {
                @unlink("{$sharedDir}/{$sf}");
                $deletedFileNames[] = $sf;
                $deletedCount++;
            }
        }

        // 2. Periksa entri di oldMap
        foreach ($oldMap as $oldName => $oldItem) {
            $oldLower = strtolower($oldName);
            if (!isset($currentDriveKeysLower[$oldLower])) {
                if (!in_array($oldName, $deletedFileNames, true)) {
                    $deletedFileNames[] = $oldName;
                }
                $targetOld = "{$sharedDir}/{$oldName}";
                if (file_exists($targetOld)) {
                    @unlink($targetOld);
                    $deletedCount++;
                }
            }
        }

        // 3. Bersihkan berkas terhapus dari setiap folder kelas yang menggunakan folder bersama ini
        foreach ($classes as $c) {
            $cId = $c['id_kelas'];
            $override = Database::fetchOne("SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $cId]);
            if (!empty($override['drive_folder_id']) && trim($override['drive_folder_id']) !== $cleanFolderId) {
                continue;
            }

            $cDir = FileService::getSubmissionsDir($taskId, $cId);
            if (is_dir($cDir) && !empty($deletedFileNames)) {
                $cFiles = array_diff(scandir($cDir), ['.', '..', 'drive_files_map.json']);
                foreach ($deletedFileNames as $df) {
                    foreach ($cFiles as $cf) {
                        if (strcasecmp($cf, $df) === 0) {
                            @unlink("{$cDir}/{$cf}");
                        }
                    }
                }
            }
        }

        // 4. Perbarui catatan nilai mahasiswa di database untuk berkas yang terhapus dari Drive
        if (!empty($deletedFileNames)) {
            foreach ($deletedFileNames as $df) {
                $affected = Database::fetchAll(
                    "SELECT id_nilai, id_kelas, nim, nama_mahasiswa FROM grades WHERE id_tugas = ? AND LOWER(file_name) = LOWER(?)",
                    [$taskId, $df]
                );

                Database::execute(
                    "UPDATE grades 
                     SET file_name = '',
                         drive_file_url = NULL,
                         nilai_total = NULL,
                         rincian_poin = '',
                         catatan_ai = 'Berkas tugas telah dihapus dari Google Drive.',
                         similarity_score = NULL,
                         similarity_target_nim = NULL,
                         similarity_details = NULL,
                         ai_detected = 0,
                         ai_detected_details = NULL,
                         status = 'Belum Mengumpulkan',
                         waktu_penilaian = ''
                     WHERE id_tugas = ? AND LOWER(file_name) = LOWER(?)",
                    [$taskId, $df]
                );

                // Cek apakah mahasiswa masih memiliki berkas lain yang tersisa di folder kelas atau shared
                foreach ($affected as $st) {
                    $nim = $st['nim'];
                    $nama = $st['nama_mahasiswa'];
                    $stClassId = $st['id_kelas'];
                    $stClassDir = FileService::getSubmissionsDir($taskId, $stClassId);
                    $remainingFile = self::findRemainingLocalFileForStudent($stClassDir, $nim, $nama)
                                  ?: self::findRemainingLocalFileForStudent($sharedDir, $nim, $nama);

                    if ($remainingFile) {
                        $remDriveUrl = $driveMap[$remainingFile]['driveUrl'] ?? null;
                        Database::execute(
                            "UPDATE grades 
                             SET file_name = ?,
                                 drive_file_url = ?,
                                 status = 'Belum Dinilai',
                                 catatan_ai = ''
                             WHERE id_tugas = ? AND id_kelas = ? AND nim = ?",
                            [$remainingFile, $remDriveUrl, $taskId, $stClassId, $nim]
                        );
                    }
                }
            }
        }

        // 5. Simpan pemetaan Drive URL ke disk
        self::saveDriveFileMap($taskId, null, $driveMap, $deletedFileNames);

        // 6. Perbarui drive_file_url untuk berkas-berkas yang aktif
        foreach ($driveMap as $fn => $item) {
            Database::execute(
                "UPDATE grades SET drive_file_url = ? WHERE id_tugas = ? AND LOWER(file_name) = LOWER(?)",
                [$item['driveUrl'], $taskId, $fn]
            );
        }

        // 7. Salin berkas hasil sinkronisasi ke kelas-kelas terkait
        $updatedClasses = [];
        foreach ($classes as $c) {
            $cId = $c['id_kelas'];
            $override = Database::fetchOne("SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $cId]);
            if (!empty($override['drive_folder_id']) && trim($override['drive_folder_id']) !== $cleanFolderId) {
                continue;
            }

            $cDir = FileService::getSubmissionsDir($taskId, $cId);
            if (!is_dir($cDir)) @mkdir($cDir, 0755, true);

            foreach ($syncedFiles as $sf) {
                $src = "{$sharedDir}/{$sf}";
                $dst = "{$cDir}/{$sf}";
                if (file_exists($src) && (!file_exists($dst) || filesize($src) !== filesize($dst))) {
                    @copy($src, $dst);
                }
            }
            self::saveDriveFileMap($taskId, $cId, $driveMap, $deletedFileNames);
            $updatedClasses[] = $c['nama_kelas'];
        }

        $classListText = !empty($updatedClasses) ? implode(', ', $updatedClasses) : 'seluruh kelas';

        $msg = "Berhasil menyinkronkan {$syncedCount} berkas dari Google Drive untuk kelas: {$classListText}!";
        if ($deletedCount > 0) {
            $msg .= " ({$deletedCount} berkas yang dihapus di Drive telah dibersihkan dari server).";
        }
        if (empty($files) && $deletedCount === 0) {
            $msg = "Folder Google Drive terhubung, namun saat ini belum ada berkas tugas mahasiswa di dalamnya.";
        }

        return [
            'success'        => true,
            'totalInDrive'   => count($files),
            'syncedCount'    => $syncedCount,
            'deletedCount'   => $deletedCount,
            'files'          => $syncedFiles,
            'updatedClasses' => $updatedClasses,
            'message'        => $msg
        ];
    }

    /**
     * Mencari apakah mahasiswa masih memiliki berkas jawaban lain di folder lokal
     */
    public static function findRemainingLocalFileForStudent(string $dir, string $nim, string $nama): ?string {
        if (!is_dir($dir)) return null;
        $files = array_diff(scandir($dir), ['.', '..', 'drive_files_map.json']);
        $cleanNim = strtolower(trim($nim));
        $sanitizedNim = preg_replace('/[^0-9a-z]/', '', $cleanNim);
        $cleanNama = strtolower(trim($nama));
        $nameWords = array_filter(explode(' ', $cleanNama), fn($w) => strlen(trim($w)) >= 3);

        foreach ($files as $f) {
            $fLower = strtolower($f);
            $sanitizedF = preg_replace('/[^0-9a-z]/', '', $fLower);
            if (!empty($cleanNim) && (str_contains($fLower, $cleanNim) || (strlen($sanitizedNim) >= 4 && str_contains($sanitizedF, $sanitizedNim)))) {
                return $f;
            }
            if (!empty($cleanNama) && str_contains($fLower, $cleanNama)) {
                return $f;
            }
            if (count($nameWords) >= 2) {
                $matchedWords = 0;
                foreach ($nameWords as $w) {
                    if (str_contains($fLower, $w)) $matchedWords++;
                }
                if ($matchedWords >= 2 && $matchedWords >= (count($nameWords) * 0.6)) {
                    return $f;
                }
            }
        }
        return null;
    }

    /**
     * Menyimpan pemetaan nama berkas ke Google Drive File ID & URL
     */
    public static function saveDriveFileMap(string $taskId, ?string $classId, array $fileMap, array $deletedFileNames = []): void {
        $cleanTask = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $taskDir = FileService::getUploadDir() . "/tasks/{$cleanTask}";
        if (!is_dir($taskDir)) @mkdir($taskDir, 0755, true);

        $mainMapFile = "{$taskDir}/drive_files_map.json";
        $existing = [];
        if (file_exists($mainMapFile)) {
            $existing = json_decode(file_get_contents($mainMapFile), true) ?: [];
        }

        // Hapus berkas yang telah dihapus di Drive dari map utama
        if (!empty($deletedFileNames)) {
            $deletedLower = array_map('strtolower', $deletedFileNames);
            foreach (array_keys($existing) as $k) {
                if (in_array(strtolower($k), $deletedLower, true)) {
                    unset($existing[$k]);
                }
            }
        }

        if ($classId === null) {
            // Jika sinkronisasi di tingkat tugas utama (shared), map utama adalah fileMap aktif
            $merged = $fileMap;
        } else {
            $merged = array_merge($existing, $fileMap);
        }
        @file_put_contents($mainMapFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!empty($classId)) {
            $classDir = FileService::getSubmissionsDir($taskId, $classId);
            $classMapFile = "{$classDir}/drive_files_map.json";
            @file_put_contents($classMapFile, json_encode($fileMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Mengambil pemetaan nama berkas ke Google Drive File ID & URL untuk tugas
     */
    public static function getDriveFileMap(string $taskId, ?string $classId = null): array {
        $cleanTask = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $mainMapFile = FileService::getUploadDir() . "/tasks/{$cleanTask}/drive_files_map.json";

        $map = [];
        if (file_exists($mainMapFile)) {
            $map = json_decode(file_get_contents($mainMapFile), true) ?: [];
        }

        if (!empty($classId)) {
            $classMapFile = FileService::getSubmissionsDir($taskId, $classId) . "/drive_files_map.json";
            if (file_exists($classMapFile)) {
                $classMap = json_decode(file_get_contents($classMapFile), true) ?: [];
                $map = array_merge($map, $classMap);
            }
        }

        return $map;
    }
}
