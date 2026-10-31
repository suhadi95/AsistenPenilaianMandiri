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
      const selectedModel = model || 'gemini-2.5-flash';
      const cleanKey = apiKey ? apiKey.trim() : '';
      if (!cleanKey) {
        return { success: false, message: 'Gemini API Key belum dimasukkan.' };
      }

      const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${cleanKey}`;
      const payload = {
        contents: [
          {
            parts: [{ text: 'Halo Gemini! Uji koneksi asisten penilaian. Jawab "OK".' }]
          }
        ],
        generationConfig: {
          maxOutputTokens: 10
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
        return { success: true, message: `Koneksi berhasil! Model [${selectedModel}] siap digunakan.` };
      } else {
        let errorMsg = `Error HTTP ${code}`;
        try {
          const errObj = JSON.parse(responseText);
          if (errObj.error && errObj.error.message) {
            errorMsg = errObj.error.message;
          }
        } catch (e) {}
        return { success: false, message: `Gagal terhubung ke Gemini API: ${errorMsg}` };
      }
    } catch (error) {
      return { success: false, message: 'Kesalahan jaringan atau eksekusi UrlFetchApp: ' + error.message };
    }
  },

  /**
   * Mengevaluasi berkas tugas mahasiswa menggunakan Gemini Multimodal
   * @param {Object} submissionData - Data dari DriveService (fileId, mimeType, base64Data / textContent)
   * @param {Object} studentInfo - { nim, nama }
   * @param {string} rubricText - Teks kriteria rubrik
   * @param {string} apiKey - API Key Gemini
   * @param {string} model - Nama model Gemini
   * @return {Object} Hasil penilaian terstruktur
   */
  evaluateSubmission(submissionData, studentInfo, rubricText, apiKey, model) {
    const selectedModel = model || 'gemini-2.5-flash';
    if (!apiKey) {
      throw new Error('Gemini API Key belum dikonfigurasi.');
    }

    const url = `https://generativelanguage.googleapis.com/v1beta/models/${selectedModel}:generateContent?key=${apiKey}`;

    // Siapkan bagian konten multimodal
    const parts = [];

    // Tambahkan data berkas (Base64 atau Plain Text)
    if (submissionData.isTextOnly) {
      parts.push({
        text: `=== ISI BERKAS TUGAS MAHASISWA (${submissionData.fileName}) ===\n${submissionData.textContent}\n=== AKHIR BERKAS ===`
      });
    } else if (submissionData.base64Data) {
      parts.push({
        inline_data: {
          mime_type: submissionData.mimeType,
          data: submissionData.base64Data
        }
      });
    }

    // Tambahkan instruksi spesifik penilaian
    const evaluationPrompt = `
Lakukan penilaian tugas mahasiswa berikut secara objektif dan mendalam.

IDENTITAS MAHASISWA:
- NIM  : ${studentInfo.nim || '-'}
- Nama : ${studentInfo.nama || '-'}
- Berkas: ${submissionData.fileName}

PANDUAN RUBRIK & BOBOT:
${rubricText}

INSTRUKSI PENILAIAN:
1. Periksa berkas tugas mahasiswa yang terlampir dengan teliti.
2. Evaluasi setiap kriteria sesuai dengan deskripsi dan bobot pada rubrik di atas.
3. Berikan skor per kriteria dan hitung Nilai Total (skala 0 - 100).
4. Buat rangkuman masukan (summaryFeedback) yang konstruktif dalam Bahasa Indonesia formal, sopan, dan mengedukasi.
5. Sebutkan poin-poin kekuatan (strengths) dan area perbaikan (improvements).
`;

    parts.push({ text: evaluationPrompt });

    // Payload dengan Response Schema JSON Terstruktur
    const payload = {
      system_instruction: {
        parts: [
          {
            text: 'Anda adalah Asisten Dosen dan Penilai Akademik Profesional yang sangat teliti, adil, objektif, dan suportif. Anda selalu menilai berdasarkan rubrik yang diberikan tanpa bias dan menghasilkan keluaran dalam format JSON terstruktur.'
          }
        ]
      },
      contents: [{ parts: parts }],
      generationConfig: {
        temperature: 0.1, // Rendah untuk konsistensi dan akurasi skor
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            totalScore: {
              type: 'NUMBER',
              description: 'Nilai total akhir akumulatif (skala 0 - 100)'
            },
            criteriaScores: {
              type: 'ARRAY',
              description: 'Daftar penilaian untuk setiap kriteria rubrik',
              items: {
                type: 'OBJECT',
                properties: {
                  criterion: { type: 'STRING', description: 'Nama kriteria' },
                  score: { type: 'NUMBER', description: 'Nilai yang diperoleh' },
                  maxScore: { type: 'NUMBER', description: 'Nilai maksimal kriteria' },
                  feedback: { type: 'STRING', description: 'Ulasan spesifik untuk kriteria ini' }
                },
                required: ['criterion', 'score', 'maxScore', 'feedback']
              }
            },
            summaryFeedback: {
              type: 'STRING',
              description: 'Ulasan menyeluruh, catatan evaluasi, dan saran perbaikan untuk mahasiswa'
            },
            strengths: {
              type: 'ARRAY',
              items: { type: 'STRING' },
              description: 'Kelebihan utama tugas ini'
            },
            improvements: {
              type: 'ARRAY',
              items: { type: 'STRING' },
              description: 'Hal-hal yang perlu ditingkatkan di masa mendatang'
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

    // Eksekusi pemanggilan API
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
        throw new Error(`Limit Rate Gemini API tercapai (429 Quota Exceeded). Silakan jeda beberapa saat atau periksa kuota Anda.`);
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
