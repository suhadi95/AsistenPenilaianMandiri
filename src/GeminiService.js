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
      const selectedModel = model || 'gemini-3.6-flash';
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
    const selectedModel = model || 'gemini-3.6-flash';
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
  }
};
