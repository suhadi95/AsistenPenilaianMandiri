/**
 * @fileoverview Layanan Basis Data Spreadsheet (CRUD Multi-Tabel: Kelas, Mahasiswa, Tugas, Nilai)
 */

const DatabaseService = {
  /**
   * Mengambil Spreadsheet aktif atau membuka melalui ID terikat jika dijalankan via Web App
   */
  getSpreadsheet() {
    let ss = null;
    try {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    } catch (e) {}

    if (!ss) {
      const boundId = (typeof SPREADSHEET_ID !== 'undefined' && SPREADSHEET_ID) 
        ? SPREADSHEET_ID 
        : '1RvpMKOjCDDYG1xODoX_6EVQTtTFeaH1pt7OQ-mq8jWQ';
      try {
        ss = SpreadsheetApp.openById(boundId);
      } catch (err) {
        console.error('Gagal membuka spreadsheet via openById: ' + err.message);
      }
    }

    if (!ss) {
      throw new Error('Tidak dapat membuka Google Spreadsheet database. Pastikan izin akses Drive/Spreadsheet sudah diberikan.');
    }

    return ss;
  },

  /**
   * Mengambil atau membuat sheet dengan header yang ditentukan
   */
  getOrCreateSheet(sheetName, headers) {
    const ss = this.getSpreadsheet();
    let sheet = ss.getSheetByName(sheetName);

    if (!sheet) {
      sheet = ss.insertSheet(sheetName);
      if (headers && headers.length > 0) {
        sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
        sheet.getRange(1, 1, 1, headers.length)
             .setBackground('#1e293b')
             .setFontColor('#ffffff')
             .setFontWeight('bold')
             .setHorizontalAlignment('center')
             .setVerticalAlignment('middle');
        sheet.setRowHeight(1, 35);
        sheet.setFrozenRows(1);
      }
    }
    return sheet;
  },

  /**
   * Inisialisasi seluruh tabel basis data
   */
  initDatabase() {
    this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'nama_kelas', 'mata_kuliah', 'semester', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.STUDENTS, ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_kelas', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);

    // Jika belum ada kelas, buatkan 1 kelas contoh
    const classes = this.getClasses();
    if (classes.length === 0) {
      const sampleClass = this.saveClass({
        nama_kelas: 'Kelas A - Pemrograman Web',
        mata_kuliah: 'Pemrograman Web',
        semester: 'Ganjil 2026/2027'
      });

      // Tambahkan contoh mahasiswa
      this.saveStudentsBulk(sampleClass.id_kelas, `2024001\tBudi Santoso\n2024002\tSiti Rahmawati\n2024003\tAhmad Hidayat`);
    }

    return { success: true, message: 'Basis data sistem siap digunakan.' };
  },

  // ==========================================
  // KELOLA KELAS (CLASSES)
  // ==========================================

  getClasses() {
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'nama_kelas', 'mata_kuliah', 'semester', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 5).getValues();
    const studentsSheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS);
    const tasksSheet = this.getOrCreateSheet(DB_SHEETS.TASKS);

    // Hitung relasi mahasiswa & tugas per kelas
    const allStudents = studentsSheet.getLastRow() >= 2 
      ? studentsSheet.getRange(2, 2, studentsSheet.getLastRow() - 1, 1).getValues() 
      : [];
    const allTasks = tasksSheet.getLastRow() >= 2 
      ? tasksSheet.getRange(2, 2, tasksSheet.getLastRow() - 1, 1).getValues() 
      : [];

    return values.map(row => {
      const classId = String(row[0]);
      const studentCount = allStudents.filter(s => String(s[0]) === classId).length;
      const taskCount = allTasks.filter(t => String(t[0]) === classId).length;

      return {
        id_kelas: classId,
        nama_kelas: row[1] || '',
        mata_kuliah: row[2] || '',
        semester: row[3] || '',
        created_at: row[4] ? new Date(row[4]).toLocaleDateString('id-ID') : '',
        studentCount: studentCount,
        taskCount: taskCount
      };
    });
  },

  saveClass(data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'nama_kelas', 'mata_kuliah', 'semester', 'created_at']);
    const classId = data.id_kelas || generateId('KLS');
    const lastRow = sheet.getLastRow();

    if (data.id_kelas && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_kelas)) {
          const row = i + 2;
          sheet.getRange(row, 2, 1, 3).setValues([[
            data.nama_kelas || '',
            data.mata_kuliah || '',
            data.semester || ''
          ]]);
          return { id_kelas: classId, ...data };
        }
      }
    }

    // Insert baru
    sheet.appendRow([
      classId,
      data.nama_kelas || 'Kelas Baru',
      data.mata_kuliah || '',
      data.semester || '',
      new Date().toISOString()
    ]);

    return { id_kelas: classId, ...data };
  },

  deleteClass(classId) {
    const cleanId = String(classId).trim();
    this.deleteRowsByColumnValue(DB_SHEETS.CLASSES, 1, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.STUDENTS, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.TASKS, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 3, cleanId);
    return { success: true, message: 'Kelas dan seluruh data terkait berhasil dihapus.' };
  },

  // ==========================================
  // KELOLA MAHASISWA (STUDENTS)
  // ==========================================

  getStudentsByClass(classId) {
    const cleanId = String(classId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS, ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 5).getValues();
    return values
      .filter(row => String(row[1]) === cleanId)
      .map(row => ({
        id_mahasiswa: String(row[0]),
        id_kelas: String(row[1]),
        nim: String(row[2]),
        nama_mahasiswa: String(row[3]),
        created_at: row[4] ? new Date(row[4]).toLocaleDateString('id-ID') : ''
      }))
      .sort((a, b) => a.nim.localeCompare(b.nim));
  },

  saveStudentsBulk(classId, rawText) {
    if (!classId) throw new Error('ID Kelas belum ditentukan.');
    if (!rawText || !rawText.trim()) throw new Error('Teks daftar mahasiswa masih kosong.');

    const sheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS, ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at']);
    const existing = this.getStudentsByClass(classId);
    const existingMap = new Map();
    existing.forEach(s => existingMap.set(s.nim.toLowerCase(), s.id_mahasiswa));

    const lines = rawText.split(/\r?\n/);
    const rowsToAdd = [];
    let count = 0;

    for (const rawLine of lines) {
      const line = rawLine.trim();
      if (!line) continue;

      let nim = '';
      let nama = '';

      if (line.includes('\t')) {
        const parts = line.split('\t');
        nim = parts[0].trim();
        nama = parts.slice(1).join(' ').trim();
      } else if (line.includes(',')) {
        const parts = line.split(',');
        nim = parts[0].trim();
        nama = parts.slice(1).join(' ').trim();
      } else if (line.includes(';')) {
        const parts = line.split(';');
        nim = parts[0].trim();
        nama = parts.slice(1).join(' ').trim();
      } else {
        // Coba pisahkan berdasarkan spasi pertama setelah angka NIM
        const match = line.match(/^([0-9a-zA-Z_-]+)[\s]+(.+)$/);
        if (match) {
          nim = match[1].trim();
          nama = match[2].trim();
        } else {
          nim = line;
          nama = 'Mahasiswa ' + line;
        }
      }

      if (!nim) continue;

      // Cek apakah sudah ada (update atau lewati)
      if (!existingMap.has(nim.toLowerCase())) {
        const newId = generateId('MHS');
        rowsToAdd.push([newId, classId, nim, nama, new Date().toISOString()]);
        existingMap.set(nim.toLowerCase(), newId);
        count++;
      }
    }

    if (rowsToAdd.length > 0) {
      sheet.getRange(sheet.getLastRow() + 1, 1, rowsToAdd.length, 5).setValues(rowsToAdd);
    }

    return {
      success: true,
      count: count,
      message: `Berhasil menambahkan ${count} mahasiswa ke dalam kelas.`
    };
  },

  saveStudent(classId, data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS, ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at']);
    const mhsId = data.id_mahasiswa || generateId('MHS');
    const lastRow = sheet.getLastRow();

    if (data.id_mahasiswa && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_mahasiswa)) {
          const row = i + 2;
          sheet.getRange(row, 3, 1, 2).setValues([[data.nim, data.nama_mahasiswa]]);
          return { id_mahasiswa: mhsId, ...data };
        }
      }
    }

    sheet.appendRow([mhsId, classId, data.nim, data.nama_mahasiswa, new Date().toISOString()]);
    return { id_mahasiswa: mhsId, ...data };
  },

  deleteStudent(studentId) {
    this.deleteRowsByColumnValue(DB_SHEETS.STUDENTS, 1, String(studentId).trim());
    return { success: true, message: 'Mahasiswa berhasil dihapus.' };
  },

  // ==========================================
  // KELOLA TUGAS (TASKS)
  // ==========================================

  getTasksByClass(classId) {
    const cleanId = String(classId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_kelas', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 9).getValues();
    const students = this.getStudentsByClass(cleanId);
    const grades = this.getGradesByClass(cleanId);

    return values
      .filter(row => String(row[1]) === cleanId)
      .map(row => {
        const taskId = String(row[0]);
        const taskGrades = grades.filter(g => g.id_tugas === taskId && g.status === 'Selesai');
        const gradedCount = taskGrades.length;
        const totalScore = taskGrades.reduce((sum, g) => sum + (Number(g.nilai_total) || 0), 0);
        const avgScore = gradedCount > 0 ? (totalScore / gradedCount).toFixed(1) : 0;

        return {
          id_tugas: taskId,
          id_kelas: String(row[1]),
          judul_tugas: String(row[2] || ''),
          deskripsi: String(row[3] || ''),
          task_file_ref: String(row[4] || ''),
          drive_folder_id: String(row[5] || ''),
          rubrik_text: String(row[6] || ''),
          bobot_persentase: Number(row[7]) || 0,
          created_at: row[8] ? new Date(row[8]).toLocaleDateString('id-ID') : '',
          totalStudents: students.length,
          gradedCount: gradedCount,
          averageScore: Number(avgScore)
        };
      });
  },

  getTaskById(taskId) {
    const cleanId = String(taskId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_kelas', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return null;

    const values = sheet.getRange(2, 1, lastRow - 1, 9).getValues();
    for (const row of values) {
      if (String(row[0]) === cleanId) {
        return {
          id_tugas: String(row[0]),
          id_kelas: String(row[1]),
          judul_tugas: String(row[2] || ''),
          deskripsi: String(row[3] || ''),
          task_file_ref: String(row[4] || ''),
          drive_folder_id: String(row[5] || ''),
          rubrik_text: String(row[6] || ''),
          bobot_persentase: Number(row[7]) || 0,
          created_at: row[8] ? new Date(row[8]).toLocaleDateString('id-ID') : ''
        };
      }
    }
    return null;
  },

  saveTask(data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_kelas', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const taskId = data.id_tugas || generateId('TGS');
    const lastRow = sheet.getLastRow();
    const cleanFolderId = extractDriveFolderId(data.drive_folder_id) || (data.drive_folder_id || '').trim();

    if (data.id_tugas && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_tugas)) {
          const row = i + 2;
          sheet.getRange(row, 3, 1, 6).setValues([[
            data.judul_tugas || '',
            data.deskripsi || '',
            data.task_file_ref || '',
            cleanFolderId,
            data.rubrik_text || '',
            Number(data.bobot_persentase) || 0
          ]]);
          return { id_tugas: taskId, ...data, drive_folder_id: cleanFolderId };
        }
      }
    }

    sheet.appendRow([
      taskId,
      data.id_kelas,
      data.judul_tugas || 'Tugas Baru',
      data.deskripsi || '',
      data.task_file_ref || '',
      cleanFolderId,
      data.rubrik_text || '',
      Number(data.bobot_persentase) || 0,
      new Date().toISOString()
    ]);

    return { id_tugas: taskId, ...data, drive_folder_id: cleanFolderId };
  },

  deleteTask(taskId) {
    const cleanId = String(taskId).trim();
    this.deleteRowsByColumnValue(DB_SHEETS.TASKS, 1, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 2, cleanId);
    return { success: true, message: 'Tugas berhasil dihapus.' };
  },

  // ==========================================
  // KELOLA NILAI & EVALUASI (GRADES)
  // ==========================================

  getGradesByClass(classId) {
    const cleanId = String(classId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 11).getValues();
    return values
      .filter(row => String(row[2]) === cleanId)
      .map(row => ({
        id_nilai: String(row[0]),
        id_tugas: String(row[1]),
        id_kelas: String(row[2]),
        nim: String(row[3]),
        nama_mahasiswa: String(row[4]),
        file_name: String(row[5] || ''),
        nilai_total: row[6] !== '' ? Number(row[6]) : null,
        rincian_poin: String(row[7] || ''),
        catatan_ai: String(row[8] || ''),
        status: String(row[9] || 'Belum Dinilai'),
        waktu_penilaian: row[10] ? String(row[10]) : ''
      }));
  },

  getGradesByTask(taskId) {
    const task = this.getTaskById(taskId);
    if (!task) throw new Error('Tugas tidak ditemukan.');

    const students = this.getStudentsByClass(task.id_kelas);
    const sheet = this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    const lastRow = sheet.getLastRow();

    const gradeMap = new Map();
    if (lastRow >= 2) {
      const values = sheet.getRange(2, 1, lastRow - 1, 11).getValues();
      for (const row of values) {
        if (String(row[1]) === String(taskId)) {
          const nimKey = String(row[3]).trim().toLowerCase();
          gradeMap.set(nimKey, {
            id_nilai: String(row[0]),
            id_tugas: String(row[1]),
            id_kelas: String(row[2]),
            nim: String(row[3]),
            nama_mahasiswa: String(row[4]),
            file_name: String(row[5] || ''),
            nilai_total: row[6] !== '' ? Number(row[6]) : null,
            rincian_poin: String(row[7] || ''),
            catatan_ai: String(row[8] || ''),
            status: String(row[9] || 'Belum Dinilai'),
            waktu_penilaian: row[10] ? String(row[10]) : ''
          });
        }
      }
    }

    // Gabungkan dengan seluruh daftar mahasiswa di kelas tersebut
    return students.map(student => {
      const nimKey = student.nim.trim().toLowerCase();
      const existingGrade = gradeMap.get(nimKey);

      if (existingGrade) {
        return {
          ...student,
          ...existingGrade
        };
      }

      return {
        ...student,
        id_nilai: '',
        id_tugas: taskId,
        file_name: '',
        nilai_total: null,
        rincian_poin: '',
        catatan_ai: '',
        status: 'Belum Dinilai',
        waktu_penilaian: ''
      };
    });
  },

  saveGrade(gradeData) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    const lastRow = sheet.getLastRow();
    const cleanNim = String(gradeData.nim).trim().toLowerCase();
    const cleanTaskId = String(gradeData.id_tugas).trim();

    if (lastRow >= 2) {
      const values = sheet.getRange(2, 1, lastRow - 1, 4).getValues();
      for (let i = 0; i < values.length; i++) {
        const rowTaskId = String(values[i][1]).trim();
        const rowNim = String(values[i][3]).trim().toLowerCase();

        if (rowTaskId === cleanTaskId && rowNim === cleanNim) {
          const row = i + 2;
          sheet.getRange(row, 5, 1, 7).setValues([[
            gradeData.nama_mahasiswa,
            gradeData.file_name || '',
            gradeData.nilai_total !== undefined && gradeData.nilai_total !== null ? Number(gradeData.nilai_total) : '',
            gradeData.rincian_poin || '',
            gradeData.catatan_ai || '',
            gradeData.status || 'Belum Dinilai',
            gradeData.waktu_penilaian || new Date().toLocaleString('id-ID')
          ]]);
          return { id_nilai: String(values[i][0]), ...gradeData };
        }
      }
    }

    const newId = generateId('NIL');
    sheet.appendRow([
      newId,
      gradeData.id_tugas,
      gradeData.id_kelas,
      gradeData.nim,
      gradeData.nama_mahasiswa,
      gradeData.file_name || '',
      gradeData.nilai_total !== undefined && gradeData.nilai_total !== null ? Number(gradeData.nilai_total) : '',
      gradeData.rincian_poin || '',
      gradeData.catatan_ai || '',
      gradeData.status || 'Belum Dinilai',
      gradeData.waktu_penilaian || new Date().toLocaleString('id-ID')
    ]);

    return { id_nilai: newId, ...gradeData };
  },

  // ==========================================
  // REKAPITULASI & LAPORAN NILAI KELAS
  // ==========================================

  getClassReport(classId) {
    const cleanId = String(classId).trim();
    const classes = this.getClasses();
    const currentClass = classes.find(c => c.id_kelas === cleanId);
    if (!currentClass) throw new Error('Kelas tidak ditemukan.');

    const students = this.getStudentsByClass(cleanId);
    const tasks = this.getTasksByClass(cleanId);
    const grades = this.getGradesByClass(cleanId);

    // Pemetaan nilai per tugas dan mahasiswa
    const studentReports = students.map(student => {
      const nimKey = student.nim.trim().toLowerCase();
      const taskScores = {};
      let totalWeighted = 0;
      let totalWeight = 0;
      let simpleSum = 0;
      let gradedCount = 0;

      tasks.forEach(task => {
        const grade = grades.find(g => g.id_tugas === task.id_tugas && g.nim.trim().toLowerCase() === nimKey);
        const score = (grade && grade.nilai_total !== null && !isNaN(grade.nilai_total)) ? Number(grade.nilai_total) : null;
        taskScores[task.id_tugas] = score;

        if (score !== null) {
          gradedCount++;
          simpleSum += score;
          const weight = task.bobot_persentase > 0 ? task.bobot_persentase : 1;
          totalWeighted += (score * weight);
          totalWeight += weight;
        }
      });

      const averageScore = gradedCount > 0 ? Number((simpleSum / gradedCount).toFixed(1)) : 0;
      const finalWeightedScore = totalWeight > 0 ? Number((totalWeighted / totalWeight).toFixed(1)) : averageScore;

      return {
        id_mahasiswa: student.id_mahasiswa,
        nim: student.nim,
        nama_mahasiswa: student.nama_mahasiswa,
        taskScores: taskScores,
        gradedCount: gradedCount,
        averageScore: averageScore,
        finalScore: finalWeightedScore
      };
    });

    // Statistik kelas
    const allFinalScores = studentReports.map(s => s.finalScore).filter(s => s > 0);
    const classAvg = allFinalScores.length > 0 
      ? Number((allFinalScores.reduce((a, b) => a + b, 0) / allFinalScores.length).toFixed(1)) 
      : 0;
    const maxScore = allFinalScores.length > 0 ? Math.max(...allFinalScores) : 0;
    const minScore = allFinalScores.length > 0 ? Math.min(...allFinalScores) : 0;

    return {
      classInfo: currentClass,
      tasks: tasks,
      students: studentReports,
      stats: {
        totalStudents: students.length,
        totalTasks: tasks.length,
        classAverage: classAvg,
        highestScore: maxScore,
        lowestScore: minScore
      }
    };
  },

  /**
   * Ekspor hasil rekapitulasi ke tab sheet baru di spreadsheet
   */
  exportReportToSheet(classId) {
    const report = this.getClassReport(classId);
    const ss = this.getSpreadsheet();
    const cleanClassName = report.classInfo.nama_kelas.replace(/[\\/*?:[\]]/g, ' ').substring(0, 25);
    const sheetName = `REKAP_${cleanClassName}`;

    let sheet = ss.getSheetByName(sheetName);
    if (sheet) {
      sheet.clear();
    } else {
      sheet = ss.insertSheet(sheetName);
    }

    // Header Informasi Kelas
    sheet.getRange(1, 1).setValue('REKAPITULASI PENILAIAN MAHASISWA').setFontSize(14).setFontWeight('bold');
    sheet.getRange(2, 1).setValue(`Kelas: ${report.classInfo.nama_kelas} | Mata Kuliah: ${report.classInfo.mata_kuliah} | Semester: ${report.classInfo.semester}`).setFontColor('#475569');

    // Kolom Header Tabel
    const tableHeaders = ['No', 'NIM', 'Nama Mahasiswa'];
    report.tasks.forEach(t => {
      const weightText = t.bobot_persentase > 0 ? ` (${t.bobot_persentase}%)` : '';
      tableHeaders.push(`${t.judul_tugas}${weightText}`);
    });
    tableHeaders.push('Rata-rata Tugas');
    tableHeaders.push('Nilai Akhir Terbobot');

    sheet.getRange(4, 1, 1, tableHeaders.length).setValues([tableHeaders]);
    sheet.getRange(4, 1, 1, tableHeaders.length)
         .setBackground('#0f172a')
         .setFontColor('#ffffff')
         .setFontWeight('bold')
         .setHorizontalAlignment('center')
         .setVerticalAlignment('middle');
    sheet.setRowHeight(4, 32);

    // Baris Data Mahasiswa
    const rows = [];
    report.students.forEach((s, idx) => {
      const r = [idx + 1, s.nim, s.nama_mahasiswa];
      report.tasks.forEach(t => {
        const score = s.taskScores[t.id_tugas];
        r.push(score !== null && score !== undefined ? score : '-');
      });
      r.push(s.averageScore);
      r.push(s.finalScore);
      rows.push(r);
    });

    if (rows.length > 0) {
      sheet.getRange(5, 1, rows.length, tableHeaders.length).setValues(rows);
      // Format alignment
      sheet.getRange(5, 1, rows.length, 1).setHorizontalAlignment('center'); // No
      sheet.getRange(5, 2, rows.length, 1).setHorizontalAlignment('center'); // NIM
      sheet.getRange(5, 4, rows.length, tableHeaders.length - 3).setHorizontalAlignment('center'); // Scores
    }

    // Auto-fit column widths
    sheet.setColumnWidth(1, 45);
    sheet.setColumnWidth(2, 110);
    sheet.setColumnWidth(3, 190);
    for (let c = 4; c <= tableHeaders.length; c++) {
      sheet.setColumnWidth(c, 130);
    }

    SpreadsheetApp.flush();
    return {
      success: true,
      sheetName: sheetName,
      message: `Lembar rekapitulasi "${sheetName}" berhasil dibuat di Google Sheets!`
    };
  },

  // Helper hapus baris berdasarkan nilai kolom
  deleteRowsByColumnValue(sheetName, colIndex, targetValue) {
    const ss = this.getSpreadsheet();
    const sheet = ss.getSheetByName(sheetName);
    if (!sheet) return;

    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    const values = sheet.getRange(2, colIndex, lastRow - 1, 1).getValues();
    for (let i = values.length - 1; i >= 0; i--) {
      if (String(values[i][0]).trim() === targetValue) {
        sheet.deleteRow(i + 2);
      }
    }
  }
};
