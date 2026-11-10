<?php
/**
 * @fileoverview Layanan Eksekusi Penilaian Multimodal Berbasis Database Relasional
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/FileService.php';
require_once __DIR__ . '/IntegrityService.php';

class GradingService {
    /**
     * Menyimpan atau memperbarui data nilai mahasiswa
     */
    public static function saveGrade(array $gradeData): array {
        $taskId = trim($gradeData['id_tugas']);
        $classId = trim($gradeData['id_kelas']);
        $nim = trim($gradeData['nim']);

        $existing = Database::fetchOne(
            "SELECT id_nilai FROM grades WHERE id_tugas = ? AND id_kelas = ? AND LOWER(nim) = LOWER(?)",
            [$taskId, $classId, $nim]
        );

        $gradeId = $existing ? $existing['id_nilai'] : Helper::generateId('NIL');
        $driveUrl = !empty($gradeData['drive_file_url']) ? $gradeData['drive_file_url'] : null;

        $simScore = isset($gradeData['similarity_score']) && $gradeData['similarity_score'] !== '' ? $gradeData['similarity_score'] : null;
        $simTarget = !empty($gradeData['similarity_target_nim']) ? $gradeData['similarity_target_nim'] : null;
        $simDetails = !empty($gradeData['similarity_details']) ? (is_array($gradeData['similarity_details']) ? json_encode($gradeData['similarity_details'], JSON_UNESCAPED_UNICODE) : $gradeData['similarity_details']) : null;
        $aiDetected = isset($gradeData['ai_detected']) ? (int)$gradeData['ai_detected'] : null;
        $aiDetails = !empty($gradeData['ai_detected_details']) ? (is_array($gradeData['ai_detected_details']) ? json_encode($gradeData['ai_detected_details'], JSON_UNESCAPED_UNICODE) : $gradeData['ai_detected_details']) : null;

        if ($existing) {
            Database::execute(
                "UPDATE grades SET nama_mahasiswa = ?, file_name = ?, drive_file_url = COALESCE(?, drive_file_url), nilai_total = ?, rincian_poin = ?, catatan_ai = ?, similarity_score = COALESCE(?, similarity_score), similarity_target_nim = COALESCE(?, similarity_target_nim), similarity_details = COALESCE(?, similarity_details), ai_detected = COALESCE(?, ai_detected), ai_detected_details = COALESCE(?, ai_detected_details), status = ?, waktu_penilaian = ? WHERE id_nilai = ?",
                [
                    $gradeData['nama_mahasiswa'],
                    $gradeData['file_name'] ?? '',
                    $driveUrl,
                    isset($gradeData['nilai_total']) && $gradeData['nilai_total'] !== '' ? $gradeData['nilai_total'] : null,
                    $gradeData['rincian_poin'] ?? '',
                    $gradeData['catatan_ai'] ?? '',
                    $simScore,
                    $simTarget,
                    $simDetails,
                    $aiDetected,
                    $aiDetails,
                    $gradeData['status'] ?? 'Belum Dinilai',
                    $gradeData['waktu_penilaian'] ?? date('d/m/Y H:i:s'),
                    $gradeId
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO grades (id_nilai, id_tugas, id_kelas, nim, nama_mahasiswa, file_name, drive_file_url, nilai_total, rincian_poin, catatan_ai, similarity_score, similarity_target_nim, similarity_details, ai_detected, ai_detected_details, status, waktu_penilaian, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $gradeId,
                    $taskId,
                    $classId,
                    $nim,
                    $gradeData['nama_mahasiswa'],
                    $gradeData['file_name'] ?? '',
                    $driveUrl,
                    isset($gradeData['nilai_total']) && $gradeData['nilai_total'] !== '' ? $gradeData['nilai_total'] : null,
                    $gradeData['rincian_poin'] ?? '',
                    $gradeData['catatan_ai'] ?? '',
                    $simScore,
                    $simTarget,
                    $simDetails,
                    $aiDetected ?? 0,
                    $aiDetails,
                    $gradeData['status'] ?? 'Belum Dinilai',
                    $gradeData['waktu_penilaian'] ?? date('d/m/Y H:i:s'),
                    date('Y-m-d H:i:s')
                ]
            );
        }

        $gradeData['id_nilai'] = $gradeId;
        return $gradeData;
    }

    /**
     * Menilai tugas satu mahasiswa secara spesifik
     */
    public static function gradeStudent(array $task, array $student, array $settings, ?array $taskQuestionData = null): array {
        $nim = trim($student['nim']);
        $nama = trim($student['nama_mahasiswa']);
        $classId = trim($student['id_kelas'] ?? $task['id_kelas'] ?? '');
        $taskId = trim($task['id_tugas']);

        // Catat status sedang menilai
        self::saveGrade([
            'id_tugas'        => $taskId,
            'id_kelas'        => $classId,
            'nim'             => $nim,
            'nama_mahasiswa'  => $nama,
            'file_name'       => '',
            'status'          => '⏳ Sedang Menilai...',
            'waktu_penilaian' => date('d/m/Y H:i:s')
        ]);

        try {
            // 1. Ambil berkas jawaban mahasiswa
            $submission = FileService::getStudentSubmission(
                $taskId,
                $classId,
                $nim,
                $nama,
                $settings['rawApiKey'] ?? '',
                $settings['modelName'] ?? 'gemini-3.5-flash-lite'
            );

            if (!$submission['success']) {
                $subMsg = $submission['message'] ?? '';
                $lowerMsg = strtolower($subMsg);

                // Jika berkas memang belum dikumpulkan oleh mahasiswa
                if (str_contains($lowerMsg, 'tidak ditemukan') || str_contains($lowerMsg, 'belum ada berkas')) {
                    $unsubmittedNote = "Mahasiswa belum mengumpulkan berkas tugas di folder Drive / server.";
                    self::saveGrade([
                        'id_tugas'        => $taskId,
                        'id_kelas'        => $classId,
                        'nim'             => $nim,
                        'nama_mahasiswa'  => $nama,
                        'file_name'       => '-',
                        'nilai_total'     => null,
                        'rincian_poin'    => '-',
                        'catatan_ai'      => $unsubmittedNote,
                        'status'          => 'Belum Mengumpulkan',
                        'waktu_penilaian' => date('d/m/Y H:i:s')
                    ]);

                    return [
                        'success'         => true,
                        'isSubmitted'     => false,
                        'nim'             => $nim,
                        'nama'            => $nama,
                        'score'           => '-',
                        'fileName'        => '-',
                        'rincian_poin'    => '-',
                        'catatan_ai'      => $unsubmittedNote,
                        'status'          => 'Belum Mengumpulkan',
                        'waktu_penilaian' => date('d/m/Y H:i:s')
                    ];
                }

                throw new Exception($submission['message']);
            }

            // 2. Evaluasi menggunakan Gemini API
            $evalResult = GeminiService::evaluateSubmission(
                $submission,
                ['nim' => $nim, 'nama' => $nama],
                $task['rubrik_text'] ?: 'Nilai secara objektif dengan skala 0 - 100.',
                $settings['rawApiKey'] ?? '',
                $settings['modelName'] ?? 'gemini-3.5-flash-lite',
                $taskQuestionData
            );

            $data = $evalResult['data'];

            // 3. Format rincian poin per butir kriteria
            $formattedCriteria = '';
            if (!empty($data['criteriaScores']) && is_array($data['criteriaScores'])) {
                $lines = [];
                foreach ($data['criteriaScores'] as $c) {
                    $lines[] = "• {$c['criterion']}: {$c['score']}/{$c['maxScore']} poin ({$c['feedback']})";
                }
                $formattedCriteria = implode("\n", $lines);
            }

            // 4. Format catatan evaluasi AI
            $fullFeedback = $data['summaryFeedback'] ?? '';
            if (!empty($data['strengths']) && is_array($data['strengths'])) {
                $fullFeedback .= "\n\n[Kelebihan Jawaban]:\n" . implode("\n", array_map(fn($s) => "✓ {$s}", $data['strengths']));
            }
            if (!empty($data['improvements']) && is_array($data['improvements'])) {
                $fullFeedback .= "\n\n[Catatan & Rekomendasi]:\n" . implode("\n", array_map(fn($im) => "⚠ {$im}", $data['improvements']));
            }

            // 5. Pindai Residu Bot AI secara deterministik lokal & integrasi multimodal
            $localText = '';
            if (!empty($submission['isTextOnly'])) {
                $localText = $submission['textContent'] ?? '';
            } else {
                $subDir = FileService::getSubmissionsDir($taskId, $classId);
                $cleanSubFile = basename($submission['fileName'] ?? '');
                $p = "{$subDir}/{$cleanSubFile}";
                if (file_exists($p)) {
                    $localText = IntegrityService::extractTextFromFile($p);
                } else {
                    $sharedDir = FileService::getSharedSubmissionsDir($taskId);
                    $pShared = "{$sharedDir}/{$cleanSubFile}";
                    if (file_exists($pShared)) {
                        $localText = IntegrityService::extractTextFromFile($pShared);
                    }
                }
            }

            $aiScan = IntegrityService::detectAiBotResidue($localText);
            $aiDetected = $aiScan['detected'] ? 1 : 0;
            $aiDetails = $aiScan;

            if (!empty($data['aiResidueDetected'])) {
                $aiDetected = 1;
                $geminiQuotes = !empty($data['aiResidueQuotes']) && is_array($data['aiResidueQuotes']) ? $data['aiResidueQuotes'] : [];
                foreach ($geminiQuotes as $q) {
                    $aiDetails['matches'][] = [
                        'category'    => 'Residu Bot AI (Deteksi Multimodal Gemini)',
                        'label'       => $q,
                        'matchedText' => $q,
                        'lineNumber'  => 1,
                        'snippet'     => "👉 {$q}"
                    ];
                }
                $aiDetails['detected'] = true;
                $aiDetails['matchCount'] = count($aiDetails['matches']);
                $aiDetails['summary'] = "Terdeteksi sisa percakapan bot AI: " . implode(', ', array_slice($geminiQuotes, 0, 2));
            }

            if ($aiDetected) {
                $quotesList = array_map(fn($m) => "• \"{$m['matchedText']}\" ({$m['category']})", $aiDetails['matches']);
                $fullFeedback .= "\n\n🚨 [INDIKASI AI - RESIDU PERCAKAPAN BOT]:\nDitemukan sisa percakapan chatbot AI yang tertinggal dalam dokumen tugas:\n" . implode("\n", array_slice($quotesList, 0, 4));
            }

            // 6. Simpan nilai ke basis data
            $driveUrl = $submission['driveUrl'] ?? '';
            self::saveGrade([
                'id_tugas'            => $taskId,
                'id_kelas'            => $classId,
                'nim'                 => $nim,
                'nama_mahasiswa'      => $nama,
                'file_name'           => $submission['fileName'] ?? '',
                'drive_file_url'      => $driveUrl,
                'nilai_total'         => $data['totalScore'],
                'rincian_poin'        => $formattedCriteria,
                'catatan_ai'          => $fullFeedback,
                'ai_detected'         => $aiDetected,
                'ai_detected_details' => $aiDetails,
                'status'              => 'Selesai',
                'waktu_penilaian'     => date('d/m/Y H:i:s')
            ]);

            return [
                'success'             => true,
                'nim'                 => $nim,
                'nama'                => $nama,
                'score'               => $data['totalScore'],
                'fileName'            => $submission['fileName'] ?? '',
                'driveUrl'            => $driveUrl,
                'rincian_poin'        => $formattedCriteria,
                'catatan_ai'          => $fullFeedback,
                'ai_detected'         => $aiDetected,
                'ai_detected_details' => $aiDetails,
                'status'              => 'Selesai',
                'waktu_penilaian'     => date('d/m/Y H:i:s')
            ];

        } catch (Exception $err) {
            $rawErrMsg = $err->getMessage();
            $lowerErr = strtolower($rawErrMsg);

            $categorizedReason = $rawErrMsg;
            $advice = '';

            if (str_contains($lowerErr, 'tidak ditemukan')) {
                $categorizedReason = "Berkas tugas tidak ditemukan untuk NIM '{$nim}' ({$nama}).";
                $advice = "Pastikan berkas tugas telah diunggah ke server (klik tombol 'Upload ZIP Tugas') dengan nama berkas memuat NIM atau nama mahasiswa.";
            } elseif (str_contains($lowerErr, 'belum ada berkas') || str_contains($lowerErr, 'belum dibuat')) {
                $categorizedReason = "Belum ada berkas tugas mahasiswa yang diunggah ke server untuk kelas ini.";
                $advice = "Silakan klik tombol ungu 'Upload ZIP Tugas' di bagian atas tabel untuk mengunggah 1 file ZIP berisi seluruh berkas jawaban tugas mahasiswa.";
            } elseif (str_contains($rawErrMsg, '429') || str_contains($lowerErr, 'quota') || str_contains($lowerErr, 'kuota') || str_contains($lowerErr, 'limit')) {
                $categorizedReason = "Batas kuota Gemini API tercapai (Rate Limit / Quota Exceeded).";
                $advice = "Tunggu 1 menit lalu coba ulangi penilaian, atau gunakan model gemini-3.5-flash-lite.";
            } elseif (str_contains($lowerErr, 'api key') || str_contains($rawErrMsg, '403') || str_contains($rawErrMsg, '401')) {
                $categorizedReason = "Kunci API Gemini tidak valid atau belum memiliki izin akses.";
                $advice = "Buka tab Pengaturan, periksa kembali Gemini API Key Anda dan klik 'Uji Koneksi'.";
            } elseif (str_contains($lowerErr, 'melebihi') || str_contains($lowerErr, '25 mb')) {
                $categorizedReason = "Ukuran berkas melebihi batas aman 25 MB.";
                $advice = "Minta mahasiswa mengompres berkas atau mengubahnya ke PDF ringkas.";
            }

            $fullFailureNote = "[PENILAIAN GAGAL]:\n{$categorizedReason}" . ($advice ? "\n\n💡 Rekomendasi Solusi:\n{$advice}" : '');

            self::saveGrade([
                'id_tugas'        => $taskId,
                'id_kelas'        => $classId,
                'nim'             => $nim,
                'nama_mahasiswa'  => $nama,
                'file_name'       => '',
                'nilai_total'     => null,
                'rincian_poin'    => '',
                'catatan_ai'      => $fullFailureNote,
                'status'          => "Gagal: {$categorizedReason}",
                'waktu_penilaian' => date('d/m/Y H:i:s')
            ]);

            return [
                'success'         => false,
                'message'         => $categorizedReason,
                'nim'             => $nim,
                'nama'            => $nama,
                'error'           => $categorizedReason,
                'advice'          => $advice,
                'status'          => "Gagal: {$categorizedReason}",
                'catatan_ai'      => $fullFailureNote,
                'waktu_penilaian' => date('d/m/Y H:i:s')
            ];
        }
    }

    /**
     * Mereset seluruh nilai yang gagal menjadi 'Belum Dinilai'
     */
    public static function resetFailedGrades(string $taskId, string $classId): array {
        Database::execute(
            "UPDATE grades SET status = 'Belum Dinilai', catatan_ai = '' WHERE id_tugas = ? AND id_kelas = ? AND status LIKE 'Gagal%'",
            [$taskId, $classId]
        );
        return [
            'success' => true,
            'message' => 'Seluruh status penilaian yang gagal berhasil direset.'
        ];
    }
}
