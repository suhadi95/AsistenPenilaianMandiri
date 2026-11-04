<?php
/**
 * @fileoverview Layanan Pengelolaan Pengguna, Autentikasi Sesi, & Otorisasi RBAC (Admin / Dosen)
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';

class UserService {
    /**
     * Memulai sesi PHP jika belum aktif
     */
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Konfigurasi session cookie yang aman
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            session_start();
        }
    }

    /**
     * Proses Login Pengguna (Email & Password)
     */
    public static function login(string $email, string $password): array {
        self::startSession();

        $email = strtolower(trim($email));
        if (empty($email) || empty($password)) {
            throw new Exception("Email dan kata sandi wajib diisi.");
        }

        $user = Database::fetchOne("SELECT * FROM users WHERE LOWER(email) = ?", [$email]);
        if (!$user) {
            throw new Exception("Email atau kata sandi tidak sesuai.");
        }

        if ($user['status'] === 'inactive' || $user['status'] === 'nonaktif') {
            throw new Exception("Akun Anda telah dinonaktifkan oleh Administrator.");
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception("Email atau kata sandi tidak sesuai.");
        }

        // Catat waktu login terakhir
        Database::execute("UPDATE users SET last_login_at = ? WHERE id_user = ?", [date('Y-m-d H:i:s'), $user['id_user']]);

        // Simpan sesi
        $_SESSION['user_id'] = $user['id_user'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_nama'] = $user['nama'];

        $updated = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$user['id_user']]);
        return self::sanitizeUser($updated);
    }

    /**
     * Mengakhiri sesi pengguna (Logout)
     */
    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Mengambil data pengguna yang sedang login saat ini
     */
    public static function getCurrentUser(): ?array {
        self::startSession();
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return null;

        $user = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        if (!$user || $user['status'] === 'inactive' || $user['status'] === 'nonaktif') {
            return null;
        }

        return $user;
    }

    /**
     * Memastikan request berasal dari pengguna yang sudah terautentikasi
     */
    public static function requireAuth(): array {
        $user = self::getCurrentUser();
        if (!$user) {
            throw new Exception("Sesi login Anda telah berakhir. Silakan login kembali.", 401);
        }
        return $user;
    }

    /**
     * Memastikan request berasal dari Administrator
     */
    public static function requireAdmin(): array {
        $user = self::requireAuth();
        if ($user['role'] !== 'admin') {
            throw new Exception("Akses ditolak: Hanya Administrator yang dapat mengakses menu ini.", 403);
        }
        return $user;
    }

    /**
     * Format data user aman tanpa mengekspos hash kata sandi atau full token
     */
    public static function sanitizeUser(array $user): array {
        $hasKey = !empty($user['gemini_api_key']);
        $hasGoogle = !empty($user['google_refresh_token']) || !empty($user['google_access_token']);

        return [
            'id_user'                => $user['id_user'],
            'nama'                   => $user['nama'] ?? '',
            'nama_lengkap'           => $user['nama'] ?? '',
            'email'                  => $user['email'] ?? '',
            'role'                   => $user['role'] ?? 'dosen',
            'status'                 => $user['status'] ?? 'active',
            'hasGeminiApiKey'        => $hasKey,
            'hasApiKey'              => $hasKey,
            'geminiApiKey'           => $hasKey ? Helper::maskApiKey($user['gemini_api_key']) : '',
            'modelName'              => $user['model_name'] ?: 'gemini-3.5-flash-lite',
            'model_name'             => $user['model_name'] ?: 'gemini-3.5-flash-lite',
            'isGoogleConnected'      => $hasGoogle,
            'hasDriveConnected'      => $hasGoogle,
            'googleConnectedEmail'   => $user['google_connected_email'] ?: '',
            'google_connected_email' => $user['google_connected_email'] ?: '',
            'isImpersonating'        => !empty($_SESSION['impersonator_admin_id']),
            'impersonatorAdminId'    => $_SESSION['impersonator_admin_id'] ?? null,
            'lastLoginAt'            => !empty($user['last_login_at']) ? date('d/m/Y H:i', strtotime($user['last_login_at'])) : '-',
            'createdAt'              => !empty($user['created_at']) ? date('d/m/Y', strtotime($user['created_at'])) : '-',
            'created_at'             => !empty($user['created_at']) ? date('d/m/Y', strtotime($user['created_at'])) : '-'
        ];
    }

    /**
     * Mengambil seluruh daftar pengguna (Khusus Admin)
     */
    public static function getAllUsers(): array {
        self::requireAdmin();
        $users = Database::fetchAll("SELECT * FROM users ORDER BY created_at DESC");

        // Ambil jumlah mata kuliah per user
        $counts = Database::fetchAll("SELECT id_user, COUNT(*) as total_courses FROM courses GROUP BY id_user");
        $courseCountMap = [];
        foreach ($counts as $c) {
            if (!empty($c['id_user'])) {
                $courseCountMap[$c['id_user']] = (int)$c['total_courses'];
            }
        }

        return array_map(function ($u) use ($courseCountMap) {
            $sanitized = self::sanitizeUser($u);
            $sanitized['totalCourses'] = $courseCountMap[$u['id_user']] ?? 0;
            return $sanitized;
        }, $users);
    }

    /**
     * Membuat pengguna baru (Khusus Admin)
     */
    public static function createUser(array $data): array {
        self::requireAdmin();

        $nama = trim($data['nama'] ?? ($data['nama_lengkap'] ?? ''));
        $email = strtolower(trim($data['email'] ?? ''));
        $password = trim($data['password'] ?? '');
        $role = in_array($data['role'] ?? '', ['admin', 'dosen'], true) ? $data['role'] : 'dosen';
        $status = in_array($data['status'] ?? '', ['active', 'aktif', 'inactive', 'nonaktif'], true) ? ($data['status'] === 'nonaktif' ? 'inactive' : 'active') : 'active';

        if (empty($nama) || empty($email) || empty($password)) {
            throw new Exception("Nama, email, dan kata sandi wajib diisi.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Format email tidak valid.");
        }

        if (strlen($password) < 6) {
            throw new Exception("Kata sandi minimal 6 karakter.");
        }

        // Cek duplikasi email
        $existing = Database::fetchOne("SELECT id_user FROM users WHERE LOWER(email) = ?", [$email]);
        if ($existing) {
            throw new Exception("Email '{$email}' sudah terdaftar pada pengguna lain.");
        }

        $userId = 'USR-' . strtoupper(substr(uniqid(), -6));
        $hash = password_hash($password, PASSWORD_DEFAULT);

        Database::execute(
            "INSERT INTO users (id_user, nama, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $nama, $email, $hash, $role, $status, date('Y-m-d H:i:s')]
        );

        $created = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        return self::sanitizeUser($created);
    }

    /**
     * Memperbarui data pengguna (Khusus Admin)
     */
    public static function updateUser(string $userId, array $data): array {
        self::requireAdmin();

        $target = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        if (!$target) throw new Exception("Pengguna tidak ditemukan.");

        $nama = trim($data['nama'] ?? ($data['nama_lengkap'] ?? $target['nama']));
        $email = strtolower(trim($data['email'] ?? $target['email']));
        $role = in_array($data['role'] ?? '', ['admin', 'dosen'], true) ? $data['role'] : $target['role'];
        $status = in_array($data['status'] ?? '', ['active', 'aktif', 'inactive', 'nonaktif'], true) ? ($data['status'] === 'nonaktif' ? 'inactive' : 'active') : $target['status'];

        if (empty($nama) || empty($email)) {
            throw new Exception("Nama dan email tidak boleh kosong.");
        }

        // Cek jika email diubah ke email user lain
        $duplicate = Database::fetchOne("SELECT id_user FROM users WHERE LOWER(email) = ? AND id_user != ?", [$email, $userId]);
        if ($duplicate) {
            throw new Exception("Email '{$email}' sudah digunakan pengguna lain.");
        }

        // Update dasar
        Database::execute(
            "UPDATE users SET nama = ?, email = ?, role = ?, status = ? WHERE id_user = ?",
            [$nama, $email, $role, $status, $userId]
        );

        // Update password jika diisi
        if (!empty($data['password'])) {
            $pwd = trim($data['password']);
            if (strlen($pwd) < 6) throw new Exception("Kata sandi baru minimal 6 karakter.");
            Database::execute("UPDATE users SET password_hash = ? WHERE id_user = ?", [password_hash($pwd, PASSWORD_DEFAULT), $userId]);
        }

        $updated = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        return self::sanitizeUser($updated);
    }

    /**
     * Menghapus pengguna (Khusus Admin)
     */
    public static function deleteUser(string $userId): array {
        $admin = self::requireAdmin();
        if ($admin['id_user'] === $userId) {
            throw new Exception("Anda tidak dapat menghapus akun Anda sendiri.");
        }

        $target = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        if (!$target) throw new Exception("Pengguna tidak ditemukan.");

        // Hapus pengguna
        Database::execute("DELETE FROM users WHERE id_user = ?", [$userId]);

        return [
            'success' => true,
            'message' => "Pengguna '{$target['nama']}' berhasil dihapus."
        ];
    }

    /**
     * Memperbarui profil pengguna sendiri (Nama, Email, & Password)
     */
    public static function updateOwnProfile(string $userId, array $data): array {
        $current = self::requireAuth();
        if ($current['id_user'] !== $userId) {
            throw new Exception("Akses ditolak.");
        }

        $nama = trim($data['nama'] ?? ($data['nama_lengkap'] ?? ''));
        $email = strtolower(trim($data['email'] ?? ''));

        if (empty($nama)) throw new Exception("Nama tidak boleh kosong.");
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Format email tidak valid.");
        }

        if (!empty($email) && $email !== strtolower($current['email'])) {
            $duplicate = Database::fetchOne("SELECT id_user FROM users WHERE LOWER(email) = ? AND id_user != ?", [$email, $userId]);
            if ($duplicate) throw new Exception("Email '{$email}' sudah digunakan oleh pengguna lain.");
            Database::execute("UPDATE users SET nama = ?, email = ? WHERE id_user = ?", [$nama, $email, $userId]);
            $_SESSION['user_email'] = $email;
        } else {
            Database::execute("UPDATE users SET nama = ? WHERE id_user = ?", [$nama, $userId]);
        }
        $_SESSION['user_nama'] = $nama;

        if (!empty($data['password'])) {
            $pwd = trim($data['password']);
            if (strlen($pwd) < 6) throw new Exception("Kata sandi baru minimal 6 karakter.");
            Database::execute("UPDATE users SET password_hash = ? WHERE id_user = ?", [password_hash($pwd, PASSWORD_DEFAULT), $userId]);
        }

        $updated = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        return self::sanitizeUser($updated);
    }

    /**
     * Masuk sebagai pengguna lain (Impersonation / Login As - Khusus Admin)
     */
    public static function impersonateUser(string $targetUserId): array {
        self::startSession();

        $adminId = $_SESSION['impersonator_admin_id'] ?? $_SESSION['user_id'] ?? null;
        if (!$adminId) {
            throw new Exception("Sesi login tidak valid.", 401);
        }

        $admin = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$adminId]);
        if (!$admin || $admin['role'] !== 'admin' || $admin['status'] !== 'active') {
            throw new Exception("Hanya Administrator yang memiliki wewenang untuk masuk sebagai pengguna lain.", 403);
        }

        if ($targetUserId === $adminId) {
            throw new Exception("Anda sudah berada pada akun administrator ini.");
        }

        $targetUser = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$targetUserId]);
        if (!$targetUser) {
            throw new Exception("Pengguna tujuan tidak ditemukan.");
        }

        if ($targetUser['status'] === 'inactive' || $targetUser['status'] === 'nonaktif') {
            throw new Exception("Akun pengguna ini sedang dinonaktifkan.");
        }

        // Catat admin ID penyamar dan alihkan sesi aktif ke pengguna tujuan
        $_SESSION['impersonator_admin_id'] = $adminId;
        $_SESSION['user_id'] = $targetUser['id_user'];
        $_SESSION['user_role'] = $targetUser['role'];
        $_SESSION['user_email'] = $targetUser['email'];
        $_SESSION['user_nama'] = $targetUser['nama'];

        $sanitized = self::sanitizeUser($targetUser);
        $sanitized['isImpersonating'] = true;
        return $sanitized;
    }

    /**
     * Kembali ke akun Administrator semula dari mode penyamaran (Exit Impersonation)
     */
    public static function revertImpersonation(): array {
        self::startSession();

        $adminId = $_SESSION['impersonator_admin_id'] ?? null;
        if (!$adminId) {
            throw new Exception("Anda saat ini tidak sedang dalam mode penyamaran.");
        }

        $admin = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$adminId]);
        if (!$admin || $admin['status'] !== 'active') {
            throw new Exception("Akun administrator tidak ditemukan atau nonaktif.");
        }

        // Pulihkan sesi ke administrator semula
        $_SESSION['user_id'] = $admin['id_user'];
        $_SESSION['user_role'] = $admin['role'];
        $_SESSION['user_email'] = $admin['email'];
        $_SESSION['user_nama'] = $admin['nama'];
        unset($_SESSION['impersonator_admin_id']);

        $sanitized = self::sanitizeUser($admin);
        $sanitized['isImpersonating'] = false;
        return $sanitized;
    }

    /**
     * Memperbarui konfigurasi Gemini AI milik pengguna sendiri (BYOK)
     */
    public static function updateOwnAiSettings(string $userId, string $geminiApiKey, string $modelName): array {
        $current = self::requireAuth();
        if ($current['id_user'] !== $userId) {
            throw new Exception("Akses ditolak.");
        }

        $modelName = trim($modelName) ?: 'gemini-3.5-flash-lite';
        $apiKey = trim($geminiApiKey);

        if (!empty($apiKey) && !str_contains($apiKey, '****')) {
            Database::execute("UPDATE users SET gemini_api_key = ?, model_name = ? WHERE id_user = ?", [$apiKey, $modelName, $userId]);
        } else {
            Database::execute("UPDATE users SET model_name = ? WHERE id_user = ?", [$modelName, $userId]);
        }

        $updated = Database::fetchOne("SELECT * FROM users WHERE id_user = ?", [$userId]);
        return self::sanitizeUser($updated);
    }
}
