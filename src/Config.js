/**
 * @fileoverview Konfigurasi dan Manajemen Properti Sistem Penilaian Mahasiswa Multimodal
 */

const CONFIG_KEYS = {
  GEMINI_API_KEY: 'GEMINI_API_KEY',
  MODEL_NAME: 'MODEL_NAME',
  DRIVE_FOLDER_ID: 'DRIVE_FOLDER_ID',
  TASK_QUESTION_REF: 'TASK_QUESTION_REF', // ID/URL file soal di Drive atau teks soal langsung
  RUBRIC_TEXT: 'RUBRIC_TEXT',
  MAX_EXECUTION_TIME_SEC: 'MAX_EXECUTION_TIME_SEC'
};

const DEFAULT_RUBRIC = `## PANDUAN PORSI NILAI & RUBRIK TUGAS

Total Porsi Nilai: 100 Poin

1. Pemahaman & Ketepatan Jawaban Soal 1 (Porsi: 35 Poin)
   - Ketepatan konsep, logika pemikiran, dan kesesuaian dengan instruksi soal.
   - Poin maksimal: 35.

2. Analisis & Pembahasan Mendalam Soal 2 (Porsi: 40 Poin)
   - Kelengkapan langkah penyelesaian, keakuratan data/metode, dan kedalaman argumen.
   - Poin maksimal: 40.

3. Orisinalitas & Sintesis Kesimpulan (Porsi: 15 Poin)
   - Pendapat kritis mandiri, sintesis hasil, dan tidak sekadar salin-tempel.
   - Poin maksimal: 15.

4. Kerapian, Tata Bahasa, & Sistematika (Porsi: 10 Poin)
   - Format dokumen rapi, penulisan baku, tabel/diagram jelas dan terbaca.
   - Poin maksimal: 10.

*Anda bebas mengubah nomor soal dan porsi poin di atas sesuai rincian tugas Anda.*`;

const DEFAULT_SETTINGS = {
  modelName: 'gemini-3.6-flash',
  driveFolderId: '',
  taskQuestionRef: '',
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

  const taskQuestionRef = docProps.getProperty(CONFIG_KEYS.TASK_QUESTION_REF) || 
                          DEFAULT_SETTINGS.taskQuestionRef;

  const rubricText = docProps.getProperty(CONFIG_KEYS.RUBRIC_TEXT) || 
                     DEFAULT_SETTINGS.rubricText;

  return {
    apiKey: apiKey ? maskApiKey(apiKey) : '',
    hasApiKey: Boolean(apiKey),
    rawApiKey: apiKey,
    modelName: modelName,
    driveFolderId: driveFolderId,
    taskQuestionRef: taskQuestionRef,
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
      const cleanFolderId = extractDriveFolderId(data.driveFolderId);
      docProps.setProperty(CONFIG_KEYS.DRIVE_FOLDER_ID, cleanFolderId);
    }

    if (data.taskQuestionRef !== undefined) {
      docProps.setProperty(CONFIG_KEYS.TASK_QUESTION_REF, data.taskQuestionRef.trim());
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
