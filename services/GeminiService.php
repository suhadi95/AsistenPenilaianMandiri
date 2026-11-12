<?php
/**
 * @fileoverview Layanan Integrasi Gemini API (Multimodal & Format Terstruktur JSON)
 * Model default: gemini-3.5-flash-lite
 */

require_once __DIR__ . '/../core/Helper.php';

class GeminiService {
    private const DEFAULT_MODEL = 'gemini-3.5-flash-lite';
    private const API_BASE_URL  = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Menguji keabsahan API Key dan ketersediaan model
     */
    public static function testConnection(string $apiKey, ?string $model = null): array {
        $cleanKey = trim($apiKey);
        if (empty($cleanKey)) {
            return ['success' => false, 'message' => 'Gemini API Key belum dimasukkan.'];
        }

        $selectedModel = !empty($model) ? trim($model) : self::DEFAULT_MODEL;
        $url = self::API_BASE_URL . "/{$selectedModel}:generateContent?key=" . urlencode($cleanKey);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Halo Gemini! Uji koneksi asisten penilaian mandiri.']
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 15
            ]
        ];

        $response = self::sendPostRequest($url, $payload);
        $code = $response['http_code'];
        $body = $response['body'];

        if ($code === 200) {
            return [
                'success' => true,
                'message' => "Koneksi berhasil! Model [{$selectedModel}] aktif dan siap digunakan."
            ];
        }

        $errorMsg = "HTTP {$code}";
        $errObj = json_decode($body, true);
        if (isset($errObj['error']['message'])) {
            $errorMsg = $errObj['error']['message'];
        }

        if ($code === 404) {
            return [
                'success' => false,
                'message' => "Model '{$selectedModel}' tidak ditemukan. Coba beralih ke model gemini-3.5-flash-lite, gemini-2.5-flash, atau gemini-2.0-flash-lite."
            ];
        }

        if ($code === 429) {
            return [
                'success' => false,
                'message' => "Limit kuota untuk model '{$selectedModel}' tercapai (HTTP 429). Solusi: gunakan model gemini-3.5-flash-lite atau gemini-2.0-flash-lite."
            ];
        }

        return ['success' => false, 'message' => "Gagal terhubung ({$code}): {$errorMsg}"];
    }

    /**
     * Mengekstrak porsi poin dan rubrik dari berkas/teks soal tugas
     */
    public static function extractRubricFromTask(array $taskQuestionData, string $apiKey, ?string $model = null): array {
        $cleanKey = trim($apiKey);
        if (empty($cleanKey)) {
            throw new Exception('Gemini API Key belum dikonfigurasi.');
        }

        $selectedModel = !empty($model) ? trim($model) : self::DEFAULT_MODEL;
        $url = self::API_BASE_URL . "/{$selectedModel}:generateContent?key=" . urlencode($cleanKey);

        $parts = [];
        $parts[] = [
            'text' => 'Analisis berkas soal tugas berikut. Periksa secara teliti apakah di dalam dokumen soal terdapat informasi bobot nilai, porsi poin, atau kriteria penilaian (contoh: "Bobot: 30 Poin", "Nilai Maks: 20", "Porsi 25%", dsb).'
        ];

        if (!empty($taskQuestionData['isTextOnly'])) {
            $parts[] = ['text' => "=== DOKUMEN SOAL TUGAS ===\n" . ($taskQuestionData['textContent'] ?? '') . "\n=== AKHIR DOKUMEN SOAL ==="];
            if (!empty($taskQuestionData['embeddedImages']) && is_array($taskQuestionData['embeddedImages'])) {
                foreach ($taskQuestionData['embeddedImages'] as $qIdx => $qImg) {
                    $parts[] = ['text' => "Gambar/Diagram Acuan Soal #" . ($qIdx + 1) . " (" . ($qImg['name'] ?? 'gambar') . "):"];
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => $qImg['mimeType'] ?? 'image/png',
                            'data'      => $qImg['base64Data']
                        ]
                    ];
                }
            }
        } elseif (!empty($taskQuestionData['base64Data'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $taskQuestionData['mimeType'] ?? 'application/pdf',
                    'data'      => $taskQuestionData['base64Data']
                ]
            ];
        }

        $instruction = "
TUGAS ANDA:
1. Periksa apakah pada setiap nomor atau bagian soal di atas sudah tertulis bobot/porsi poin penilaiannya.
2. JIKA ADA:
   - Tetapkan pointsFound = true.
   - Ekstrak seluruh butir kriteria/soal beserta porsi poin maksimal yang tertulis dalam dokumen.
   - Buat format rubrik Markdown yang rapi dengan judul '## PANDUAN PORSI NILAI (DARI DOKUMEN SOAL)'.
3. JIKA TIDAK ADA bobot/porsi poin tertulis dalam soal:
   - Tetapkan pointsFound = false.
   - Buatlah saran pembagian poin yang seimbang untuk setiap butir soal yang ada di dokumen (total akumulasi 100 poin).
   - Buat format rubrik Markdown dengan judul '## PANDUAN PORSI NILAI (SARAN AI - BELUM ADA BOBOT DI SOAL)'.
";
        $parts[] = ['text' => $instruction];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => 'Anda adalah Asisten Kurikulum Akademik. Tugas Anda menganalisis berkas soal ujian/tugas, mendeteksi bobot poin yang tertera, dan menghasilkan skema penilaian terstruktur dalam JSON.']
                ]
            ],
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature'       => 0.1,
                'responseMimeType'  => 'application/json',
                'responseSchema'    => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'pointsFound'        => ['type' => 'BOOLEAN'],
                        'totalPoints'        => ['type' => 'NUMBER'],
                        'formattedRubricText'=> ['type' => 'STRING'],
                        'explanation'        => ['type' => 'STRING']
                    ],
                    'required'   => ['pointsFound', 'formattedRubricText', 'explanation']
                ]
            ]
        ];

        $response = self::sendPostRequest($url, $payload);
        if ($response['http_code'] !== 200) {
            $errMsg = "HTTP {$response['http_code']}";
            $errObj = json_decode($response['body'], true);
            if (isset($errObj['error']['message'])) $errMsg = $errObj['error']['message'];
            throw new Exception("Gagal menganalisis dokumen soal: {$errMsg}");
        }

        $resJson = json_decode($response['body'], true);
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = json_decode($rawText, true);

        if (!$parsed) {
            throw new Exception("Format respons JSON tidak valid dari Gemini.");
        }

        $pointsFound = (bool)($parsed['pointsFound'] ?? false);
        $totalPoints = $parsed['totalPoints'] ?? 100;
        $msg = $pointsFound 
            ? "Berhasil mendeteksi bobot dari dokumen soal (Total {$totalPoints} Poin)."
            : "Dokumen soal terbaca: Belum ada bobot nilai tertulis di soal, AI membuatkan saran porsi poin otomatis.";

        return [
            'success'     => true,
            'pointsFound' => $pointsFound,
            'totalPoints' => $totalPoints,
            'rubricText'  => $parsed['formattedRubricText'] ?? '',
            'explanation' => $parsed['explanation'] ?? '',
            'message'     => $msg
        ];
    }

    /**
     * Mengevaluasi berkas tugas mahasiswa menggunakan Gemini Multimodal
     */
    public static function evaluateSubmission(
        array $submissionData,
        array $studentInfo,
        string $rubricText,
        string $apiKey,
        ?string $model = null,
        ?array $taskQuestionData = null
    ): array {
        $cleanKey = trim($apiKey);
        if (empty($cleanKey)) {
            throw new Exception('Gemini API Key belum dikonfigurasi.');
        }

        $selectedModel = !empty($model) ? trim($model) : self::DEFAULT_MODEL;
        $url = self::API_BASE_URL . "/{$selectedModel}:generateContent?key=" . urlencode($cleanKey);

        $parts = [];

        // 1. Lampirkan Soal Acuan jika tersedia
        if (!empty($taskQuestionData)) {
            $parts[] = ['text' => "=== DOKUMEN ACUAN: SOAL TUGAS / PETUNJUK PENILAIAN (" . ($taskQuestionData['fileName'] ?? 'Soal Tugas') . ") ==="];
            if (!empty($taskQuestionData['isTextOnly'])) {
                $parts[] = ['text' => $taskQuestionData['textContent'] ?? ''];
                if (!empty($taskQuestionData['embeddedImages']) && is_array($taskQuestionData['embeddedImages'])) {
                    foreach ($taskQuestionData['embeddedImages'] as $qIdx => $qImg) {
                        $parts[] = ['text' => "Gambar/Diagram Acuan Soal #" . ($qIdx + 1) . " (" . ($qImg['name'] ?? 'gambar') . "):"];
                        $parts[] = [
                            'inline_data' => [
                                'mime_type' => $qImg['mimeType'] ?? 'image/png',
                                'data'      => $qImg['base64Data']
                            ]
                        ];
                    }
                }
            } elseif (!empty($taskQuestionData['base64Data'])) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $taskQuestionData['mimeType'] ?? 'application/pdf',
                        'data'      => $taskQuestionData['base64Data']
                    ]
                ];
            }
            $parts[] = ['text' => "=== AKHIR DOKUMEN SOAL ACUAN ===\n"];
        }

        // 2. Lampirkan Berkas Jawaban Mahasiswa
        $parts[] = ['text' => "=== BERKAS JAWABAN MAHASISWA: " . ($studentInfo['nama'] ?? '-') . " (NIM: " . ($studentInfo['nim'] ?? '-') . ") - File: " . ($submissionData['fileName'] ?? '') . " ==="];
        if (!empty($submissionData['isTextOnly'])) {
            $parts[] = ['text' => $submissionData['textContent'] ?? ''];

            // Lampirkan seluruh gambar/diagram yang diekstrak dari dokumen Word (.docx) secara multimodal
            if (!empty($submissionData['embeddedImages']) && is_array($submissionData['embeddedImages'])) {
                $parts[] = ['text' => "\n=== LAMPIRAN GAMBAR / DIAGRAM DARI DOKUMEN WORD (.DOCX) MAHASISWA ==="];
                $parts[] = ['text' => "Catatan Penting Penilai: Di bawah ini adalah gambar/diagram visual yang disematkan atau ditempelkan oleh mahasiswa di dalam berkas dokumen Word tugasnya. Periksalah gambar diagram visual ini secara seksama untuk menilai pemenuhan kriteria pembuatan diagram (Class Diagram, Use Case, Activity Diagram, ERD, relasi antarkelas, notasi UML, multiplicity, atribut, dan tipe data)."];
                foreach ($submissionData['embeddedImages'] as $sIdx => $sImg) {
                    $parts[] = ['text' => "Gambar / Diagram Mahasiswa #" . ($sIdx + 1) . " (" . ($sImg['name'] ?? 'gambar') . "):"];
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => $sImg['mimeType'] ?? 'image/png',
                            'data'      => $sImg['base64Data']
                        ]
                    ];
                }
                $parts[] = ['text' => "=== AKHIR LAMPIRAN GAMBAR DARI DOKUMEN WORD (.DOCX) ===\n"];
            }
        } elseif (!empty($submissionData['base64Data'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $submissionData['mimeType'] ?? 'application/pdf',
                    'data'      => $submissionData['base64Data']
                ]
            ];
        }
        $parts[] = ['text' => "=== AKHIR BERKAS JAWABAN MAHASISWA ===\n"];

        // 3. Prompt Evaluasi Objektif
        $evalPrompt = "
Lakukan penilaian tugas mahasiswa secara seksama, adil, transparan, dan objektif.

IDENTITAS MAHASISWA:
• NIM  : " . ($studentInfo['nim'] ?? '-') . "
• Nama : " . ($studentInfo['nama'] ?? '-') . "
• Berkas Jawaban: " . ($submissionData['fileName'] ?? '') . "

PANDUAN PORSI NILAI & RUBRIK:
{$rubricText}

INSTRUKSI PENILAIAN KRUSIAL:
1. Bacalah jawaban mahasiswa dan bandingkan dengan SOAL TUGAS / PETUNJUK PENILAIAN / PANDUAN JAWABAN di atas.
2. Nilai setiap butir soal/kriteria secara ketat berdasarkan PORSI POIN MAKSIMAL yang telah ditentukan (score / maxScore).
3. ATURAN WAJIB FEEDBACK PER BUTIR KRITERIA (criteriaScores.feedback):
   - Jika perolehan nilai MAKSIMAL (score == maxScore): Tuliskan feedback yang mengapresiasi dan membuktikan bahwa butir kriteria ini telah dijawab secara lengkap dan tepat tanpa celah.
   - Jika perolehan nilai TIDAK MAKSIMAL (score < maxScore): Anda WAJIB SECARA EKSPLISIT menjelaskan alasan pemotongan nilai dan bagian mana yang kurang/salah/belum lengkap!
     Contoh format: '[Bagian yang sudah benar], namun terdapat pengurangan X poin karena [sebutkan kekurangan/kesalahan spesifik mengapa tidak mendapat poin maksimal]'.
     JANGAN PERNAH memberikan nilai tidak maksimal (seperti 22.5/25) hanya dengan kalimat pujian tanpa menyebutkan penyebab kekurangannya!
4. Hitung Nilai Total akumulatif (totalScore) secara matematis akurat dari penjumlahan skor butir kriteria (skala 0 - 100).
5. Buat Ulasan Evaluasi Terstruktur:
   - summaryFeedback: Rangkuman umum performa dan capaian mahasiswa dalam mengerjakan tugas ini.
   - strengths: 2-4 butir poin kelebihan dan aspek positif yang menonjol dari jawaban mahasiswa.
   - weaknesses: 2-4 butir poin kekurangan, kekeliruan, atau hal yang belum lengkap dari jawaban mahasiswa (terutama yang menyebabkan pengurangan nilai).
   - revisions: 2-4 butir langkah konkret, spesifik, dan actionable apa yang harus diperbaiki/ditambahkan mahasiswa jika ingin melakukan perbaikan nilai atau revisi tugas.
6. PEMERIKSAAN RESIDU BOT AI: Teliti apakah di berkas jawaban mahasiswa terdapat kalimat percakapan chatbot AI yang tertinggal karena mahasiswa teledor lupa menghapus (misal: 'Apakah jawaban ini sudah cukup?', 'Semoga membantu', 'Tentu, ini solusinya', 'Sebagai model AI', 'Is there anything else I can help with?'). Jika ada, tetapkan aiResidueDetected = true dan kutip kalimat persisnya di aiResidueQuotes.
7. PEMERIKSAAN DIAGRAM & GAMBAR VISUAL (SANGAT PENTING):
   - Mahasiswa dapat melampirkan diagram baik di dalam dokumen PDF maupun disematkan di dalam dokumen Word (.docx). Seluruh gambar/diagram dari dokumen telah dilampirkan secara multimodal untuk Anda periksa (baik pada halaman PDF maupun pada bagian [LAMPIRAN GAMBAR / DIAGRAM DARI DOKUMEN WORD (.DOCX) MAHASISWA]).
   - Periksa secara teliti keberadaan diagram visual pada berkas jawaban mahasiswa (misalnya Class Diagram, Use Case Diagram, Activity Diagram, Sequence Diagram, ERD, Flowchart, sketsa rancangan, dsb).
   - JANGAN PERNAH menyimpulkan bahwa gambar diagram 'kosong', 'tidak dilampirkan', atau 'belum ditempelkan' apabila terdapat gambar/diagram yang relevan di berkas PDF maupun pada lampiran gambar dokumen Word tersebut!
   - Periksalah ketepatan notasi UML, nama kelas, atribut, tipe data, relasi (association, dependency, inheritance, aggregation, composition), dan multiplicity yang tertera pada diagram visual tersebut secara objektif sesuai rubrik.
";
        $parts[] = ['text' => $evalPrompt];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => 'Anda adalah Asisten Dosen Senior dan Penilai Akademik Profesional yang sangat teliti, adil, objektif, dan suportif. Anda memeriksa jawaban mahasiswa berdasarkan soal dan pedoman rubrik/porsi nilai yang ditentukan, menghitung skor secara matematis tepat, dan mengembalikan hasil dalam format JSON terstruktur.']
                ]
            ],
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'totalScore'     => ['type' => 'NUMBER', 'description' => 'Nilai total akhir mahasiswa (skala 0 - 100)'],
                        'criteriaScores' => [
                            'type'  => 'ARRAY',
                            'items' => [
                                'type'       => 'OBJECT',
                                'properties' => [
                                    'criterion' => ['type' => 'STRING'],
                                    'score'     => ['type' => 'NUMBER'],
                                    'maxScore'  => ['type' => 'NUMBER'],
                                    'feedback'  => ['type' => 'STRING', 'description' => 'Justifikasi nilai butir soal. WAJIB menyebutkan alasan pemotongan nilai jika score < maxScore.']
                                ],
                                'required'   => ['criterion', 'score', 'maxScore', 'feedback']
                            ]
                        ],
                        'summaryFeedback'   => ['type' => 'STRING', 'description' => 'Rangkuman ulasan komprehensif'],
                        'strengths'         => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Poin-poin kelebihan jawaban mahasiswa'],
                        'weaknesses'        => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Poin-poin kekurangan, ketidaklengkapan, atau kekeliruan jawaban mahasiswa'],
                        'revisions'         => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Langkah konkret perbaikan/revisi yang perlu dilakukan jika ingin perbaikan nilai'],
                        'improvements'      => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Rekomendasi pengembangan kompetensi mahasiswa'],
                        'aiResidueDetected' => ['type' => 'BOOLEAN', 'description' => 'True jika ditemukan kalimat residu percakapan bot AI yang lupa dihapus mahasiswa'],
                        'aiResidueQuotes'   => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Kutipan persis kalimat percakapan bot AI yang tertinggal']
                    ],
                    'required'   => ['totalScore', 'criteriaScores', 'summaryFeedback', 'strengths', 'weaknesses', 'revisions']
                ]
            ]
        ];

        $response = self::sendPostRequest($url, $payload);
        if ($response['http_code'] !== 200) {
            $errMsg = "HTTP {$response['http_code']}";
            $errObj = json_decode($response['body'], true);
            if (isset($errObj['error']['message'])) $errMsg = $errObj['error']['message'];
            if ($response['http_code'] === 429) {
                throw new Exception("Limit kuota tercapai (HTTP 429). Coba beralih ke model gemini-3.5-flash-lite.");
            }
            throw new Exception("Gemini API Error: {$errMsg}");
        }

        $resJson = json_decode($response['body'], true);
        $rawJson = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = json_decode($rawJson, true);

        if (!$parsed) {
            throw new Exception("Gagal memproses JSON hasil penilaian Gemini.");
        }

        return [
            'success' => true,
            'data'    => $parsed
        ];
    }

    /**
     * Memeriksa kepemilikan dokumen tugas mahasiswa jika nama file tidak memuat NIM
     */
    public static function checkDocumentBelongsToStudent(
        array $filePayload,
        string $targetNim,
        string $targetNama,
        string $apiKey,
        ?string $model = null
    ): ?array {
        $cleanKey = trim($apiKey);
        if (empty($cleanKey)) return null;

        $selectedModel = !empty($model) ? trim($model) : self::DEFAULT_MODEL;
        $url = self::API_BASE_URL . "/{$selectedModel}:generateContent?key=" . urlencode($cleanKey);

        $parts = [];
        $parts[] = [
            'text' => "Periksa dokumen tugas mahasiswa berikut ini secara teliti.
TUGAS ANDA:
Periksa apakah dokumen ini adalah berkas tugas milik mahasiswa dengan:
• Target NIM  : \"{$targetNim}\"
• Target Nama : \"{$targetNama}\"

PETUNJUK PEMERIKSAAN:
1. Telusuri seluruh halaman dokumen: cover depan, judul/header, footer, tabel identitas penulis, halaman pertama.
2. Temukan nomor induk mahasiswa (NIM / NPM) dan nama mahasiswa yang tertera.
3. Tentukan apakah ada kecocokan dengan Target NIM atau Target Nama di atas (isMatch: true/false).
Kembalikan format JSON sesuai schema."
        ];

        if (!empty($filePayload['isTextOnly'])) {
            $snippet = substr($filePayload['textContent'] ?? '', 0, 10000);
            $parts[] = ['text' => "=== AWAL DOKUMEN ===\n{$snippet}\n=== AKHIR DOKUMEN ==="];
        } elseif (!empty($filePayload['base64Data'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $filePayload['mimeType'] ?? 'application/pdf',
                    'data'      => $filePayload['base64Data']
                ]
            ];
        } else {
            return null;
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'maxOutputTokens'  => 200,
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'isMatch'      => ['type' => 'BOOLEAN'],
                        'detectedNim'  => ['type' => 'STRING'],
                        'detectedNama' => ['type' => 'STRING']
                    ],
                    'required'   => ['isMatch', 'detectedNim', 'detectedNama']
                ]
            ]
        ];

        $response = self::sendPostRequest($url, $payload);
        if ($response['http_code'] !== 200) return null;

        $resJson = json_decode($response['body'], true);
        $rawText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return json_decode($rawText, true) ?: null;
    }

    /**
     * Helper cURL POST request
     */
    private static function sendPostRequest(string $url, array $payload): array {
        $ch = curl_init($url);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ],
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => false // Kompatibel dengan shared hosting SSL bundles
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("Koneksi cURL gagal: {$curlError}");
        }

        return [
            'http_code' => $httpCode,
            'body'      => $body
        ];
    }
}
