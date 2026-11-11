<?php
/**
 * @fileoverview Layanan Pengelolaan Berkas Tugas, Ekstraksi ZIP & Pemrosesan Multimodal
 */

require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/../core/Database.php';

class FileService {
    /**
     * Mengambil direktori dasar upload
     */
    public static function getUploadDir(): string {
        $config = Database::loadConfig();
        $dir = $config['storage']['upload_dir'] ?? (__DIR__ . '/../uploads');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return realpath($dir) ?: $dir;
    }

    /**
     * Mengambil path direktori tugas untuk kelas tertentu
     */
    public static function getSubmissionsDir(string $taskId, string $classId): string {
        $base = self::getUploadDir();
        $cleanTask = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $cleanClass = preg_replace('/[^a-zA-Z0-9_-]/', '', $classId);
        $dir = "{$base}/tasks/{$cleanTask}/{$cleanClass}";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Mengambil path direktori berkas tugas bersama (seluruh kelas di bawah tugas ini)
     */
    public static function getSharedSubmissionsDir(string $taskId): string {
        $base = self::getUploadDir();
        $cleanTask = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $dir = "{$base}/tasks/{$cleanTask}/shared";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Mengambil path direktori berkas soal tugas
     */
    public static function getTaskQuestionsDir(string $taskId): string {
        $base = self::getUploadDir();
        $cleanTask = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $dir = "{$base}/tasks/{$cleanTask}/question";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Menyimpan berkas soal yang diunggah
     */
    public static function saveUploadedTaskQuestion(string $taskId, array $file): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Gagal mengunggah berkas soal: kode kesalahan {$file['error']}");
        }

        $dir = self::getTaskQuestionsDir($taskId);
        $originalName = basename($file['name']);
        $cleanName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);
        $targetPath = "{$dir}/{$cleanName}";

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception("Tidak dapat memindahkan berkas soal ke direktori tujuan.");
        }

        return [
            'success'   => true,
            'fileName'  => $originalName,
            'filePath'  => $targetPath,
            'fileUrl'   => "uploads/tasks/{$taskId}/question/{$cleanName}"
        ];
    }

    /**
     * Mengekstrak berkas arsip ZIP tugas mahasiswa dan menempatkannya ke direktori kelas
     */
    public static function extractSubmissionsZip(string $zipTmpPath, string $taskId, string $classId): array {
        if (!class_exists('ZipArchive')) {
            throw new Exception("Ekstensi PHP ZipArchive tidak aktif di server.");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath) !== true) {
            throw new Exception("Berkas ZIP tidak dapat dibuka atau korup.");
        }

        $destDir = self::getSubmissionsDir($taskId, $classId);
        $extractedCount = 0;
        $extractedFiles = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            // Abaikan folder MacOS internal __MACOSX / .DS_Store
            if (str_contains($entryName, '__MACOSX') || str_contains($entryName, '.DS_Store')) {
                continue;
            }

            // Jika entri berupa berkas (bukan folder)
            if (!str_ends_with($entryName, '/')) {
                $cleanEntry = trim(str_replace('\\', '/', $entryName), '/');
                $parts = explode('/', $cleanEntry);
                $baseFileName = end($parts);
                if (empty($baseFileName) || str_starts_with($baseFileName, '.')) continue;

                // Jika berkas berada di dalam subfolder (misal export dari LMS / Classroom: Nama_NIM/tugas.pdf)
                // Gabungkan nama subfolder agar berkas tidak saling timpa dan NIM/nama tetap terbaca
                $finalName = (count($parts) > 1) ? implode('_', $parts) : $baseFileName;
                $cleanFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $finalName);
                $destFilePath = "{$destDir}/{$cleanFileName}";

                // Ambil stream dari zip dan simpan
                $stream = $zip->getStream($entryName);
                if ($stream) {
                    file_put_contents($destFilePath, stream_get_contents($stream));
                    fclose($stream);

                    // Pertahankan timestamp asli dari arsip ZIP
                    $stat = $zip->statIndex($i);
                    if (!empty($stat['mtime'])) {
                        @touch($destFilePath, (int)$stat['mtime']);
                    }

                    $extractedCount++;
                    $extractedFiles[] = $cleanFileName;
                }
            }
        }

        $zip->close();

        return [
            'success'        => true,
            'extractedCount' => $extractedCount,
            'files'          => $extractedFiles,
            'message'        => "Berhasil mengekstrak {$extractedCount} berkas tugas ke dalam kelas."
        ];
    }

    /**
     * Memproses upload berkas tugas mahasiswa, mendukung berkas ZIP maupun berkas tunggal/jamak
     */
    public static function handleSubmissionsUpload(string $taskId, string $classId, array $files): array {
        $destDir = self::getSubmissionsDir($taskId, $classId);
        $totalProcessed = 0;
        $fileNames = [];

        // Normalisasi struktur $_FILES
        $fileList = [];
        if (isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileList[] = [
                        'name'     => $files['name'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'size'     => $files['size'][$i],
                        'error'    => $files['error'][$i]
                    ];
                }
            }
        } elseif (isset($files['error']) && $files['error'] === UPLOAD_ERR_OK) {
            $fileList[] = $files;
        }

        if (empty($fileList)) {
            throw new Exception("Tidak ada berkas tugas valid yang berhasil diunggah.");
        }

        foreach ($fileList as $item) {
            $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $zipRes = self::extractSubmissionsZip($item['tmp_name'], $taskId, $classId);
                $totalProcessed += $zipRes['extractedCount'] ?? 0;
                $fileNames = array_merge($fileNames, $zipRes['files'] ?? []);
            } else {
                $clean = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($item['name']));
                $target = "{$destDir}/{$clean}";
                if (move_uploaded_file($item['tmp_name'], $target)) {
                    $totalProcessed++;
                    $fileNames[] = $clean;
                }
            }
        }

        return [
            'success'        => true,
            'extractedCount' => $totalProcessed,
            'files'          => $fileNames,
            'message'        => "Berhasil memproses {$totalProcessed} berkas tugas ke server."
        ];
    }

    /**
     * Ekstraksi teks dari berkas Word (.docx) melalui pembongkaran XML arsip zip
     */
    public static function extractTextFromDocx(string $filePath): string {
        if (!class_exists('ZipArchive') || !file_exists($filePath)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $fullText = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/') && (str_contains($name, 'document') || str_contains($name, 'header') || str_contains($name, 'footer'))) {
                $xml = $zip->getFromIndex($i);
                if ($xml) {
                    $fullText .= ' ' . strip_tags($xml);
                }
            }
        }
        $zip->close();

        return trim(preg_replace('/\s+/', ' ', $fullText));
    }

    /**
     * Mengonversi berkas lokal menjadi payload multimodal untuk Gemini
     */
    public static function processFileForGemini(string $filePath): array {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'Berkas tidak ditemukan di server.'];
        }

        $fileName = basename($filePath);
        $fileSize = filesize($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Batas ukuran 25MB
        if ($fileSize > 25 * 1024 * 1024) {
            return [
                'success' => false,
                'message' => "Ukuran berkas (" . round($fileSize / (1024 * 1024), 1) . " MB) melebihi batas 25 MB."
            ];
        }

        // Teks murni, kode program, HTML, Markdown
        $textExtensions = ['txt', 'py', 'js', 'html', 'css', 'java', 'cpp', 'c', 'php', 'json', 'csv', 'md', 'sql'];
        if (in_array($ext, $textExtensions, true)) {
            return [
                'success'     => true,
                'fileName'    => $fileName,
                'mimeType'    => 'text/plain',
                'isTextOnly'  => true,
                'textContent' => file_get_contents($filePath),
                'base64Data'  => null,
                'sizeBytes'   => $fileSize
            ];
        }

        // Word .docx: Ekstrak teks langsung dari XML agar ringan dan cepat
        if ($ext === 'docx') {
            $text = self::extractTextFromDocx($filePath);
            if (!empty($text)) {
                return [
                    'success'     => true,
                    'fileName'    => $fileName,
                    'mimeType'    => 'text/plain',
                    'isTextOnly'  => true,
                    'textContent' => $text,
                    'base64Data'  => null,
                    'sizeBytes'   => $fileSize
                ];
            }
        }

        // Biner Multimodal (PDF, Gambar, Audio, Video)
        $mimeType = 'application/octet-stream';
        if ($ext === 'pdf') {
            $mimeType = 'application/pdf';
        } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
            $mimeType = 'image/jpeg';
        } elseif (in_array($ext, ['png', 'webp'], true)) {
            $mimeType = "image/{$ext}";
        } elseif (in_array($ext, ['heic', 'heif'], true)) {
            $mimeType = "image/{$ext}";
        } elseif ($ext === 'mp3') {
            $mimeType = 'audio/mp3';
        } elseif ($ext === 'mp4') {
            $mimeType = 'video/mp4';
        }

        $fileContent = file_get_contents($filePath);
        $base64 = base64_encode($fileContent);

        return [
            'success'     => true,
            'fileName'    => $fileName,
            'mimeType'    => $mimeType,
            'isTextOnly'  => false,
            'textContent' => null,
            'base64Data'  => $base64,
            'sizeBytes'   => $fileSize
        ];
    }

    /**
     * Mengunduh berkas dari Google Drive, Google Docs, atau URL publik
     */
    public static function fetchRemoteOrDriveFile(string $urlOrRef): ?array {
        $trimmed = trim($urlOrRef);
        if (empty($trimmed)) return null;

        $fileId = null;
        $isGoogleDoc = false;
        $isGoogleSheet = false;
        $isGoogleSlide = false;

        if (preg_match('#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#i', $trimmed, $m)) {
            $fileId = $m[1];
            $isGoogleDoc = true;
        } elseif (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#i', $trimmed, $m)) {
            $fileId = $m[1];
            $isGoogleSheet = true;
        } elseif (preg_match('#docs\.google\.com/presentation/d/([a-zA-Z0-9_-]+)#i', $trimmed, $m)) {
            $fileId = $m[1];
            $isGoogleSlide = true;
        } elseif (preg_match('#(?:drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:export=download&)?id=)|/d/)([a-zA-Z0-9_-]+)#i', $trimmed, $m)) {
            $fileId = $m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_-]{25,}$/', $trimmed) && !str_contains($trimmed, ' ')) {
            $fileId = $trimmed;
        }

        $candidateUrls = [];
        if ($isGoogleDoc) {
            $candidateUrls[] = ['url' => "https://docs.google.com/document/d/{$fileId}/export?format=txt", 'type' => 'txt'];
            $candidateUrls[] = ['url' => "https://docs.google.com/document/d/{$fileId}/export?format=pdf", 'type' => 'pdf'];
        } elseif ($isGoogleSheet) {
            $candidateUrls[] = ['url' => "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=pdf", 'type' => 'pdf'];
        } elseif ($isGoogleSlide) {
            $candidateUrls[] = ['url' => "https://docs.google.com/presentation/d/{$fileId}/export/pdf", 'type' => 'pdf'];
        } elseif ($fileId) {
            $candidateUrls[] = ['url' => "https://drive.usercontent.google.com/download?id={$fileId}&export=download&confirm=t", 'type' => 'auto'];
            $candidateUrls[] = ['url' => "https://docs.google.com/uc?export=download&id={$fileId}", 'type' => 'auto'];
            $candidateUrls[] = ['url' => "https://drive.google.com/uc?export=download&id={$fileId}&confirm=t", 'type' => 'auto'];
        } elseif (preg_match('#^https?://#i', $trimmed)) {
            $candidateUrls[] = ['url' => $trimmed, 'type' => 'auto'];
        } else {
            return null;
        }

        $tempDir = self::getUploadDir() . '/temp';
        if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);

        $downloadedContent = null;
        $effectiveType = 'auto';
        $contentDispositionFilename = '';

        foreach ($candidateUrls as $candidate) {
            $ch = curl_init($candidate['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER    => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 35,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$contentDispositionFilename) {
                    if (stripos($header, 'Content-Disposition:') === 0) {
                        if (preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';\r\n]+)["\']?/i', $header, $matches)) {
                            $contentDispositionFilename = trim($matches[1]);
                        }
                    }
                    return strlen($header);
                }
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($resp)) {
                // Periksa apakah respon berupa halaman login Google (file privat)
                if (str_contains($resp, 'accounts.google.com') || 
                    str_contains($resp, 'Sign in - Google Accounts') || 
                    str_contains($resp, 'Google Drive - Access Denied') ||
                    (str_contains($resp, '<html') && str_contains($resp, 'ServiceLogin'))) {
                    throw new Exception("Berkas di Google Drive tidak dapat diakses secara publik (masih privat). Silakan ubah opsi berbagi file di Google Drive menjadi 'Siapa saja yang memiliki link' (Anyone with the link), atau unggah berkas soal langsung dari komputer Anda.");
                }

                $downloadedContent = $resp;
                $effectiveType = $candidate['type'];
                break;
            }
        }

        if (!$downloadedContent) {
            throw new Exception("Tidak dapat mengunduh berkas dari tautan Google Drive / URL yang diberikan. Pastikan tautan valid dan dibagikan secara publik ('Anyone with the link').");
        }

        // Teks UTF-8 langsung
        if ($effectiveType === 'txt' || (is_string($downloadedContent) && !str_starts_with($downloadedContent, '%PDF') && !str_starts_with($downloadedContent, "PK\x03\x04") && mb_check_encoding($downloadedContent, 'UTF-8') && !str_contains($downloadedContent, "\0") && !str_contains($downloadedContent, '<html'))) {
            return [
                'success'     => true,
                'fileName'    => $contentDispositionFilename ?: 'Soal_Tugas.txt',
                'mimeType'    => 'text/plain',
                'isTextOnly'  => true,
                'textContent' => $downloadedContent,
                'base64Data'  => null,
                'sizeBytes'   => strlen($downloadedContent)
            ];
        }

        $ext = 'bin';
        if (str_starts_with($downloadedContent, '%PDF')) {
            $ext = 'pdf';
        } elseif (str_starts_with($downloadedContent, "PK\x03\x04")) {
            $ext = 'docx';
        } elseif (str_starts_with($downloadedContent, "\xFF\xD8\xFF")) {
            $ext = 'jpg';
        } elseif (str_starts_with($downloadedContent, "\x89PNG")) {
            $ext = 'png';
        } elseif (strlen($downloadedContent) > 12 && str_contains(substr($downloadedContent, 4, 8), 'ftyp')) {
            $ext = 'heic';
        }

        $tempPath = $tempDir . '/remote_' . md5($trimmed) . '.' . $ext;
        file_put_contents($tempPath, $downloadedContent);

        $payload = self::processFileForGemini($tempPath);
        if ($contentDispositionFilename) {
            $payload['fileName'] = $contentDispositionFilename;
        }
        return $payload;
    }

    /**
     * Memvalidasi berkas soal di Google Drive, URL, atau lokal
     */
    public static function validateTaskFile(string $urlOrRef): array {
        $trimmed = trim($urlOrRef);
        if (empty($trimmed)) {
            return ['success' => false, 'message' => 'Tautan berkas atau teks soal tugas belum diisi.'];
        }

        if (file_exists($trimmed)) {
            $name = basename($trimmed);
            $size = round(filesize($trimmed) / 1024, 1);
            return [
                'success'   => true,
                'fileName'  => $name,
                'sizeBytes' => filesize($trimmed),
                'message'   => "Berkas lokal ditemukan: {$name} ({$size} KB)"
            ];
        }

        $isUrl = preg_match('#^https?://#i', $trimmed);
        $isDriveId = preg_match('/^[a-zA-Z0-9_-]{25,}$/', $trimmed);

        if ($isUrl || $isDriveId) {
            try {
                $payload = self::fetchRemoteOrDriveFile($trimmed);
                if ($payload && !empty($payload['success'])) {
                    $name = $payload['fileName'] ?? 'Dokumen Soal';
                    $size = !empty($payload['sizeBytes']) ? round($payload['sizeBytes'] / 1024, 1) . ' KB' : 'Siap dibaca';
                    return [
                        'success'   => true,
                        'fileName'  => $name,
                        'sizeBytes' => $payload['sizeBytes'] ?? 0,
                        'message'   => "Berkas berhasil diakses dari Drive: {$name} ({$size})"
                    ];
                }
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        return [
            'success'  => true,
            'fileName' => 'Teks Soal Langsung',
            'message'  => "Teks soal langsung terdeteksi (" . strlen($trimmed) . " karakter)."
        ];
    }

    /**
     * Membaca konten Soal Tugas (bisa teks langsung, berkas upload, atau Google Drive)
     */
    public static function getTaskQuestionPayload(?string $taskId = null, ?string $taskFileRef = null): ?array {
        // 1. Cek apakah ada berkas soal di folder pertanyaan tugas
        if (!empty($taskId)) {
            $questionDir = self::getTaskQuestionsDir($taskId);
            if (is_dir($questionDir)) {
                $files = array_diff(scandir($questionDir), ['.', '..']);
                if (!empty($files)) {
                    $firstFile = reset($files);
                    return self::processFileForGemini("{$questionDir}/{$firstFile}");
                }
            }
        }

        // 2. Jika ada referensi teks atau file di taskFileRef
        if (!empty($taskFileRef)) {
            $trimmed = trim($taskFileRef);
            if (file_exists($trimmed)) {
                return self::processFileForGemini($trimmed);
            }

            $isUrl = preg_match('#^https?://#i', $trimmed);
            $isDriveId = preg_match('/^[a-zA-Z0-9_-]{25,}$/', $trimmed);

            if ($isUrl || $isDriveId) {
                $remotePayload = self::fetchRemoteOrDriveFile($trimmed);
                if ($remotePayload) {
                    return $remotePayload;
                }
            }

            return [
                'success'     => true,
                'fileName'    => 'Petunjuk Soal Tugas',
                'isTextOnly'  => true,
                'textContent' => $trimmed,
                'base64Data'  => null,
                'mimeType'    => 'text/plain'
            ];
        }

        return null;
    }

    /**
     * Memilih kandidat berkas tugas terbaik/terbaru jika mahasiswa memiliki lebih dari 1 berkas jawaban
     * Mengurutkan berdasarkan timestamp (Google Drive modifiedTime atau filemtime lokal) dan nomor versi/revisi nama berkas
     */
    public static function selectLatestCandidate(array $candidates, string $taskId, string $classId): array {
        if (count($candidates) <= 1) {
            return $candidates[0] ?? [];
        }

        $driveMap = class_exists('GoogleDriveService') ? GoogleDriveService::getDriveFileMap($taskId, $classId) : [];
        $sharedMap = class_exists('GoogleDriveService') ? GoogleDriveService::getDriveFileMap($taskId, null) : [];

        $scored = [];
        foreach ($candidates as $cand) {
            $name = $cand['name'];
            $filePath = "{$cand['dir']}/{$name}";

            // 1. Dapatkan Timestamp modifikasi (Drive modifiedTime atau filemtime lokal)
            $mtime = 0;
            if (isset($driveMap[$name]['modifiedTime'])) {
                $t = strtotime($driveMap[$name]['modifiedTime']);
                if ($t > 0) $mtime = $t;
            } elseif (isset($sharedMap[$name]['modifiedTime'])) {
                $t = strtotime($sharedMap[$name]['modifiedTime']);
                if ($t > 0) $mtime = $t;
            }

            if ($mtime === 0 && file_exists($filePath)) {
                $mtime = (int)@filemtime($filePath);
            }

            // Ukuran berkas
            $size = file_exists($filePath) ? (int)@filesize($filePath) : 0;

            // 2. Deteksi indikator revisi / versi pada nama berkas
            $revScore = 0;

            // Pola (1), (2), (3) dari Google Drive / browser download
            if (preg_match('/\((?<v>\d+)\)\.[a-z0-9]+$/i', $name, $m)) {
                $revScore += ((int)$m['v']) * 10;
            }

            // Pola revisi, v2, v3, rev2
            if (preg_match('/[_-](?:v|ver|version|rev|revisi)[_-]?(?<v>\d+)?(?:\.[a-z0-9]+)?$/i', $name, $m)) {
                $num = !empty($m['v']) ? (int)$m['v'] : 1;
                $revScore += $num * 10;
            }

            // Pola penamaan final / fix / terbaru
            if (preg_match('/[_-](?:final|fix|terbaru|terakhir|update)(?:\.[a-z0-9]+)?$/i', $name)) {
                $revScore += 15;
            }

            $cand['_mtime'] = $mtime;
            $cand['_revScore'] = $revScore;
            $cand['_size'] = $size;
            $scored[] = $cand;
        }

        // Urutkan kandidat dari yang terbaru ke yang terlama
        usort($scored, function($a, $b) {
            // Berkas 0 bytes diposisikan paling bawah jika ada berkas yang valid
            if ($a['_size'] === 0 && $b['_size'] > 0) return 1;
            if ($b['_size'] === 0 && $a['_size'] > 0) return -1;

            // Selisih waktu modifikasi: jika selisih > 2 detik, waktu terbaru mutlak menang
            $timeDiff = $b['_mtime'] - $a['_mtime'];
            if (abs($timeDiff) > 2) {
                return $timeDiff;
            }

            // Jika waktu modifikasi sama/hampir bersamaan, gunakan penanda revisi
            if ($b['_revScore'] !== $a['_revScore']) {
                return $b['_revScore'] <=> $a['_revScore'];
            }

            // Fallback natural case-insensitive comparison (misal 'Tugas (2).pdf' > 'Tugas (1).pdf')
            return strnatcasecmp($b['name'], $a['name']);
        });

        $chosen = $scored[0];
        unset($chosen['_mtime'], $chosen['_revScore'], $chosen['_size']);
        return $chosen;
    }

    /**
     * Mencari dan menemukan berkas tugas mahasiswa secara cerdas dan akurat:
     * 1. Cek riwayat berkas di tabel grades (jika sudah ada dan berkas fisik masih ada di disk).
     * 2. Cari berdasarkan NIM mahasiswa (Prioritas Utama, Pass 1 - memeriksa seluruh berkas).
     * 3. Cari berdasarkan Nama Lengkap (Pass 2 - hanya jika NIM tidak ada sama sekali, minimal 2 kata & tanpa NIM mahasiswa lain).
     *
     * @return array|null ['name' => string, 'path' => string, 'isShared' => bool] atau null
     */
    public static function findStudentSubmissionFile(string $taskId, string $classId, string $nim, string $nama): ?array {
        $classDir = self::getSubmissionsDir($taskId, $classId);
        $sharedDir = self::getSharedSubmissionsDir($taskId);

        // 1. Cek riwayat berkas di tabel grades jika sudah tercatat sebelumnya
        try {
            $grade = Database::fetchOne(
                "SELECT file_name FROM grades WHERE id_tugas = ? AND id_kelas = ? AND LOWER(nim) = LOWER(?)",
                [$taskId, $classId, $nim]
            );
            if ($grade && !empty($grade['file_name']) && $grade['file_name'] !== '-') {
                $fName = $grade['file_name'];
                if (file_exists("{$classDir}/{$fName}")) {
                    return ['name' => $fName, 'path' => "{$classDir}/{$fName}", 'isShared' => false];
                }
                if (file_exists("{$sharedDir}/{$fName}")) {
                    return ['name' => $fName, 'path' => "{$sharedDir}/{$fName}", 'isShared' => true];
                }
            }
        } catch (Throwable $e) {}

        // Kumpulkan semua berkas kandidat di kelas dan shared
        $classFiles = is_dir($classDir) ? array_diff(scandir($classDir), ['.', '..', 'drive_files_map.json']) : [];
        $sharedFiles = is_dir($sharedDir) ? array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']) : [];

        $allCandidates = [];
        $knownClassFiles = [];
        foreach ($classFiles as $f) {
            $allCandidates[] = ['name' => $f, 'dir' => $classDir, 'isShared' => false];
            $knownClassFiles[] = $f;
        }
        foreach ($sharedFiles as $f) {
            if (!in_array($f, $knownClassFiles, true)) {
                $allCandidates[] = ['name' => $f, 'dir' => $sharedDir, 'isShared' => true];
            }
        }

        if (empty($allCandidates)) {
            return null;
        }

        $cleanNim = strtolower(trim($nim));
        $sanitizedNim = preg_replace('/[^0-9a-z]/', '', $cleanNim);
        $cleanNama = strtolower(trim($nama));

        $matchedCandidates = [];

        // 2. Pass 1: Prioritas NIM pada seluruh nama berkas
        foreach ($allCandidates as $cand) {
            $fileLower = strtolower($cand['name']);
            $sanitizedFile = preg_replace('/[^0-9a-z]/', '', $fileLower);

            if (!empty($cleanNim) && (str_contains($fileLower, $cleanNim) || (strlen($sanitizedNim) >= 4 && str_contains($sanitizedFile, $sanitizedNim)))) {
                $matchedCandidates[] = $cand;
            }
        }

        // 3. Pass 2: Prioritas Nama (hanya jika NIM tidak cocok pada seluruh berkas yang ada)
        if (empty($matchedCandidates) && !empty($cleanNama) && strlen($cleanNama) >= 4) {
            $nameWords = array_filter(explode(' ', $cleanNama), fn($w) => strlen(trim($w)) >= 3);
            foreach ($allCandidates as $cand) {
                $fileName = $cand['name'];
                $fileLower = strtolower($fileName);

                // Hindari mencocokkan berkas yang memuat NIM mahasiswa lain
                if (preg_match('/\b\d{6,}\b/', $fileName, $digits)) {
                    if (!empty($sanitizedNim) && !str_contains($digits[0], $sanitizedNim) && !str_contains($sanitizedNim, $digits[0])) {
                        continue;
                    }
                }

                if (str_contains($fileLower, $cleanNama)) {
                    $matchedCandidates[] = $cand;
                    continue;
                }

                if (count($nameWords) >= 2) {
                    $matchedWords = 0;
                    foreach ($nameWords as $w) {
                        if (str_contains($fileLower, $w)) $matchedWords++;
                    }
                    if ($matchedWords >= 2 && $matchedWords >= (count($nameWords) * 0.6)) {
                        $matchedCandidates[] = $cand;
                    }
                }
            }
        }

        if (!empty($matchedCandidates)) {
            $target = self::selectLatestCandidate($matchedCandidates, $taskId, $classId);
            if ($target) {
                return [
                    'name'     => $target['name'],
                    'path'     => "{$target['dir']}/{$target['name']}",
                    'isShared' => $target['isShared']
                ];
            }
        }

        return null;
    }

    /**
     * Mencari berkas tugas mahasiswa berdasarkan NIM, Nama, atau kecocokan isi
     */
    public static function getStudentSubmission(
        string $taskId,
        string $classId,
        string $nim,
        string $nama,
        string $apiKey = '',
        string $modelName = ''
    ): array {
        $classDir = self::getSubmissionsDir($taskId, $classId);
        $sharedDir = self::getSharedSubmissionsDir($taskId);

        $classFiles = is_dir($classDir) ? array_diff(scandir($classDir), ['.', '..', 'drive_files_map.json']) : [];
        $sharedFiles = is_dir($sharedDir) ? array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']) : [];

        // Gabungkan berkas: sertakan berkas kelas dan berkas shared
        $allCandidates = [];
        $knownClassFiles = [];
        foreach ($classFiles as $f) {
            $allCandidates[] = ['name' => $f, 'dir' => $classDir, 'isShared' => false];
            $knownClassFiles[] = $f;
        }
        foreach ($sharedFiles as $f) {
            // Hindari duplikasi jika nama berkas sudah ada di folder kelas
            if (!in_array($f, $knownClassFiles, true)) {
                $allCandidates[] = ['name' => $f, 'dir' => $sharedDir, 'isShared' => true];
            }
        }

        if (empty($allCandidates)) {
            return [
                'success' => false,
                'message' => "Belum ada berkas tugas yang diunggah di server untuk tugas ini. Silakan klik 'Sinkron Drive' atau 'Upload ZIP Tugas'."
            ];
        }

        $cleanNim = strtolower(trim($nim));
        $sanitizedNim = preg_replace('/[^0-9a-z]/', '', $cleanNim);
        $cleanNama = strtolower(trim($nama));

        $matchedCandidates = [];
        $matchedBy = 'filename';

        // 1. Prioritas NIM pada nama berkas (kumpulkan SEMUA yang cocok)
        foreach ($allCandidates as $cand) {
            $fileLower = strtolower($cand['name']);
            $sanitizedFile = preg_replace('/[^0-9a-z]/', '', $fileLower);

            if (!empty($cleanNim) && (str_contains($fileLower, $cleanNim) || (strlen($sanitizedNim) >= 4 && str_contains($sanitizedFile, $sanitizedNim)))) {
                $matchedCandidates[] = $cand;
            }
        }

        // 2. Prioritas Nama pada nama berkas jika NIM tidak cocok
        if (empty($matchedCandidates) && !empty($cleanNama) && strlen($cleanNama) >= 4) {
            // Pecah nama menjadi kata kunci utama (abaikan kata gelar/pendek < 3 huruf)
            $nameWords = array_filter(explode(' ', $cleanNama), fn($w) => strlen(trim($w)) >= 3);
            foreach ($allCandidates as $cand) {
                $fileLower = strtolower($cand['name']);
                if (str_contains($fileLower, $cleanNama)) {
                    $matchedCandidates[] = $cand;
                    continue;
                }
                // Jika mengandung minimal 2 kata nama berurutan
                if (count($nameWords) >= 2) {
                    $matchedWords = 0;
                    foreach ($nameWords as $w) {
                        if (str_contains($fileLower, $w)) $matchedWords++;
                    }
                    if ($matchedWords >= 2 && $matchedWords >= (count($nameWords) * 0.6)) {
                        $matchedCandidates[] = $cand;
                    }
                }
            }
        }

        // Jika ditemukan 1 atau beberapa berkas yang cocok, pilih berkas TERBARU / TERAKHIR
        if (!empty($matchedCandidates)) {
            $targetCandidate = self::selectLatestCandidate($matchedCandidates, $taskId, $classId);
        } else {
            $targetCandidate = null;
        }

        // 3. Pencarian Berdasarkan Isi Dokumen (Content-Based Matching)
        // PENTING: Hanya periksa berkas anonim (berkas yang TIDAK memuat NIM mahasiswa lain)!
        if (!$targetCandidate) {
            $checkedAiCount = 0;

            foreach ($allCandidates as $cand) {
                $fileName = $cand['name'];
                $filePath = "{$cand['dir']}/{$fileName}";
                $fileLower = strtolower($fileName);

                // Jika nama berkas memuat deretan angka 6 digit atau lebih dan BUKAN NIM target,
                // maka berkas ini sudah pasti milik mahasiswa lain. LEWATI SEKETIKA!
                if (preg_match('/\b\d{6,}\b/', $fileName, $digits)) {
                    if (!empty($sanitizedNim) && !str_contains($digits[0], $sanitizedNim) && !str_contains($sanitizedNim, $digits[0])) {
                        continue;
                    }
                }

                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

                // Cek teks dokumen DOCX secara lokal (sangat cepat, 0 detik, tanpa API)
                if ($ext === 'docx') {
                    $docText = strtolower(self::extractTextFromDocx($filePath));
                    if (!empty($cleanNim) && str_contains($docText, $cleanNim)) {
                        $targetCandidate = $cand;
                        $matchedBy = 'content';
                        break;
                    }
                }

                // Cek Gemini Vision HANYA jika berkas benar-benar anonim dan dibatasi maksimal 1 berkas
                if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'heic', 'heif'], true) && !empty($apiKey) && $checkedAiCount < 1) {
                    $checkedAiCount++;
                    try {
                        $payload = self::processFileForGemini($filePath);
                        if (!empty($payload['success'])) {
                            $identified = GeminiService::checkDocumentBelongsToStudent($payload, $cleanNim, $cleanNama, $apiKey, $modelName);
                            if ($identified && !empty($identified['isMatch'])) {
                                $targetCandidate = $cand;
                                $matchedBy = 'ai_vision';
                                break;
                            }
                        }
                    } catch (Throwable $e) {
                        // Abaikan error pada AI vision agar tidak merusak flow
                    }
                }
            }
        }

        if (!$targetCandidate) {
            return [
                'success' => false,
                'message' => "Berkas tugas tidak ditemukan untuk NIM '{$nim}' ({$nama})."
            ];
        }

        $sourcePath = "{$targetCandidate['dir']}/{$targetCandidate['name']}";

        // Jika berkas ditemukan di folder shared, salin otomatis ke folder kelas agar sinkron lokal
        if ($targetCandidate['isShared']) {
            if (!is_dir($classDir)) @mkdir($classDir, 0755, true);
            $localClassPath = "{$classDir}/{$targetCandidate['name']}";
            if (!file_exists($localClassPath)) {
                @copy($sourcePath, $localClassPath);
                $mtime = @filemtime($sourcePath);
                if ($mtime > 0) @touch($localClassPath, $mtime);
            }
            $targetPath = $localClassPath;
        } else {
            $targetPath = $sourcePath;
        }

        $processed = self::processFileForGemini($targetPath);
        $processed['matchedBy'] = $matchedBy;

        // Ambil link Google Drive jika tersedia dari pemetaan berkas
        $cleanFileName = $targetCandidate['name'];
        $driveMap = class_exists('GoogleDriveService') ? GoogleDriveService::getDriveFileMap($taskId, $classId) : [];
        if (isset($driveMap[$cleanFileName])) {
            $processed['driveUrl'] = $driveMap[$cleanFileName]['driveUrl'] ?? '';
            $processed['driveFileId'] = $driveMap[$cleanFileName]['fileId'] ?? '';
        } else {
            $processed['driveUrl'] = '';
            $processed['driveFileId'] = '';
        }

        return $processed;
    }
}
