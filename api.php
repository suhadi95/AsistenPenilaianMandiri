<?php
/**
 * @fileoverview API Gateway & RESTful Dispatcher untuk Asisten Penilaian Mandiri (Multi-User SaaS)
 */

// Error handling & header CORS / JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(180);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Helper.php';
require_once __DIR__ . '/services/GeminiService.php';
require_once __DIR__ . '/services/FileService.php';
require_once __DIR__ . '/services/GradingService.php';
require_once __DIR__ . '/services/GoogleDriveService.php';
require_once __DIR__ . '/services/UserService.php';

// Pastikan sesi & database terinisialisasi
UserService::startSession();
Database::initDatabase();

// Parsing input: dukung JSON body, POST multipart, atau GET query
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = [];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    if (isset($input['action']) && empty($action)) {
        $action = $input['action'];
    }
} else {
    $input = array_merge($_GET, $_POST);
}

// Router API
try {
    switch ($action) {
        // ==========================================
        // AUTENTIKASI & MANAJEMEN SESI PENGGUNA
        // ==========================================
        case 'login':
            $email = trim($input['email'] ?? '');
            $password = trim($input['password'] ?? '');
            if (empty($email) || empty($password)) {
                throw new Exception("Email dan password wajib diisi.");
            }
            $loggedUser = UserService::login($email, $password);
            Helper::json([
                'success' => true,
                'message' => "Selamat datang kembali, {$loggedUser['nama_lengkap']}!",
                'user'    => UserService::sanitizeUser($loggedUser)
            ]);
            break;

        case 'logout':
            UserService::logout();
            Helper::json([
                'success' => true,
                'message' => 'Anda telah berhasil keluar (logout).'
            ]);
            break;

        case 'checkAuth':
            $cur = UserService::getCurrentUser();
            Helper::json([
                'success'       => true,
                'authenticated' => !empty($cur),
                'user'          => $cur ? UserService::sanitizeUser($cur) : null
            ]);
            break;

        case 'updateOwnProfile':
            $user = UserService::requireAuth();
            $updated = UserService::updateOwnProfile($user['id_user'], $input);
            Helper::json([
                'success' => true,
                'message' => 'Profil berhasil diperbarui.',
                'user'    => $updated
            ]);
            break;

        case 'updateOwnAiSettings':
            $user = UserService::requireAuth();
            $apiKey = trim($input['apiKey'] ?? $input['gemini_api_key'] ?? '');
            $modelName = trim($input['modelName'] ?? $input['model_name'] ?? '');
            $updated = UserService::updateOwnAiSettings($user['id_user'], $apiKey, $modelName);
            Helper::json([
                'success' => true,
                'message' => 'Pengaturan AI & Model berhasil diperbarui.',
                'user'    => $updated
            ]);
            break;

        // ==========================================
        // ADMIN ONLY: MANAJEMEN PENGGUNA & GLOBAL
        // ==========================================
        case 'adminGetUsers':
            UserService::requireAdmin();
            $users = UserService::getAllUsers();
            Helper::json([
                'success' => true,
                'users'   => $users
            ]);
            break;

        case 'adminSaveUser':
            UserService::requireAdmin();
            $userData = $input['userData'] ?? $input;
            $savedUser = null;
            if (!empty($userData['id_user'])) {
                $savedUser = UserService::updateUser($userData['id_user'], $userData);
                $msg = "Data pengguna '{$savedUser['nama_lengkap']}' berhasil diperbarui.";
            } else {
                $savedUser = UserService::createUser($userData);
                $msg = "Pengguna baru '{$savedUser['nama_lengkap']}' berhasil ditambahkan.";
            }
            Helper::json([
                'success' => true,
                'message' => $msg,
                'user'    => $savedUser
            ]);
            break;

        case 'adminDeleteUser':
            UserService::requireAdmin();
            $targetId = trim($input['id_user'] ?? $input['id'] ?? '');
            if (!$targetId) throw new Exception("ID Pengguna wajib disertakan.");
            UserService::deleteUser($targetId);
            Helper::json([
                'success' => true,
                'message' => 'Pengguna berhasil dihapus.'
            ]);
            break;

        case 'adminSaveGlobalOAuth':
            UserService::requireAdmin();
            $clientId = trim($input['clientId'] ?? '');
            $clientSecret = trim($input['clientSecret'] ?? '');
            if (!empty($clientId)) {
                saveSetting('GOOGLE_CLIENT_ID', $clientId);
            }
            if (!empty($clientSecret) && !str_contains($clientSecret, '****')) {
                saveSetting('GOOGLE_CLIENT_SECRET', $clientSecret);
            }
            Helper::json([
                'success' => true,
                'message' => 'Kredensial Global Google OAuth berhasil disimpan.'
            ]);
            break;

        // ==========================================
        // SISTEM & PENGATURAN
        // ==========================================
        case 'getInitialData':
            $cur = UserService::getCurrentUser();
            if (!$cur) {
                Helper::json([
                    'success'       => true,
                    'authenticated' => false,
                    'currentUser'   => null,
                    'settings'      => [
                        'availableModels' => Database::loadConfig()['gemini']['available_models'] ?? []
                    ],
                    'courses'       => [],
                    'classes'       => []
                ]);
            } else {
                $settings = getSystemSettings($cur);
                $courses = getCoursesList($cur);
                $classes = getClassesList(null, $cur);

                Helper::json([
                    'success'       => true,
                    'authenticated' => true,
                    'currentUser'   => UserService::sanitizeUser($cur),
                    'settings'      => $settings,
                    'courses'       => $courses,
                    'classes'       => $classes
                ]);
            }
            break;

        case 'getSettings':
            $cur = UserService::getCurrentUser();
            Helper::json([
                'success'  => true,
                'settings' => getSystemSettings($cur)
            ]);
            break;

        case 'saveSettings':
            $user = UserService::requireAuth();
            $data = $input['data'] ?? $input;

            // 1. Pengaturan AI Pengguna Aktif (BYOK)
            $userApiKey = null;
            $userModel = null;
            if (isset($data['apiKey']) && !str_contains($data['apiKey'], '****')) {
                $userApiKey = trim($data['apiKey']);
            }
            if (!empty($data['modelName'])) {
                $userModel = trim($data['modelName']);
            }
            if ($userApiKey !== null || $userModel !== null) {
                UserService::updateOwnAiSettings(
                    $user['id_user'],
                    $userApiKey ?? $user['gemini_api_key'] ?? '',
                    $userModel ?? $user['model_name'] ?? 'gemini-3.5-flash-lite'
                );
            }

            // 2. Preferensi Kursus / Kelas Aktif
            if (isset($data['activeCourseId'])) {
                saveSetting('ACTIVE_COURSE_ID', trim($data['activeCourseId']));
            }
            if (isset($data['activeClassId'])) {
                saveSetting('ACTIVE_CLASS_ID', trim($data['activeClassId']));
            }

            // 3. Admin Only: Konfigurasi Global OAuth Client
            if ($user['role'] === 'admin') {
                if (isset($data['googleClientId'])) {
                    saveSetting('GOOGLE_CLIENT_ID', trim($data['googleClientId']));
                }
                if (!empty($data['googleClientSecret']) && !str_contains($data['googleClientSecret'], '****')) {
                    saveSetting('GOOGLE_CLIENT_SECRET', trim($data['googleClientSecret']));
                }
            }

            Helper::json([
                'success' => true,
                'message' => 'Pengaturan berhasil disimpan!'
            ]);
            break;

        case 'getGoogleOAuthStatus':
            $cur = UserService::getCurrentUser();
            $userId = $cur ? $cur['id_user'] : null;
            Helper::json([
                'success' => true,
                'status'  => GoogleDriveService::getOAuthStatus($userId)
            ]);
            break;

        case 'getGoogleAuthUrl':
            $cur = UserService::getCurrentUser();
            $userId = $cur ? $cur['id_user'] : null;
            $redirectUri = trim($input['redirectUri'] ?? '');
            $url = GoogleDriveService::getAuthUrl($redirectUri ?: null, $userId);
            Helper::json([
                'success' => true,
                'authUrl' => $url
            ]);
            break;

        case 'disconnectGoogleDrive':
            $cur = UserService::getCurrentUser();
            $userId = $cur ? $cur['id_user'] : null;
            GoogleDriveService::disconnect($userId);
            Helper::json([
                'success' => true,
                'message' => 'Integrasi Google Drive berhasil diputuskan.'
            ]);
            break;

        case 'testGoogleDriveConnection':
            $cur = UserService::requireAuth();
            if (!GoogleDriveService::isConnected($cur['id_user'])) {
                throw new Exception("Akun Google Drive belum terhubung. Silakan klik 'Hubungkan Google Drive Saya' terlebih dahulu.");
            }
            $token = GoogleDriveService::getValidAccessToken($cur['id_user']);
            $ch = curl_init("https://www.googleapis.com/drive/v3/about?fields=user(displayName,emailAddress)");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            $res = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($res === false || !empty($curlErr)) {
                throw new Exception("Koneksi cURL gagal: {$curlErr}");
            }

            $json = json_decode($res, true);
            if ($status !== 200) {
                $msg = $json['error']['message'] ?? "HTTP {$status}";
                if ($status === 403 && (str_contains(strtolower($msg), 'drive api') || str_contains(strtolower($msg), 'not been used') || str_contains(strtolower($msg), 'disabled'))) {
                    $msg .= " -> Harap aktifkan 'Google Drive API' di Google Cloud Console pada menu APIs & Services > Enabled APIs & Services, lalu tunggu 1-2 menit.";
                }
                throw new Exception("Google Drive Error: {$msg}");
            }

            $userName = $json['user']['displayName'] ?? '';
            $userMail = $json['user']['emailAddress'] ?? ($cur['google_connected_email'] ?? 'Google Drive');
            Helper::json([
                'success' => true,
                'message' => "Koneksi Google Drive API Berhasil & Aktif! Terhubung sebagai: {$userMail}" . ($userName ? " ({$userName})" : "")
            ]);
            break;

        case 'syncTaskDriveFolder':
            $cur = UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            if (!$taskId) {
                throw new Exception("ID Tugas wajib disertakan.");
            }

            if (!GoogleDriveService::isConnected($cur['id_user'])) {
                throw new Exception("Akun Google Drive belum terhubung. Silakan hubungkan akun Google di menu Pengaturan.");
            }

            $task = Database::fetchOne("SELECT drive_folder_id, judul_tugas, id_mata_kuliah FROM tasks WHERE id_tugas = ?", [$taskId]);
            if (!$task) {
                throw new Exception("Tugas dengan ID '{$taskId}' tidak ditemukan.");
            }

            // Jika classId kosong, sinkronkan untuk seluruh kelas di mata kuliah ini
            if (empty($classId)) {
                $folderId = (!empty($input['folderId'])) ? trim($input['folderId']) : ($task['drive_folder_id'] ?? '');
                if (empty($folderId)) {
                    $taskName = $task['judul_tugas'] ?? $taskId;
                    throw new Exception("Folder Google Drive belum diatur pada tugas '{$taskName}'. Silakan edit tugas di tab 'Kelola Tugas' dan isi link/ID folder Drive.");
                }

                $syncRes = GoogleDriveService::syncDriveFolderForTaskAllClasses($taskId, $task['id_mata_kuliah'], $folderId);
                Helper::json($syncRes);
                break;
            }

            // Jika classId ada, sinkronkan spesifik untuk kelas tersebut
            $override = Database::fetchOne("SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);
            $folderId = (!empty($override['drive_folder_id'])) ? $override['drive_folder_id'] : ($task['drive_folder_id'] ?? '');

            if (empty($folderId) && !empty($input['folderId'])) {
                $folderId = trim($input['folderId']);
            }

            if (empty($folderId)) {
                $taskName = $task['judul_tugas'] ?? $taskId;
                throw new Exception("Folder Google Drive tempat tugas mahasiswa belum diisi pada tugas '{$taskName}'. Silakan edit tugas atau atur folder kelas.");
            }

            $syncRes = GoogleDriveService::syncDriveFolderToLocal($taskId, $classId, $folderId);
            Helper::json($syncRes);
            break;

        case 'getClassTaskFolder':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            if (!$taskId || !$classId) {
                throw new Exception("ID Tugas dan ID Kelas wajib disertakan.");
            }

            $task = Database::fetchOne("SELECT drive_folder_id, judul_tugas FROM tasks WHERE id_tugas = ?", [$taskId]);
            $override = Database::fetchOne("SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);

            $taskFolder = $task['drive_folder_id'] ?? '';
            $classFolder = $override['drive_folder_id'] ?? '';
            $activeFolder = !empty($classFolder) ? $classFolder : $taskFolder;

            Helper::json([
                'success'      => true,
                'taskFolder'   => $taskFolder,
                'classFolder'  => $classFolder,
                'activeFolder' => $activeFolder,
                'isOverridden' => !empty($classFolder)
            ]);
            break;

        case 'saveClassTaskFolder':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            $folderId = trim($input['folderId'] ?? '');

            if (!$taskId || !$classId) {
                throw new Exception("ID Tugas dan ID Kelas wajib disertakan.");
            }

            if (empty($folderId)) {
                Database::execute("DELETE FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);
                Helper::json([
                    'success'      => true,
                    'isOverridden' => false,
                    'message'      => 'Pengaturan folder khusus kelas dihapus. Kelas ini sekarang kembali menggunakan Folder Utama Tugas.'
                ]);
            } else {
                $cleanId = Helper::extractDriveFolderId($folderId);
                Database::execute("DELETE FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);
                Database::execute(
                    "INSERT INTO class_task_folders (id_tugas, id_kelas, drive_folder_id) VALUES (?, ?, ?)",
                    [$taskId, $classId, $cleanId]
                );
                Helper::json([
                    'success'      => true,
                    'isOverridden' => true,
                    'folderId'     => $cleanId,
                    'message'      => 'Folder Google Drive khusus kelas berhasil disimpan!'
                ]);
            }
            break;

        case 'testConnection':
            $cur = UserService::getCurrentUser();
            $key = $input['apiKey'] ?? '';
            if (empty($key) || str_contains($key, '****')) {
                if ($cur && !empty($cur['gemini_api_key'])) {
                    $key = $cur['gemini_api_key'];
                } else {
                    $saved = getSetting('GEMINI_API_KEY');
                    $key = $saved ?: '';
                }
            }
            $model = $input['model'] ?? ($cur['model_name'] ?? 'gemini-3.5-flash-lite');
            $testRes = GeminiService::testConnection($key, $model);
            Helper::json($testRes);
            break;

        case 'resetDatabase':
            UserService::requireAdmin();
            $resetRes = Database::resetDatabase();
            Helper::json($resetRes);
            break;

        // ==========================================
        // KELOLA MATA KULIAH (COURSES)
        // ==========================================
        case 'getCourses':
            $cur = UserService::requireAuth();
            Helper::json([
                'success' => true,
                'courses' => getCoursesList($cur)
            ]);
            break;

        case 'saveCourse':
            $user = UserService::requireAuth();
            $data = $input['data'] ?? $input;
            $courseId = !empty($data['id_mata_kuliah']) ? trim($data['id_mata_kuliah']) : Helper::generateId('MK');
            $kodeMk = trim($data['kode_mk'] ?? '');
            $namaMk = trim($data['nama_mata_kuliah'] ?? 'Mata Kuliah Baru');
            $semester = trim($data['semester'] ?? '');

            $existing = Database::fetchOne("SELECT id_mata_kuliah, id_user FROM courses WHERE id_mata_kuliah = ?", [$courseId]);
            if ($existing) {
                if ($user['role'] !== 'admin' && !empty($existing['id_user']) && $existing['id_user'] !== $user['id_user']) {
                    throw new Exception("Anda tidak memiliki hak akses untuk mengubah mata kuliah ini.");
                }
                Database::execute(
                    "UPDATE courses SET kode_mk = ?, nama_mata_kuliah = ?, semester = ? WHERE id_mata_kuliah = ?",
                    [$kodeMk, $namaMk, $semester, $courseId]
                );
            } else {
                Database::execute(
                    "INSERT INTO courses (id_mata_kuliah, id_user, kode_mk, nama_mata_kuliah, semester, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                    [$courseId, $user['id_user'], $kodeMk, $namaMk, $semester, date('Y-m-d H:i:s')]
                );
            }

            Helper::json([
                'success' => true,
                'data'    => [
                    'id_mata_kuliah'   => $courseId,
                    'kode_mk'          => $kodeMk,
                    'nama_mata_kuliah' => $namaMk,
                    'semester'         => $semester
                ]
            ]);
            break;

        case 'deleteCourse':
            $user = UserService::requireAuth();
            $courseId = trim($input['courseId'] ?? $input['id'] ?? '');
            if (!$courseId) throw new Exception("ID Mata Kuliah tidak valid.");

            $course = Database::fetchOne("SELECT id_mata_kuliah, id_user FROM courses WHERE id_mata_kuliah = ?", [$courseId]);
            if (!$course) throw new Exception("Mata kuliah tidak ditemukan.");

            if ($user['role'] !== 'admin' && !empty($course['id_user']) && $course['id_user'] !== $user['id_user']) {
                throw new Exception("Anda tidak memiliki hak akses untuk menghapus mata kuliah ini.");
            }

            // Hapus mahasiswa & nilai pada kelas-kelas mata kuliah ini
            $classes = Database::fetchAll("SELECT id_kelas FROM classes WHERE id_mata_kuliah = ?", [$courseId]);
            foreach ($classes as $c) {
                Database::execute("DELETE FROM students WHERE id_kelas = ?", [$c['id_kelas']]);
                Database::execute("DELETE FROM grades WHERE id_kelas = ?", [$c['id_kelas']]);
                Database::execute("DELETE FROM class_task_folders WHERE id_kelas = ?", [$c['id_kelas']]);
            }
            Database::execute("DELETE FROM tasks WHERE id_mata_kuliah = ?", [$courseId]);
            Database::execute("DELETE FROM classes WHERE id_mata_kuliah = ?", [$courseId]);
            Database::execute("DELETE FROM courses WHERE id_mata_kuliah = ?", [$courseId]);

            Helper::json([
                'success' => true,
                'message' => 'Mata kuliah dan seluruh data kelas, tugas, serta nilai terkait berhasil dihapus.'
            ]);
            break;

        // ==========================================
        // KELOLA KELAS (CLASSES)
        // ==========================================
        case 'getClasses':
            $cur = UserService::requireAuth();
            $courseId = $input['courseId'] ?? null;
            Helper::json([
                'success' => true,
                'classes' => getClassesList($courseId, $cur)
            ]);
            break;

        case 'saveClass':
            $user = UserService::requireAuth();
            $data = $input['data'] ?? $input;
            $classId = !empty($data['id_kelas']) ? trim($data['id_kelas']) : Helper::generateId('KLS');
            $courseId = trim($data['id_mata_kuliah'] ?? '');
            if (empty($courseId)) {
                $userCourses = getCoursesList($user);
                $courseId = !empty($userCourses) ? $userCourses[0]['id_mata_kuliah'] : '';
            }
            $namaKelas = trim($data['nama_kelas'] ?? 'Kelas Baru');
            $semester = trim($data['semester'] ?? '');

            $existing = Database::fetchOne("SELECT id_kelas FROM classes WHERE id_kelas = ?", [$classId]);
            if ($existing) {
                Database::execute(
                    "UPDATE classes SET id_mata_kuliah = ?, nama_kelas = ?, semester = ? WHERE id_kelas = ?",
                    [$courseId, $namaKelas, $semester, $classId]
                );
            } else {
                Database::execute(
                    "INSERT INTO classes (id_kelas, id_mata_kuliah, nama_kelas, semester, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$classId, $courseId, $namaKelas, $semester, date('Y-m-d H:i:s')]
                );
            }

            Helper::json([
                'success' => true,
                'data'    => [
                    'id_kelas'       => $classId,
                    'id_mata_kuliah' => $courseId,
                    'nama_kelas'     => $namaKelas,
                    'semester'       => $semester
                ]
            ]);
            break;

        case 'deleteClass':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? $input['id'] ?? '');
            if (!$classId) throw new Exception("ID Kelas tidak valid.");

            Database::execute("DELETE FROM students WHERE id_kelas = ?", [$classId]);
            Database::execute("DELETE FROM grades WHERE id_kelas = ?", [$classId]);
            Database::execute("DELETE FROM class_task_folders WHERE id_kelas = ?", [$classId]);
            Database::execute("DELETE FROM classes WHERE id_kelas = ?", [$classId]);

            Helper::json([
                'success' => true,
                'message' => 'Kelas dan seluruh data mahasiswa serta nilai terkait berhasil dihapus.'
            ]);
            break;

        // ==========================================
        // KELOLA MAHASISWA (STUDENTS)
        // ==========================================
        case 'getStudents':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            $students = Database::fetchAll(
                "SELECT * FROM students WHERE id_kelas = ? ORDER BY nim ASC",
                [$classId]
            );
            Helper::json([
                'success'  => true,
                'students' => $students
            ]);
            break;

        case 'saveStudentsBulk':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            $rawText = trim($input['rawText'] ?? '');
            if (!$classId) throw new Exception('ID Kelas belum ditentukan.');
            if (!$rawText) throw new Exception('Daftar mahasiswa masih kosong.');

            $lines = preg_split('/\r?\n/', $rawText);
            $count = 0;

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);
                if (empty($line)) continue;

                $nim = '';
                $nama = '';

                if (str_contains($line, "\t")) {
                    $parts = explode("\t", $line);
                    $nim = trim($parts[0]);
                    $nama = trim(implode(' ', array_slice($parts, 1)));
                } elseif (str_contains($line, ",")) {
                    $parts = explode(",", $line);
                    $nim = trim($parts[0]);
                    $nama = trim(implode(' ', array_slice($parts, 1)));
                } else {
                    if (preg_match('/^([0-9a-zA-Z_-]+)[\s]+(.+)$/', $line, $m)) {
                        $nim = trim($m[1]);
                        $nama = trim($m[2]);
                    } else {
                        $nim = $line;
                        $nama = "Mahasiswa {$line}";
                    }
                }

                if (empty($nim)) continue;

                // Cek duplikasi
                $exist = Database::fetchOne(
                    "SELECT id_mahasiswa FROM students WHERE id_kelas = ? AND LOWER(nim) = LOWER(?)",
                    [$classId, $nim]
                );

                if (!$exist) {
                    $mhsId = Helper::generateId('MHS');
                    Database::execute(
                        "INSERT INTO students (id_mahasiswa, id_kelas, nim, nama_mahasiswa, created_at) VALUES (?, ?, ?, ?, ?)",
                        [$mhsId, $classId, $nim, $nama, date('Y-m-d H:i:s')]
                    );
                    $count++;
                }
            }

            Helper::json([
                'success' => true,
                'count'   => $count,
                'message' => "Berhasil menambahkan {$count} mahasiswa ke dalam kelas."
            ]);
            break;

        case 'saveStudent':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            $data = $input['data'] ?? $input;
            $mhsId = !empty($data['id_mahasiswa']) ? trim($data['id_mahasiswa']) : Helper::generateId('MHS');
            $nim = trim($data['nim'] ?? '');
            $nama = trim($data['nama_mahasiswa'] ?? '');

            $exist = Database::fetchOne("SELECT id_mahasiswa FROM students WHERE id_mahasiswa = ?", [$mhsId]);
            if ($exist) {
                Database::execute(
                    "UPDATE students SET nim = ?, nama_mahasiswa = ? WHERE id_mahasiswa = ?",
                    [$nim, $nama, $mhsId]
                );
            } else {
                Database::execute(
                    "INSERT INTO students (id_mahasiswa, id_kelas, nim, nama_mahasiswa, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$mhsId, $classId, $nim, $nama, date('Y-m-d H:i:s')]
                );
            }

            Helper::json([
                'success' => true,
                'data'    => ['id_mahasiswa' => $mhsId, 'nim' => $nim, 'nama_mahasiswa' => $nama]
            ]);
            break;

        case 'deleteStudent':
            UserService::requireAuth();
            $studentId = trim($input['studentId'] ?? $input['id'] ?? '');
            Database::execute("DELETE FROM students WHERE id_mahasiswa = ?", [$studentId]);
            Helper::json(['success' => true, 'message' => 'Mahasiswa berhasil dihapus.']);
            break;

        // ==========================================
        // KELOLA TUGAS (TASKS)
        // ==========================================
        case 'getTasks':
            UserService::requireAuth();
            $courseOrClassId = trim($input['courseIdOrClassId'] ?? $input['courseId'] ?? $input['classId'] ?? '');
            if (str_starts_with($courseOrClassId, 'KLS-')) {
                Helper::json(['success' => true, 'tasks' => getTasksByClassList($courseOrClassId)]);
            } else {
                Helper::json(['success' => true, 'tasks' => getTasksByCourseList($courseOrClassId)]);
            }
            break;

        case 'getTasksByCourse':
            UserService::requireAuth();
            $courseId = trim($input['courseId'] ?? '');
            Helper::json(['success' => true, 'tasks' => getTasksByCourseList($courseId)]);
            break;

        case 'getTasksByClass':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            Helper::json(['success' => true, 'tasks' => getTasksByClassList($classId)]);
            break;

        case 'getTaskById':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            $task = Database::fetchOne("SELECT * FROM tasks WHERE id_tugas = ?", [$taskId]);
            if (!$task) throw new Exception("Tugas tidak ditemukan.");

            if ($classId) {
                $override = Database::fetchOne(
                    "SELECT drive_folder_id FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?",
                    [$taskId, $classId]
                );
                if ($override && !empty($override['drive_folder_id'])) {
                    $task['drive_folder_id'] = $override['drive_folder_id'];
                }
            }

            Helper::json(['success' => true, 'task' => $task]);
            break;

        case 'saveTask':
            $user = UserService::requireAuth();
            $data = $input['data'] ?? $input;
            $taskId = !empty($data['id_tugas']) ? trim($data['id_tugas']) : Helper::generateId('TGS');
            $courseId = trim($data['id_mata_kuliah'] ?? '');
            if (!$courseId) {
                $userCourses = getCoursesList($user);
                $courseId = !empty($userCourses) ? $userCourses[0]['id_mata_kuliah'] : '';
            }

            $judul = trim($data['judul_tugas'] ?? 'Tugas Baru');
            $deskripsi = trim($data['deskripsi'] ?? '');
            $taskFileRef = trim($data['task_file_ref'] ?? '');
            $driveFolderId = Helper::extractDriveFolderId($data['drive_folder_id'] ?? '');
            $rubrikText = trim($data['rubrik_text'] ?? '');
            $bobot = (float)($data['bobot_persentase'] ?? 0);

            $existing = Database::fetchOne("SELECT id_tugas FROM tasks WHERE id_tugas = ?", [$taskId]);
            if ($existing) {
                Database::execute(
                    "UPDATE tasks SET id_mata_kuliah = ?, judul_tugas = ?, deskripsi = ?, task_file_ref = ?, drive_folder_id = ?, rubrik_text = ?, bobot_persentase = ? WHERE id_tugas = ?",
                    [$courseId, $judul, $deskripsi, $taskFileRef, $driveFolderId, $rubrikText, $bobot, $taskId]
                );
            } else {
                Database::execute(
                    "INSERT INTO tasks (id_tugas, id_mata_kuliah, judul_tugas, deskripsi, task_file_ref, drive_folder_id, rubrik_text, bobot_persentase, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$taskId, $courseId, $judul, $deskripsi, $taskFileRef, $driveFolderId, $rubrikText, $bobot, date('Y-m-d H:i:s')]
                );
            }

            // Simpan override folder per kelas jika ada
            if (!empty($data['class_folders']) && is_array($data['class_folders'])) {
                foreach ($data['class_folders'] as $clsId => $folderVal) {
                    $cleanFdk = Helper::extractDriveFolderId($folderVal);
                    Database::execute("DELETE FROM class_task_folders WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $clsId]);
                    if (!empty($cleanFdk)) {
                        $fdkId = Helper::generateId('FDK');
                        Database::execute(
                            "INSERT INTO class_task_folders (id_folder_kelas, id_tugas, id_kelas, drive_folder_id, created_at) VALUES (?, ?, ?, ?, ?)",
                            [$fdkId, $taskId, $clsId, $cleanFdk, date('Y-m-d H:i:s')]
                        );
                    }
                }
            }

            Helper::json([
                'success' => true,
                'data'    => [
                    'id_tugas'         => $taskId,
                    'id_mata_kuliah'   => $courseId,
                    'judul_tugas'      => $judul,
                    'deskripsi'        => $deskripsi,
                    'drive_folder_id'  => $driveFolderId,
                    'bobot_persentase' => $bobot
                ]
            ]);
            break;

        case 'deleteTask':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? $input['id'] ?? '');
            Database::execute("DELETE FROM grades WHERE id_tugas = ?", [$taskId]);
            Database::execute("DELETE FROM class_task_folders WHERE id_tugas = ?", [$taskId]);
            Database::execute("DELETE FROM tasks WHERE id_tugas = ?", [$taskId]);
            Helper::json(['success' => true, 'message' => 'Tugas berhasil dihapus.']);
            break;

        // ==========================================
        // UPLOAD & PENGELOLAAN BERKAS TUGAS
        // ==========================================
        case 'uploadSubmissionsZip':
            UserService::requireAuth();
            $taskId = trim($_POST['taskId'] ?? '');
            $classId = trim($_POST['classId'] ?? '');
            if (!$taskId || !$classId) throw new Exception("ID Tugas dan ID Kelas wajib disertakan.");
            
            $uploaded = $_FILES['files'] ?? ($_FILES['zipFile'] ?? null);
            if (!$uploaded) throw new Exception("Berkas tugas belum dipilih.");

            $res = FileService::handleSubmissionsUpload($taskId, $classId, $uploaded);
            Helper::json($res);
            break;

        case 'validateTaskFile':
            UserService::requireAuth();
            $ref = trim($_POST['ref'] ?? ($input['ref'] ?? ($input['taskFileRef'] ?? '')));
            $res = FileService::validateTaskFile($ref);
            Helper::json($res);
            break;

        case 'uploadTaskQuestion':
            UserService::requireAuth();
            $taskId = trim($_POST['taskId'] ?? ($input['taskId'] ?? ''));
            if (!$taskId) throw new Exception("ID Tugas wajib disertakan.");
            if (empty($_FILES['questionFile'])) throw new Exception("Berkas soal belum dipilih.");

            $uploadRes = FileService::saveUploadedTaskQuestion($taskId, $_FILES['questionFile']);
            Database::execute("UPDATE tasks SET task_file_ref = ? WHERE id_tugas = ?", [$uploadRes['filePath'], $taskId]);
            Helper::json($uploadRes);
            break;

        case 'extractRubricFromTask':
            $user = UserService::requireAuth();
            $taskId = trim($_POST['taskId'] ?? ($input['taskId'] ?? ''));
            $taskFileRef = trim($_POST['taskFileRef'] ?? ($input['taskFileRef'] ?? ''));
            $model = trim($_POST['model'] ?? ($input['model'] ?? ''));

            // 1. Cek jika berkas diunggah langsung via multipart form
            if (!empty($_FILES['questionFile']) && $_FILES['questionFile']['error'] === UPLOAD_ERR_OK) {
                $tempDir = FileService::getUploadDir() . '/temp';
                if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
                $cleanName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($_FILES['questionFile']['name']));
                $dest = "{$tempDir}/soal_upload_" . time() . "_{$cleanName}";
                if (!move_uploaded_file($_FILES['questionFile']['tmp_name'], $dest)) {
                    throw new Exception("Gagal memindahkan berkas soal yang diunggah.");
                }
                $taskPayload = FileService::processFileForGemini($dest);
            } else {
                $taskPayload = FileService::getTaskQuestionPayload($taskId, $taskFileRef);
            }

            if (!$taskPayload || empty($taskPayload['success'])) {
                $errMsg = !empty($taskPayload['message']) ? $taskPayload['message'] : "Berkas atau teks soal tugas belum diisi atau tidak dapat diakses.";
                throw new Exception($errMsg);
            }

            $settings = getSystemSettings($user);
            $apiKey = $settings['rawApiKey'];
            if (!$apiKey) throw new Exception("Gemini API Key belum dimasukkan di menu Pengaturan Akun Anda.");

            $modelName = $model ?: ($settings['modelName'] ?? 'gemini-3.5-flash-lite');
            $rubricRes = GeminiService::extractRubricFromTask($taskPayload, $apiKey, $modelName);
            Helper::json($rubricRes);
            break;

        // ==========================================
        // PENILAIAN & EVALUASI GEMINI (GRADES)
        // ==========================================
        case 'getGradesByTask':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            if (!$taskId || !$classId) throw new Exception("Parameter taskId dan classId wajib diisi.");

            $students = Database::fetchAll("SELECT * FROM students WHERE id_kelas = ? ORDER BY nim ASC", [$classId]);
            $grades = Database::fetchAll("SELECT * FROM grades WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);

            // Ambil pemetaan Google Drive File URL
            $driveMap = class_exists('GoogleDriveService') ? GoogleDriveService::getDriveFileMap($taskId, $classId) : [];

            $gradeMap = [];
            foreach ($grades as $g) {
                // Auto-fill drive_file_url jika di database belum ada tapi terpetakan di driveMap
                if (empty($g['drive_file_url']) && !empty($g['file_name']) && isset($driveMap[$g['file_name']])) {
                    $g['drive_file_url'] = $driveMap[$g['file_name']]['driveUrl'] ?? '';
                }
                $gradeMap[strtolower(trim($g['nim']))] = $g;
            }

            $result = array_map(function ($st) use ($gradeMap, $taskId, $classId, $driveMap) {
                $nimKey = strtolower(trim($st['nim']));
                if (isset($gradeMap[$nimKey])) {
                    $item = array_merge($st, $gradeMap[$nimKey]);
                    if (empty($item['drive_file_url']) && !empty($item['file_name']) && isset($driveMap[$item['file_name']])) {
                        $item['drive_file_url'] = $driveMap[$item['file_name']]['driveUrl'] ?? '';
                    }
                    return $item;
                }
                return array_merge($st, [
                    'id_nilai'        => '',
                    'id_tugas'        => $taskId,
                    'file_name'       => '',
                    'drive_file_url'  => '',
                    'nilai_total'     => null,
                    'rincian_poin'    => '',
                    'catatan_ai'      => '',
                    'status'          => 'Belum Dinilai',
                    'waktu_penilaian' => ''
                ]);
            }, $students);

            $submissionsDir = FileService::getSubmissionsDir($taskId, $classId);
            $submissionFiles = is_dir($submissionsDir) ? array_diff(scandir($submissionsDir), ['.', '..', 'drive_files_map.json']) : [];
            $uploadedCount = count($submissionFiles);

            Helper::json([
                'success'       => true,
                'grades'        => $result,
                'uploadedCount' => $uploadedCount
            ]);
            break;

        case 'viewSubmissionFile':
            // Membuka / mengunduh berkas jawaban mahasiswa langsung
            $user = UserService::getCurrentUser();
            if (!$user) {
                die("Akses ditolak. Silakan login ke aplikasi terlebih dahulu.");
            }

            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            $fileName = trim($input['fileName'] ?? ($input['file'] ?? ''));
            $isLocalOnly = !empty($input['local']);

            if (!$taskId || !$fileName) {
                die("Parameter taskId dan fileName wajib disertakan.");
            }

            $cleanFile = basename($fileName);
            $driveMap = class_exists('GoogleDriveService') ? GoogleDriveService::getDriveFileMap($taskId, $classId) : [];

            // Jika ada link Google Drive dan tidak meminta berkas lokal, langsung redirect ke Google Drive
            if (!$isLocalOnly && !empty($driveMap[$cleanFile]['driveUrl'])) {
                header("Location: " . $driveMap[$cleanFile]['driveUrl']);
                exit;
            }

            // Cari berkas di folder kelas atau shared
            $targetPath = '';
            if ($classId) {
                $p = FileService::getSubmissionsDir($taskId, $classId) . "/{$cleanFile}";
                if (file_exists($p)) $targetPath = $p;
            }
            if (!$targetPath) {
                $p = FileService::getSharedSubmissionsDir($taskId) . "/{$cleanFile}";
                if (file_exists($p)) $targetPath = $p;
            }

            if (!$targetPath || !file_exists($targetPath)) {
                if (!empty($driveMap[$cleanFile]['driveUrl'])) {
                    header("Location: " . $driveMap[$cleanFile]['driveUrl']);
                    exit;
                }
                die("Berkas '{$cleanFile}' tidak ditemukan di server maupun Google Drive.");
            }

            $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
            $mimeMap = [
                'pdf'  => 'application/pdf',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
                'heif' => 'image/heif',
                'txt'  => 'text/plain',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'doc'  => 'application/msword'
            ];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';

            // Jika berkas berupa HEIC/HEIF dan tautan Google Drive tersedia,
            // alihkan ke Google Drive agar dapat dipratinjau langsung di browser non-Apple
            if (in_array($ext, ['heic', 'heif'], true) && !empty($driveMap[$cleanFile]['driveUrl'])) {
                header("Location: " . $driveMap[$cleanFile]['driveUrl']);
                exit;
            }

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($targetPath));
            header('Content-Disposition: inline; filename="' . $cleanFile . '"');
            header('Cache-Control: private, max-age=3600');
            readfile($targetPath);
            exit;

        case 'gradeSingleStudent':
            $user = UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            $studentNim = trim($input['studentNim'] ?? '');

            if (!$taskId || !$classId || !$studentNim) {
                throw new Exception("Parameter taskId, classId, dan studentNim wajib disertakan.");
            }

            $task = Database::fetchOne("SELECT * FROM tasks WHERE id_tugas = ?", [$taskId]);
            if (!$task) throw new Exception("Tugas tidak ditemukan.");

            $student = Database::fetchOne(
                "SELECT * FROM students WHERE id_kelas = ? AND LOWER(nim) = LOWER(?)",
                [$classId, $studentNim]
            );
            if (!$student) throw new Exception("Mahasiswa dengan NIM {$studentNim} tidak ditemukan di kelas ini.");

            $settings = getSystemSettings($user);
            if (empty($settings['rawApiKey'])) {
                throw new Exception("Gemini API Key belum dimasukkan di menu Pengaturan Akun Anda.");
            }

            $taskQuestionData = FileService::getTaskQuestionPayload($taskId, $task['task_file_ref']);
            $res = GradingService::gradeStudent($task, $student, $settings, $taskQuestionData);
            Helper::json($res);
            break;

        case 'resetFailedGrades':
            UserService::requireAuth();
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            Helper::json(GradingService::resetFailedGrades($taskId, $classId));
            break;

        case 'saveGradeManual':
            UserService::requireAuth();
            $gradeData = $input['gradeData'] ?? $input;
            $res = GradingService::saveGrade($gradeData);
            Helper::json(['success' => true, 'data' => $res]);
            break;

        // ==========================================
        // REKAPITULASI & LAPORAN NILAI KELAS
        // ==========================================
        case 'getClassReport':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            if (!$classId) throw new Exception("ID Kelas wajib disertakan.");
            Helper::json([
                'success' => true,
                'report'  => generateClassReport($classId)
            ]);
            break;

        case 'getClassFullExportData':
            UserService::requireAuth();
            $classId = trim($input['classId'] ?? '');
            if (!$classId) throw new Exception("ID Kelas wajib disertakan.");
            $report = generateClassReport($classId);

            $grades = Database::fetchAll("SELECT * FROM grades WHERE id_kelas = ?", [$classId]);
            $students = Database::fetchAll("SELECT * FROM students WHERE id_kelas = ? ORDER BY nim ASC", [$classId]);

            $taskDetails = array_map(function ($task) use ($students, $grades) {
                $taskGrades = array_map(function ($st, $idx) use ($task, $grades) {
                    $nimKey = strtolower(trim($st['nim']));
                    $g = null;
                    foreach ($grades as $item) {
                        if ($item['id_tugas'] === $task['id_tugas'] && strtolower(trim($item['nim'])) === $nimKey) {
                            $g = $item;
                            break;
                        }
                    }

                    $scoreVal = ($g && $g['nilai_total'] !== null && is_numeric($g['nilai_total']))
                        ? (float)$g['nilai_total']
                        : '-';

                    return [
                        'no'              => $idx + 1,
                        'nim'             => $st['nim'],
                        'nama_mahasiswa'  => $st['nama_mahasiswa'],
                        'file_name'       => $g ? ($g['file_name'] ?: '-') : '-',
                        'nilai_total'     => $scoreVal,
                        'rincian_poin'    => $g ? ($g['rincian_poin'] ?: '-') : '-',
                        'catatan_ai'      => $g ? ($g['catatan_ai'] ?: '-') : '-',
                        'status'          => $g ? ($g['status'] ?: 'Belum Dinilai') : 'Belum Dinilai',
                        'waktu_penilaian' => $g ? ($g['waktu_penilaian'] ?: '-') : '-'
                    ];
                }, $students, array_keys($students));

                return [
                    'task'   => $task,
                    'grades' => $taskGrades
                ];
            }, $report['tasks']);

            Helper::json([
                'success'     => true,
                'classInfo'   => $report['classInfo'],
                'report'      => $report,
                'taskDetails' => $taskDetails
            ]);
            break;

        default:
            Helper::json([
                'success' => false,
                'message' => "Aksi '{$action}' tidak dikenal."
            ], 400);
            break;
    }
} catch (Throwable $e) {
    Helper::json([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ==========================================
// FUNGSI HELPER INTERNAL API
// ==========================================

function getSetting(string $key): ?string {
    $row = Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row ? $row['setting_value'] : null;
}

function saveSetting(string $key, string $value): void {
    $exist = Database::fetchOne("SELECT setting_key FROM settings WHERE setting_key = ?", [$key]);
    if ($exist) {
        Database::execute("UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?", [$value, date('Y-m-d H:i:s'), $key]);
    } else {
        Database::execute("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)", [$key, $value, date('Y-m-d H:i:s')]);
    }
}

function getSystemSettings(?array $user = null): array {
    $config = Database::loadConfig();

    // Preferensi API Key & Model: per-pengguna aktif (BYOK), fallback ke global
    $rawApiKey = '';
    $modelName = 'gemini-3.5-flash-lite';
    $userId = $user ? $user['id_user'] : null;

    if ($user && !empty($user['gemini_api_key'])) {
        $rawApiKey = $user['gemini_api_key'];
    } else {
        $rawApiKey = getSetting('GEMINI_API_KEY') ?: ($config['gemini']['api_key'] ?? '');
    }

    if ($user && !empty($user['model_name'])) {
        $modelName = $user['model_name'];
    } else {
        $modelName = getSetting('MODEL_NAME') ?: ($config['gemini']['default_model'] ?? 'gemini-3.5-flash-lite');
    }

    $activeCourseId = getSetting('ACTIVE_COURSE_ID') ?: '';
    $activeClassId = getSetting('ACTIVE_CLASS_ID') ?: '';

    return [
        'apiKey'              => Helper::maskApiKey($rawApiKey),
        'hasApiKey'           => !empty($rawApiKey),
        'rawApiKey'           => $rawApiKey,
        'modelName'           => $modelName,
        'availableModels'     => $config['gemini']['available_models'] ?? [],
        'activeCourseId'      => $activeCourseId,
        'activeClassId'       => $activeClassId,
        'dbDriver'            => $config['db_driver'] ?? 'sqlite',
        'maxExecutionTimeSec' => 300,
        'googleOAuth'         => GoogleDriveService::getOAuthStatus($userId),
        'currentUser'         => $user ? UserService::sanitizeUser($user) : null
    ];
}

function getCoursesList(?array $user = null): array {
    $sql = "SELECT c.*, u.nama as dosen_nama, u.email as dosen_email 
            FROM courses c 
            LEFT JOIN users u ON c.id_user = u.id_user";
    $params = [];

    if ($user && $user['role'] !== 'admin') {
        $sql .= " WHERE c.id_user = ?";
        $params[] = $user['id_user'];
    }

    $sql .= " ORDER BY c.nama_mata_kuliah ASC";
    $courses = Database::fetchAll($sql, $params);

    $classes = Database::fetchAll("SELECT id_kelas, id_mata_kuliah FROM classes");
    $tasks = Database::fetchAll("SELECT id_tugas, id_mata_kuliah FROM tasks");
    $students = Database::fetchAll("SELECT id_mahasiswa, id_kelas FROM students");

    $classToCourse = [];
    foreach ($classes as $c) {
        $classToCourse[$c['id_kelas']] = $c['id_mata_kuliah'];
    }

    return array_map(function ($c) use ($classes, $tasks, $students, $classToCourse) {
        $cId = $c['id_mata_kuliah'];
        $classCount = count(array_filter($classes, fn($cl) => $cl['id_mata_kuliah'] === $cId));
        $taskCount = count(array_filter($tasks, fn($t) => $t['id_mata_kuliah'] === $cId));
        $studentCount = count(array_filter($students, fn($s) => ($classToCourse[$s['id_kelas']] ?? '') === $cId));

        return [
            'id_mata_kuliah'   => $cId,
            'id_user'          => $c['id_user'] ?? '',
            'dosen_nama'       => $c['dosen_nama'] ?? '',
            'dosen_email'      => $c['dosen_email'] ?? '',
            'kode_mk'          => $c['kode_mk'] ?? '',
            'nama_mata_kuliah' => $c['nama_mata_kuliah'] ?? '',
            'semester'         => $c['semester'] ?? '',
            'created_at'       => !empty($c['created_at']) ? date('d/m/Y', strtotime($c['created_at'])) : '',
            'classCount'       => $classCount,
            'taskCount'        => $taskCount,
            'studentCount'     => $studentCount
        ];
    }, $courses);
}

function getClassesList(?string $courseId = null, ?array $user = null): array {
    $sql = "SELECT c.*, mk.nama_mata_kuliah, mk.id_user 
            FROM classes c 
            LEFT JOIN courses mk ON c.id_mata_kuliah = mk.id_mata_kuliah";
    $params = [];
    $where = [];

    if ($courseId) {
        $where[] = "c.id_mata_kuliah = ?";
        $params[] = $courseId;
    }

    if ($user && $user['role'] !== 'admin') {
        $where[] = "mk.id_user = ?";
        $params[] = $user['id_user'];
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY c.nama_kelas ASC";

    $classes = Database::fetchAll($sql, $params);
    $students = Database::fetchAll("SELECT id_kelas FROM students");
    $tasks = Database::fetchAll("SELECT id_mata_kuliah FROM tasks");

    return array_map(function ($c) use ($students, $tasks) {
        $clsId = $c['id_kelas'];
        $cId = $c['id_mata_kuliah'];
        $studentCount = count(array_filter($students, fn($s) => $s['id_kelas'] === $clsId));
        $taskCount = count(array_filter($tasks, fn($t) => $t['id_mata_kuliah'] === $cId));

        return [
            'id_kelas'         => $clsId,
            'id_mata_kuliah'   => $cId,
            'nama_mata_kuliah' => $c['nama_mata_kuliah'] ?? '',
            'nama_kelas'       => $c['nama_kelas'] ?? '',
            'semester'         => $c['semester'] ?? '',
            'created_at'       => !empty($c['created_at']) ? date('d/m/Y', strtotime($c['created_at'])) : '',
            'studentCount'     => $studentCount,
            'taskCount'        => $taskCount
        ];
    }, $classes);
}

function getTasksByCourseList(string $courseId): array {
    $tasks = Database::fetchAll("SELECT * FROM tasks WHERE id_mata_kuliah = ? ORDER BY created_at DESC", [$courseId]);
    $grades = Database::fetchAll("SELECT id_tugas, nilai_total, status FROM grades WHERE status = 'Selesai'");

    return array_map(function ($task) use ($grades) {
        $tId = $task['id_tugas'];
        $taskGrades = array_filter($grades, fn($g) => $g['id_tugas'] === $tId);
        $gradedCount = count($taskGrades);
        $totalScore = array_reduce($taskGrades, fn($sum, $g) => $sum + (float)$g['nilai_total'], 0);
        $avg = $gradedCount > 0 ? round($totalScore / $gradedCount, 1) : 0;

        // Hitung jumlah berkas tugas mahasiswa yang sudah tersimpan di server
        $sharedDir = FileService::getSharedSubmissionsDir($tId);
        $sharedFiles = is_dir($sharedDir) ? array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']) : [];
        $subCount = count($sharedFiles);

        if ($subCount === 0) {
            $cleanTId = preg_replace('/[^a-zA-Z0-9_-]/', '', $tId);
            $taskBaseDir = FileService::getUploadDir() . "/tasks/{$cleanTId}";
            if (is_dir($taskBaseDir)) {
                $subDirs = array_diff(scandir($taskBaseDir), ['.', '..', 'shared']);
                $maxInSub = 0;
                foreach ($subDirs as $sd) {
                    $clsDir = "{$taskBaseDir}/{$sd}";
                    if (is_dir($clsDir)) {
                        $cf = array_diff(scandir($clsDir), ['.', '..', 'drive_files_map.json']);
                        if (count($cf) > $maxInSub) $maxInSub = count($cf);
                    }
                }
                $subCount = $maxInSub;
            }
        }

        return array_merge($task, [
            'gradedCount'     => $gradedCount,
            'averageScore'    => $avg,
            'submissionCount' => $subCount
        ]);
    }, $tasks);
}

function getTasksByClassList(string $classId): array {
    $class = Database::fetchOne("SELECT * FROM classes WHERE id_kelas = ?", [$classId]);
    if (!$class || empty($class['id_mata_kuliah'])) return [];

    $tasks = getTasksByCourseList($class['id_mata_kuliah']);
    $students = Database::fetchAll("SELECT id_mahasiswa FROM students WHERE id_kelas = ?", [$classId]);
    $grades = Database::fetchAll("SELECT id_tugas, nilai_total, status FROM grades WHERE id_kelas = ? AND status = 'Selesai'", [$classId]);

    $overrides = Database::fetchAll("SELECT id_tugas, drive_folder_id FROM class_task_folders WHERE id_kelas = ?", [$classId]);
    $overrideMap = [];
    foreach ($overrides as $ov) {
        $overrideMap[$ov['id_tugas']] = $ov['drive_folder_id'];
    }

    return array_map(function ($task) use ($classId, $students, $grades, $overrideMap) {
        $tId = $task['id_tugas'];
        $taskGrades = array_filter($grades, fn($g) => $g['id_tugas'] === $tId);
        $gradedCount = count($taskGrades);
        $totalScore = array_reduce($taskGrades, fn($sum, $g) => $sum + (float)$g['nilai_total'], 0);
        $avg = $gradedCount > 0 ? round($totalScore / $gradedCount, 1) : 0;

        $effectiveFolder = $overrideMap[$tId] ?? $task['drive_folder_id'];

        return array_merge($task, [
            'id_kelas'                  => $classId,
            'drive_folder_id'           => $effectiveFolder,
            'has_class_folder_override' => isset($overrideMap[$tId]),
            'totalStudents'             => count($students),
            'gradedCount'               => $gradedCount,
            'averageScore'              => $avg
        ]);
    }, $tasks);
}

function generateClassReport(string $classId): array {
    $class = Database::fetchOne("SELECT * FROM classes WHERE id_kelas = ?", [$classId]);
    if (!$class) throw new Exception("Kelas tidak ditemukan.");

    $course = Database::fetchOne("SELECT * FROM courses WHERE id_mata_kuliah = ?", [$class['id_mata_kuliah']]);
    $students = Database::fetchAll("SELECT * FROM students WHERE id_kelas = ? ORDER BY nim ASC", [$classId]);
    $tasks = getTasksByClassList($classId);
    $grades = Database::fetchAll("SELECT * FROM grades WHERE id_kelas = ?", [$classId]);

    $studentReports = array_map(function ($st) use ($tasks, $grades) {
        $nimKey = strtolower(trim($st['nim']));
        $taskScores = [];
        $totalWeighted = 0;
        $totalWeight = 0;
        $simpleSum = 0;
        $gradedCount = 0;

        foreach ($tasks as $task) {
            $tId = $task['id_tugas'];
            $grade = null;
            foreach ($grades as $g) {
                if ($g['id_tugas'] === $tId && strtolower(trim($g['nim'])) === $nimKey) {
                    $grade = $g;
                    break;
                }
            }

            $score = ($grade && $grade['nilai_total'] !== null && is_numeric($grade['nilai_total']))
                ? (float)$grade['nilai_total']
                : null;
            $taskScores[$tId] = $score;

            if ($score !== null) {
                $gradedCount++;
                $simpleSum += $score;
                $weight = $task['bobot_persentase'] > 0 ? (float)$task['bobot_persentase'] : 1;
                $totalWeighted += ($score * $weight);
                $totalWeight += $weight;
            }
        }

        $average = $gradedCount > 0 ? round($simpleSum / $gradedCount, 1) : 0;
        $final = $totalWeight > 0 ? round($totalWeighted / $totalWeight, 1) : $average;

        return [
            'id_mahasiswa'   => $st['id_mahasiswa'],
            'nim'            => $st['nim'],
            'nama_mahasiswa' => $st['nama_mahasiswa'],
            'taskScores'     => $taskScores,
            'gradedCount'    => $gradedCount,
            'averageScore'   => $average,
            'finalScore'     => $final
        ];
    }, $students);

    $allFinalScores = array_filter(array_column($studentReports, 'finalScore'), fn($s) => $s > 0);
    $classAvg = count($allFinalScores) > 0 ? round(array_sum($allFinalScores) / count($allFinalScores), 1) : 0;
    $maxScore = count($allFinalScores) > 0 ? max($allFinalScores) : 0;
    $minScore = count($allFinalScores) > 0 ? min($allFinalScores) : 0;

    return [
        'classInfo' => array_merge($class, [
            'mata_kuliah' => $course ? $course['nama_mata_kuliah'] : 'Mata Kuliah',
            'kode_mk'     => $course ? $course['kode_mk'] : ''
        ]),
        'courseInfo'=> $course,
        'tasks'     => $tasks,
        'students'  => $studentReports,
        'stats'     => [
            'totalStudents' => count($students),
            'totalTasks'    => count($tasks),
            'classAverage'  => $classAvg,
            'highestScore'  => $maxScore,
            'lowestScore'   => $minScore
        ]
    ];
}
