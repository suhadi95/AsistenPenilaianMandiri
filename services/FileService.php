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
        if ($ext === 'pdf') $mimeType = 'application/pdf';
        elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) $mimeType = "image/{$ext}";
        elseif ($ext === 'mp3') $mimeType = 'audio/mp3';
        elseif ($ext === 'mp4') $mimeType = 'video/mp4';

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
        $dir = self::getSubmissionsDir($taskId, $classId);
        if (!is_dir($dir)) {
            return [
                'success' => false,
                'message' => "Direktori berkas tugas belum dibuat untuk kelas ini. Silakan unggah tugas/ZIP terlebih dahulu."
            ];
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        if (empty($files)) {
            return [
                'success' => false,
                'message' => "Belum ada berkas tugas yang diunggah di server untuk kelas ini."
            ];
        }

        $cleanNim = strtolower(trim($nim));
        $sanitizedNim = preg_replace('/[^0-9a-z]/', '', $cleanNim);
        $cleanNama = strtolower(trim($nama));

        $targetFile = null;
        $matchedBy = 'filename';

        // 1. Prioritas NIM pada nama berkas
        foreach ($files as $file) {
            $fileLower = strtolower($file);
            $sanitizedFile = preg_replace('/[^0-9a-z]/', '', $fileLower);

            if (!empty($cleanNim) && (str_contains($fileLower, $cleanNim) || (strlen($sanitizedNim) >= 4 && str_contains($sanitizedFile, $sanitizedNim)))) {
                $targetFile = "{$dir}/{$file}";
                break;
            }
        }

        // 2. Prioritas Nama pada nama berkas jika NIM tidak cocok
        if (!$targetFile && !empty($cleanNama) && strlen($cleanNama) >= 4) {
            foreach ($files as $file) {
                $fileLower = strtolower($file);
                if (str_contains($fileLower, $cleanNama)) {
                    $targetFile = "{$dir}/{$file}";
                    break;
                }
            }
        }

        // 3. Pencarian Berdasarkan Isi Dokumen (Content-Based Matching)
        if (!$targetFile) {
            foreach ($files as $file) {
                $filePath = "{$dir}/{$file}";
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

                // Cek teks DOCX atau teks langsung
                if ($ext === 'docx') {
                    $docText = strtolower(self::extractTextFromDocx($filePath));
                    if (!empty($cleanNim) && str_contains($docText, $cleanNim)) {
                        $targetFile = $filePath;
                        $matchedBy = 'content';
                        break;
                    }
                }

                // Cek Gemini Vision jika PDF atau Gambar
                if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true) && !empty($apiKey)) {
                    $payload = self::processFileForGemini($filePath);
                    if ($payload['success']) {
                        $identified = GeminiService::checkDocumentBelongsToStudent($payload, $cleanNim, $cleanNama, $apiKey, $modelName);
                        if ($identified && !empty($identified['isMatch'])) {
                            $targetFile = $filePath;
                            $matchedBy = 'ai_vision';
                            break;
                        }
                    }
                }
            }
        }

        if (!$targetFile) {
            return [
                'success' => false,
                'message' => "Berkas tugas tidak ditemukan untuk NIM '{$nim}' ({$nama})."
            ];
        }

        $processed = self::processFileForGemini($targetFile);
        $processed['matchedBy'] = $matchedBy;
        return $processed;
    }
}
