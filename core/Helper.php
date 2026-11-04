<?php
/**
 * @fileoverview Utilitas & Fungsi Bantuan Sistem
 */

class Helper {
    /**
     * Mengirim respons JSON terstandarisasi dan mengakhiri eksekusi
     */
    public static function json(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Menghasilkan ID unik dengan prefix
     */
    public static function generateId(string $prefix = 'ID'): string {
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $time = strtoupper(base_convert((string)time(), 10, 36));
        return "{$prefix}-{$time}-{$rand}";
    }

    /**
     * Sensor API Key untuk keamanan tampilan UI
     */
    public static function maskApiKey(?string $key): string {
        if (!$key || strlen($key) < 8) return '********';
        return substr($key, 0, 4) . '****************' . substr($key, -4);
    }

    /**
     * Ekstrak ID folder Google Drive dari string URL atau ID mentah
     */
    public static function extractDriveFolderId(?string $input): string {
        if (!$input) return '';
        $trimmed = trim((string)$input);

        // 1. Pola standar URL folder Google Drive: /folders/FOLDER_ID
        if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $trimmed, $m)) {
            return $m[1];
        }

        // 2. Pola URL open?id=FOLDER_ID atau ?id=FOLDER_ID atau &id=FOLDER_ID
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $trimmed, $m)) {
            return $m[1];
        }

        // 3. Pola path URL: ambil segmen terakhir sebelum query params (?) atau fragment (#)
        $clean = preg_replace('/\/+$/', '', explode('#', explode('?', $trimmed)[0])[0]);
        if (str_contains($clean, '/')) {
            $parts = explode('/', $clean);
            $lastPart = end($parts);
            if ($lastPart && preg_match('/^[a-zA-Z0-9_-]+$/', $lastPart) && strlen($lastPart) >= 10) {
                return $lastPart;
            }
        }

        // 4. Input langsung ID folder (string alfanumerik)
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $clean)) {
            return $clean;
        }

        return $clean;
    }

    /**
     * Ekstrak file ID dari URL berkas Google Drive
     */
    public static function extractDriveFileId(?string $input): ?string {
        if (!$input) return null;
        $trimmed = trim((string)$input);

        if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/', $trimmed, $m)) return $m[1];
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $trimmed, $m)) return $m[1];
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $trimmed, $m)) return $m[1];
        if (strlen($trimmed) > 24 && !str_contains($trimmed, '/') && !str_contains($trimmed, ' ')) {
            return $trimmed;
        }
        return null;
    }

    /**
     * Sanitasi teks umum
     */
    public static function sanitize(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}
