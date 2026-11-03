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

        // Simpan token khusus ke profil pengguna aktif
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

        // Simpan juga ke settings sebagai fallback global
        Database::saveSetting('GOOGLE_ACCESS_TOKEN', $json['access_token']);
        if (!empty($refreshToken)) {
            Database::saveSetting('GOOGLE_REFRESH_TOKEN', $refreshToken);
        }
        Database::saveSetting('GOOGLE_TOKEN_EXPIRES_AT', (string)$expiresAt);
        if ($userEmail) {
            Database::saveSetting('GOOGLE_CONNECTED_EMAIL', $userEmail);
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
        $token = null;
        $expiresAt = 0;
        $refreshToken = null;

        // 1. Cek di profil pengguna
        if (!empty($targetUser)) {
            $u = Database::fetchOne("SELECT google_access_token, google_token_expires_at, google_refresh_token FROM users WHERE id_user = ?", [$targetUser]);
            if ($u) {
                $token = $u['google_access_token'] ?: null;
                $expiresAt = (int)($u['google_token_expires_at'] ?: 0);
                $refreshToken = $u['google_refresh_token'] ?: null;
            }
        }

        // 2. Fallback ke settings global
        if (empty($token) && empty($refreshToken)) {
            $token = Database::getSetting('GOOGLE_ACCESS_TOKEN');
            $expiresAt = (int)(Database::getSetting('GOOGLE_TOKEN_EXPIRES_AT') ?: 0);
            $refreshToken = Database::getSetting('GOOGLE_REFRESH_TOKEN');
        }

        if (empty($token) && empty($refreshToken)) {
            return null;
        }

        // Jika token masih berlaku dengan sisa waktu > 60 detik
        if (!empty($token) && ($expiresAt - time() > 60)) {
            return $token;
        }

        // Segarkan token menggunakan refresh_token
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

            Database::saveSetting('GOOGLE_ACCESS_TOKEN', $newAccess);
            Database::saveSetting('GOOGLE_TOKEN_EXPIRES_AT', (string)$expiresAt);
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

        // Jika tidak ada user spesifik, hapus global
        if (empty($targetUser)) {
            Database::saveSetting('GOOGLE_ACCESS_TOKEN', '');
            Database::saveSetting('GOOGLE_REFRESH_TOKEN', '');
            Database::saveSetting('GOOGLE_TOKEN_EXPIRES_AT', '0');
            Database::saveSetting('GOOGLE_CONNECTED_EMAIL', '');
        }

        return true;
    }

    /**
     * Mengambil ringkasan status integrasi OAuth
     */
    public static function getOAuthStatus(?string $userId = null): array {
        $targetUser = self::getTargetUserId($userId);
        $connected = self::isConnected($targetUser);
        $email = '';

        if (!empty($targetUser)) {
            $u = Database::fetchOne("SELECT google_connected_email FROM users WHERE id_user = ?", [$targetUser]);
            $email = $u['google_connected_email'] ?? '';
        }
        if (empty($email)) {
            $email = Database::getSetting('GOOGLE_CONNECTED_EMAIL') ?: '';
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
    public static function listFilesInFolder(string $folderId): array {
        $token = self::getValidAccessToken();
        if (!$token) {
            throw new Exception("Akun Google belum terhubung atau token otorisasi kedaluwarsa. Silakan hubungkan kembali di tab Pengaturan.");
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
            $userEmail = Database::getSetting('GOOGLE_CONNECTED_EMAIL') ?: 'akun Anda';
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
    public static function downloadFile(string $fileId, string $targetPath, string $mimeType = ''): bool {
        $token = self::getValidAccessToken();
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
    public static function syncDriveFolderToLocal(string $taskId, string $classId, string $folderId): array {
        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $cleanFolderId = Helper::extractDriveFolderId($folderId);
        if (empty($cleanFolderId)) {
            throw new Exception("ID Folder Google Drive tidak valid ('{$folderId}').");
        }

        $files = self::listFilesInFolder($cleanFolderId);
        if (empty($files)) {
            return [
                'success'      => true,
                'totalInDrive' => 0,
                'syncedCount'  => 0,
                'skippedCount' => 0,
                'files'        => [],
                'message'      => "Folder Google Drive terhubung, namun saat ini belum ada berkas tugas mahasiswa di dalamnya."
            ];
        }

        $destDir = FileService::getSubmissionsDir($taskId, $classId);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $syncedCount = 0;
        $skippedCount = 0;
        $syncedFiles = [];

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $fileId = $file['id'] ?? '';
            $mimeType = $file['mimeType'] ?? '';
            $driveSize = isset($file['size']) ? (int)$file['size'] : null;

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

            $targetPath = "{$destDir}/{$cleanName}";

            // Jika berkas sudah ada secara lokal dengan ukuran yang valid, lewati download ulang
            if (file_exists($targetPath) && filesize($targetPath) > 0) {
                if ($driveSize === null || filesize($targetPath) === $driveSize) {
                    $syncedCount++;
                    $syncedFiles[] = $cleanName;
                    continue;
                }
            }

            // Unduh berkas
            if (self::downloadFile($fileId, $targetPath, $mimeType)) {
                $syncedCount++;
                $syncedFiles[] = $cleanName;
            } else {
                $skippedCount++;
            }
        }

        return [
            'success'      => true,
            'totalInDrive' => count($files),
            'syncedCount'  => $syncedCount,
            'skippedCount' => $skippedCount,
            'files'        => $syncedFiles,
            'message'      => "Berhasil menyinkronkan {$syncedCount} berkas langsung dari Google Drive!"
        ];
    }

    /**
     * Sinkronisasi seluruh isi folder Google Drive tugas utama dan mendistribusikannya ke seluruh kelas
     */
    public static function syncDriveFolderForTaskAllClasses(string $taskId, string $courseId, string $folderId): array {
        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $cleanFolderId = Helper::extractDriveFolderId($folderId);
        if (empty($cleanFolderId)) {
            throw new Exception("ID Folder Google Drive tugas belum diisi atau tidak valid.");
        }

        $files = self::listFilesInFolder($cleanFolderId);
        if (empty($files)) {
            return [
                'success'      => true,
                'totalInDrive' => 0,
                'syncedCount'  => 0,
                'files'        => [],
                'message'      => "Folder Google Drive terhubung, namun saat ini belum ada berkas tugas mahasiswa di dalamnya."
            ];
        }

        // Simpan ke direktori bersama (shared)
        $sharedDir = FileService::getSharedSubmissionsDir($taskId);
        if (!is_dir($sharedDir)) {
            @mkdir($sharedDir, 0755, true);
        }

        $syncedCount = 0;
        $syncedFiles = [];

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $fileId = $file['id'] ?? '';
            $mimeType = $file['mimeType'] ?? '';
            $driveSize = isset($file['size']) ? (int)$file['size'] : null;

            if (empty($fileId) || empty($name)) continue;
            if ($mimeType === 'application/vnd.google-apps.folder') continue;

            $cleanName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name);
            if ($mimeType === 'application/vnd.google-apps.document') {
                $cleanName = preg_replace('/\.docx?$/i', '', $cleanName) . '.pdf';
            }

            $targetPath = "{$sharedDir}/{$cleanName}";

            if (file_exists($targetPath) && filesize($targetPath) > 0) {
                if ($driveSize === null || filesize($targetPath) === $driveSize) {
                    $syncedCount++;
                    $syncedFiles[] = $cleanName;
                    continue;
                }
            }

            if (self::downloadFile($fileId, $targetPath, $mimeType)) {
                $syncedCount++;
                $syncedFiles[] = $cleanName;
            }
        }

        // Ambil kelas-kelas di bawah mata kuliah ini
        $classes = Database::fetchAll("SELECT id_kelas, nama_kelas FROM classes WHERE id_mata_kuliah = ?", [$courseId]);
        $updatedClasses = [];

        foreach ($classes as $c) {
            $cId = $c['id_kelas'];
            // Cek apakah kelas ini punya folder khusus sendiri
            $override = Database::fetchOne("SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $cId]);
            if (!empty($override['drive_folder_id']) && trim($override['drive_folder_id']) !== $cleanFolderId) {
                continue; // Jangan timpa kelas yang punya folder terpisah
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
            $updatedClasses[] = $c['nama_kelas'];
        }

        $classListText = !empty($updatedClasses) ? implode(', ', $updatedClasses) : 'seluruh kelas';

        return [
            'success'        => true,
            'totalInDrive'   => count($files),
            'syncedCount'    => $syncedCount,
            'files'          => $syncedFiles,
            'updatedClasses' => $updatedClasses,
            'message'        => "Berhasil menyinkronkan {$syncedCount} berkas dari Google Drive untuk kelas: {$classListText}!"
        ];
    }
}
