<?php
/**
 * @fileoverview API Gateway & RESTful Dispatcher untuk Asisten Penilaian Mandiri
 */

// Error handling & header CORS / JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Helper.php';
require_once __DIR__ . '/services/GeminiService.php';
require_once __DIR__ . '/services/FileService.php';
require_once __DIR__ . '/services/GradingService.php';

// Pastikan database terinisialisasi
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
        // SISTEM & PENGATURAN
        // ==========================================
        case 'getInitialData':
            $settings = getSystemSettings();
            $courses = getCoursesList();
            $classes = getClassesList();

            Helper::json([
                'success'  => true,
                'settings' => $settings,
                'courses'  => $courses,
                'classes'  => $classes
            ]);
            break;

        case 'getSettings':
            Helper::json([
                'success'  => true,
                'settings' => getSystemSettings()
            ]);
            break;

        case 'saveSettings':
            $data = $input['data'] ?? $input;
            if (!empty($data['apiKey']) && !str_contains($data['apiKey'], '****')) {
                saveSetting('GEMINI_API_KEY', trim($data['apiKey']));
            }
            if (!empty($data['modelName'])) {
                saveSetting('MODEL_NAME', trim($data['modelName']));
            }
            if (isset($data['activeCourseId'])) {
                saveSetting('ACTIVE_COURSE_ID', trim($data['activeCourseId']));
            }
            if (isset($data['activeClassId'])) {
                saveSetting('ACTIVE_CLASS_ID', trim($data['activeClassId']));
            }
            Helper::json([
                'success' => true,
                'message' => 'Pengaturan berhasil disimpan!'
            ]);
            break;

        case 'testConnection':
            $key = $input['apiKey'] ?? '';
            if (empty($key) || str_contains($key, '****')) {
                $saved = getSetting('GEMINI_API_KEY');
                $key = $saved ?: '';
            }
            $model = $input['model'] ?? 'gemini-3.5-flash-lite';
            $testRes = GeminiService::testConnection($key, $model);
            Helper::json($testRes);
            break;

        case 'resetDatabase':
            $resetRes = Database::resetDatabase();
            Helper::json($resetRes);
            break;

        // ==========================================
        // KELOLA MATA KULIAH (COURSES)
        // ==========================================
        case 'getCourses':
            Helper::json([
                'success' => true,
                'courses' => getCoursesList()
            ]);
            break;

        case 'saveCourse':
            $data = $input['data'] ?? $input;
            $courseId = !empty($data['id_mata_kuliah']) ? trim($data['id_mata_kuliah']) : Helper::generateId('MK');
            $kodeMk = trim($data['kode_mk'] ?? '');
            $namaMk = trim($data['nama_mata_kuliah'] ?? 'Mata Kuliah Baru');
            $semester = trim($data['semester'] ?? '');

            $existing = Database::fetchOne("SELECT id_mata_kuliah FROM courses WHERE id_mata_kuliah = ?", [$courseId]);
            if ($existing) {
                Database::execute(
                    "UPDATE courses SET kode_mk = ?, nama_mata_kuliah = ?, semester = ? WHERE id_mata_kuliah = ?",
                    [$kodeMk, $namaMk, $semester, $courseId]
                );
            } else {
                Database::execute(
                    "INSERT INTO courses (id_mata_kuliah, kode_mk, nama_mata_kuliah, semester, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$courseId, $kodeMk, $namaMk, $semester, date('Y-m-d H:i:s')]
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
            $courseId = trim($input['courseId'] ?? $input['id'] ?? '');
            if (!$courseId) throw new Exception("ID Mata Kuliah tidak valid.");

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
            $courseId = $input['courseId'] ?? null;
            Helper::json([
                'success' => true,
                'classes' => getClassesList($courseId)
            ]);
            break;

        case 'saveClass':
            $data = $input['data'] ?? $input;
            $classId = !empty($data['id_kelas']) ? trim($data['id_kelas']) : Helper::generateId('KLS');
            $courseId = trim($data['id_mata_kuliah'] ?? '');
            if (empty($courseId)) {
                $firstCourse = Database::fetchOne("SELECT id_mata_kuliah FROM courses LIMIT 1");
                $courseId = $firstCourse ? $firstCourse['id_mata_kuliah'] : '';
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
            $studentId = trim($input['studentId'] ?? $input['id'] ?? '');
            Database::execute("DELETE FROM students WHERE id_mahasiswa = ?", [$studentId]);
            Helper::json(['success' => true, 'message' => 'Mahasiswa berhasil dihapus.']);
            break;

        // ==========================================
        // KELOLA TUGAS (TASKS)
        // ==========================================
        case 'getTasks':
            $courseOrClassId = trim($input['courseIdOrClassId'] ?? $input['courseId'] ?? $input['classId'] ?? '');
            if (str_starts_with($courseOrClassId, 'KLS-')) {
                Helper::json(['success' => true, 'tasks' => getTasksByClassList($courseOrClassId)]);
            } else {
                Helper::json(['success' => true, 'tasks' => getTasksByCourseList($courseOrClassId)]);
            }
            break;

        case 'getTasksByCourse':
            $courseId = trim($input['courseId'] ?? '');
            Helper::json(['success' => true, 'tasks' => getTasksByCourseList($courseId)]);
            break;

        case 'getTasksByClass':
            $classId = trim($input['classId'] ?? '');
            Helper::json(['success' => true, 'tasks' => getTasksByClassList($classId)]);
            break;

        case 'getTaskById':
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
            $data = $input['data'] ?? $input;
            $taskId = !empty($data['id_tugas']) ? trim($data['id_tugas']) : Helper::generateId('TGS');
            $courseId = trim($data['id_mata_kuliah'] ?? '');
            if (!$courseId) {
                $firstCourse = Database::fetchOne("SELECT id_mata_kuliah FROM courses LIMIT 1");
                $courseId = $firstCourse ? $firstCourse['id_mata_kuliah'] : '';
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
            $taskId = trim($_POST['taskId'] ?? '');
            $classId = trim($_POST['classId'] ?? '');
            if (!$taskId || !$classId) throw new Exception("ID Tugas dan ID Kelas wajib disertakan.");
            if (empty($_FILES['zipFile'])) throw new Exception("Berkas ZIP belum dipilih.");

            $zipRes = FileService::extractSubmissionsZip($_FILES['zipFile']['tmp_name'], $taskId, $classId);
            Helper::json($zipRes);
            break;

        case 'validateTaskFile':
            $ref = trim($_POST['ref'] ?? ($input['ref'] ?? ($input['taskFileRef'] ?? '')));
            $res = FileService::validateTaskFile($ref);
            Helper::json($res);
            break;

        case 'uploadTaskQuestion':
            $taskId = trim($_POST['taskId'] ?? ($input['taskId'] ?? ''));
            if (!$taskId) throw new Exception("ID Tugas wajib disertakan.");
            if (empty($_FILES['questionFile'])) throw new Exception("Berkas soal belum dipilih.");

            $uploadRes = FileService::saveUploadedTaskQuestion($taskId, $_FILES['questionFile']);
            // Update ref di tabel tasks
            Database::execute("UPDATE tasks SET task_file_ref = ? WHERE id_tugas = ?", [$uploadRes['filePath'], $taskId]);
            Helper::json($uploadRes);
            break;

        case 'extractRubricFromTask':
            $taskId = trim($_POST['taskId'] ?? ($input['taskId'] ?? ''));
            $taskFileRef = trim($_POST['taskFileRef'] ?? ($input['taskFileRef'] ?? ''));
            $model = trim($_POST['model'] ?? ($input['model'] ?? 'gemini-3.5-flash-lite'));

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

            $settings = getSystemSettings();
            $apiKey = $settings['rawApiKey'];
            if (!$apiKey) throw new Exception("Gemini API Key belum dimasukkan di menu Pengaturan.");

            $rubricRes = GeminiService::extractRubricFromTask($taskPayload, $apiKey, $model);
            Helper::json($rubricRes);
            break;

        // ==========================================
        // PENILAIAN & EVALUASI GEMINI (GRADES)
        // ==========================================
        case 'getGradesByTask':
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            if (!$taskId || !$classId) throw new Exception("Parameter taskId dan classId wajib diisi.");

            $students = Database::fetchAll("SELECT * FROM students WHERE id_kelas = ? ORDER BY nim ASC", [$classId]);
            $grades = Database::fetchAll("SELECT * FROM grades WHERE id_tugas = ? AND id_kelas = ?", [$taskId, $classId]);

            $gradeMap = [];
            foreach ($grades as $g) {
                $gradeMap[strtolower(trim($g['nim']))] = $g;
            }

            $result = array_map(function ($st) use ($gradeMap, $taskId, $classId) {
                $nimKey = strtolower(trim($st['nim']));
                if (isset($gradeMap[$nimKey])) {
                    return array_merge($st, $gradeMap[$nimKey]);
                }
                return array_merge($st, [
                    'id_nilai'        => '',
                    'id_tugas'        => $taskId,
                    'file_name'       => '',
                    'nilai_total'     => null,
                    'rincian_poin'    => '',
                    'catatan_ai'      => '',
                    'status'          => 'Belum Dinilai',
                    'waktu_penilaian' => ''
                ]);
            }, $students);

            Helper::json([
                'success' => true,
                'grades'  => $result
            ]);
            break;

        case 'gradeSingleStudent':
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

            $settings = getSystemSettings();
            if (empty($settings['rawApiKey'])) {
                throw new Exception("Gemini API Key belum dimasukkan di menu Pengaturan.");
            }

            $taskQuestionData = FileService::getTaskQuestionPayload($taskId, $task['task_file_ref']);
            $res = GradingService::gradeStudent($task, $student, $settings, $taskQuestionData);
            Helper::json($res);
            break;

        case 'resetFailedGrades':
            $taskId = trim($input['taskId'] ?? '');
            $classId = trim($input['classId'] ?? '');
            Helper::json(GradingService::resetFailedGrades($taskId, $classId));
            break;

        case 'saveGradeManual':
            $gradeData = $input['gradeData'] ?? $input;
            $res = GradingService::saveGrade($gradeData);
            Helper::json(['success' => true, 'data' => $res]);
            break;

        // ==========================================
        // REKAPITULASI & LAPORAN NILAI KELAS
        // ==========================================
        case 'getClassReport':
            $classId = trim($input['classId'] ?? '');
            if (!$classId) throw new Exception("ID Kelas wajib disertakan.");
            Helper::json([
                'success' => true,
                'report'  => generateClassReport($classId)
            ]);
            break;

        case 'getClassFullExportData':
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
} catch (Exception $e) {
    Helper::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
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

function getSystemSettings(): array {
    $config = Database::loadConfig();
    $rawApiKey = getSetting('GEMINI_API_KEY') ?: ($config['gemini']['api_key'] ?? '');
    $modelName = getSetting('MODEL_NAME') ?: ($config['gemini']['default_model'] ?? 'gemini-3.5-flash-lite');
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
        'maxExecutionTimeSec' => 300
    ];
}

function getCoursesList(): array {
    $courses = Database::fetchAll("SELECT * FROM courses ORDER BY nama_mata_kuliah ASC");
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

function getClassesList(?string $courseId = null): array {
    $sql = "SELECT c.*, mk.nama_mata_kuliah 
            FROM classes c 
            LEFT JOIN courses mk ON c.id_mata_kuliah = mk.id_mata_kuliah";
    $params = [];
    if ($courseId) {
        $sql .= " WHERE c.id_mata_kuliah = ?";
        $params[] = $courseId;
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

        return array_merge($task, [
            'gradedCount'  => $gradedCount,
            'averageScore' => $avg
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
