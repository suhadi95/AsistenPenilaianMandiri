/**
 * @fileoverview Konfigurasi Global & Manajemen Properti Sistem Portal Penilaian Akademik
 */

const DB_SHEETS = {
  CLASSES: '_DB_KELAS',
  STUDENTS: '_DB_MAHASISWA',
  TASKS: '_DB_TUGAS',
  GRADES: '_DB_NILAI'
};

const CONFIG_KEYS = {
  GEMINI_API_KEY: 'GEMINI_API_KEY',
  MODEL_NAME: 'MODEL_NAME',
  ACTIVE_CLASS_ID: 'ACTIVE_CLASS_ID'
};

const DEFAULT_SETTINGS = {
  modelName: 'gemini-3.5-flash-lite',
  maxExecutionTimeSec: 300 // 5 menit checkpoint
};

/**
 * Mengambil pengaturan aplikasi (API Key, Model, dsb)
 * @return {Object}
 */
function getAppSettings() {
  const userProps = PropertiesService.getUserProperties();
  const docProps = PropertiesService.getDocumentProperties();

  const apiKey = userProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) || 
                 docProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) || '';

  const modelName = docProps.getProperty(CONFIG_KEYS.MODEL_NAME) || 
                    userProps.getProperty(CONFIG_KEYS.MODEL_NAME) || 
                    DEFAULT_SETTINGS.modelName;

  const activeClassId = docProps.getProperty(CONFIG_KEYS.ACTIVE_CLASS_ID) || '';

  return {
    apiKey: apiKey ? maskApiKey(apiKey) : '',
    hasApiKey: Boolean(apiKey),
    rawApiKey: apiKey,
    modelName: modelName,
    activeClassId: activeClassId,
    maxExecutionTimeSec: DEFAULT_SETTINGS.maxExecutionTimeSec
  };
}

/**
 * Menyimpan pengaturan aplikasi
 * @param {Object} data 
 * @return {Object}
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

    if (data.activeClassId !== undefined) {
      docProps.setProperty(CONFIG_KEYS.ACTIVE_CLASS_ID, data.activeClassId);
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
 * Menghasilkan Unique ID sederhana
 * @param {string} prefix 
 * @return {string}
 */
function generateId(prefix = 'ID') {
  const rand = Math.random().toString(36).substring(2, 8).toUpperCase();
  const time = Date.now().toString(36).toUpperCase();
  return `${prefix}-${time}-${rand}`;
}
