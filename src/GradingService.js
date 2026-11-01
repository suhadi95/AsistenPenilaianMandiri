/**
 * @fileoverview Layanan Eksekusi Penilaian Multimodal Berbasis Tugas & Database
 */

const GradingService = {
  /**
   * Menilai tugas satu mahasiswa spesifik
   * @param {Object} task - Objek tugas dari DB_TUGAS
   * @param {Object} student - { nim, nama_mahasiswa, id_kelas }
   * @param {Object} settings - Pengaturan aplikasi (API key, model)
   * @param {Object|null} taskQuestionData - Konten soal acuan
   * @return {Object}
   */
  gradeStudent(task, student, settings, taskQuestionData) {
    const nim = student.nim.trim();
    const nama = student.nama_mahasiswa.trim();

    // Catat status sedang diproses
    DatabaseService.saveGrade({
      id_tugas: task.id_tugas,
      id_kelas: task.id_kelas,
      nim: nim,
      nama_mahasiswa: nama,
      file_name: '',
      status: '⏳ Sedang Menilai...',
      waktu_penilaian: new Date().toLocaleString('id-ID')
    });
    SpreadsheetApp.flush();

    try {
      // 1. Ambil berkas jawaban mahasiswa dari Google Drive
      const submissionResult = DriveService.getStudentSubmission(
        task.drive_folder_id,
        nim,
        nama,
        ''
      );

      if (!submissionResult.success) {
        throw new Error(submissionResult.message);
      }

      // 2. Evaluasi menggunakan Gemini API
      const evalResult = GeminiService.evaluateSubmission(
        submissionResult,
        { nim, nama },
        task.rubrik_text || 'Nilai secara objektif dengan skala 0 - 100.',
        settings.rawApiKey,
        settings.modelName,
        taskQuestionData
      );

      const data = evalResult.data;

      // 3. Format rincian poin per butir soal
      let formattedCriteria = '';
      if (data.criteriaScores && Array.isArray(data.criteriaScores)) {
        formattedCriteria = data.criteriaScores
          .map(c => `• ${c.criterion}: ${c.score}/${c.maxScore} poin (${c.feedback})`)
          .join('\n');
      }

      // 4. Format catatan dan alasan penilaian
      let fullFeedback = data.summaryFeedback || '';
      if (data.strengths && data.strengths.length > 0) {
        fullFeedback += '\n\n[Kelebihan Jawaban]:\n' + data.strengths.map(s => `✓ ${s}`).join('\n');
      }
      if (data.improvements && data.improvements.length > 0) {
        fullFeedback += '\n\n[Catatan & Rekomendasi]:\n' + data.improvements.map(im => `⚠ ${im}`).join('\n');
      }

      // 5. Simpan nilai ke basis data
      const saved = DatabaseService.saveGrade({
        id_tugas: task.id_tugas,
        id_kelas: task.id_kelas,
        nim: nim,
        nama_mahasiswa: nama,
        file_name: submissionResult.fileName || '',
        nilai_total: data.totalScore,
        rincian_poin: formattedCriteria,
        catatan_ai: fullFeedback,
        status: 'Selesai',
        waktu_penilaian: new Date().toLocaleString('id-ID')
      });
      SpreadsheetApp.flush();

      return {
        success: true,
        nim: nim,
        nama: nama,
        score: data.totalScore,
        fileName: submissionResult.fileName || '',
        rincian_poin: formattedCriteria,
        catatan_ai: fullFeedback,
        status: 'Selesai',
        waktu_penilaian: new Date().toLocaleString('id-ID')
      };
    } catch (err) {
      const errMsg = err.message || 'Kesalahan penilaian tidak diketahui';
      DatabaseService.saveGrade({
        id_tugas: task.id_tugas,
        id_kelas: task.id_kelas,
        nim: nim,
        nama_mahasiswa: nama,
        status: `Gagal: ${errMsg}`,
        waktu_penilaian: new Date().toLocaleString('id-ID')
      });
      SpreadsheetApp.flush();
      return { 
        success: false, 
        nim: nim, 
        nama: nama, 
        error: errMsg,
        status: `Gagal: ${errMsg}`,
        waktu_penilaian: new Date().toLocaleString('id-ID')
      };
    }
  },

  /**
   * Menilai hanya mahasiswa yang dipilih (selected students)
   * @param {string} taskId 
   * @param {Array<string>} studentNims - Daftar NIM yang dipilih
   * @return {Object}
   */
  gradeSelectedStudents(taskId, studentNims) {
    const task = DatabaseService.getTaskById(taskId);
    if (!task) throw new Error('Tugas tidak ditemukan.');
    if (!studentNims || studentNims.length === 0) {
      throw new Error('Pilih minimal satu mahasiswa yang ingin dinilai.');
    }

    const settings = getAppSettings();
    if (!settings.rawApiKey) {
      throw new Error('Gemini API Key belum dimasukkan di menu Pengaturan.');
    }
    if (!task.drive_folder_id) {
      throw new Error('Folder Google Drive jawaban mahasiswa belum diisi pada tugas ini.');
    }

    const students = DatabaseService.getStudentsByClass(task.id_kelas);
    const selectedStudents = students.filter(s => studentNims.includes(s.nim));

    // Baca berkas soal sekali saja
    let taskQuestionData = null;
    if (task.task_file_ref) {
      taskQuestionData = DriveService.getTaskQuestionPayload(task.task_file_ref);
    }

    let successCount = 0;
    let failedCount = 0;
    const scoredList = [];

    for (const student of selectedStudents) {
      const result = this.gradeStudent(task, student, settings, taskQuestionData);
      if (result.success) {
        successCount++;
        scoredList.push(`${result.nim}: ${result.score}`);
      } else {
        failedCount++;
      }
      Utilities.sleep(500);
    }

    return {
      success: true,
      totalSelected: selectedStudents.length,
      successCount: successCount,
      failedCount: failedCount,
      message: `Selesai menilai ${successCount} mahasiswa terpilih.` + (scoredList.length ? ` (${scoredList.join(', ')})` : '')
    };
  },

  /**
   * Menilai seluruh mahasiswa untuk suatu tugas (batch dengan proteksi batas waktu)
   * @param {string} taskId 
   * @param {boolean} onlyUnassessed - Jika true, hanya nilai yang belum selesai/gagal
   * @return {Object}
   */
  gradeAllStudents(taskId, onlyUnassessed = true) {
    const startTime = Date.now();
    const task = DatabaseService.getTaskById(taskId);
    if (!task) throw new Error('Tugas tidak ditemukan.');

    const settings = getAppSettings();
    if (!settings.rawApiKey) {
      throw new Error('Gemini API Key belum dimasukkan di menu Pengaturan.');
    }
    if (!task.drive_folder_id) {
      throw new Error('Folder Google Drive tempat jawaban mahasiswa belum diisi pada tugas ini.');
    }

    const studentsWithGrades = DatabaseService.getGradesByTask(taskId);
    const maxExecutionMs = (settings.maxExecutionTimeSec || 300) * 1000;

    let taskQuestionData = null;
    if (task.task_file_ref) {
      taskQuestionData = DriveService.getTaskQuestionPayload(task.task_file_ref);
    }

    let processedCount = 0;
    let successCount = 0;
    let failedCount = 0;
    let skippedCount = 0;
    let isTimeout = false;

    for (const student of studentsWithGrades) {
      // Filter: jika sudah dinilai dan mode resume aktif
      if (onlyUnassessed && student.status && student.status.toLowerCase() === 'selesai') {
        skippedCount++;
        continue;
      }

      // Checkpoint batas waktu GAS 6 menit
      const elapsedTime = Date.now() - startTime;
      if (elapsedTime >= maxExecutionMs) {
        isTimeout = true;
        DatabaseService.saveGrade({
          id_tugas: task.id_tugas,
          id_kelas: task.id_kelas,
          nim: student.nim,
          nama_mahasiswa: student.nama_mahasiswa,
          status: 'Tertunda (Batas Waktu)',
          waktu_penilaian: new Date().toLocaleString('id-ID')
        });
        SpreadsheetApp.flush();
        break;
      }

      processedCount++;
      const result = this.gradeStudent(task, student, settings, taskQuestionData);

      if (result.success) {
        successCount++;
      } else {
        failedCount++;
      }

      Utilities.sleep(500);
    }

    const summaryMessage = isTimeout
      ? `Waktu eksekusi mendekati batas 6 menit. ${successCount} mahasiswa berhasil dinilai. Silakan klik "Mulai Penilaian" kembali untuk memproses sisa mahasiswa!`
      : `Penilaian Selesai! Berhasil: ${successCount}, Gagal: ${failedCount}, Dilewati (Sudah Selesai): ${skippedCount}.`;

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
   * Mereset status baris penilaian yang gagal atau tertunda
   */
  resetFailedGrades(taskId) {
    const grades = DatabaseService.getGradesByTask(taskId);
    let resetCount = 0;

    for (const g of grades) {
      const statusText = (g.status || '').toLowerCase();
      if (statusText.startsWith('gagal') || statusText.includes('sedang') || statusText.includes('tertunda')) {
        DatabaseService.saveGrade({
          id_tugas: taskId,
          id_kelas: g.id_kelas,
          nim: g.nim,
          nama_mahasiswa: g.nama_mahasiswa,
          status: 'Belum Dinilai',
          waktu_penilaian: ''
        });
        resetCount++;
      }
    }

    SpreadsheetApp.flush();
    return {
      success: true,
      count: resetCount,
      message: `${resetCount} status mahasiswa berhasil di-reset menjadi "Belum Dinilai".`
    };
  }
};
