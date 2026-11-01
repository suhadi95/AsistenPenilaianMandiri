/**
 * @fileoverview Konfigurasi Global & Manajemen Properti Sistem Portal Penilaian Akademik
 */

const SPREADSHEET_ID = '1RvpMKOjCDDYG1xODoX_6EVQTtTFeaH1pt7OQ-mq8jWQ';

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
 * Mengambil pengaturan aplikasi (API Key, Model, dsb) secara aman
 * @return {Object}
 */
function getAppSettings() {
  const userProps = PropertiesService.getUserProperties();
  let scriptProps = null;
  try { scriptProps = PropertiesService.getScriptProperties(); } catch (e) {}
  let docProps = null;
  try { docProps = PropertiesService.getDocumentProperties(); } catch (e) {}

  const apiKey = userProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) || 
                 (scriptProps ? scriptProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) : '') ||
                 (docProps ? docProps.getProperty(CONFIG_KEYS.GEMINI_API_KEY) : '') || '';

  const modelName = (scriptProps ? scriptProps.getProperty(CONFIG_KEYS.MODEL_NAME) : '') ||
                    (docProps ? docProps.getProperty(CONFIG_KEYS.MODEL_NAME) : '') ||
                    userProps.getProperty(CONFIG_KEYS.MODEL_NAME) || 
                    DEFAULT_SETTINGS.modelName;

  const activeClassId = (scriptProps ? scriptProps.getProperty(CONFIG_KEYS.ACTIVE_CLASS_ID) : '') ||
                        (docProps ? docProps.getProperty(CONFIG_KEYS.ACTIVE_CLASS_ID) : '') || '';

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
    let scriptProps = null;
    try { scriptProps = PropertiesService.getScriptProperties(); } catch (e) {}
    let docProps = null;
    try { docProps = PropertiesService.getDocumentProperties(); } catch (e) {}

    if (data.apiKey && !data.apiKey.includes('****')) {
      const cleanKey = data.apiKey.trim();
      userProps.setProperty(CONFIG_KEYS.GEMINI_API_KEY, cleanKey);
      if (scriptProps) scriptProps.setProperty(CONFIG_KEYS.GEMINI_API_KEY, cleanKey);
    }

    if (data.modelName) {
      const cleanModel = data.modelName.trim();
      userProps.setProperty(CONFIG_KEYS.MODEL_NAME, cleanModel);
      if (scriptProps) scriptProps.setProperty(CONFIG_KEYS.MODEL_NAME, cleanModel);
      if (docProps) docProps.setProperty(CONFIG_KEYS.MODEL_NAME, cleanModel);
    }

    if (data.activeClassId !== undefined) {
      userProps.setProperty(CONFIG_KEYS.ACTIVE_CLASS_ID, data.activeClassId);
      if (scriptProps) scriptProps.setProperty(CONFIG_KEYS.ACTIVE_CLASS_ID, data.activeClassId);
      if (docProps) docProps.setProperty(CONFIG_KEYS.ACTIVE_CLASS_ID, data.activeClassId);
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

/**
 * Ekstrak ID folder Google Drive dari string URL atau ID mentah
 * @param {string} input - URL folder atau ID folder
 * @return {string} Folder ID bersih
 */
function extractDriveFolderId(input) {
  if (!input) return '';
  const trimmed = String(input).trim();
  
  // 1. Pola standar URL folder Google Drive: /folders/FOLDER_ID
  const folderMatch = trimmed.match(/folders\/([a-zA-Z0-9_-]+)/);
  if (folderMatch && folderMatch[1]) {
    return folderMatch[1];
  }

  // 2. Pola URL open?id=FOLDER_ID atau ?id=FOLDER_ID atau &id=FOLDER_ID
  const idMatch = trimmed.match(/[?&]id=([a-zA-Z0-9_-]+)/);
  if (idMatch && idMatch[1]) {
    return idMatch[1];
  }

  // 3. Pola path URL: ambil segmen terakhir sebelum query params (?) atau fragment (#)
  const clean = trimmed.split('?')[0].split('#')[0].replace(/\/+$/, '');
  if (clean.includes('/')) {
    const parts = clean.split('/');
    const lastPart = parts[parts.length - 1];
    if (lastPart && /^[a-zA-Z0-9_-]+$/.test(lastPart) && lastPart.length >= 10) {
      return lastPart;
    }
  }

  // 4. Input langsung ID folder (string alfanumerik)
  if (/^[a-zA-Z0-9_-]+$/.test(clean)) {
    return clean;
  }

  return clean;
}
