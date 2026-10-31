/**
 * @fileoverview Konfigurasi dan Manajemen Properti Sistem Penilaian Mahasiswa Multimodal
 */

const CONFIG_KEYS = {
  GEMINI_API_KEY: 'GEMINI_API_KEY',
  MODEL_NAME: 'MODEL_NAME',
  DRIVE_FOLDER_ID: 'DRIVE_FOLDER_ID',
  RUBRIC_TEXT: 'RUBRIC_TEXT',
  MAX_EXECUTION_TIME_SEC: 'MAX_EXECUTION_TIME_SEC'
};

const DEFAULT_RUBRIC = `## RUBRIK PENILAIAN TUGAS MULTIMODAL

Silakan evaluasi berkas jawaban mahasiswa secara objektif dan mendalam berdasarkan kriteria berikut:

1. Kelengkapan & Struktur Dokumen (Bobot: 20%, Maksimal 20 poin)
   - Sistematika penulisan rapi, memuat pendahuluan, pembahasan, dan kesimpulan.
   - Format sesuai instruksi berkas (header, penomoran, kelengkapan lampiran).

2. Ketepatan Konsep & Analisis Masalah (Bobot: 40%, Maksimal 40 poin)
   - Pemahaman materi kuat, argumen logis dan didukung data/literatur yang relevan.
   - Solusi atau penjelasan teknis tepat sasaran dan akurat.

3. Orisinalitas & Kreativitas Penyelesaian (Bobot: 25%, Maksimal 25 poin)
   - Gagasan orisinal, menunjukkan eksplorasi mandiri, tidak sekadar salin-tempel.
   - Pendekatan atau penyajian visual (diagram, grafik, flowchart) informatif dan menarik.

4. Kerapian & Bahasa (Bobot: 15%, Maksimal 15 poin)
   - Tata bahasa baku, ejaan benar, istilah teknis digunakan secara konsisten.
   - Keterbacaan visual (tata letak diagram/gambar/tabel) jelas dan bersih.

Skala Total: 0 - 100.
Berikan masukan konstruktif yang spesifik menunjuk bagian mana yang sudah baik dan bagian mana yang perlu diperbaiki.`;

const DEFAULT_SETTINGS = {
  modelName: 'gemini-2.5-flash',
  driveFolderId: '',
  rubricText: DEFAULT_RUBRIC,
  maxExecutionTimeSec: 300 // 5 menit checkpoint (batas GAS 6 menit)
};

/**
 * Mengambil seluruh pengaturan gabungan (User Properties + Document Properties + Defaults)
 * @return {Object} Pengaturan aktif
 */
function getAppSettings() {
  const userProps = PropertiesService.getUserProperties();
  const docProps = PropertiesService.getDocumentProperties();

  const apiKey = userProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) || 
                 docProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) || '';

  const modelName = docProps.getProperty(CONFIG_KEYS.MODEL_NAME) || 
                    userProps.getProperty(CONFIG_KEYS.MODEL_NAME) || 
                    DEFAULT_SETTINGS.modelName;

  const driveFolderId = docProps.getProperty(CONFIG_KEYS.DRIVE_FOLDER_ID) || 
                        userProps.getProperty(CONFIG_KEYS.DRIVE_FOLDER_ID) || '';

  const rubricText = docProps.getProperty(CONFIG_KEYS.RUBRIC_TEXT) || 
                     DEFAULT_SETTINGS.rubricText;

  return {
    apiKey: apiKey ? maskApiKey(apiKey) : '',
    hasApiKey: Boolean(apiKey),
    rawApiKey: apiKey,
    modelName: modelName,
    driveFolderId: driveFolderId,
    rubricText: rubricText,
    maxExecutionTimeSec: DEFAULT_SETTINGS.maxExecutionTimeSec
  };
}

/**
 * Menyimpan pengaturan dari antarmuka pengguna
 * @param {Object} data - Objek pengaturan baru
 * @return {Object} Status hasil penyimpanan
 */
function saveAppSettings(data) {
  try {
    const userProps = PropertiesService.getUserProperties();
    const docProps = PropertiesService.getDocumentProperties();

    if (data.apiKey && !data.apiKey.includes('****')) {
      userProps.setProperty(CONFIG_KEYS.GEMINI_API_KEY, data.apiKey.trim());
    }

    if (data.modelName) {
      docProps.setProperty(CONFIG_KEYS.MODEL_NAME, data.modelName.trim());
    }

    if (data.driveFolderId !== undefined) {
      // Ekstrak folder ID murni jika pengguna memasukkan URL lengkap Google Drive
      const cleanFolderId = extractDriveFolderId(data.driveFolderId);
      docProps.setProperty(CONFIG_KEYS.DRIVE_FOLDER_ID, cleanFolderId);
    }

    if (data.rubricText !== undefined) {
      docProps.setProperty(CONFIG_KEYS.RUBRIC_TEXT, data.rubricText);
    }

    return { success: true, message: 'Pengaturan berhasil disimpan!' };
  } catch (error) {
    return { success: false, message: 'Gagal menyimpan pengaturan: ' + error.message };
  }
}

/**
 * Sensor API Key untuk keamanan tampilan
 * @param {string} key
 * @return {string}
 */
function maskApiKey(key) {
  if (!key || key.length < 8) return '********';
  return key.substring(0, 4) + '****************' + key.substring(key.length - 4);
}

/**
 * Ekstraksi folder ID dari URL Google Drive atau ID murni
 * @param {string} input 
 * @return {string} Folder ID bersih
 */
function extractDriveFolderId(input) {
  if (!input) return '';
  const trimmed = input.trim();
  // Pola regex mencakup format URL Drive: /folders/ID atau id=ID
  const folderUrlMatch = trimmed.match(/folders\/([a-zA-Z0-9_-]+)/);
  if (folderUrlMatch && folderUrlMatch[1]) {
    return folderUrlMatch[1];
  }
  const idParamMatch = trimmed.match(/id=([a-zA-Z0-9_-]+)/);
  if (idParamMatch && idParamMatch[1]) {
    return idParamMatch[1];
  }
  return trimmed;
}

/**
 * Menghapus API Key pengguna dari User Properties
 */
function clearApiKey() {
  PropertiesService.getUserProperties().deleteProperty(CONFIG_KEYS.GEMINI_API_KEY);
  return { success: true, message: 'API Key berhasil dihapus.' };
}
