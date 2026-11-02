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
                $baseFileName = basename($entryName);
                if (empty($baseFileName) || str_starts_with($baseFileName, '.')) continue;

                $cleanFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $baseFileName);
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
     * Membaca konten Soal Tugas (bisa teks langsung atau berkas yang diunggah)
     */
    public static function getTaskQuestionPayload(string $taskId, ?string $taskFileRef = null): ?array {
        // 1. Cek apakah ada berkas soal di folder pertanyaan tugas
        $questionDir = self::getTaskQuestionsDir($taskId);
        if (is_dir($questionDir)) {
            $files = array_diff(scandir($questionDir), ['.', '..']);
            if (!empty($files)) {
                $firstFile = reset($files);
                return self::processFileForGemini("{$questionDir}/{$firstFile}");
            }
        }

        // 2. Jika ada referensi teks atau file di taskFileRef
        if (!empty($taskFileRef)) {
            $trimmed = trim($taskFileRef);
            if (file_exists($trimmed)) {
                return self::processFileForGemini($trimmed);
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
