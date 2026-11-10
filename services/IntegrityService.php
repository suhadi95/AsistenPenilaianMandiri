<?php
/**
 * @fileoverview Layanan Deteksi Integritas Akademik:
 * 1. Deteksi Jawaban Copy-Paste Antar Mahasiswa (Plagiarisme & Kesamaan Teks)
 * 2. Deteksi Residu Percakapan Chatbot AI yang Lupa Dihapus Mahasiswa
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helper.php';
require_once __DIR__ . '/FileService.php';

class IntegrityService {
    /**
     * Ekstraksi teks dari berkas PDF murni PHP tanpa ketergantungan library eksternal
     */
    public static function extractTextFromPdf(string $filePath): string {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $content = @file_get_contents($filePath);
        if (!$content) {
            return '';
        }

        $textResult = '';

        // Tangkap objek stream beserta kamus (dictionary) pendahulunya
        // Format standar PDF: << dictionary >> \r\n stream \r\n ... \r\n endstream
        if (preg_match_all('#(<<[\s\S]*?>>)\s*stream[\r\n]+([\s\S]*?)[\r\n]+endstream#', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $dict = $m[1];
                $streamData = $m[2];

                // 1. Lewati stream biner non-teks: Font file, gambar, XRef, Metadata, ICC Profile
                if (preg_match('#/(?:FontFile[123]?|Type\s*/\s*Font\b|Subtype\s*/\s*(?:Image|Type1C|TrueType|CIDFontType[02]|ICCBased)|Type\s*/\s*(?:XRef|Metadata|ObjStm))#i', $dict)) {
                    continue;
                }

                $uncompressed = '';
                // Coba decompress FlateDecode (zlib gzuncompress atau gzinflate)
                if (function_exists('gzuncompress')) {
                    $uncompressed = @gzuncompress($streamData);
                }
                if (!$uncompressed && function_exists('gzinflate')) {
                    $uncompressed = @gzinflate($streamData);
                    if (!$uncompressed && strlen($streamData) > 2) {
                        $uncompressed = @gzinflate(substr($streamData, 2));
                    }
                }
                if (!$uncompressed) {
                    $uncompressed = $streamData;
                }

                // 2. Ekstraksi teks HANYA dari blok BT ... ET (Begin Text ... End Text) sesuai standar ISO PDF
                if (preg_match_all('#\bBT\b([\s\S]*?)\bET\b#', $uncompressed, $btMatches)) {
                    foreach ($btMatches[1] as $textBlock) {
                        // Ekstraksi operator Tj standar: (teks) Tj
                        if (preg_match_all('#\((.*?)\)\s*Tj#s', $textBlock, $tjMatches)) {
                            foreach ($tjMatches[1] as $tm) {
                                $textResult .= ' ' . self::decodePdfString($tm);
                            }
                        }

                        // Ekstraksi operator TJ array: [(teks1) 10 (teks2)] TJ
                        if (preg_match_all('#\[(.*?)\]\s*TJ#s', $textBlock, $tjArrayMatches)) {
                            foreach ($tjArrayMatches[1] as $block) {
                                if (preg_match_all('#\((.*?)\)#s', $block, $inner)) {
                                    foreach ($inner[1] as $str) {
                                        $textResult .= ' ' . self::decodePdfString($str);
                                    }
                                }
                                if (preg_match_all('#<([0-9a-fA-F]+)>#', $block, $innerHex)) {
                                    foreach ($innerHex[1] as $hexStr) {
                                        $textResult .= ' ' . self::decodeHexPdfString($hexStr);
                                    }
                                }
                            }
                        }

                        // Ekstraksi operator hex string langsung: <48656c6c6f> Tj
                        if (preg_match_all('#<([0-9a-fA-F]+)>\s*Tj#', $textBlock, $hexMatches)) {
                            foreach ($hexMatches[1] as $hexStr) {
                                $textResult .= ' ' . self::decodeHexPdfString($hexStr);
                            }
                        }

                        // Operator ' dan "
                        if (preg_match_all('#\((.*?)\)\s*[\'"]#s', $textBlock, $quoteMatches)) {
                            foreach ($quoteMatches[1] as $qm) {
                                $textResult .= ' ' . self::decodePdfString($qm);
                            }
                        }
                    }
                }
            }
        }

        // Normalisasi spasi dan baris
        $cleaned = preg_replace('/[ \t]+/', ' ', $textResult);
        $cleaned = preg_replace('/[\r\n]+/', "\n", $cleaned);
        $finalText = trim($cleaned);

        // Jika teks hasil ekstraksi terdeteksi sebagai sampah karakter biner/font acak, kosongkan agar tidak memicu false positive
        if (self::isGibberish($finalText)) {
            return '';
        }

        return $finalText;
    }

    /**
     * Decode string heksadesimal dalam PDF (e.g. <48656c6c6f> -> Hello)
     */
    private static function decodeHexPdfString(string $hex): string {
        $cleanHex = preg_replace('/[^0-9a-fA-F]/', '', $hex);
        if (strlen($cleanHex) % 2 !== 0) {
            $cleanHex .= '0';
        }
        $bin = @hex2bin($cleanHex);
        if (!$bin) return '';

        // Deteksi UTF-16BE BOM (\xFE\xFF)
        if (str_starts_with($bin, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE');
        }
        // Deteksi 2-byte ASCII (\x00A\x00B)
        if (strlen($bin) >= 4 && substr($bin, 0, 1) === "\x00" && substr($bin, 2, 1) === "\x00") {
            return @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE') ?: '';
        }
        return mb_check_encoding($bin, 'UTF-8') ? $bin : utf8_encode($bin);
    }

    /**
     * Memeriksa apakah teks yang diekstrak merupakan sampah karakter biner / font terenkripsi (bukan teks bacaan alami)
     */
    public static function isGibberish(string $text): bool {
        $trimmed = trim($text);
        if (strlen($trimmed) < 20) return false;

        $tokens = preg_split('/\s+/u', $trimmed);
        $total = count($tokens);
        if ($total < 10) return false;

        $singleLetters = 0;
        $vowelDeficient = 0;
        foreach ($tokens as $t) {
            $len = mb_strlen($t);
            if ($len === 1 && !is_numeric($t)) {
                $singleLetters++;
            } elseif ($len >= 6 && !preg_match('/[aiueo]/i', $t)) {
                $vowelDeficient++;
            }
        }

        $ratio = ($singleLetters + $vowelDeficient) / $total;
        return $ratio > 0.30;
    }

    /**
     * Decode karakter escape dalam string PDF
     */
    private static function decodePdfString(string $str): string {
        // Octal escape sequences \123
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str);

        // Standard escapes
        $replace = [
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\t'  => "\t",
            '\\b'  => "\b",
            '\\f'  => "\f",
            '\\('  => '(',
            '\\)'  => ')',
            '\\\\' => '\\'
        ];
        return strtr($str, $replace);
    }

    /**
     * Ekstraksi teks universal dari berbagai format berkas tugas mahasiswa
     */
    public static function extractTextFromFile(string $filePath): string {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Format Word (.docx)
        if ($ext === 'docx') {
            return FileService::extractTextFromDocx($filePath);
        }

        // Format PDF
        if ($ext === 'pdf') {
            return self::extractTextFromPdf($filePath);
        }

        // Format Teks Murni, Markdown, Berkas Kode Program
        $textExts = ['txt', 'py', 'js', 'html', 'css', 'java', 'cpp', 'c', 'cs', 'php', 'json', 'csv', 'md', 'sql', 'rb', 'go', 'rs', 'kt', 'swift'];
        if (in_array($ext, $textExts, true)) {
            $raw = @file_get_contents($filePath);
            return $raw ? trim($raw) : '';
        }

        return '';
    }

    /**
     * Kamus Pola Deteksi Residu Chatbot AI
     * Menangkap sisa percakapan bot yang tertinggal karena mahasiswa teledor lupa menghapus.
     */
    private static function getAiBotPatterns(): array {
        return [
            // Kategori: Pertanyaan Penutup Bot (Sangat Khas & Pembuktian Mutlak)
            [
                'category'    => 'Pertanyaan Penutup Bot AI',
                'description' => 'Kalimat khas chatbot di akhir jawaban untuk menanyakan kecukupan hasil',
                'regex'       => '/\b(?:apakah|apa)\s+(?:jawaban|penjelasan|informasi|solusi|uraian|rangkuman|kode)\s+ini\s+sudah\s+(?:cukup|jelas|sesuai|lengkap|memuaskan)\b[\s\?]/iu',
                'label'       => 'apakah jawaban ini sudah cukup?'
            ],
            [
                'category'    => 'Tawaran Bantuan Tambahan Bot AI',
                'description' => 'Tawaran lanjutan chatbot yang tertinggal di akhir teks',
                'regex'       => '/\bapakah\s+ada\s+(?:hal|pertanyaan|bagian|poin)\s+lain\s+yang\s+(?:bisa|dapat)\s+saya\s+(?:bantu|jelaskan|tambahkan)\b[\s\?]/iu',
                'label'       => 'apakah ada hal lain yang bisa saya bantu?'
            ],
            [
                'category'    => 'Tawaran Bantuan Tambahan Bot AI',
                'description' => 'Pertanyaan chatbot mengenai hal yang ingin ditanyakan lagi',
                'regex'       => '/\b(?:ada|apakah\s+ada)\s+yang\s+ingin\s+(?:anda|kamu|saudara)\s+tanyakan\s+(?:lagi|kembali)\b[\s\?]/iu',
                'label'       => 'ada yang ingin ditanyakan lagi?'
            ],
            [
                'category'    => 'Salam Penutup Percakapan Bot AI',
                'description' => 'Penutup khas bot yang lupa dihapus',
                'regex'       => '/\bsemoga\s+(?:jawaban|penjelasan|informasi|solusi|kode|rangkuman)\s+ini\s+(?:dapat\s+)?(?:membantu|bermanfaat)\b/iu',
                'label'       => 'semoga jawaban ini membantu'
            ],
            [
                'category'    => 'Undangan Bertanya Bot AI',
                'description' => 'Ajakan bertanya khas chatbot asisten',
                'regex'       => '/\bjangan\s+ragu\s+untuk\s+(?:bertanya|menanyakan)\s+(?:lagi|kembali|lebih\s+lanjut)\b/iu',
                'label'       => 'jangan ragu untuk bertanya lagi'
            ],
            [
                'category'    => 'Instruksi Konfirmasi Bot AI',
                'description' => 'Instruksi khas bot untuk memberi tahu jika ada yang kurang jelas',
                'regex'       => '/\bsilakan\s+(?:beri\s+tahu|tanyakan|kabari)\s+(?:saya\s+)?jika\s+(?:ada\s+yang\s+)?(?:kurang\s+jelas|perlu\s+diperbaiki|perlu\s+ditambahkan)\b/iu',
                'label'       => 'silakan beri tahu jika ada yang kurang jelas'
            ],

            // Kategori: Kalimat Pembuka & Penegasan Bot AI
            [
                'category'    => 'Kalimat Pembuka Percakapan Bot AI',
                'description' => 'Afirmasi pembuka chatbot menjawab perintah',
                'regex'       => '/\btentu(?:saja)?[,!]?\s+(?:berikut|di\s+bawah\s+ini|ini\s+adalah)\s+(?:penjelasan|jawaban|rangkuman|kode|solusi|analisis|uraian)\b/iu',
                'label'       => 'tentu, berikut adalah jawaban...'
            ],
            [
                'category'    => 'Kalimat Pembuka Percakapan Bot AI',
                'description' => 'Pernyataan kesiapan chatbot membantu tugas',
                'regex'       => '/\bbaik[,!]?\s+saya\s+(?:akan\s+)?(?:bantu|membantu\s+anda)\s+(?:menjawab|menganalisis|menyelesaikan|membuatkan)\b/iu',
                'label'       => 'baik, saya akan membantu Anda menjawab...'
            ],
            [
                'category'    => 'Identifikasi Diri Sebagai AI',
                'description' => 'Bot secara eksplisit menyebut dirinya sebagai model AI/asisten',
                'regex'       => '/\bsebagai\s+(?:model\s+bahasa\s+)?(?:ai|kecerdasan\s+buatan|asisten\s+(?:virtual|ai))\b/iu',
                'label'       => 'sebagai model AI / asisten AI'
            ],

            // Kategori: Bahasa Inggris (English Bot Leftovers)
            [
                'category'    => 'Pertanyaan Penutup Bot (English)',
                'description' => 'English chatbot closing question',
                'regex'       => '/\b(?:does\s+this|did\s+this)\s+(?:answer|help\s+with)\s+your\s+question\b[\s\?]/iu',
                'label'       => 'does this answer your question?'
            ],
            [
                'category'    => 'Pertanyaan Penutup Bot (English)',
                'description' => 'English chatbot follow-up inquiry',
                'regex'       => '/\bis\s+there\s+anything\s+else\s+(?:i\s+can|i\s+could)\s+(?:help|assist)\s+(?:you\s+)?with\b[\s\?]/iu',
                'label'       => 'is there anything else I can help with?'
            ],
            [
                'category'    => 'Salam Penutup Bot (English)',
                'description' => 'English chatbot closing remark',
                'regex'       => '/\bhope\s+this\s+helps[!.]?/iu',
                'label'       => 'hope this helps!'
            ],
            [
                'category'    => 'Undangan Bertanya Bot (English)',
                'description' => 'English chatbot invitation to follow up',
                'regex'       => '/\b(?:feel\s+free\s+to\s+ask|let\s+me\s+know)\s+if\s+you\s+(?:have|need)\s+(?:any\s+)?(?:further|more|other)\s+(?:questions|help|clarification)\b/iu',
                'label'       => 'feel free to ask if you have further questions'
            ],
            [
                'category'    => 'Pembuka Bot (English)',
                'description' => 'English chatbot opening affirmation',
                'regex'       => '/\b(?:certainly|sure)[,!]?\s+(?:here\s+is|here\s+are|here\'s)\s+(?:the\s+)?(?:solution|code|answer|explanation)\b/iu',
                'label'       => 'certainly, here is the answer'
            ],
            [
                'category'    => 'Identifikasi Diri Sebagai AI (English)',
                'description' => 'Self-identification as AI model in English',
                'regex'       => '/\bas\s+an\s+ai(?:\s+language)?\s+model\b/iu',
                'label'       => 'as an AI language model'
            ],

            // Kategori: Artefak Antarmuka Chat & Reasoning Model
            [
                'category'    => 'Artefak Antarmuka Chatbot',
                'description' => 'Tombol / label antarmuka web ChatGPT/Claude yang tersalin',
                'regex'       => '/\b(?:regenerate\s+response|continue\s+generating|copy\s+code|edit\s+prompt)\b/iu',
                'label'       => 'artefak UI (regenerate response / copy code)'
            ],
            [
                'category'    => 'Artefak Model Penalaran (Reasoning Artifact)',
                'description' => 'Jejak penalaran model o1 / DeepSeek-R1 yang tersalin',
                'regex'       => '/\b(?:thinking\s+process|thought\s+for\s+\d+\s+seconds)\b/iu',
                'label'       => 'artefak penalaran (thought for X seconds)'
            ],

            // Kategori: Placeholder AI yang Lupa Diganti Mahasiswa
            [
                'category'    => 'Placeholder AI Belum Diganti',
                'description' => 'Format isian nama/NIM dari template AI yang tidak diisi mahasiswa',
                'regex'       => '/\[\s*(?:masukkan|ganti\s+dengan|isi\s+dengan)\s+(?:nama|nim|npm|identitas|data)\s*(?:anda|kamu)?\s*\]/iu',
                'label'       => '[masukkan nama/NIM Anda]'
            ]
        ];
    }

    /**
     * Memindai teks berkas mahasiswa untuk mendeteksi residu chatbot AI
     */
    public static function detectAiBotResidue(string $text): array {
        $trimmed = trim($text);
        if (empty($trimmed) || strlen($trimmed) < 20) {
            return [
                'detected'   => false,
                'matchCount' => 0,
                'matches'    => [],
                'summary'    => 'Teks kosong atau terlalu pendek.'
            ];
        }

        $patterns = self::getAiBotPatterns();
        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        $totalLines = count($lines);

        $matchesFound = [];

        foreach ($patterns as $pat) {
            if (preg_match($pat['regex'], $trimmed, $globalMatch, PREG_OFFSET_CAPTURE)) {
                $matchedText = $globalMatch[0][0];
                $matchedOffset = $globalMatch[0][1];

                // Temukan nomor baris dan konteks kalimat sekitar
                $charCount = 0;
                $targetLineIndex = 0;
                foreach ($lines as $idx => $line) {
                    $charCount += strlen($line) + 1;
                    if ($charCount >= $matchedOffset) {
                        $targetLineIndex = $idx;
                        break;
                    }
                }

                // Ambil konteks: 1 baris sebelum dan 1 baris sesudah
                $startLine = max(0, $targetLineIndex - 1);
                $endLine = min($totalLines - 1, $targetLineIndex + 1);
                $contextLines = [];
                for ($li = $startLine; $li <= $endLine; $li++) {
                    $prefix = ($li === $targetLineIndex) ? '👉 ' : '   ';
                    $contextLines[] = $prefix . trim($lines[$li]);
                }
                $snippet = implode("\n", array_filter($contextLines));

                $matchesFound[] = [
                    'category'    => $pat['category'],
                    'label'       => $pat['label'],
                    'matchedText' => trim($matchedText),
                    'lineNumber'  => $targetLineIndex + 1,
                    'snippet'     => $snippet
                ];
            }
        }

        $detected = !empty($matchesFound);
        $summary = '';
        if ($detected) {
            $uniqueLabels = array_unique(array_map(fn($m) => "\"{$m['matchedText']}\"", $matchesFound));
            $summary = "Terdeteksi " . count($matchesFound) . " residu bot AI: " . implode(', ', array_slice($uniqueLabels, 0, 2));
        } else {
            $summary = 'Bersih (Tidak ditemukan residu percakapan chatbot AI).';
        }

        return [
            'detected'   => $detected,
            'matchCount' => count($matchesFound),
            'matches'    => $matchesFound,
            'summary'    => $summary
        ];
    }

    /**
     * Menghitung kemiripan teks antar dua dokumen menggunakan Word Shingling & Jaccard/Dice Index
     */
    public static function calculateSimilarity(string $textA, string $textB, int $k = 4): array {
        // Cek jika salah satu teks adalah sampah biner/font PDF khusus
        if (self::isGibberish($textA) || self::isGibberish($textB)) {
            return [
                'percentage'      => 0.0,
                'jaccard'         => 0.0,
                'containmentA'    => 0.0,
                'containmentB'    => 0.0,
                'matchingPhrases' => [],
                'isGibberish'     => true,
                'note'            => 'Teks berkas terdeteksi sebagai artefak biner/font PDF khusus dan diabaikan dari pemindaian plagiasi teks.'
            ];
        }

        $cleanA = self::tokenizeAndFilter($textA);
        $cleanB = self::tokenizeAndFilter($textB);

        $lenA = count($cleanA);
        $lenB = count($cleanB);

        // Jika salah satu dokumen terlalu pendek (< 15 kata penting)
        if ($lenA < 15 || $lenB < 15) {
            return [
                'percentage'      => 0.0,
                'jaccard'         => 0.0,
                'containmentA'    => 0.0,
                'containmentB'    => 0.0,
                'matchingPhrases' => []
            ];
        }

        // Buat shingles k-gram
        $shinglesA = self::createShingles($cleanA, $k);
        $shinglesB = self::createShingles($cleanB, $k);

        $setA = array_flip($shinglesA);
        $setB = array_flip($shinglesB);

        // Hitung irisan (Intersection)
        $intersection = 0;
        foreach ($setA as $shingle => $_) {
            if (isset($setB[$shingle])) {
                $intersection++;
            }
        }

        $countA = count($setA);
        $countB = count($setB);
        $union = $countA + $countB - $intersection;

        $jaccard = $union > 0 ? ($intersection / $union) : 0.0;
        $dice = ($countA + $countB) > 0 ? ((2 * $intersection) / ($countA + $countB)) : 0.0;
        $containmentA = $countA > 0 ? ($intersection / $countA) : 0.0;
        $containmentB = $countB > 0 ? ($intersection / $countB) : 0.0;

        // Persentase kemiripan representatif (kombinasi Dice & Containment tertinggi)
        $effectiveScore = max($dice, ($containmentA + $containmentB) / 2);
        $percentage = round($effectiveScore * 100, 1);

        // Temukan kalimat/blok teks identik (minimal 5 kata berturut-turut)
        $matchingPhrases = self::findMatchingPhrases($cleanA, $cleanB, 5);

        return [
            'percentage'      => $percentage,
            'jaccard'         => round($jaccard * 100, 1),
            'containmentA'    => round($containmentA * 100, 1),
            'containmentB'    => round($containmentB * 100, 1),
            'matchingPhrases' => array_slice($matchingPhrases, 0, 5)
        ];
    }

    /**
     * Tokenisasi teks dan pembersihan karakter non-alfanumerik
     */
    private static function tokenizeAndFilter(string $text): array {
        $lower = mb_strtolower($text, 'UTF-8');
        // Ganti tanda baca dengan spasi
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower);
        $words = preg_split('/\s+/', trim($clean));

        // Filter kata yang kosong, abaikan huruf tunggal acak (noise), dan karakter berulang ekstrem
        return array_values(array_filter($words, function ($w) {
            $w = trim($w);
            if ($w === '') return false;
            // Abaikan huruf tunggal alfabet acak (a-z) kecuali angka, karena di n-gram shingling huruf tunggal acak menciptakan false-positive
            if (mb_strlen($w) === 1 && !is_numeric($w)) return false;
            // Abaikan kata berulang tidak wajar (misal: sssssssssssssssssss)
            if (preg_match('/(.)\1{3,}/u', $w)) return false;
            return true;
        }));
    }

    /**
     * Membentuk k-gram shingles dari daftar token kata
     */
    private static function createShingles(array $words, int $k = 4): array {
        $count = count($words);
        if ($count < $k) {
            return [implode(' ', $words)];
        }

        $shingles = [];
        for ($i = 0; $i <= $count - $k; $i++) {
            $slice = array_slice($words, $i, $k);
            $shingles[] = md5(implode(' ', $slice));
        }
        return $shingles;
    }

    /**
     * Menemukan potongan frasa yang identik antar dua dokumen (panjang min 5 kata)
     */
    private static function findMatchingPhrases(array $wordsA, array $wordsB, int $minLen = 5): array {
        $matches = [];
        $dictB = [];
        $lenB = count($wordsB);

        // Petakan indeks kemunculan kata di dokumen B
        for ($j = 0; $j < $lenB; $j++) {
            $dictB[$wordsB[$j]][] = $j;
        }

        $lenA = count($wordsA);
        $i = 0;
        while ($i < $lenA) {
            $word = $wordsA[$i];
            $bestMatchLen = 0;
            $bestPhrase = '';

            if (isset($dictB[$word])) {
                foreach ($dictB[$word] as $startB) {
                    $matchLen = 0;
                    while (($i + $matchLen < $lenA) &&
                           ($startB + $matchLen < $lenB) &&
                           ($wordsA[$i + $matchLen] === $wordsB[$startB + $matchLen])) {
                        $matchLen++;
                    }

                    if ($matchLen >= $minLen && $matchLen > $bestMatchLen) {
                        $bestMatchLen = $matchLen;
                        $bestPhrase = implode(' ', array_slice($wordsA, $i, $matchLen));
                    }
                }
            }

            if ($bestMatchLen >= $minLen) {
                $matches[] = [
                    'wordCount' => $bestMatchLen,
                    'phrase'    => $bestPhrase
                ];
                $i += $bestMatchLen;
            } else {
                $i++;
            }
        }

        // Urutkan potongan teks dari yang terpanjang
        usort($matches, fn($a, $b) => $b['wordCount'] <=> $a['wordCount']);
        return $matches;
    }

    /**
     * Menjalankan analisis integritas lengkap untuk satu kelas dan satu tugas:
     * 1. Deteksi residu chatbot AI pada berkas setiap mahasiswa.
     * 2. Perbandingan kemiripan berpasangan (pairwise) antar seluruh mahasiswa.
     * 3. Menyimpan hasilnya ke basis data (tabel grades).
     */
    public static function checkTaskIntegrity(string $taskId, string $classId): array {
        $students = Database::fetchAll(
            "SELECT nim, nama_mahasiswa FROM students WHERE id_kelas = ? ORDER BY nim ASC",
            [$classId]
        );

        if (empty($students)) {
            throw new Exception("Belum ada mahasiswa terdaftar di kelas ini.");
        }

        $submissionsDir = FileService::getSubmissionsDir($taskId, $classId);
        $sharedDir = FileService::getSharedSubmissionsDir($taskId);

        $studentTexts = [];
        $studentFiles = [];
        $aiScanResults = [];
        $studentNameMap = [];

        foreach ($students as $st) {
            $studentNameMap[trim(strtolower($st['nim']))] = $st['nama_mahasiswa'];
        }

        // 1. Ekstraksi teks & pemindaian AI untuk setiap mahasiswa
        foreach ($students as $st) {
            $nim = trim($st['nim']);
            $nama = trim($st['nama_mahasiswa']);

            // Cari berkas jawaban mahasiswa
            $targetFilePath = '';
            $targetFileName = '';

            // Cari di folder kelas
            if (is_dir($submissionsDir)) {
                $files = array_diff(scandir($submissionsDir), ['.', '..', 'drive_files_map.json']);
                foreach ($files as $f) {
                    $fLower = strtolower($f);
                    if (str_contains($fLower, strtolower($nim)) || (!empty($nama) && str_contains($fLower, strtolower(explode(' ', $nama)[0])))) {
                        $targetFilePath = "{$submissionsDir}/{$f}";
                        $targetFileName = $f;
                        break;
                    }
                }
            }

            // Fallback shared dir
            if (!$targetFilePath && is_dir($sharedDir)) {
                $files = array_diff(scandir($sharedDir), ['.', '..', 'drive_files_map.json']);
                foreach ($files as $f) {
                    $fLower = strtolower($f);
                    if (str_contains($fLower, strtolower($nim)) || (!empty($nama) && str_contains($fLower, strtolower(explode(' ', $nama)[0])))) {
                        $targetFilePath = "{$sharedDir}/{$f}";
                        $targetFileName = $f;
                        break;
                    }
                }
            }

            if ($targetFilePath && file_exists($targetFilePath)) {
                $extractedText = self::extractTextFromFile($targetFilePath);
                $studentTexts[$nim] = $extractedText;
                $studentFiles[$nim] = $targetFileName;

                // Pindai Residu Bot AI
                $aiScan = self::detectAiBotResidue($extractedText);
                $aiScanResults[$nim] = $aiScan;
            } else {
                $studentTexts[$nim] = '';
                $studentFiles[$nim] = '';
                $aiScanResults[$nim] = [
                    'detected'   => false,
                    'matchCount' => 0,
                    'matches'    => [],
                    'summary'    => 'Berkas belum dikumpulkan / tidak ditemukan di server.'
                ];
            }
        }

        // 2. Pembandingan Kemiripan Berpasangan (Pairwise Comparison)
        $pairwiseScores = []; // [nimA][nimB] => result
        $suspiciousPairs = []; // Pasangan yang terindikasi plagiasi
        $maxScores = [];      // [nim] => ['score' => float, 'targetNim' => str, 'targetNama' => str, 'phrases' => array]

        $nims = array_keys($studentTexts);
        $totalStudents = count($nims);

        // Inisialisasi max score
        foreach ($nims as $n) {
            $maxScores[$n] = [
                'score'           => 0.0,
                'targetNim'       => '',
                'targetNama'      => '',
                'matchingPhrases' => []
            ];
        }

        // Pembandingan N * (N-1) / 2
        for ($i = 0; $i < $totalStudents; $i++) {
            $nimA = $nims[$i];
            $textA = $studentTexts[$nimA];
            if (empty($textA)) continue;

            for ($j = $i + 1; $j < $totalStudents; $j++) {
                $nimB = $nims[$j];
                $textB = $studentTexts[$nimB];
                if (empty($textB)) continue;

                $sim = self::calculateSimilarity($textA, $textB);
                $pct = $sim['percentage'];

                $pairwiseScores[$nimA][$nimB] = $sim;
                $pairwiseScores[$nimB][$nimA] = $sim;

                $nameA = $studentNameMap[trim(strtolower($nimA))] ?? $nimA;
                $nameB = $studentNameMap[trim(strtolower($nimB))] ?? $nimB;

                // Update max score untuk A
                if ($pct > $maxScores[$nimA]['score']) {
                    $maxScores[$nimA] = [
                        'score'           => $pct,
                        'targetNim'       => $nimB,
                        'targetNama'      => $nameB,
                        'matchingPhrases' => $sim['matchingPhrases']
                    ];
                }

                // Update max score untuk B
                if ($pct > $maxScores[$nimB]['score']) {
                    $maxScores[$nimB] = [
                        'score'           => $pct,
                        'targetNim'       => $nimA,
                        'targetNama'      => $nameA,
                        'matchingPhrases' => $sim['matchingPhrases']
                    ];
                }

                // Jika kemiripan cukup signifikan (>= 40%)
                if ($pct >= 40.0) {
                    $suspiciousPairs[] = [
                        'nimA'            => $nimA,
                        'namaA'           => $nameA,
                        'fileNameA'       => $studentFiles[$nimA] ?? '-',
                        'nimB'            => $nimB,
                        'namaB'           => $nameB,
                        'fileNameB'       => $studentFiles[$nimB] ?? '-',
                        'percentage'      => $pct,
                        'jaccard'         => $sim['jaccard'],
                        'severity'        => ($pct >= 70.0) ? 'Kritis' : 'Sedang',
                        'matchingPhrases' => $sim['matchingPhrases']
                    ];
                }
            }
        }

        // Urutkan pasangan mencurigakan dari persentase tertinggi
        usort($suspiciousPairs, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

        // 3. Simpan Hasil Analisis ke Basis Data (Tabel grades)
        $aiDetectedCount = 0;
        $highPlagCount = 0;
        $mediumPlagCount = 0;
        $cleanCount = 0;
        $totalWithFiles = 0;

        foreach ($students as $st) {
            $nim = $st['nim'];
            $nama = $st['nama_mahasiswa'];
            $hasFile = !empty($studentTexts[$nim]);
            if ($hasFile) $totalWithFiles++;

            $aiInfo = $aiScanResults[$nim];
            $isAi = $aiInfo['detected'] ? 1 : 0;
            if ($isAi) $aiDetectedCount++;

            $maxSim = $maxScores[$nim]['score'];
            if ($maxSim >= 70.0) {
                $highPlagCount++;
            } elseif ($maxSim >= 40.0) {
                $mediumPlagCount++;
            } elseif ($hasFile) {
                $cleanCount++;
            }

            $aiDetailsJson = json_encode($aiInfo, JSON_UNESCAPED_UNICODE);
            $simDetailsJson = json_encode([
                'highestMatch'    => $maxScores[$nim],
                'matchingPhrases' => $maxScores[$nim]['matchingPhrases']
            ], JSON_UNESCAPED_UNICODE);

            // Periksa apakah baris nilai sudah ada
            $existing = Database::fetchOne(
                "SELECT id_nilai FROM grades WHERE id_tugas = ? AND id_kelas = ? AND LOWER(nim) = LOWER(?)",
                [$taskId, $classId, $nim]
            );

            if ($existing) {
                Database::execute(
                    "UPDATE grades SET similarity_score = ?, similarity_target_nim = ?, similarity_details = ?, ai_detected = ?, ai_detected_details = ? WHERE id_nilai = ?",
                    [
                        $maxSim > 0 ? $maxSim : null,
                        $maxScores[$nim]['targetNim'] ?: null,
                        $simDetailsJson,
                        $isAi,
                        $aiDetailsJson,
                        $existing['id_nilai']
                    ]
                );
            } else {
                $gradeId = Helper::generateId('NIL');
                Database::execute(
                    "INSERT INTO grades (id_nilai, id_tugas, id_kelas, nim, nama_mahasiswa, file_name, similarity_score, similarity_target_nim, similarity_details, ai_detected, ai_detected_details, status, waktu_penilaian, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Belum Dinilai', '', ?)",
                    [
                        $gradeId,
                        $taskId,
                        $classId,
                        $nim,
                        $nama,
                        $studentFiles[$nim] ?? '',
                        $maxSim > 0 ? $maxSim : null,
                        $maxScores[$nim]['targetNim'] ?: null,
                        $simDetailsJson,
                        $isAi,
                        $aiDetailsJson,
                        date('Y-m-d H:i:s')
                    ]
                );
            }
        }

        // Daftar mahasiswa yang terindikasi AI
        $aiFlaggedStudents = [];
        foreach ($students as $st) {
            $nim = $st['nim'];
            if (!empty($aiScanResults[$nim]['detected'])) {
                $aiFlaggedStudents[] = [
                    'nim'      => $nim,
                    'nama'     => $st['nama_mahasiswa'],
                    'fileName' => $studentFiles[$nim] ?? '-',
                    'aiScan'   => $aiScanResults[$nim]
                ];
            }
        }

        return [
            'success'           => true,
            'taskId'            => $taskId,
            'classId'           => $classId,
            'stats'             => [
                'totalStudents'       => count($students),
                'totalWithFiles'      => $totalWithFiles,
                'aiDetectedCount'     => $aiDetectedCount,
                'highPlagiarismCount' => $highPlagCount,
                'mediumPlagCount'     => $mediumPlagCount,
                'cleanCount'          => $cleanCount,
                'analyzedAt'          => date('d/m/Y H:i:s')
            ],
            'suspiciousPairs'   => $suspiciousPairs,
            'aiFlaggedStudents' => $aiFlaggedStudents,
            'maxScores'         => $maxScores,
            'studentFiles'      => $studentFiles,
            'message'           => "Analisis integritas selesai: {$aiDetectedCount} terindikasi AI, " . count($suspiciousPairs) . " pasangan kemiripan tinggi ditemukan."
        ];
    }
}
