/**
 * @fileoverview Layanan Pengelolaan Berkas Google Drive untuk Penilaian Multimodal
 */

const DriveService = {
  /**
   * Memvalidasi folder Google Drive dan menghitung berkas di dalamnya
   * @param {string} folderIdOrUrl 
   * @return {Object} Status akses dan rincian folder
   */
  validateFolder(folderIdOrUrl) {
    try {
      const folderId = this.extractFolderId(folderIdOrUrl);
      if (!folderId) {
        return { success: false, message: 'ID atau URL Folder Google Drive belum diisi atau format tidak valid.' };
      }

      const folder = DriveApp.getFolderById(folderId);
      const folderName = folder.getName();
      const filesIter = folder.getFiles();
      let fileCount = 0;

      while (filesIter.hasNext() && fileCount < 100) {
        filesIter.next();
        fileCount++;
      }

      const countText = fileCount >= 100 ? '100+ berkas' : `${fileCount} berkas`;

      return {
        success: true,
        folderId: folderId,
        folderName: folderName,
        message: `Folder "${folderName}" terhubung (${countText}).`
      };
    } catch (error) {
      return {
        success: false,
        message: 'Tidak dapat mengakses folder Google Drive: ' + error.message
      };
    }
  },

  /**
   * Memvalidasi dan membaca berkas Soal Tugas / Kunci Jawaban di Google Drive
   * @param {string} ref - URL atau ID berkas di Drive atau teks soal
   * @return {Object}
   */
  validateTaskFile(ref) {
    if (!ref || !ref.trim()) {
      return { success: false, message: 'Tautan berkas atau teks soal tugas belum diisi.' };
    }

    const trimmed = ref.trim();
    const fileId = this.extractFileId(trimmed);

    if (fileId) {
      try {
        const file = DriveApp.getFileById(fileId);
        return {
          success: true,
          fileName: file.getName(),
          sizeBytes: file.getSize(),
          message: `Berkas Soal ditemukan: "${file.getName()}" (${(file.getSize() / 1024).toFixed(1)} KB)`
        };
      } catch (err) {
        return {
          success: false,
          message: 'Gagal mengakses berkas soal di Drive: ' + err.message
        };
      }
    }

    // Jika bukan ID/URL Drive, diperlakukan sebagai teks deskripsi soal langsung
    return {
      success: true,
      fileName: 'Teks Soal Langsung',
      message: `Teks Soal langsung terdeteksi (${trimmed.length} karakter).`
    };
  },

  /**
   * Mengambil konten multimodal Soal Tugas / Kunci Jawaban
   * @param {string} ref 
   * @return {Object|null}
   */
  getTaskQuestionPayload(ref) {
    if (!ref || !ref.trim()) return null;
    const trimmed = ref.trim();
    const fileId = this.extractFileId(trimmed);

    if (fileId) {
      try {
        const file = DriveApp.getFileById(fileId);
        return this.processFileForGemini(file);
      } catch (err) {
        // Jika gagal membaca sebagai berkas, gunakan sebagai teks
      }
    }

    return {
      success: true,
      fileName: 'Instruksi Soal Tugas',
      isTextOnly: true,
      textContent: trimmed,
      base64Data: null,
      mimeType: 'text/plain'
    };
  },

  /**
   * Mencari berkas tugas mahasiswa berdasarkan NIM, Nama, atau Tautan langsung
   * @param {string} folderIdOrUrl - Folder target
   * @param {string} nim - NIM Mahasiswa
   * @param {string} nama - Nama Mahasiswa
   * @param {string} directLinkOrName - Opsional: URL/ID langsung atau nama file spesifik
   * @return {Object} Berkas yang ditemukan beserta konten multimodal
   */
  getStudentSubmission(folderIdOrUrl, nim, nama, directLinkOrName) {
    try {
      let targetFile = null;

      // 1. Cek apakah ada tautan langsung ke berkas Drive
      if (directLinkOrName && typeof directLinkOrName === 'string') {
        const fileId = this.extractFileId(directLinkOrName);
        if (fileId) {
          try {
            targetFile = DriveApp.getFileById(fileId);
          } catch (e) {
            // Lanjut ke pencarian folder jika ID spesifik tidak valid
          }
        }
      }

      // 2. Jika belum ditemukan, lakukan pencarian dalam folder Drive yang ditentukan
      if (!targetFile) {
        const folderId = this.extractFolderId(folderIdOrUrl);
        if (!folderId) {
          return {
            success: false,
            message: 'Folder Google Drive belum dikonfigurasi pada tugas ini.'
          };
        }

        const folder = DriveApp.getFolderById(folderId);
        targetFile = this.searchFileInFolder(folder, nim, nama, directLinkOrName);
      }

      if (!targetFile) {
        return {
          success: false,
          message: `Berkas tugas tidak ditemukan untuk NIM: "${nim}" (${nama || 'Tanpa Nama'}).`
        };
      }

      // 3. Proses berkas menjadi payload multimodal
      return this.processFileForGemini(targetFile);

    } catch (error) {
      return {
        success: false,
        message: 'Kesalahan saat membaca berkas tugas dari Drive: ' + error.message
      };
    }
  },

  /**
   * Mencari berkas di dalam folder berdasarkan prioritas: NIM > Nama > Filename
   */
  searchFileInFolder(folder, nim, nama, customFileName) {
    const cleanNim = (nim || '').toString().trim().toLowerCase();
    const cleanNama = (nama || '').toString().trim().toLowerCase();
    const cleanCustom = (customFileName || '').toString().trim().toLowerCase();

    const files = folder.getFiles();
    let candidateByName = null;

    while (files.hasNext()) {
      const file = files.next();
      const fileNameLower = file.getName().toLowerCase();

      // Prioritas 1: Cocok dengan nama file spesifik jika diinput
      if (cleanCustom && (fileNameLower === cleanCustom || fileNameLower.includes(cleanCustom))) {
        return file;
      }

      // Prioritas 2: Cocok dengan NIM (menghilangkan tanda titik/strip)
      const sanitizedNim = cleanNim.replace(/[^0-9a-z]/g, '');
      const sanitizedFileName = fileNameLower.replace(/[^0-9a-z]/g, '');

      if (cleanNim && (fileNameLower.includes(cleanNim) || (sanitizedNim.length >= 4 && sanitizedFileName.includes(sanitizedNim)))) {
        return file;
      }

      // Simpan kandidat cadangan jika nama mahasiswa cocok
      if (!candidateByName && cleanNama && cleanNama.length > 3 && fileNameLower.includes(cleanNama)) {
        candidateByName = file;
      }
    }

    // Jika pencarian file di folder utama belum dapat, cek apakah mahasiswa punya subfolder
    if (!candidateByName) {
      const subfolders = folder.getFolders();
      while (subfolders.hasNext()) {
        const sub = subfolders.next();
        const subName = sub.getName().toLowerCase();
        if ((cleanNim && subName.includes(cleanNim)) || (cleanNama && subName.includes(cleanNama))) {
          const subFiles = sub.getFiles();
          if (subFiles.hasNext()) {
            return subFiles.next();
          }
        }
      }
    }

    return candidateByName;
  },

  /**
   * Mengonversi berkas Drive menjadi format yang kompatibel dengan Gemini API
   */
  processFileForGemini(file) {
    let mimeType = file.getMimeType();
    let blob = file.getBlob();
    const fileName = file.getName();
    const fileSize = file.getSize();

    // Batasan ukuran aman untuk Base64 inlineData di GAS (maksimum 20MB)
    const MAX_SAFE_SIZE = 20 * 1024 * 1024;
    if (fileSize > MAX_SAFE_SIZE) {
      return {
        success: false,
        message: `Ukuran berkas (${(fileSize / (1024 * 1024)).toFixed(1)} MB) melebihi batas aman (20 MB).`
      };
    }

    // Penanganan khusus berkas Google Workspace (Docs, Slides, Sheets)
    if (mimeType === MimeType.GOOGLE_DOCS) {
      blob = file.getAs(MimeType.PDF);
      mimeType = 'application/pdf';
    } else if (mimeType === MimeType.GOOGLE_SLIDES) {
      blob = file.getAs(MimeType.PDF);
      mimeType = 'application/pdf';
    } else if (mimeType === MimeType.GOOGLE_SHEETS) {
      blob = file.getAs(MimeType.PDF);
      mimeType = 'application/pdf';
    } else if (mimeType.includes('officedocument') || mimeType.includes('msword')) {
      try {
        blob = file.getAs(MimeType.PDF);
        mimeType = 'application/pdf';
      } catch (e) {
        // Biarkan MIME asli jika konversi PDF internal tidak tersedia
      }
    }

    // Berkas teks murni (txt, code, md, csv)
    const isTextFormat = mimeType.startsWith('text/') || 
                         fileName.endsWith('.py') || 
                         fileName.endsWith('.js') || 
                         fileName.endsWith('.html') || 
                         fileName.endsWith('.java') || 
                         fileName.endsWith('.cpp');

    if (isTextFormat) {
      return {
        success: true,
        fileId: file.getId(),
        fileName: fileName,
        fileUrl: file.getUrl(),
        mimeType: 'text/plain',
        isTextOnly: true,
        textContent: blob.getDataAsString('UTF-8'),
        base64Data: null,
        sizeBytes: fileSize
      };
    }

    // Berkas biner multimodal (PDF, Gambar, Audio, Video)
    const bytes = blob.getBytes();
    const base64Data = Utilities.base64Encode(bytes);

    return {
      success: true,
      fileId: file.getId(),
      fileName: fileName,
      fileUrl: file.getUrl(),
      mimeType: mimeType,
      isTextOnly: false,
      textContent: null,
      base64Data: base64Data,
      sizeBytes: fileSize
    };
  },

  /**
   * Ekstrak file ID dari URL berkas Google Drive
   */
  extractFileId(input) {
    if (!input) return null;
    const trimmed = input.trim();
    const match = trimmed.match(/\/file\/d\/([a-zA-Z0-9_-]+)/) || 
                  trimmed.match(/\/d\/([a-zA-Z0-9_-]+)/) || 
                  trimmed.match(/id=([a-zA-Z0-9_-]+)/);
    if (match && match[1]) return match[1];
    if (trimmed.length > 24 && !trimmed.includes('/') && !trimmed.includes(' ')) return trimmed;
    return null;
  },

  /**
   * Ekstrak folder ID dari URL berkas Google Drive atau ID mentah
   */
  extractFolderId(input) {
    return extractDriveFolderId(input);
  }
};

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
