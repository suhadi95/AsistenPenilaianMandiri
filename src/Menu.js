/**
 * @fileoverview Titik Masuk (Entry Point), Web App Handler (doGet), Menu Toolbar, dan API RPC
 */

/**
 * Titik masuk utama Standalone Web Application
 */
function doGet(e) {
  DatabaseService.initDatabase();
  return HtmlService.createTemplateFromFile('Index')
    .evaluate()
    .setTitle('🎓 Asisten Penilaian Mahasiswa - Portal Akademik')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1.0')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

/**
 * Pemicu saat spreadsheet dibuka
 */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('🎓 Asisten Penilaian')
    .addItem('🌐 Buka Portal Web Penilaian', 'openWebAppPrompt')
    .addSeparator()
    .addItem('📊 Generate / Update Sheet Rekapitulasi', 'menuExportActiveReport')
    .addItem('📋 Inisialisasi Database Kelas', 'menuInitDatabase')
    .addItem('⚠️ Reset / Bersihkan Basis Data', 'menuResetDatabase')
    .addSeparator()
    .addItem('⚙️ Pengaturan & API Key', 'openSidebar')
    .addItem('ℹ️ Panduan Sistem', 'openHelpDialog')
    .addToUi();
}

function menuResetDatabase() {
  const ui = SpreadsheetApp.getUi();
  const resp = ui.alert(
    'Konfirmasi Reset Basis Data',
    'PERINGATAN: Tindakan ini akan mengosongkan seluruh database dan menginisialisasi ulang dengan struktur baru dan contoh data bersih. Apakah Anda yakin?',
    ui.ButtonSet.YES_NO
  );
  if (resp === ui.Button.YES) {
    const res = DatabaseService.resetDatabase();
    ui.alert('Berhasil', res.message, ui.ButtonSet.OK);
  }
}

/**
 * Menampilkan tautan langsung untuk membuka Web App di tab browser baru
 */
function openWebAppPrompt() {
  const url = ScriptApp.getService().getUrl();
  const ui = SpreadsheetApp.getUi();
  
  if (url) {
    const html = `
      <div style="font-family: -apple-system, sans-serif; padding: 16px; text-align: center;">
        <h3 style="margin-bottom: 12px; color: #1e293b;">🌐 Portal Web Penilaian Mandiri</h3>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 18px;">
          Gunakan portal web standalone untuk mengelola mata kuliah, kelas, mahasiswa, tugas multi-kelas, koreksi multimodal, dan rekapitulasi nilai.
        </p>
        <a href="${url}" target="_blank" style="display: inline-block; background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 13px;">
          🚀 Buka Portal Web di Tab Baru
        </a>
      </div>
    `;
    const output = HtmlService.createHtmlOutput(html).setWidth(400).setHeight(180);
    ui.showModalDialog(output, 'Akses Portal Web');
  } else {
    ui.alert(
      'Deploy Web App Diperlukan',
      'Aplikasi belum di-deploy sebagai Web App. Silakan lakukan Deploy > New Deployment > Web App di editor Apps Script.',
      ui.ButtonSet.OK
    );
  }
}

function menuExportActiveReport() {
  const classes = DatabaseService.getClasses();
  if (classes.length === 0) {
    SpreadsheetApp.getUi().alert('Perhatian', 'Belum ada kelas yang terdaftar. Buka Portal Web untuk membuat kelas.', SpreadsheetApp.getUi().ButtonSet.OK);
    return;
  }
  const result = DatabaseService.exportReportToSheet(classes[0].id_kelas);
  SpreadsheetApp.getUi().alert('Berhasil', result.message, SpreadsheetApp.getUi().ButtonSet.OK);
}

function menuInitDatabase() {
  const result = DatabaseService.initDatabase();
  SpreadsheetApp.getActiveSpreadsheet().toast(result.message, 'Database Siap', 4);
}

function openSidebar() {
  const htmlOutput = HtmlService.createHtmlOutputFromFile('Sidebar')
    .setTitle('🎓 Pengaturan Asisten Penilaian')
    .setWidth(420);
  SpreadsheetApp.getUi().showSidebar(htmlOutput);
}

function openHelpDialog() {
  const message = `
Panduan Sistem Manajemen Mata Kuliah, Kelas & Penilaian Akademik:

1. Portal Web Mandiri (Standalone):
   • Kelola seluruh mata kuliah, kelas, daftar mahasiswa, penugasan, dan penilaian melalui antarmuka web.
   • Buka menu "🌐 Buka Portal Web Penilaian" untuk mengakses dashboard penuh.

2. Struktur Penyimpanan Berpusat Mata Kuliah:
   • 1 Mata Kuliah dapat membawahi beberapa Kelas (misal Kelas A, B, C).
   • 1 Tugas dibuat di tingkat Mata Kuliah dan otomatis berlaku untuk seluruh kelas tanpa perlu input ulang link soal atau rubrik.
   • Seluruh data tersimpan di sheet database: _DB_MATA_KULIAH, _DB_KELAS, _DB_MAHASISWA, _DB_TUGAS, dan _DB_NILAI.

3. Penilaian Multimodal & Cerdas:
   • Sistem membandingkan berkas jawaban mahasiswa di Drive terhadap berkas soal dan rubrik porsi poin.
   • Dilengkapi Pencocokan Cerdas Isi Dokumen (Content-Based Matching) dengan Gemini AI jika nama berkas tidak memuat NIM/Nama.
`;
  SpreadsheetApp.getUi().alert('ℹ️ Panduan Sistem', message, SpreadsheetApp.getUi().ButtonSet.OK);
}

// ==========================================
// API RPC JEMBATAN UNTUK WEB APP (google.script.run)
// ==========================================

function apiResetDatabase() {
  return DatabaseService.resetDatabase();
}

function apiGetInitialData() {
  DatabaseService.initDatabase();
  const settings = getAppSettings();
  const courses = DatabaseService.getCourses();
  const classes = DatabaseService.getClasses();
  const webAppUrl = ScriptApp.getService().getUrl() || '';

  return {
    settings: settings,
    courses: courses,
    classes: classes,
    webAppUrl: webAppUrl
  };
}

// Mata Kuliah (Courses)
function apiGetCourses() {
  return DatabaseService.getCourses();
}

function apiSaveCourse(data) {
  return DatabaseService.saveCourse(data);
}

function apiDeleteCourse(courseId) {
  return DatabaseService.deleteCourse(courseId);
}

// Kelas (Classes)
function apiGetClasses(courseId = null) {
  return DatabaseService.getClasses(courseId);
}

function apiSaveClass(data) {
  return DatabaseService.saveClass(data);
}

function apiDeleteClass(classId) {
  return DatabaseService.deleteClass(classId);
}

// Mahasiswa (Students)
function apiGetStudents(classId) {
  return DatabaseService.getStudentsByClass(classId);
}

function apiSaveStudentsBulk(classId, rawText) {
  return DatabaseService.saveStudentsBulk(classId, rawText);
}

function apiSaveStudent(classId, data) {
  return DatabaseService.saveStudent(classId, data);
}

function apiDeleteStudent(studentId) {
  return DatabaseService.deleteStudent(studentId);
}

// Tugas (Tasks)
function apiGetTasks(courseIdOrClassId) {
  if (!courseIdOrClassId) return [];
  const cleanId = String(courseIdOrClassId).trim();
  // Jika diawali KLS-, ambil tugas berdasarkan kelas
  if (cleanId.startsWith('KLS-')) {
    return DatabaseService.getTasksByClass(cleanId);
  }
  // Jika diawali MK-, ambil tugas berdasarkan mata kuliah
  if (cleanId.startsWith('MK-')) {
    return DatabaseService.getTasksByCourse(cleanId);
  }
  // Fallback: coba getTasksByClass dulu, jika kosong coba getTasksByCourse
  const byClass = DatabaseService.getTasksByClass(cleanId);
  if (byClass && byClass.length > 0) return byClass;
  return DatabaseService.getTasksByCourse(cleanId);
}

function apiGetTasksByCourse(courseId) {
  return DatabaseService.getTasksByCourse(courseId);
}

function apiGetTasksByClass(classId) {
  return DatabaseService.getTasksByClass(classId);
}

function apiGetTaskById(taskId, classId = null) {
  return DatabaseService.getTaskById(taskId, classId);
}

function apiSaveTask(data) {
  return DatabaseService.saveTask(data);
}

function apiDeleteTask(taskId) {
  return DatabaseService.deleteTask(taskId);
}

// Penilaian & Koreksi
function apiGetGradesByTask(taskId, classId = null) {
  if (classId) {
    return DatabaseService.getGradesByTaskAndClass(taskId, classId);
  }
  return DatabaseService.getGradesByTask(taskId);
}

function apiGradeSingleStudent(taskId, classId, studentNim) {
  if (!classId) throw new Error('ID Kelas belum ditentukan.');
  const task = DatabaseService.getTaskById(taskId, classId);
  if (!task) throw new Error('Tugas tidak ditemukan.');

  const settings = getAppSettings();
  if (!settings.rawApiKey) {
    throw new Error('Gemini API Key belum dimasukkan di menu Pengaturan.');
  }
  if (!task.drive_folder_id) {
    throw new Error('Folder Google Drive jawaban mahasiswa belum diisi pada tugas ini.');
  }

  const students = DatabaseService.getStudentsByClass(classId);
  const student = students.find(s => String(s.nim).trim() === String(studentNim).trim());
  if (!student) {
    throw new Error(`Mahasiswa dengan NIM "${studentNim}" tidak ditemukan dalam kelas ini.`);
  }

  let taskQuestionData = null;
  if (task.task_file_ref) {
    taskQuestionData = DriveService.getTaskQuestionPayload(task.task_file_ref);
  }

  return GradingService.gradeStudent(task, student, settings, taskQuestionData);
}

function apiGradeSelectedStudents(taskId, classId, studentNims) {
  return GradingService.gradeSelectedStudents(taskId, classId, studentNims);
}

function apiGradeAllStudents(taskId, classId, onlyUnassessed) {
  return GradingService.gradeAllStudents(taskId, classId, onlyUnassessed);
}

function apiResetFailedGrades(taskId, classId) {
  return GradingService.resetFailedGrades(taskId, classId);
}

function apiSaveGradeManual(gradeData) {
  return DatabaseService.saveGrade(gradeData);
}

// Laporan Rekapitulasi
function apiGetClassReport(classId) {
  return DatabaseService.getClassReport(classId);
}

function apiExportReportToSheet(classId) {
  return DatabaseService.exportReportToSheet(classId);
}

function apiGetClassFullExportData(classId) {
  return DatabaseService.getClassFullExportData(classId);
}

// Utilitas & Integrasi
function apiValidateDriveFolder(folderId) {
  return DriveService.validateFolder(folderId);
}

function apiValidateTaskFile(ref) {
  return DriveService.validateTaskFile(ref);
}

function apiExtractRubricFromTask(taskQuestionRef, model) {
  const settings = getAppSettings();
  const apiKey = settings.rawApiKey;
  if (!apiKey) {
    throw new Error('Gemini API Key belum dimasukkan di menu Pengaturan.');
  }

  if (!taskQuestionRef || !taskQuestionRef.trim()) {
    throw new Error('Tautan berkas soal tugas belum diisi.');
  }

  const taskPayload = DriveService.getTaskQuestionPayload(taskQuestionRef);
  if (!taskPayload) {
    throw new Error('Berkas soal tugas tidak dapat diakses dari Google Drive.');
  }

  const selectedModel = model || settings.modelName;
  return GeminiService.extractRubricFromTask(taskPayload, apiKey, selectedModel);
}

function apiGetSettings() {
  return getAppSettings();
}

function apiSaveSettings(data) {
  return saveAppSettings(data);
}

function apiTestConnection(apiKey, model) {
  let keyToUse = apiKey ? apiKey.trim() : '';
  if (!keyToUse || keyToUse.includes('****')) {
    keyToUse = getAppSettings().rawApiKey;
  }
  return GeminiService.testConnection(keyToUse, model);
}
