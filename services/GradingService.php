<?php
/**
 * @fileoverview Layanan Eksekusi Penilaian Multimodal Berbasis Database Relasional
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/FileService.php';

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

        if ($existing) {
            Database::execute(
                "UPDATE grades SET nama_mahasiswa = ?, file_name = ?, nilai_total = ?, rincian_poin = ?, catatan_ai = ?, status = ?, waktu_penilaian = ? WHERE id_nilai = ?",
                [
                    $gradeData['nama_mahasiswa'],
                    $gradeData['file_name'] ?? '',
                    isset($gradeData['nilai_total']) && $gradeData['nilai_total'] !== '' ? $gradeData['nilai_total'] : null,
                    $gradeData['rincian_poin'] ?? '',
                    $gradeData['catatan_ai'] ?? '',
                    $gradeData['status'] ?? 'Belum Dinilai',
                    $gradeData['waktu_penilaian'] ?? date('d/m/Y H:i:s'),
                    $gradeId
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO grades (id_nilai, id_tugas, id_kelas, nim, nama_mahasiswa, file_name, nilai_total, rincian_poin, catatan_ai, status, waktu_penilaian, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $gradeId,
                    $taskId,
                    $classId,
                    $nim,
                    $gradeData['nama_mahasiswa'],
                    $gradeData['file_name'] ?? '',
                    isset($gradeData['nilai_total']) && $gradeData['nilai_total'] !== '' ? $gradeData['nilai_total'] : null,
                    $gradeData['rincian_poin'] ?? '',
                    $gradeData['catatan_ai'] ?? '',
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

            // 5. Simpan nilai ke basis data
            self::saveGrade([
                'id_tugas'        => $taskId,
                'id_kelas'        => $classId,
                'nim'             => $nim,
                'nama_mahasiswa'  => $nama,
                'file_name'       => $submission['fileName'] ?? '',
                'nilai_total'     => $data['totalScore'],
                'rincian_poin'    => $formattedCriteria,
                'catatan_ai'      => $fullFeedback,
                'status'          => 'Selesai',
                'waktu_penilaian' => date('d/m/Y H:i:s')
            ]);

            return [
                'success'         => true,
                'nim'             => $nim,
                'nama'            => $nama,
                'score'           => $data['totalScore'],
                'fileName'        => $submission['fileName'] ?? '',
                'rincian_poin'    => $formattedCriteria,
                'catatan_ai'      => $fullFeedback,
                'status'          => 'Selesai',
                'waktu_penilaian' => date('d/m/Y H:i:s')
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
