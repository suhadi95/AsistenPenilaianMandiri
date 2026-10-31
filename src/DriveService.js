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
      const folderId = extractDriveFolderId(folderIdOrUrl);
      if (!folderId) {
        return { success: false, message: 'ID atau URL Folder Google Drive belum diisi.' };
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
        message: 'Tidak dapat mengakses folder Google Drive. Pastikan ID folder valid dan izin akses telah diberikan: ' + error.message
      };
    }
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
        const folderId = extractDriveFolderId(folderIdOrUrl);
        if (!folderId) {
          return {
            success: false,
            message: 'Folder Google Drive belum dikonfigurasi di Pengaturan.'
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

      // Prioritas 2: Cocok persis dengan NIM
      if (cleanNim && fileNameLower.includes(cleanNim)) {
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

    // Batasan ukuran aman untuk Base64 inlineData di GAS (maksimum ~20MB)
    const MAX_SAFE_SIZE = 20 * 1024 * 1024;
    if (fileSize > MAX_SAFE_SIZE) {
      return {
        success: false,
        message: `Ukuran berkas (${(fileSize / (1024 * 1024)).toFixed(1)} MB) melebihi batas aman inline multimodal (20 MB).`
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
      // Untuk DOCX, coba convert ke PDF via Drive temporary conversion jika didukung,
      // atau gunakan MIME bawaan
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
    const match = input.match(/\/d\/([a-zA-Z0-9_-]+)/) || input.match(/id=([a-zA-Z0-9_-]+)/);
    return match ? match[1] : (input.length > 20 && !input.includes('/') ? input : null);
  }
};
