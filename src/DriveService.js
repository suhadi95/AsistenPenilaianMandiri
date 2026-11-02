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
  getStudentSubmission(folderIdOrUrl, nim, nama, directLinkOrName, options = {}) {
    try {
      let targetFile = null;
      let matchedBy = 'filename';

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
      let folder = null;
      if (!targetFile) {
        const folderId = this.extractFolderId(folderIdOrUrl);
        if (!folderId) {
          return {
            success: false,
            message: 'Folder Google Drive belum dikonfigurasi pada tugas ini.'
          };
        }

        folder = DriveApp.getFolderById(folderId);
        targetFile = this.searchFileInFolder(folder, nim, nama, directLinkOrName);
      }

      // 3. Pencarian Cadangan Cerdas: Berdasarkan Isi Dokumen (Content-Based Matching)
      if (!targetFile && folder && options.checkFileContent !== false) {
        targetFile = this.searchFileByContent(
          folder,
          nim,
          nama,
          options.allStudentNims || [],
          options.apiKey || '',
          options.modelName || '',
          options.cache || null
        );
        if (targetFile) {
          matchedBy = 'content';
        }
      }

      if (!targetFile) {
        return {
          success: false,
          message: `Berkas tugas tidak ditemukan untuk NIM: "${nim}" (${nama || 'Tanpa Nama'}).`
        };
      }

      // 4. Proses berkas menjadi payload multimodal
      const processed = this.processFileForGemini(targetFile);
      if (processed && processed.success) {
        processed.matchedBy = matchedBy;
      }
      return processed;

    } catch (error) {
      return {
        success: false,
        message: 'Kesalahan saat membaca berkas tugas dari Drive: ' + error.message
      };
    }
  },

    /**
   * Mengambil seluruh berkas dari folder utama dan subfolder di dalamnya
   * @param {Folder} folder
   * @return {Array<File>}
   */
  getAllFilesInFolder(folder) {
    const allFiles = [];
    try {
      const mainFiles = folder.getFiles();
      while (mainFiles.hasNext()) {
        allFiles.push(mainFiles.next());
      }
    } catch (e) {}

    try {
      const subfolders = folder.getFolders();
      while (subfolders.hasNext()) {
        const sub = subfolders.next();
        const subFiles = sub.getFiles();
        while (subFiles.hasNext()) {
          allFiles.push(subFiles.next());
        }
        // Periksa 1 kedalaman subfolder lagi jika ada
        const subSubfolders = sub.getFolders();
        while (subSubfolders.hasNext()) {
          const subSub = subSubfolders.next();
          const subSubFiles = subSub.getFiles();
          while (subSubFiles.hasNext()) {
            allFiles.push(subSubFiles.next());
          }
        }
      }
    } catch (e) {
      console.warn('Gagal membaca subfolder: ' + e.message);
    }

    return allFiles;
  },

  /**
   * Mencari berkas di dalam folder berdasarkan prioritas: NIM > Nama > Filename
   */
  searchFileInFolder(folder, nim, nama, customFileName) {
    const cleanNim = (nim || '').toString().trim().toLowerCase();
    const cleanNama = (nama || '').toString().trim().toLowerCase();
    const cleanCustom = (customFileName || '').toString().trim().toLowerCase();
    const sanitizedNim = cleanNim.replace(/[^0-9a-z]/g, '');

    const allFiles = this.getAllFilesInFolder(folder);
    let candidateByName = null;

    for (const file of allFiles) {
      const fileNameLower = file.getName().toLowerCase();
      const sanitizedFileName = fileNameLower.replace(/[^0-9a-z]/g, '');

      // Prioritas 1: Cocok dengan nama file spesifik jika diinput
      if (cleanCustom && (fileNameLower === cleanCustom || fileNameLower.includes(cleanCustom))) {
        return file;
      }

      // Prioritas 2: Cocok dengan NIM (menghilangkan tanda titik/strip)
      if (cleanNim && (fileNameLower.includes(cleanNim) || (sanitizedNim.length >= 4 && sanitizedFileName.includes(sanitizedNim)))) {
        return file;
      }

      // Simpan kandidat cadangan jika nama mahasiswa cocok
      if (!candidateByName && cleanNama && cleanNama.length > 3 && fileNameLower.includes(cleanNama)) {
        candidateByName = file;
      }
    }

    return candidateByName;
  },

  /**
   * Ekstraksi teks dari berkas Word (.docx) melalui pembongkaran XML arsip zip
   * @param {Blob} blob
   * @return {string}
   */
    extractTextFromDocx(blob) {
    try {
      const unzipped = Utilities.unzip(blob);
      let fullText = '';
      for (const item of unzipped) {
        const itemName = item.getName();
        if (itemName.startsWith('word/') && (itemName.includes('document') || itemName.includes('header') || itemName.includes('footer'))) {
          const xml = item.getDataAsString('UTF-8');
          fullText += ' ' + xml.replace(/<[^>]+>/g, ' ');
        }
      }
      return fullText.replace(/\s+/g, ' ').trim();
    } catch (e) {
      console.warn('Gagal membaca struktur .docx XML: ' + e.message);
    }
    return '';
  },

  /**
   * Ekstraksi teks cepat dari berkas Google Workspace, DOCX, dan file teks murni
   * @param {File} file
   * @return {string}
   */
  extractTextFromFile(file) {
    try {
      const mime = file.getMimeType();
      const name = file.getName().toLowerCase();

      // Google Docs (termasuk header dan footer)
      if (mime === MimeType.GOOGLE_DOCS) {
        const doc = DocumentApp.openById(file.getId());
        let txt = doc.getBody().getText();
        const hdr = doc.getHeader();
        if (hdr) txt += ' ' + hdr.getText();
        const ftr = doc.getFooter();
        if (ftr) txt += ' ' + ftr.getText();
        return txt;
      }

      // Google Sheets
      if (mime === MimeType.GOOGLE_SHEETS) {
        const ss = SpreadsheetApp.openById(file.getId());
        const sheets = ss.getSheets();
        let txt = '';
        for (const s of sheets) {
          const lastRow = Math.min(s.getLastRow(), 30);
          const lastCol = Math.min(s.getLastColumn(), 15);
          if (lastRow > 0 && lastCol > 0) {
            txt += s.getRange(1, 1, lastRow, lastCol).getValues().map(r => r.join(' ')).join('\n') + ' ';
          }
        }
        return txt;
      }

      // Google Slides
      if (mime === MimeType.GOOGLE_SLIDES) {
        const slides = SlidesApp.openById(file.getId()).getSlides();
        let txt = '';
        for (const slide of slides.slice(0, 5)) {
          for (const shape of slide.getShapes()) {
            txt += shape.getText().asString() + ' ';
          }
        }
        return txt;
      }

      // Word .docx
      if (mime.includes('wordprocessingml') || name.endsWith('.docx')) {
        const docxText = this.extractTextFromDocx(file.getBlob());
        if (docxText) return docxText;
      }

      // File Teks, Kode Sumber, HTML, Markdown
      if (mime.startsWith('text/') || name.endsWith('.py') || name.endsWith('.js') || name.endsWith('.html') || name.endsWith('.txt') || name.endsWith('.md') || name.endsWith('.csv') || name.endsWith('.json')) {
        return file.getBlob().getDataAsString('UTF-8');
      }

      // PDF - coba ambil string teks mentah jika uncompressed
      if (mime === MimeType.PDF || name.endsWith('.pdf')) {
        try {
          const raw = file.getBlob().getDataAsString('UTF-8');
          // Ambil cuplikan teks awal dokumen
          return raw.slice(0, 50000);
        } catch (e) {}
      }
    } catch (err) {
      console.warn('Gagal membaca isi berkas ' + file.getName() + ': ' + err.message);
    }
    return '';
  },

  /**
   * Mencari berkas berdasarkan isi dokumen (Content-Based Matching)
   * Menyaring berkas tanpa nama yang memuat NIM atau Nama mahasiswa di halaman awal/cover
   * @param {Folder} folder
   * @param {string} nim
   * @param {string} nama
   * @param {Array<string>} allStudentNims - Daftar seluruh NIM untuk mencegah salah sasaran
   * @param {string} apiKey
   * @param {string} modelName
   * @param {Object} cache
   * @return {File|null}
   */
    searchFileByContent(folder, nim, nama, allStudentNims = [], apiKey = '', modelName = '', cache = null) {
    const cleanNim = (nim || '').toString().trim().toLowerCase();
    const sanitizedNim = cleanNim.replace(/[^0-9a-z]/g, '');
    const cleanNama = (nama || '').toString().trim().toLowerCase();

    // Himpunan NIM mahasiswa lain untuk memeriksa kepemilikan nama file (hanya jika panjang >= 5)
    const otherNimsSanitized = allStudentNims
      .map(n => String(n).trim().toLowerCase().replace(/[^0-9a-z]/g, ''))
      .filter(n => n.length >= 5 && n !== sanitizedNim);

    const allFiles = this.getAllFilesInFolder(folder);
    const candidateFiles = [];

    // Filter berkas di folder: abaikan berkas yang nama filenya sudah jelas memuat NIM mahasiswa lain
    for (const file of allFiles) {
      const fileNameLower = file.getName().toLowerCase();
      const sanitizedFileName = fileNameLower.replace(/[^0-9a-z]/g, '');

      const isOtherStudentFile = otherNimsSanitized.some(otherNim => sanitizedFileName.includes(otherNim));
      if (!isOtherStudentFile) {
        candidateFiles.push(file);
      }
    }

    if (candidateFiles.length === 0) return null;

    // TIER 1: Pindai teks dokumen secara langsung (Cepat, Lokal & Bebas Kuota)
    const geminiCandidates = [];

    for (const file of candidateFiles) {
      const extractedText = this.extractTextFromFile(file);
      if (extractedText && extractedText.length > 5) {
        const textLower = extractedText.toLowerCase();
        const sanitizedText = textLower.replace(/[^0-9a-z]/g, '');

        const nimMatched = cleanNim && (textLower.includes(cleanNim) || (sanitizedNim.length >= 4 && sanitizedText.includes(sanitizedNim)));
        const namaMatched = cleanNama && cleanNama.length >= 4 && textLower.includes(cleanNama);

        if (nimMatched || namaMatched) {
          return file;
        }
      }

      // PENTING: Jika belum cocok di Tier 1, SELALU masukkan berkas PDF, Word, atau Gambar ke geminiCandidates!
      const mime = file.getMimeType();
      const name = file.getName().toLowerCase();
      if (mime === MimeType.PDF || name.endsWith('.pdf') ||
          mime.includes('word') || name.endsWith('.docx') || name.endsWith('.doc') ||
          mime.startsWith('image/')) {
        geminiCandidates.push(file);
      }
    }

    // TIER 2: Pindai dokumen biner/PDF menggunakan AI Vision (Gemini 3.5 Flash Lite)
    if (geminiCandidates.length > 0 && apiKey && typeof GeminiService !== 'undefined') {
      for (const file of geminiCandidates) {
        const fileId = file.getId();
        let identified = cache ? cache[fileId] : null;

        if (!identified) {
          const payload = this.processFileForGemini(file);
          if (payload && payload.success) {
            // Cek langsung kecocokan dokumen dengan target mahasiswa
            if (typeof GeminiService.checkDocumentBelongsToStudent === 'function') {
              identified = GeminiService.checkDocumentBelongsToStudent(payload, cleanNim, cleanNama, apiKey, modelName);
            } else {
              identified = GeminiService.identifyStudentFromDocument(payload, apiKey, modelName);
            }
            if (cache) cache[fileId] = identified;
          }
        }

        if (identified) {
          const isDirectMatch = Boolean(identified.isMatch);
          const idNimClean = (identified.detectedNim || identified.nim || '').toString().trim().toLowerCase().replace(/[^0-9a-z]/g, '');
          const idNamaClean = (identified.detectedNama || identified.nama || '').toString().trim().toLowerCase();

          const nimMatched = sanitizedNim && idNimClean && (idNimClean === sanitizedNim || idNimClean.includes(sanitizedNim) || sanitizedNim.includes(idNimClean));
          const namaMatched = cleanNama && idNamaClean && cleanNama.length >= 4 && (idNamaClean.includes(cleanNama) || cleanNama.includes(idNamaClean));

          if (isDirectMatch || nimMatched || namaMatched) {
            return file;
          }
        }
      }
    }

    return null;
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
