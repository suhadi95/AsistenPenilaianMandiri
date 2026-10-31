/**
 * @fileoverview Mesin Penilaian Otomatis Mahasiswa (Batch Processor, Checkpoint, dan Spreadsheet I/O)
 */

const SHEET_HEADERS = [
  'NIM',
  'Nama Mahasiswa',
  'Berkas / Tautan Jawaban',
  'Nilai Total',
  'Rincian Poin Soal',
  'Catatan & Alasan Penilaian',
  'Status Penilaian',
  'Waktu Penilaian'
];

const GradingEngine = {
  /**
   * Menginisialisasi atau merapikan template sheet aktif
   */
  setupSheetTemplate() {
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    const lastRow = sheet.getLastRow();
    const lastCol = sheet.getLastColumn();

    // Jika sheet masih kosong, buat header standar beserta contoh data
    if (lastRow === 0 && lastCol === 0) {
      sheet.getRange(1, 1, 1, SHEET_HEADERS.length).setValues([SHEET_HEADERS]);

      // Contoh baris dummy mahasiswa
      const sampleData = [
        ['2024001', 'Budi Santoso', '', '', '', '', 'Belum Dinilai', ''],
        ['2024002', 'Siti Rahmawati', '', '', '', '', 'Belum Dinilai', ''],
        ['2024003', 'Ahmad Hidayat', '', '', '', '', 'Belum Dinilai', '']
      ];
      sheet.getRange(2, 1, sampleData.length, SHEET_HEADERS.length).setValues(sampleData);
    } else {
      // Pastikan baris 1 memiliki header lengkap
      const currentHeaders = sheet.getRange(1, 1, 1, Math.max(lastCol, SHEET_HEADERS.length)).getValues()[0];
      for (let i = 0; i < SHEET_HEADERS.length; i++) {
        if (!currentHeaders[i]) {
          sheet.getRange(1, i + 1).setValue(SHEET_HEADERS[i]);
        }
      }
    }

    // Pemformatan visual header
    const headerRange = sheet.getRange(1, 1, 1, SHEET_HEADERS.length);
    headerRange.setBackground('#0f172a') // Slate Navy gelap elegan
               .setFontColor('#ffffff')
               .setFontWeight('bold')
               .setFontFamily('Arial')
               .setHorizontalAlignment('center')
               .setVerticalAlignment('middle');

    sheet.setRowHeight(1, 36);
    sheet.setFrozenRows(1);

    // Pengaturan lebar kolom optimal
    sheet.setColumnWidth(1, 110); // NIM
    sheet.setColumnWidth(2, 180); // Nama
    sheet.setColumnWidth(3, 160); // File Jawaban
    sheet.setColumnWidth(4, 95);  // Nilai Total
    sheet.setColumnWidth(5, 230); // Rincian Poin Soal
    sheet.setColumnWidth(6, 330); // Catatan & Alasan Penilaian
    sheet.setColumnWidth(7, 130); // Status
    sheet.setColumnWidth(8, 150); // Waktu

    // Format wrap text untuk Catatan dan Rincian
    sheet.getRange(2, 5, Math.max(1, sheet.getLastRow() - 1), 2).setWrap(true);

    return { success: true, message: 'Template sheet penilaian berhasil disiapkan!' };
  },

  /**
   * Mendeteksi pemetaan kolom pada sheet aktif berdasarkan teks header
   */
  detectColumnIndices(sheet) {
    const lastCol = Math.max(sheet.getLastColumn(), SHEET_HEADERS.length);
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0].map(h => String(h).trim().toLowerCase());

    const findCol = (keywords, fallbackIndex) => {
      for (const kw of keywords) {
        const idx = headers.findIndex(h => h.includes(kw));
        if (idx !== -1) return idx + 1;
      }
      return fallbackIndex;
    };

    return {
      nimCol: findCol(['nim', 'npm', 'nrp', 'id', 'student id'], 1),
      namaCol: findCol(['nama', 'name', 'mahasiswa'], 2),
      fileCol: findCol(['berkas', 'file', 'link', 'tautan', 'tugas', 'jawaban'], 3),
      scoreCol: findCol(['nilai total', 'skor total', 'nilai', 'score', 'total'], 4),
      criteriaCol: findCol(['rincian', 'kriteria', 'poin', 'breakdown'], 5),
      feedbackCol: findCol(['catatan', 'masukan', 'ulasan', 'alasan', 'feedback', 'review'], 6),
      statusCol: findCol(['status', 'keterangan'], 7),
      timeCol: findCol(['waktu', 'tanggal', 'timestamp', 'date'], 8)
    };
  },

  /**
   * Menjalankan proses penilaian batch dengan proteksi timeout dan resume otomatis
   * @param {boolean} onlyUnassessed - Jika true, hanya nilai baris yang belum dinilai atau gagal
   * @return {Object} Statistik hasil eksekusi
   */
  runBatchGrading(onlyUnassessed = true) {
    const startTime = Date.now();
    const settings = getAppSettings();

    if (!settings.rawApiKey) {
      throw new Error('Gemini API Key belum dikonfigurasi. Buka menu "⚙️ Pengaturan & Rubrik" terlebih dahulu.');
    }

    if (!settings.driveFolderId) {
      throw new Error('Folder Google Drive tempat jawaban mahasiswa belum ditentukan.');
    }

    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    const lastRow = sheet.getLastRow();

    if (lastRow < 2) {
      return {
        success: false,
        message: 'Tidak ada baris data mahasiswa pada sheet aktif. Klik "Siapkan Template Sheet" terlebih dahulu.'
      };
    }

    const cols = this.detectColumnIndices(sheet);
    const maxExecutionMs = (settings.maxExecutionTimeSec || 300) * 1000;

    // Baca berkas / teks Soal Tugas SEKALI saja untuk efisiensi
    let taskQuestionData = null;
    if (settings.taskQuestionRef) {
      taskQuestionData = DriveService.getTaskQuestionPayload(settings.taskQuestionRef);
    }

    let processedCount = 0;
    let successCount = 0;
    let failedCount = 0;
    let skippedCount = 0;
    let isTimeout = false;

    const totalDataRows = lastRow - 1;
    const allData = sheet.getRange(2, 1, totalDataRows, sheet.getLastColumn()).getValues();

    for (let i = 0; i < totalDataRows; i++) {
      const rowIndex = i + 2;
      const rowValues = allData[i];

      const nim = String(rowValues[cols.nimCol - 1] || '').trim();
      const nama = String(rowValues[cols.namaCol - 1] || '').trim();
      const customFile = String(rowValues[cols.fileCol - 1] || '').trim();
      const currentStatus = String(rowValues[cols.statusCol - 1] || '').trim();

      // Lewati baris kosong
      if (!nim && !nama) {
        continue;
      }

      // Filter: Lewati jika sudah dinilai dan mode resume aktif
      if (onlyUnassessed && currentStatus.toLowerCase() === 'selesai') {
        skippedCount++;
        continue;
      }

      // Checkpoint batas waktu eksekusi GAS (timeout safety)
      const elapsedTime = Date.now() - startTime;
      if (elapsedTime >= maxExecutionMs) {
        isTimeout = true;
        sheet.getRange(rowIndex, cols.statusCol).setValue('Tertunda (Batas Waktu)');
        SpreadsheetApp.flush();
        break;
      }

      // Update status menjadi sedang diproses
      sheet.getRange(rowIndex, cols.statusCol).setValue('⏳ Sedang Menilai...');
      SpreadsheetApp.flush();
      processedCount++;

      try {
        // 1. Ambil berkas jawaban mahasiswa dari Google Drive
        const submissionResult = DriveService.getStudentSubmission(
          settings.driveFolderId,
          nim,
          nama,
          customFile
        );

        if (!submissionResult.success) {
          throw new Error(submissionResult.message);
        }

        // Catat nama berkas jika kolom berkas masih kosong
        if (!customFile && submissionResult.fileName) {
          sheet.getRange(rowIndex, cols.fileCol).setValue(submissionResult.fileName);
        }

        // 2. Evaluasi menggunakan Gemini API (membandingkan jawaban vs soal + rubrik porsi nilai)
        const evalResult = GeminiService.evaluateSubmission(
          submissionResult,
          { nim, nama },
          settings.rubricText,
          settings.rawApiKey,
          settings.modelName,
          taskQuestionData
        );

        const data = evalResult.data;

        // 3. Format rincian poin per butir soal / kriteria
        let formattedCriteria = '';
        if (data.criteriaScores && Array.isArray(data.criteriaScores)) {
          formattedCriteria = data.criteriaScores
            .map(c => `• ${c.criterion}: ${c.score}/${c.maxScore} poin (${c.feedback})`)
            .join('\n');
        }

        // 4. Format catatan dan alasan penilaian lengkap
        let fullFeedback = data.summaryFeedback || '';
        if (data.strengths && data.strengths.length > 0) {
          fullFeedback += '\n\n[Kelebihan Jawaban]:\n' + data.strengths.map(s => `✓ ${s}`).join('\n');
        }
        if (data.improvements && data.improvements.length > 0) {
          fullFeedback += '\n\n[Catatan & Rekomendasi]:\n' + data.improvements.map(im => `⚠ ${im}`).join('\n');
        }

        // 5. Tuliskan nilai dan ulasan langsung ke Google Sheets secara real-time
        sheet.getRange(rowIndex, cols.scoreCol).setValue(data.totalScore);
        sheet.getRange(rowIndex, cols.criteriaCol).setValue(formattedCriteria);
        sheet.getRange(rowIndex, cols.feedbackCol).setValue(fullFeedback);
        sheet.getRange(rowIndex, cols.statusCol).setValue('Selesai');
        sheet.getRange(rowIndex, cols.timeCol).setValue(new Date().toLocaleString('id-ID'));

        // Indikator warna hijau lembut untuk baris yang selesai
        sheet.getRange(rowIndex, cols.statusCol).setBackground('#dcfce7').setFontColor('#166534');

        successCount++;
      } catch (err) {
        failedCount++;
        const errMsg = err.message || 'Kesalahan tidak diketahui';
        sheet.getRange(rowIndex, cols.statusCol).setValue(`Gagal: ${errMsg}`);
        sheet.getRange(rowIndex, cols.statusCol).setBackground('#fee2e2').setFontColor('#991b1b');
      }

      // Flush real-time agar dosen bisa melihat penilaian masuk baris demi baris langsung
      SpreadsheetApp.flush();

      // Jeda 500ms antar baris
      Utilities.sleep(500);
    }

    const summaryMessage = isTimeout
      ? `Waktu eksekusi mendekati batas 6 menit. ${successCount} mahasiswa berhasil dinilai. Silakan klik menu "▶️ Mulai / Lanjutkan Penilaian" kembali untuk memproses sisa baris!`
      : `Penilaian Selesai! Berhasil dinilai: ${successCount}, Gagal: ${failedCount}, Dilewati (Sudah Selesai): ${skippedCount}.`;

    return {
      success: true,
      processed: processedCount,
      successCount: successCount,
      failedCount: failedCount,
      skippedCount: skippedCount,
      isTimeout: isTimeout,
      message: summaryMessage
    };
  },

  /**
   * Mengembalikan status baris yang gagal atau terhenti menjadi "Belum Dinilai"
   */
  resetFailedRows() {
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return { success: true, count: 0 };

    const cols = this.detectColumnIndices(sheet);
    const statuses = sheet.getRange(2, cols.statusCol, lastRow - 1, 1).getValues();
    let resetCount = 0;

    for (let i = 0; i < statuses.length; i++) {
      const statusText = String(statuses[i][0] || '').toLowerCase();
      if (statusText.startsWith('gagal') || statusText.includes('sedang') || statusText.includes('tertunda')) {
        const row = i + 2;
        sheet.getRange(row, cols.statusCol).setValue('Belum Dinilai').setBackground('#ffffff').setFontColor('#000000');
        resetCount++;
      }
    }

    SpreadsheetApp.flush();
    return {
      success: true,
      count: resetCount,
      message: `${resetCount} baris status penilaian telah di-reset menjadi "Belum Dinilai".`
    };
  }
};
