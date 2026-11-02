/**
 * @fileoverview Layanan Integrasi Gemini API via UrlFetchApp dengan Dukungan Multimodal & Format Terstruktur
 */

const GeminiService = {
  /**
   * Menguji keabsahan API Key dan ketersediaan model
   * @param {string} apiKey 
   * @param {string} model 
   * @return {Object} Status pengujian
   */
  testConnection(apiKey, model) {
    try {
      const selectedModel = model || 'gemini-3.5-flash-lite';
      const cleanKey = apiKey ? apiKey.trim() : '';
      if (!cleanKey) {
        return { success: false, message: 'Gemini API Key belum dimasukkan.' };
      }

      const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${cleanKey}`;
      const payload = {
        contents: [
          {
            parts: [{ text: 'Halo Gemini! Uji koneksi asisten penilaian.' }]
          }
        ],
        generationConfig: {
          maxOutputTokens: 15
        }
      };

      const options = {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify(payload),
        muteHttpExceptions: true
      };

      const response = UrlFetchApp.fetch(url, options);
      const code = response.getResponseCode();
      const responseText = response.getContentText();

      if (code === 200) {
        return { 
          success: true, 
          message: `Koneksi berhasil! Model [${selectedModel}] aktif dan siap digunakan.` 
        };
      } else {
        let errorMsg = `HTTP ${code}`;
        try {
          const errObj = JSON.parse(responseText);
          if (errObj.error && errObj.error.message) {
            errorMsg = errObj.error.message;
          }
        } catch (e) {}

        if (code === 404) {
          return {
            success: false,
            message: `Model "${selectedModel}" tidak ditemukan atau sudah tidak tersedia. Coba beralih ke model gemini-3.6-flash, gemini-3.5-flash-lite, atau gemini-3.1-flash-lite.`
          };
        } else if (code === 429) {
          return {
            success: false,
            message: `Limit kuota Free Tier untuk model "${selectedModel}" tercapai (maks 20 req/hari). Solusi: pilih model gemini-3.5-flash-lite atau gemini-3.1-flash-lite pada dropdown model.`
          };
        }

        return { success: false, message: `Gagal terhubung (${code}): ${errorMsg}` };
      }
    } catch (error) {
      return { success: false, message: 'Kesalahan jaringan UrlFetchApp: ' + error.message };
    }
  },

  /**
   * Mengekstrak porsi poin dan kriteria penilaian langsung dari berkas soal tugas
   * @param {Object} taskQuestionData 
   * @param {string} apiKey 
   * @param {string} model 
   * @return {Object}
   */
  extractRubricFromTask(taskQuestionData, apiKey, model) {
    const selectedModel = model || 'gemini-3.5-flash-lite';
    if (!apiKey) {
      throw new Error('Gemini API Key belum dikonfigurasi.');
    }

    const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${apiKey}`;
    const parts = [];

    parts.push({
      text: 'Analisis berkas soal tugas berikut. Periksa secara teliti apakah di dalam dokumen soal terdapat informasi bobot nilai, porsi poin, atau kriteria penilaian (contoh: "Bobot: 30 Poin", "Nilai Maks: 20", "Porsi 25%", dsb).'
    });

    if (taskQuestionData.isTextOnly) {
      parts.push({ text: `=== DOKUMEN SOAL TUGAS ===\n${taskQuestionData.textContent}\n=== AKHIR DOKUMEN SOAL ===` });
    } else if (taskQuestionData.base64Data) {
      parts.push({
        inline_data: {
          mime_type: taskQuestionData.mimeType,
          data: taskQuestionData.base64Data
        }
      });
    }

    const instruction = `
TUGAS ANDA:
1. Periksa apakah pada setiap nomor atau bagian soal di atas sudah tertulis bobot/porsi poin penilaiannya.
2. JIKA ADA:
   - Tetapkan pointsFound = true.
   - Ekstrak seluruh butir kriteria/soal beserta porsi poin maksimal yang tertulis dalam dokumen.
   - Buat format rubrik Markdown yang rapi dengan judul "## PANDUAN PORSI NILAI (DARI DOKUMEN SOAL)".
3. JIKA TIDAK ADA bobot/porsi poin tertulis dalam soal:
   - Tetapkan pointsFound = false.
   - Buatlah saran pembagian poin yang seimbang untuk setiap butir soal yang ada di dokumen (total akumulasi 100 poin).
   - Buat format rubrik Markdown dengan judul "## PANDUAN PORSI NILAI (SARAN AI - BELUM ADA BOBOT DI SOAL)".
`;

    parts.push({ text: instruction });

    const payload = {
      system_instruction: {
        parts: [
          {
            text: 'Anda adalah Asisten Kurikulum Akademik. Tugas Anda menganalisis berkas soal ujian/tugas, mendeteksi bobot poin yang tertera, dan menghasilkan skema penilaian terstruktur dalam JSON.'
          }
        ]
      },
      contents: [{ parts: parts }],
      generationConfig: {
        temperature: 0.1,
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            pointsFound: {
              type: 'BOOLEAN',
              description: 'true jika bobot/porsi poin tertulis eksplisit di dalam dokumen soal, false jika tidak ada'
            },
            totalPoints: {
              type: 'NUMBER',
              description: 'Total akumulasi poin semua kriteria'
            },
            formattedRubricText: {
              type: 'STRING',
              description: 'Teks lengkap rubrik dan porsi poin dalam format Markdown rapi'
            },
            explanation: {
              type: 'STRING',
              description: 'Keterangan ringkas hasil deteksi'
            }
          },
          required: ['pointsFound', 'formattedRubricText', 'explanation']
        }
      }
    };

    const options = {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    };

    const response = UrlFetchApp.fetch(url, options);
    const statusCode = response.getResponseCode();
    const responseBody = response.getContentText();

    if (statusCode !== 200) {
      let errMsg = `HTTP ${statusCode}`;
      try {
        const errObj = JSON.parse(responseBody);
        if (errObj.error && errObj.error.message) errMsg = errObj.error.message;
      } catch (e) {}
      throw new Error(`Gagal menganalisis dokumen soal (${statusCode}): ${errMsg}`);
    }

    try {
      const resultObj = JSON.parse(responseBody);
      const rawJson = resultObj.candidates[0].content.parts[0].text;
      const parsed = JSON.parse(rawJson);

      return {
        success: true,
        pointsFound: Boolean(parsed.pointsFound),
        totalPoints: parsed.totalPoints || 100,
        rubricText: parsed.formattedRubricText,
        explanation: parsed.explanation
      };
    } catch (e) {
      throw new Error('Gagal memproses JSON ekstraksi soal: ' + e.message);
    }
  },

  /**
   * Mengevaluasi berkas tugas mahasiswa menggunakan Gemini Multimodal
   * @param {Object} submissionData - Data jawaban mahasiswa dari DriveService
   * @param {Object} studentInfo - { nim, nama }
   * @param {string} rubricText - Teks kriteria rubrik & porsi nilai
   * @param {string} apiKey - API Key Gemini
   * @param {string} model - Nama model Gemini
   * @param {Object|null} taskQuestionData - Data Soal Tugas / Kunci Jawaban dari DriveService
   * @return {Object} Hasil penilaian terstruktur
   */
  evaluateSubmission(submissionData, studentInfo, rubricText, apiKey, model, taskQuestionData) {
    const selectedModel = model || 'gemini-3.5-flash-lite';
    if (!apiKey) {
      throw new Error('Gemini API Key belum dikonfigurasi.');
    }

    const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${apiKey}`;

    const parts = [];

    // 1. Lampirkan Soal Tugas / Kunci Jawaban Acuan jika tersedia
    if (taskQuestionData) {
      parts.push({
        text: `=== DOKUMEN ACUAN: SOAL TUGAS / PETUNJUK PENILAIAN (${taskQuestionData.fileName || 'Soal Tugas'}) ===`
      });

      if (taskQuestionData.isTextOnly) {
        parts.push({ text: taskQuestionData.textContent });
      } else if (taskQuestionData.base64Data) {
        parts.push({
          inline_data: {
            mime_type: taskQuestionData.mimeType,
            data: taskQuestionData.base64Data
          }
        });
      }
      parts.push({ text: `=== AKHIR DOKUMEN SOAL ACUAN ===\n` });
    }

    // 2. Lampirkan Berkas Jawaban Mahasiswa
    parts.push({
      text: `=== BERKAS JAWABAN MAHASISWA: ${studentInfo.nama || '-'} (NIM: ${studentInfo.nim || '-'}) - File: ${submissionData.fileName} ===`
    });

    if (submissionData.isTextOnly) {
      parts.push({ text: submissionData.textContent });
    } else if (submissionData.base64Data) {
      parts.push({
        inline_data: {
          mime_type: submissionData.mimeType,
          data: submissionData.base64Data
        }
      });
    }
    parts.push({ text: `=== AKHIR BERKAS JAWABAN MAHASISWA ===\n` });

    // 3. Instruksi Evaluasi Komprehensif
    const evaluationPrompt = `
Lakukan penilaian tugas mahasiswa secara seksama, adil, dan objektif.

IDENTITAS MAHASISWA:
• NIM  : ${studentInfo.nim || '-'}
• Nama : ${studentInfo.nama || '-'}
• Berkas Jawaban: ${submissionData.fileName}

PANDUAN PORSI NILAI & RUBRIK:
${rubricText}

INSTRUKSI PENILAIAN:
1. Bacalah jawaban mahasiswa dan bandingkan dengan SOAL TUGAS / PANDUAN JAWABAN di atas.
2. Nilai setiap butir soal/kriteria secara ketat berdasarkan PORSI POIN MAKSIMAL yang telah ditentukan.
3. Hitung Nilai Total akumulatif (totalScore).
4. Buat rangkuman masukan (summaryFeedback) yang menjelaskan justifikasi perolehan nilai: bagian mana yang sudah benar, bagian mana yang kurang/salah, dan apa solusi yang benar secara santun dan mengedukasi.
5. Sebutkan poin-poin kelebihan (strengths) dan poin perbaikan (improvements).
`;

    parts.push({ text: evaluationPrompt });

    // 4. Payload Terstruktur dengan Schema JSON
    const payload = {
      system_instruction: {
        parts: [
          {
            text: 'Anda adalah Asisten Dosen Senior dan Penilai Akademik Profesional yang sangat teliti, adil, objektif, dan suportif. Anda memeriksa jawaban mahasiswa berdasarkan soal dan pedoman rubrik/porsi nilai yang ditentukan, menghitung skor secara matematis tepat, dan mengembalikan hasil dalam format JSON terstruktur.'
          }
        ]
      },
      contents: [{ parts: parts }],
      generationConfig: {
        temperature: 0.1, // Rendah untuk konsistensi nilai
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            totalScore: {
              type: 'NUMBER',
              description: 'Nilai total akhir mahasiswa (penjumlahan skor semua kriteria/soal)'
            },
            criteriaScores: {
              type: 'ARRAY',
              description: 'Daftar nilai untuk setiap butir soal atau kriteria rubrik',
              items: {
                type: 'OBJECT',
                properties: {
                  criterion: { type: 'STRING', description: 'Nama butir soal atau kriteria penilaian' },
                  score: { type: 'NUMBER', description: 'Poin nilai yang diperoleh mahasiswa' },
                  maxScore: { type: 'NUMBER', description: 'Poin maksimal untuk kriteria ini' },
                  feedback: { type: 'STRING', description: 'Alasan/justifikasi perolehan nilai pada butir ini' }
                },
                required: ['criterion', 'score', 'maxScore', 'feedback']
              }
            },
            summaryFeedback: {
              type: 'STRING',
              description: 'Rangkuman ulasan komprehensif, evaluasi objektif, dan justifikasi total nilai'
            },
            strengths: {
              type: 'ARRAY',
              items: { type: 'STRING' },
              description: 'Kelebihan utama jawaban mahasiswa'
            },
            improvements: {
              type: 'ARRAY',
              items: { type: 'STRING' },
              description: 'Kekurangan jawaban dan hal yang perlu diperbaiki'
            }
          },
          required: ['totalScore', 'criteriaScores', 'summaryFeedback']
        }
      }
    };

    const options = {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    };

    const response = UrlFetchApp.fetch(url, options);
    const statusCode = response.getResponseCode();
    const responseBody = response.getContentText();

    if (statusCode !== 200) {
      let errorMessage = `HTTP ${statusCode}`;
      try {
        const errorJson = JSON.parse(responseBody);
        if (errorJson.error && errorJson.error.message) {
          errorMessage = errorJson.error.message;
        }
      } catch (e) {}

      if (statusCode === 429) {
        throw new Error(`Limit kuota tercapai (HTTP 429). Coba ganti model ke gemini-3.5-flash-lite di Pengaturan.`);
      }
      throw new Error(`Gemini API Error: ${errorMessage}`);
    }

    try {
      const resultObj = JSON.parse(responseBody);
      const candidate = resultObj.candidates && resultObj.candidates[0];
      if (!candidate || !candidate.content || !candidate.content.parts || !candidate.content.parts[0]) {
        throw new Error('Respons Gemini tidak memuat konten kandidat.');
      }

      const rawJson = candidate.content.parts[0].text;
      const parsedGrading = JSON.parse(rawJson);

      return {
        success: true,
        data: parsedGrading,
        raw: parsedGrading
      };
    } catch (parseError) {
      throw new Error('Gagal memproses JSON hasil penilaian Gemini: ' + parseError.message);
    }
  },

  /**
   * Mengidentifikasi identitas mahasiswa (NIM dan Nama) dari isi dokumen multimodal
   * @param {Object} filePayload - Payload berkas dari DriveService.processFileForGemini
   * @param {string} apiKey - Gemini API Key
   * @param {string} model - Nama model Gemini
   * @return {Object|null} { nim: string, nama: string }
   */
    /**
   * Memeriksa apakah sebuah dokumen memuat NIM atau Nama mahasiswa target secara spesifik
   * @param {Object} filePayload - Payload berkas dari DriveService.processFileForGemini
   * @param {string} targetNim - NIM yang dicari
   * @param {string} targetNama - Nama yang dicari
   * @param {string} apiKey - Gemini API Key
   * @param {string} model - Nama model Gemini
   * @return {Object|null} { isMatch: boolean, detectedNim: string, detectedNama: string }
   */
  checkDocumentBelongsToStudent(filePayload, targetNim, targetNama, apiKey, model) {
    if (!apiKey) return null;
    const selectedModel = model || 'gemini-3.5-flash-lite';
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${apiKey}`;

    const parts = [];
    parts.push({
      text: `Periksa dokumen tugas mahasiswa berikut ini secara teliti.
TUGAS ANDA:
Periksa apakah dokumen ini adalah berkas tugas milik mahasiswa dengan:
• Target NIM  : "${targetNim || '-'}"
• Target Nama : "${targetNama || '-'}"

PETUNJUK PEMERIKSAAN:
1. Telusuri seluruh halaman dokumen: cover depan, judul/header, footer, tabel identitas penulis, halaman pertama, atau teks di dalam dokumen.
2. Temukan nomor induk mahasiswa (NIM / NPM / No. Mahasiswa) dan nama mahasiswa yang tertera.
3. Tentukan apakah ada kecocokan dengan Target NIM atau Target Nama di atas (isMatch: true/false).

Kembalikan format JSON persis sesuai schema.`
    });

    if (filePayload.isTextOnly) {
      const snippet = (filePayload.textContent || '').slice(0, 10000);
      parts.push({ text: `=== AWAL DOKUMEN ===\n${snippet}\n=== AKHIR DOKUMEN ===` });
    } else if (filePayload.base64Data) {
      parts.push({
        inline_data: {
          mime_type: filePayload.mimeType,
          data: filePayload.base64Data
        }
      });
    } else {
      return null;
    }

    const payload = {
      contents: [{ parts: parts }],
      generationConfig: {
        temperature: 0.1,
        maxOutputTokens: 200,
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            isMatch: { type: 'BOOLEAN', description: 'True jika dokumen memuat NIM atau Nama target' },
            detectedNim: { type: 'STRING', description: 'NIM yang ditemukan di dalam dokumen' },
            detectedNama: { type: 'STRING', description: 'Nama mahasiswa yang ditemukan di dalam dokumen' }
          },
          required: ['isMatch', 'detectedNim', 'detectedNama']
        }
      }
    };

    const options = {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    };

    try {
      const response = UrlFetchApp.fetch(url, options);
      if (response.getResponseCode() !== 200) {
        console.warn('Gemini checkDocumentBelongsToStudent HTTP ' + response.getResponseCode() + ': ' + response.getContentText().slice(0, 200));
        return null;
      }
      const resJson = JSON.parse(response.getContentText());
      const candidate = resJson.candidates && resJson.candidates[0];
      if (!candidate || !candidate.content || !candidate.content.parts || !candidate.content.parts[0]) return null;
      const rawText = candidate.content.parts[0].text;
      return JSON.parse(rawText);
    } catch (e) {
      console.warn('Gagal memeriksa kepemilikan dokumen via Gemini: ' + e.message);
      return null;
    }
  },

  identifyStudentFromDocument(filePayload, apiKey, model) {
    if (!apiKey) return null;
    const selectedModel = model || 'gemini-3.5-flash-lite';
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${apiKey}`;

    const parts = [];
    parts.push({
      text: 'Periksa dokumen tugas mahasiswa berikut ini secara seksama. Cari dan ekstrak identitas mahasiswa pembuat tugas yang biasanya tertera pada cover, judul, header, identitas penulis, atau bagian awal dokumen (NIM dan Nama Mahasiswa).'
    });

    if (filePayload.isTextOnly) {
      const snippet = (filePayload.textContent || '').slice(0, 3000);
      parts.push({ text: `=== KUTIPAN AWAL DOKUMEN ===\n${snippet}\n=== AKHIR KUTIPAN ===` });
    } else if (filePayload.base64Data) {
      parts.push({
        inline_data: {
          mime_type: filePayload.mimeType,
          data: filePayload.base64Data
        }
      });
    } else {
      return null;
    }

    const payload = {
      contents: [{ parts: parts }],
      generationConfig: {
        response_mime_type: 'application/json',
        response_schema: {
          type: 'OBJECT',
          properties: {
            nim: { type: 'STRING', description: 'Nomor Induk Mahasiswa jika tertera di dokumen, atau kosong jika tidak ada' },
            nama: { type: 'STRING', description: 'Nama Mahasiswa jika tertera di dokumen, atau kosong jika tidak ada' }
          },
          required: ['nim', 'nama']
        },
        temperature: 0.1,
        maxOutputTokens: 150
      }
    };

    const options = {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    };

    try {
      const response = UrlFetchApp.fetch(url, options);
      if (response.getResponseCode() !== 200) return null;
      const resJson = JSON.parse(response.getContentText());
      const candidate = resJson.candidates && resJson.candidates[0];
      if (!candidate || !candidate.content || !candidate.content.parts || !candidate.content.parts[0]) return null;
      const rawText = candidate.content.parts[0].text;
      return JSON.parse(rawText);
    } catch (e) {
      console.warn('Gagal mengidentifikasi identitas mahasiswa via Gemini: ' + e.message);
      return null;
    }
  }
};