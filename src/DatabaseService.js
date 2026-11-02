/**
 * @fileoverview Layanan Basis Data Spreadsheet (CRUD Multi-Tabel: Mata Kuliah, Kelas, Mahasiswa, Tugas, Nilai, Folder Kelas)
 */

const DatabaseService = {
  /**
   * Mengambil Spreadsheet aktif
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
   * Inisialisasi seluruh tabel basis data & migrasi otomatis ke hierarki Mata Kuliah
   */
  initDatabase() {
    this.getOrCreateSheet(DB_SHEETS.COURSES, ['id_mata_kuliah', 'kode_mk', 'nama_mata_kuliah', 'semester', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'id_mata_kuliah', 'nama_kelas', 'semester', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.STUDENTS, ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    this.getOrCreateSheet(DB_SHEETS.CLASS_FOLDERS, ['id_folder_kelas', 'id_tugas', 'id_kelas', 'drive_folder_id', 'created_at']);

    // Jalankan migrasi jika tabel kelas atau tugas masih berformat lama
    this.migrateToCourseHierarchy();

    // Auto-repair & penjaminan konsistensi hierarki Mata Kuliah & Kelas
    const courses = this.getCourses();
    let defaultCourseId = courses.length > 0 ? courses[0].id_mata_kuliah : null;

    if (!defaultCourseId) {
      const sampleCourse = this.saveCourse({
        kode_mk: 'IF210',
        nama_mata_kuliah: 'Pemrograman Web',
        semester: 'Ganjil 2026/2027'
      });
      defaultCourseId = sampleCourse.id_mata_kuliah;
    }

    const classSheet = this.getOrCreateSheet(DB_SHEETS.CLASSES);
    if (classSheet.getLastRow() < 2) {
      const sampleClassA = this.saveClass({
        id_mata_kuliah: defaultCourseId,
        nama_kelas: 'Kelas A',
        semester: 'Ganjil 2026/2027'
      });

      this.saveClass({
        id_mata_kuliah: defaultCourseId,
        nama_kelas: 'Kelas B',
        semester: 'Ganjil 2026/2027'
      });

      this.saveStudentsBulk(sampleClassA.id_kelas, '2024001\tBudi Santoso\n2024002\tSiti Rahmawati\n2024003\tAhmad Hidayat');
    } else {
      // Hubungkan kelas yatim (tanpa id_mata_kuliah valid) ke defaultCourseId
      const courseIdSet = new Set(this.getCourses().map(c => c.id_mata_kuliah));
      const classRows = classSheet.getRange(2, 1, classSheet.getLastRow() - 1, 2).getValues();
      classRows.forEach((r, idx) => {
        const cId = String(r[1] || '').trim();
        if (!cId || !courseIdSet.has(cId)) {
          classSheet.getRange(idx + 2, 2).setValue(defaultCourseId);
        }
      });
    }

    return { success: true, message: 'Basis data sistem siap digunakan.' };
  },

  /**
   * Reset total seluruh basis data ke kondisi awal yang bersih & terstruktur
   */
  resetDatabase() {
    const ss = this.getSpreadsheet();
    const sheetConfigs = [
      { name: DB_SHEETS.COURSES, headers: ['id_mata_kuliah', 'kode_mk', 'nama_mata_kuliah', 'semester', 'created_at'] },
      { name: DB_SHEETS.CLASSES, headers: ['id_kelas', 'id_mata_kuliah', 'nama_kelas', 'semester', 'created_at'] },
      { name: DB_SHEETS.STUDENTS, headers: ['id_mahasiswa', 'id_kelas', 'nim', 'nama_mahasiswa', 'created_at'] },
      { name: DB_SHEETS.TASKS, headers: ['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at'] },
      { name: DB_SHEETS.GRADES, headers: ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian'] },
      { name: DB_SHEETS.CLASS_FOLDERS, headers: ['id_folder_kelas', 'id_tugas', 'id_kelas', 'drive_folder_id', 'created_at'] }
    ];

    sheetConfigs.forEach(cfg => {
      let sheet = ss.getSheetByName(cfg.name);
      if (sheet) {
        sheet.clear();
      } else {
        sheet = ss.insertSheet(cfg.name);
      }
      sheet.getRange(1, 1, 1, cfg.headers.length).setValues([cfg.headers]);
      sheet.getRange(1, 1, 1, cfg.headers.length)
           .setBackground('#1e293b')
           .setFontColor('#ffffff')
           .setFontWeight('bold')
           .setHorizontalAlignment('center')
           .setVerticalAlignment('middle');
      sheet.setRowHeight(1, 35);
      sheet.setFrozenRows(1);
    });

    // Buat data contoh awal yang bersih & konsisten
    const sampleCourse = this.saveCourse({
      kode_mk: 'IF210',
      nama_mata_kuliah: 'Pemrograman Web',
      semester: 'Ganjil 2026/2027'
    });

    const sampleClassA = this.saveClass({
      id_mata_kuliah: sampleCourse.id_mata_kuliah,
      nama_kelas: 'Kelas A',
      semester: 'Ganjil 2026/2027'
    });

    const sampleClassB = this.saveClass({
      id_mata_kuliah: sampleCourse.id_mata_kuliah,
      nama_kelas: 'Kelas B',
      semester: 'Ganjil 2026/2027'
    });

    // Mahasiswa Kelas A
    this.saveStudentsBulk(sampleClassA.id_kelas, '2024001\tBudi Santoso\n2024002\tSiti Rahmawati\n2024003\tAhmad Hidayat');

    // Tugas Contoh di tingkat Mata Kuliah
    this.saveTask({
      id_mata_kuliah: sampleCourse.id_mata_kuliah,
      judul_tugas: 'Tugas 1 - Pemrograman Web Dasar',
      deskripsi: 'Tugas implementasi antarmuka HTML dan CSS responsif.',
      bobot_persentase: 25,
      rubrik_text: '## PANDUAN PORSI NILAI TUGAS (TOTAL: 100 POIN)\n\n1. Struktur HTML & Semantik (Porsi: 35 Poin)\n   - Kebersihan tag, struktur hierarki DOM, dan kelengkapan elemen.\n\n2. Tampilan & Responsif CSS (Porsi: 40 Poin)\n   - Kesesuaian styling, penggunaan flexbox/grid, responsivitas layar HP.\n\n3. Kerapian & Format (Porsi: 25 Poin)\n   - Indentasi kode, komentar, kerapian berkas.'
    });

    return { 
      success: true, 
      message: 'Basis data berhasil dibersihkan dan diinisialisasi ulang dengan struktur baru yang siap pakai.' 
    };
  },

  /**
   * Migrasi mulus dari skema lama (Kelas -> Tugas) ke skema baru (Mata Kuliah -> Kelas & Tugas)
   */
  migrateToCourseHierarchy() {
    try {
      const courseSheet = this.getOrCreateSheet(DB_SHEETS.COURSES, ['id_mata_kuliah', 'kode_mk', 'nama_mata_kuliah', 'semester', 'created_at']);
      const classSheet = this.getOrCreateSheet(DB_SHEETS.CLASSES);
      const taskSheet = this.getOrCreateSheet(DB_SHEETS.TASKS);

      if (classSheet.getLastRow() >= 1) {
        const classHeaders = classSheet.getRange(1, 1, 1, Math.max(classSheet.getLastColumn(), 5)).getValues()[0];
        
        // Format lama: header[1] === 'nama_kelas' && header[2] === 'mata_kuliah'
        if (classHeaders[1] === 'nama_kelas' && classHeaders[2] === 'mata_kuliah') {
          const classLastRow = classSheet.getLastRow();
          const courseMap = new Map(); // nama_mk -> courseId

          if (classLastRow >= 2) {
            const classRows = classSheet.getRange(2, 1, classLastRow - 1, 5).getValues();
            
            // 1. Kumpulkan seluruh nama mata kuliah unik
            classRows.forEach(row => {
              const mkName = String(row[2] || '').trim() || 'Mata Kuliah Umum';
              const semester = String(row[3] || '').trim();
              if (!courseMap.has(mkName.toLowerCase())) {
                const courseId = generateId('MK');
                courseMap.set(mkName.toLowerCase(), courseId);
                courseSheet.appendRow([courseId, '', mkName, semester, new Date().toISOString()]);
              }
            });

            // 2. Perbarui baris kelas dengan id_mata_kuliah
            const newClassRows = classRows.map(row => {
              const mkName = String(row[2] || '').trim() || 'Mata Kuliah Umum';
              const courseId = courseMap.get(mkName.toLowerCase()) || '';
              return [
                row[0], // id_kelas
                courseId, // id_mata_kuliah
                row[1], // nama_kelas
                row[3], // semester
                row[4] || new Date().toISOString() // created_at
              ];
            });
            classSheet.getRange(2, 1, newClassRows.length, 5).setValues(newClassRows);
          }

          // Perbarui header kelas
          classSheet.getRange(1, 1, 1, 5).setValues([['id_kelas', 'id_mata_kuliah', 'nama_kelas', 'semester', 'created_at']]);

          // 3. Perbarui baris tugas: jika header[1] === 'id_kelas', gantikan dengan id_mata_kuliah
          if (taskSheet.getLastRow() >= 1) {
            const taskHeaders = taskSheet.getRange(1, 1, 1, Math.max(taskSheet.getLastColumn(), 9)).getValues()[0];
            if (taskHeaders[1] === 'id_kelas') {
              const taskLastRow = taskSheet.getLastRow();
              if (taskLastRow >= 2) {
                const taskRows = taskSheet.getRange(2, 1, taskLastRow - 1, 9).getValues();
                const classToCourseMap = new Map();
                if (classLastRow >= 2) {
                  const cRows = classSheet.getRange(2, 1, classLastRow - 1, 2).getValues();
                  cRows.forEach(r => classToCourseMap.set(String(r[0]), String(r[1])));
                }

                const defaultCourseId = courseMap.values().next().value || '';
                const newTaskRows = taskRows.map(tRow => {
                  const oldClassId = String(tRow[1]);
                  const courseId = classToCourseMap.get(oldClassId) || defaultCourseId;
                  tRow[1] = courseId; // ganti id_kelas -> id_mata_kuliah
                  return tRow;
                });
                taskSheet.getRange(2, 1, newTaskRows.length, 9).setValues(newTaskRows);
              }
              taskSheet.getRange(1, 1, 1, 9).setValues([['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']]);
            }
          }
        }
      }
    } catch (err) {
      console.warn('Peringatan migrasi basis data: ' + err.message);
    }
  },

  // ==========================================
  // KELOLA MATA KULIAH (COURSES)
  // ==========================================

  getCourses() {
    const sheet = this.getOrCreateSheet(DB_SHEETS.COURSES, ['id_mata_kuliah', 'kode_mk', 'nama_mata_kuliah', 'semester', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 5).getValues();
    const classSheet = this.getOrCreateSheet(DB_SHEETS.CLASSES);
    const taskSheet = this.getOrCreateSheet(DB_SHEETS.TASKS);
    const studentSheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS);

    // Kumpulkan relasi kelas per mata kuliah
    const allClasses = classSheet.getLastRow() >= 2 
      ? classSheet.getRange(2, 1, classSheet.getLastRow() - 1, 2).getValues() 
      : [];
    const allTasks = taskSheet.getLastRow() >= 2 
      ? taskSheet.getRange(2, 2, taskSheet.getLastRow() - 1, 1).getValues() 
      : [];
    const allStudents = studentSheet.getLastRow() >= 2
      ? studentSheet.getRange(2, 2, studentSheet.getLastRow() - 1, 1).getValues()
      : [];

    // Map classId -> courseId
    const classToCourse = new Map();
    allClasses.forEach(r => classToCourse.set(String(r[0]), String(r[1])));

    return values.map(row => {
      const courseId = String(row[0]);
      const classCount = allClasses.filter(c => String(c[1]) === courseId).length;
      const taskCount = allTasks.filter(t => String(t[0]) === courseId).length;
      
      // Hitung total mahasiswa di seluruh kelas mata kuliah ini
      const studentCount = allStudents.filter(s => classToCourse.get(String(s[0])) === courseId).length;

      return {
        id_mata_kuliah: courseId,
        kode_mk: String(row[1] || ''),
        nama_mata_kuliah: String(row[2] || 'Mata Kuliah'),
        semester: String(row[3] || ''),
        created_at: row[4] ? new Date(row[4]).toLocaleDateString('id-ID') : '',
        classCount: classCount,
        taskCount: taskCount,
        studentCount: studentCount
      };
    });
  },

  getCourseById(courseId) {
    const cleanId = String(courseId).trim();
    const courses = this.getCourses();
    return courses.find(c => c.id_mata_kuliah === cleanId) || null;
  },

  saveCourse(data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.COURSES, ['id_mata_kuliah', 'kode_mk', 'nama_mata_kuliah', 'semester', 'created_at']);
    const courseId = data.id_mata_kuliah || generateId('MK');
    const lastRow = sheet.getLastRow();

    if (data.id_mata_kuliah && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_mata_kuliah)) {
          const row = i + 2;
          sheet.getRange(row, 2, 1, 3).setValues([[
            data.kode_mk || '',
            data.nama_mata_kuliah || 'Mata Kuliah',
            data.semester || ''
          ]]);
          return { id_mata_kuliah: courseId, ...data };
        }
      }
    }

    sheet.appendRow([
      courseId,
      data.kode_mk || '',
      data.nama_mata_kuliah || 'Mata Kuliah Baru',
      data.semester || '',
      new Date().toISOString()
    ]);

    return { id_mata_kuliah: courseId, ...data };
  },

  deleteCourse(courseId) {
    const cleanId = String(courseId).trim();
    // Cari seluruh kelas di bawah mata kuliah ini untuk hapus mahasiswa dan nilai
    const classes = this.getClasses(cleanId);
    classes.forEach(c => {
      this.deleteRowsByColumnValue(DB_SHEETS.STUDENTS, 2, c.id_kelas);
      this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 3, c.id_kelas);
      this.deleteRowsByColumnValue(DB_SHEETS.CLASS_FOLDERS, 3, c.id_kelas);
    });

    // Cari seluruh tugas di bawah mata kuliah ini
    const tasks = this.getTasksByCourse(cleanId);
    tasks.forEach(t => {
      this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 2, t.id_tugas);
      this.deleteRowsByColumnValue(DB_SHEETS.CLASS_FOLDERS, 2, t.id_tugas);
    });

    this.deleteRowsByColumnValue(DB_SHEETS.TASKS, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.CLASSES, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.COURSES, 1, cleanId);

    return { success: true, message: 'Mata kuliah dan seluruh kelas, tugas, serta data nilai terkait berhasil dihapus.' };
  },

  // ==========================================
  // KELOLA KELAS (CLASSES)
  // ==========================================

  getClasses(courseId = null) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'id_mata_kuliah', 'nama_kelas', 'semester', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 5).getValues();
    const studentsSheet = this.getOrCreateSheet(DB_SHEETS.STUDENTS);
    const taskSheet = this.getOrCreateSheet(DB_SHEETS.TASKS);
    const courseSheet = this.getOrCreateSheet(DB_SHEETS.COURSES);

    // Kumpulkan nama mata kuliah
    const courseMap = new Map();
    if (courseSheet.getLastRow() >= 2) {
      const cRows = courseSheet.getRange(2, 1, courseSheet.getLastRow() - 1, 3).getValues();
      cRows.forEach(r => courseMap.set(String(r[0]), String(r[2] || '')));
    }

    // Hitung relasi mahasiswa & tugas
    const allStudents = studentsSheet.getLastRow() >= 2 
      ? studentsSheet.getRange(2, 2, studentsSheet.getLastRow() - 1, 1).getValues() 
      : [];
    const allTasks = taskSheet.getLastRow() >= 2 
      ? taskSheet.getRange(2, 2, taskSheet.getLastRow() - 1, 1).getValues() 
      : [];

    let filteredValues = values;
    if (courseId) {
      const cleanCourseId = String(courseId).trim();
      filteredValues = values.filter(row => String(row[1]) === cleanCourseId);
    }

    return filteredValues.map(row => {
      const classId = String(row[0]);
      const cId = String(row[1] || '');
      const studentCount = allStudents.filter(s => String(s[0]) === classId).length;
      const taskCount = allTasks.filter(t => String(t[0]) === cId).length;

      return {
        id_kelas: classId,
        id_mata_kuliah: cId,
        nama_mata_kuliah: courseMap.get(cId) || '',
        nama_kelas: row[2] || '',
        semester: row[3] || '',
        created_at: row[4] ? new Date(row[4]).toLocaleDateString('id-ID') : '',
        studentCount: studentCount,
        taskCount: taskCount
      };
    });
  },

  getClassById(classId) {
    const cleanId = String(classId).trim();
    const classes = this.getClasses();
    return classes.find(c => c.id_kelas === cleanId) || null;
  },

  saveClass(data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASSES, ['id_kelas', 'id_mata_kuliah', 'nama_kelas', 'semester', 'created_at']);
    const classId = data.id_kelas || generateId('KLS');
    let courseId = data.id_mata_kuliah || '';

    // Auto-repair: jika courseId kosong, hubungkan ke mata kuliah pertama atau buatkan default
    if (!courseId) {
      const courses = this.getCourses();
      if (courses.length > 0) {
        courseId = courses[0].id_mata_kuliah;
      } else {
        const defaultCourse = this.saveCourse({
          kode_mk: 'IF210',
          nama_mata_kuliah: 'Pemrograman Web',
          semester: data.semester || 'Ganjil 2026/2027'
        });
        courseId = defaultCourse.id_mata_kuliah;
      }
    }

    const lastRow = sheet.getLastRow();

    if (data.id_kelas && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_kelas)) {
          const row = i + 2;
          sheet.getRange(row, 2, 1, 3).setValues([[
            courseId,
            data.nama_kelas || '',
            data.semester || ''
          ]]);
          return { id_kelas: classId, ...data };
        }
      }
    }

    // Insert baru
    sheet.appendRow([
      classId,
      courseId,
      data.nama_kelas || 'Kelas Baru',
      data.semester || '',
      new Date().toISOString()
    ]);

    return { id_kelas: classId, ...data };
  },

  deleteClass(classId) {
    const cleanId = String(classId).trim();
    this.deleteRowsByColumnValue(DB_SHEETS.CLASSES, 1, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.STUDENTS, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 3, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.CLASS_FOLDERS, 3, cleanId);
    return { success: true, message: 'Kelas dan seluruh data mahasiswa serta nilai terkait berhasil dihapus.' };
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
  // KELOLA TUGAS (TASKS) BERPUSAT MATA KULIAH
  // ==========================================

  getTasksByCourse(courseId) {
    const cleanCourseId = String(courseId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return [];

    const values = sheet.getRange(2, 1, lastRow - 1, 9).getValues();
    const gradeSheet = this.getOrCreateSheet(DB_SHEETS.GRADES);
    const allGrades = gradeSheet.getLastRow() >= 2 
      ? gradeSheet.getRange(2, 1, gradeSheet.getLastRow() - 1, 10).getValues() 
      : [];

    return values
      .filter(row => String(row[1]) === cleanCourseId)
      .map(row => {
        const taskId = String(row[0]);
        const taskGrades = allGrades.filter(g => String(g[1]) === taskId && String(g[9]) === 'Selesai');
        const gradedCount = taskGrades.length;
        const totalScore = taskGrades.reduce((sum, g) => sum + (Number(g[6]) || 0), 0);
        const avgScore = gradedCount > 0 ? (totalScore / gradedCount).toFixed(1) : 0;

        return {
          id_tugas: taskId,
          id_mata_kuliah: String(row[1]),
          judul_tugas: String(row[2] || ''),
          deskripsi: String(row[3] || ''),
          task_file_ref: String(row[4] || ''),
          drive_folder_id: String(row[5] || ''),
          rubrik_text: String(row[6] || ''),
          bobot_persentase: Number(row[7]) || 0,
          created_at: row[8] ? new Date(row[8]).toLocaleDateString('id-ID') : '',
          gradedCount: gradedCount,
          averageScore: Number(avgScore)
        };
      });
  },

  /**
   * Mengambil daftar tugas yang berlaku untuk suatu kelas (diambil dari Mata Kuliah kelas tersebut)
   */
  getTasksByClass(classId) {
    const cls = this.getClassById(classId);
    if (!cls || !cls.id_mata_kuliah) return [];

    const tasks = this.getTasksByCourse(cls.id_mata_kuliah);
    const folderOverrides = this.getClassTaskFolders(classId);

    const students = this.getStudentsByClass(classId);
    const grades = this.getGradesByClass(classId);

    return tasks.map(task => {
      const taskGrades = grades.filter(g => g.id_tugas === task.id_tugas && g.status === 'Selesai');
      const gradedCount = taskGrades.length;
      const totalScore = taskGrades.reduce((sum, g) => sum + (Number(g.nilai_total) || 0), 0);
      const avgScore = gradedCount > 0 ? (totalScore / gradedCount).toFixed(1) : 0;

      // Cek apakah ada override folder khusus untuk kelas ini
      const overrideFolder = folderOverrides.get(task.id_tugas);
      const effectiveFolder = overrideFolder || task.drive_folder_id;

      return {
        ...task,
        id_kelas: classId,
        drive_folder_id: effectiveFolder,
        has_class_folder_override: Boolean(overrideFolder),
        totalStudents: students.length,
        gradedCount: gradedCount,
        averageScore: Number(avgScore)
      };
    });
  },

  getTaskById(taskId, classId = null) {
    const cleanId = String(taskId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return null;

    const values = sheet.getRange(2, 1, lastRow - 1, 9).getValues();
    for (const row of values) {
      if (String(row[0]) === cleanId) {
        let folderId = String(row[5] || '');
        if (classId) {
          const override = this.getClassTaskFolder(cleanId, classId);
          if (override) folderId = override;
        }

        return {
          id_tugas: String(row[0]),
          id_mata_kuliah: String(row[1]),
          judul_tugas: String(row[2] || ''),
          deskripsi: String(row[3] || ''),
          task_file_ref: String(row[4] || ''),
          drive_folder_id: folderId,
          rubrik_text: String(row[6] || ''),
          bobot_persentase: Number(row[7]) || 0,
          created_at: row[8] ? new Date(row[8]).toLocaleDateString('id-ID') : ''
        };
      }
    }
    return null;
  },

  saveTask(data) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.TASKS, ['id_tugas', 'id_mata_kuliah', 'judul_tugas', 'deskripsi', 'task_file_ref', 'drive_folder_id', 'rubrik_text', 'bobot_persentase', 'created_at']);
    const taskId = data.id_tugas || generateId('TGS');
    let courseId = data.id_mata_kuliah || '';
    if (!courseId) {
      const courses = this.getCourses();
      if (courses.length > 0) courseId = courses[0].id_mata_kuliah;
    }
    const lastRow = sheet.getLastRow();
    const cleanFolderId = extractDriveFolderId(data.drive_folder_id) || (data.drive_folder_id || '').trim();

    if (data.id_tugas && lastRow >= 2) {
      const ids = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
      for (let i = 0; i < ids.length; i++) {
        if (String(ids[i][0]) === String(data.id_tugas)) {
          const row = i + 2;
          sheet.getRange(row, 2, 1, 7).setValues([[
            courseId,
            data.judul_tugas || '',
            data.deskripsi || '',
            data.task_file_ref || '',
            cleanFolderId,
            data.rubrik_text || '',
            Number(data.bobot_persentase) || 0
          ]]);

          // Simpan folder kelas khusus jika disediakan
          if (data.class_folders && typeof data.class_folders === 'object') {
            this.saveClassTaskFoldersBulk(taskId, data.class_folders);
          }

          return { id_tugas: taskId, ...data, drive_folder_id: cleanFolderId };
        }
      }
    }

    sheet.appendRow([
      taskId,
      courseId,
      data.judul_tugas || 'Tugas Baru',
      data.deskripsi || '',
      data.task_file_ref || '',
      cleanFolderId,
      data.rubrik_text || '',
      Number(data.bobot_persentase) || 0,
      new Date().toISOString()
    ]);

    if (data.class_folders && typeof data.class_folders === 'object') {
      this.saveClassTaskFoldersBulk(taskId, data.class_folders);
    }

    return { id_tugas: taskId, ...data, drive_folder_id: cleanFolderId };
  },

  deleteTask(taskId) {
    const cleanId = String(taskId).trim();
    this.deleteRowsByColumnValue(DB_SHEETS.TASKS, 1, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.GRADES, 2, cleanId);
    this.deleteRowsByColumnValue(DB_SHEETS.CLASS_FOLDERS, 2, cleanId);
    return { success: true, message: 'Tugas berhasil dihapus.' };
  },

  // ==========================================
  // FOLDER JAWABAN KHUSUS PER KELAS (OPSIONAL)
  // ==========================================

  getClassTaskFolders(classId) {
    const cleanClassId = String(classId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASS_FOLDERS, ['id_folder_kelas', 'id_tugas', 'id_kelas', 'drive_folder_id', 'created_at']);
    const lastRow = sheet.getLastRow();
    const map = new Map();
    if (lastRow < 2) return map;

    const values = sheet.getRange(2, 2, lastRow - 1, 3).getValues();
    values.forEach(r => {
      if (String(r[1]) === cleanClassId && r[2]) {
        map.set(String(r[0]), String(r[2]));
      }
    });
    return map;
  },

  getClassTaskFolder(taskId, classId) {
    const cleanTaskId = String(taskId).trim();
    const cleanClassId = String(classId).trim();
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASS_FOLDERS, ['id_folder_kelas', 'id_tugas', 'id_kelas', 'drive_folder_id', 'created_at']);
    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return '';

    const values = sheet.getRange(2, 2, lastRow - 1, 3).getValues();
    for (const r of values) {
      if (String(r[0]) === cleanTaskId && String(r[1]) === cleanClassId) {
        return String(r[2] || '');
      }
    }
    return '';
  },

  saveClassTaskFoldersBulk(taskId, classFoldersMap) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.CLASS_FOLDERS, ['id_folder_kelas', 'id_tugas', 'id_kelas', 'drive_folder_id', 'created_at']);
    for (const classId in classFoldersMap) {
      const folderVal = extractDriveFolderId(classFoldersMap[classId]);
      this.deleteRowsByTwoColumns(DB_SHEETS.CLASS_FOLDERS, 2, taskId, 3, classId);
      if (folderVal) {
        sheet.appendRow([generateId('FDK'), taskId, classId, folderVal, new Date().toISOString()]);
      }
    }
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

  /**
   * Mengambil data nilai untuk tugas tertentu dalam konteks kelas tertentu
   */
  getGradesByTaskAndClass(taskId, classId) {
    const task = this.getTaskById(taskId, classId);
    if (!task) throw new Error('Tugas tidak ditemukan.');

    const students = this.getStudentsByClass(classId);
    const sheet = this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    const lastRow = sheet.getLastRow();

    const gradeMap = new Map();
    if (lastRow >= 2) {
      const values = sheet.getRange(2, 1, lastRow - 1, 11).getValues();
      for (const row of values) {
        if (String(row[1]) === String(taskId) && String(row[2]) === String(classId)) {
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

  getGradesByTask(taskId, classId = null) {
    if (classId) {
      return this.getGradesByTaskAndClass(taskId, classId);
    }
    // Jika classId tidak disertakan, cari kelas pertama yang memiliki tugas ini
    const task = this.getTaskById(taskId);
    if (!task) throw new Error('Tugas tidak ditemukan.');
    const classes = this.getClasses(task.id_mata_kuliah);
    if (classes.length > 0) {
      return this.getGradesByTaskAndClass(taskId, classes[0].id_kelas);
    }
    return [];
  },

  saveGrade(gradeData) {
    const sheet = this.getOrCreateSheet(DB_SHEETS.GRADES, ['id_nilai', 'id_tugas', 'id_kelas', 'nim', 'nama_mahasiswa', 'file_name', 'nilai_total', 'rincian_poin', 'catatan_ai', 'status', 'waktu_penilaian']);
    const lastRow = sheet.getLastRow();
    const cleanNim = String(gradeData.nim).trim().toLowerCase();
    const cleanTaskId = String(gradeData.id_tugas).trim();
    const cleanClassId = String(gradeData.id_kelas).trim();

    if (lastRow >= 2) {
      const values = sheet.getRange(2, 1, lastRow - 1, 4).getValues();
      for (let i = 0; i < values.length; i++) {
        const rowTaskId = String(values[i][1]).trim();
        const rowClassId = String(values[i][2]).trim();
        const rowNim = String(values[i][3]).trim().toLowerCase();

        if (rowTaskId === cleanTaskId && rowClassId === cleanClassId && rowNim === cleanNim) {
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
    const currentClass = this.getClassById(cleanId);
    if (!currentClass) throw new Error('Kelas tidak ditemukan.');

    const course = this.getCourseById(currentClass.id_mata_kuliah);
    const students = this.getStudentsByClass(cleanId);
    const tasks = this.getTasksByClass(cleanId);
    const grades = this.getGradesByClass(cleanId);

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

    const allFinalScores = studentReports.map(s => s.finalScore).filter(s => s > 0);
    const classAvg = allFinalScores.length > 0 
      ? Number((allFinalScores.reduce((a, b) => a + b, 0) / allFinalScores.length).toFixed(1)) 
      : 0;
    const maxScore = allFinalScores.length > 0 ? Math.max(...allFinalScores) : 0;
    const minScore = allFinalScores.length > 0 ? Math.min(...allFinalScores) : 0;

    return {
      classInfo: {
        ...currentClass,
        mata_kuliah: course ? course.nama_mata_kuliah : (currentClass.nama_mata_kuliah || 'Mata Kuliah'),
        kode_mk: course ? course.kode_mk : ''
      },
      courseInfo: course,
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
    sheet.getRange(2, 1).setValue(`Mata Kuliah: ${report.classInfo.mata_kuliah} | Kelas: ${report.classInfo.nama_kelas} | Semester: ${report.classInfo.semester}`).setFontColor('#475569');

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
      sheet.getRange(5, 1, rows.length, 1).setHorizontalAlignment('center');
      sheet.getRange(5, 2, rows.length, 1).setHorizontalAlignment('center');
      sheet.getRange(5, 4, rows.length, tableHeaders.length - 3).setHorizontalAlignment('center');
    }

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

  getClassFullExportData(classId) {
    const report = this.getClassReport(classId);
    const cleanId = String(classId).trim();
    const grades = this.getGradesByClass(cleanId);
    const students = this.getStudentsByClass(cleanId);

    const taskDetails = report.tasks.map(task => {
      const taskGrades = students.map((st, idx) => {
        const nimKey = st.nim.trim().toLowerCase();
        const g = grades.find(item => item.id_tugas === task.id_tugas && item.nim.trim().toLowerCase() === nimKey);

        let scoreVal = '-';
        if (g && g.nilai_total !== null && g.nilai_total !== undefined && !isNaN(g.nilai_total)) {
          scoreVal = Number(g.nilai_total);
        }

        return {
          no: idx + 1,
          nim: st.nim,
          nama_mahasiswa: st.nama_mahasiswa,
          file_name: g ? (g.file_name || '-') : '-',
          nilai_total: scoreVal,
          rincian_poin: g ? (g.rincian_poin || '-') : '-',
          catatan_ai: g ? (g.catatan_ai || '-') : '-',
          status: g ? (g.status || 'Belum Dinilai') : 'Belum Dinilai',
          waktu_penilaian: g ? (g.waktu_penilaian || '-') : '-'
        };
      });

      return {
        task: task,
        grades: taskGrades
      };
    });

    return {
      success: true,
      classInfo: report.classInfo,
      report: report,
      taskDetails: taskDetails
    };
  },

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
  },

  deleteRowsByTwoColumns(sheetName, col1Index, val1, col2Index, val2) {
    const ss = this.getSpreadsheet();
    const sheet = ss.getSheetByName(sheetName);
    if (!sheet) return;

    const lastRow = sheet.getLastRow();
    if (lastRow < 2) return;

    const maxCol = Math.max(col1Index, col2Index);
    const values = sheet.getRange(2, 1, lastRow - 1, maxCol).getValues();
    for (let i = values.length - 1; i >= 0; i--) {
      if (String(values[i][col1Index - 1]).trim() === String(val1).trim() &&
          String(values[i][col2Index - 1]).trim() === String(val2).trim()) {
        sheet.deleteRow(i + 2);
      }
    }
  }
};
