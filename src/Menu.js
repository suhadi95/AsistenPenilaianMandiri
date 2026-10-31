/**
 * @fileoverview Menu Kustom, Dialog, dan Titik Masuk (Entry Point) Google Apps Script
 */

/**
 * Pemicu otomatis saat spreadsheet dibuka
 */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('🎓 Asisten Penilaian')
    .addItem('⚙️ Pengaturan & Rubrik', 'openSidebar')
    .addSeparator()
    .addItem('📋 Siapkan Template Sheet', 'menuSetupTemplate')
    .addItem('▶️ Mulai / Lanjutkan Penilaian', 'menuRunGrading')
    .addItem('🔄 Reset Status Gagal / Tertunda', 'menuResetFailed')
    .addSeparator()
    .addItem('ℹ️ Panduan Penggunaan', 'openHelpDialog')
    .addToUi();
}

/**
 * Membuka Sidebar Pengaturan Modern
 */
function openSidebar() {
  const htmlOutput = HtmlService.createHtmlOutputFromFile('Sidebar')
    .setTitle('🎓 Pengaturan Asisten Penilaian')
    .setWidth(420);
  SpreadsheetApp.getUi().showSidebar(htmlOutput);
}

/**
 * Handler Menu: Siapkan Template
 */
function menuSetupTemplate() {
  const result = GradingEngine.setupSheetTemplate();
  SpreadsheetApp.getActiveSpreadsheet().toast(result.message, 'Berhasil', 4);
}

/**
 * Handler Menu: Jalankan / Lanjutkan Penilaian
 */
function menuRunGrading() {
  const ui = SpreadsheetApp.getUi();
  const settings = getAppSettings();

  if (!settings.hasApiKey) {
    ui.alert('Perhatian', 'Gemini API Key belum dimasukkan. Buka menu "⚙️ Pengaturan & Rubrik" terlebih dahulu.', ui.ButtonSet.OK);
    openSidebar();
    return;
  }

  if (!settings.driveFolderId) {
    ui.alert('Perhatian', 'Folder Google Drive belum diatur. Buka menu "⚙️ Pengaturan & Rubrik" untuk memasukkan ID folder.', ui.ButtonSet.OK);
    openSidebar();
    return;
  }

  SpreadsheetApp.getActiveSpreadsheet().toast('Memulai proses penilaian otomatis...', 'Asisten Penilaian', 5);

  try {
    const result = GradingEngine.runBatchGrading(true);
    ui.alert('Proses Selesai', result.message, ui.ButtonSet.OK);
  } catch (error) {
    ui.alert('Terjadi Kesalahan', error.message, ui.ButtonSet.OK);
  }
}

/**
 * Handler Menu: Reset Baris Gagal
 */
function menuResetFailed() {
  const result = GradingEngine.resetFailedRows();
  SpreadsheetApp.getUi().alert('Reset Selesai', result.message, SpreadsheetApp.getUi().ButtonSet.OK);
}

/**
 * Handler Menu: Dialog Bantuan
 */
function openHelpDialog() {
  const message = `
Panduan Cepat Asisten Penilaian Mahasiswa Multimodal:

1. Pengaturan Awal:
   • Masukkan Gemini API Key di menu "⚙️ Pengaturan & Rubrik".
   • Pilih model Gemini (rekomendasi: gemini-2.5-flash).
   • Masukkan Link atau ID Folder Google Drive tempat jawaban mahasiswa disimpan.
   • Sesuaikan rubrik dan bobot penilaian sesuai silabus Anda.

2. Struktur Berkas Tugas di Google Drive:
   • Berkas tugas mahasiswa (PDF, Gambar, Docs, Teks) sebaiknya memuat NIM pada nama berkasnya (misal: "Tugas1_2024001.pdf").
   • Asisten otomatis mencocokkan baris sheet dengan berkas berdasarkan NIM atau Nama.

3. Limitasi Waktu 6 Menit GAS:
   • Google Apps Script membatasi eksekusi maksimal 6 menit per sesi.
   • Sistem dilengkapi checkpoint cerdas. Jika waktu hampir habis, sistem akan berhenti aman dan mencatat progres.
   • Anda cukup klik "▶️ Mulai / Lanjutkan Penilaian" kembali untuk melanjutkan baris yang tersisa.
`;
  SpreadsheetApp.getUi().alert('ℹ️ Panduan & Info', message, SpreadsheetApp.getUi().ButtonSet.OK);
}

// ==========================================
// API Jembatan untuk HTML Service (google.script.run)
// ==========================================

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

function apiValidateDriveFolder(folderId) {
  return DriveService.validateFolder(folderId);
}

function apiValidateTaskFile(ref) {
  return DriveService.validateTaskFile(ref);
}

function apiRunGrading(onlyUnassessed) {
  return GradingEngine.runBatchGrading(onlyUnassessed);
}

function apiResetFailed() {
  return GradingEngine.resetFailedRows();
}

function apiSetupTemplate() {
  return GradingEngine.setupSheetTemplate();
}
