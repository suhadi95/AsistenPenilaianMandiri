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
    .addSeparator()
    .addItem('⚙️ Pengaturan & API Key', 'openSidebar')
    .addItem('ℹ️ Panduan Sistem', 'openHelpDialog')
    .addToUi();
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
          Gunakan portal web standalone untuk mengelola kelas, mahasiswa, tugas, koreksi multimodal, dan rekapitulasi nilai.
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
Panduan Sistem Manajemen Kelas & Penilaian Akademik:

1. Portal Web Mandiri (Standalone):
   • Kelola seluruh kelas, daftar mahasiswa, penugasan, dan penilaian melalui antarmuka web.
   • Buka menu "🌐 Buka Portal Web Penilaian" untuk mengakses dashboard penuh.

2. Struktur Penyimpanan:
   • Seluruh data tersimpan rapi di sheet database: _DB_KELAS, _DB_MAHASISWA, _DB_TUGAS, dan _DB_NILAI.
   • Nilai akhir dapat diekspor langsung ke tab sheet rekapitulasi kapan saja.

3. Penilaian Multimodal:
   • Sistem membandingkan berkas jawaban mahasiswa di Drive terhadap berkas soal dan rubrik porsi poin.
   • Mendukung penilaian semua mahasiswa (batch) maupun mahasiswa terpilih.
`;
  SpreadsheetApp.getUi().alert('ℹ️ Panduan Sistem', message, SpreadsheetApp.getUi().ButtonSet.OK);
}

// ==========================================
// API RPC JEMBATAN UNTUK WEB APP (google.script.run)
// ==========================================

function apiGetInitialData() {
  DatabaseService.initDatabase();
  const settings = getAppSettings();
  const classes = DatabaseService.getClasses();
  const webAppUrl = ScriptApp.getService().getUrl() || '';

  return {
    settings: settings,
    classes: classes,
    webAppUrl: webAppUrl
  };
}

// Kelas
function apiGetClasses() {
  return DatabaseService.getClasses();
}

function apiSaveClass(data) {
  return DatabaseService.saveClass(data);
}

function apiDeleteClass(classId) {
  return DatabaseService.deleteClass(classId);
}

// Mahasiswa
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

// Tugas
function apiGetTasks(classId) {
  return DatabaseService.getTasksByClass(classId);
}

function apiGetTaskById(taskId) {
  return DatabaseService.getTaskById(taskId);
}

function apiSaveTask(data) {
  return DatabaseService.saveTask(data);
}

function apiDeleteTask(taskId) {
  return DatabaseService.deleteTask(taskId);
}

// Penilaian & Koreksi
function apiGetGradesByTask(taskId) {
  return DatabaseService.getGradesByTask(taskId);
}

function apiGradeSelectedStudents(taskId, studentNims) {
  return GradingService.gradeSelectedStudents(taskId, studentNims);
}

function apiGradeAllStudents(taskId, onlyUnassessed) {
  return GradingService.gradeAllStudents(taskId, onlyUnassessed);
}

function apiResetFailedGrades(taskId) {
  return GradingService.resetFailedGrades(taskId);
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
