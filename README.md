# 🎓 Asisten Penilaian Mahasiswa Multimodal (Google Apps Script & Gemini API)

Aplikasi otomatisasi penilaian tugas mahasiswa berbasis **Google Apps Script (GAS)** yang terintegrasi secara mulus dengan **Google Sheets**, **Google Drive**, dan **Gemini API (Multimodal)**. Proyek ini dikembangkan secara lokal menggunakan `@google/clasp`.

---

## 🌟 Fitur Utama

1. **Alur Kerja Lokal dengan Clasp (`@google/clasp`)**:
   - Struktur modular rapi di direktori `src/`.
   - Sinkronisasi instan via `npm run push` tanpa perlu copy-paste manual ke editor browser.
   - IntelliSense kode lengkap dengan `@types/google-apps-script`.

2. **Evaluasi Multimodal Cerdas (Gemini API via UrlFetchApp)**:
   - Menggunakan model default **`gemini-3.5-flash-lite`** (cepat, inferensi multimodal akurat, dan batas kuota luas).
   - Mendukung berbagai format tugas di Google Drive: **PDF, Google Docs/Word, Gambar (PNG, JPG, WebP), Audio, Video, dan Source Code**.
   - Mendukung berkas acuan **Soal Tugas / Kunci Jawaban di Google Drive** beserta fitur **Ekstrak Otomatis Porsi Poin & Kriteria dari Soal**.
   - Menghasilkan respon terstruktur (`responseMimeType: "application/json"`) yang mengevaluasi skor per kriteria, total nilai, kelebihan, serta saran perbaikan.

3. **Fleksibilitas Penilaian (Semua Mahasiswa atau Mahasiswa Terpilih Saja)**:
   - **Nilai Baris yang Dipilih**: Cukup sorot/blok baris mahasiswa tertentu di Google Sheets untuk menilai hanya mahasiswa tersebut (sangat praktis untuk susulan, revisi, atau pengujian).
   - **Nilai Semua Baris (Batch)** dengan mekanisme **Checkpoint & Resume Otomatis** (batas 6 menit GAS).

4. **Antarmuka Google Sheets Interaktif**:
   - Custom Menu `"🎓 Asisten Penilaian"` pada toolbar spreadsheet (`onOpen`).
   - Sidebar pengaturan interaktif untuk konfigurasi API Key, pemilihan model Gemini, Berkas Soal, Folder Jawaban Drive, dan Porsi Poin.
   - Pencatatan nilai, rincian kriteria, dan catatan ulasan secara *real-time* dengan `SpreadsheetApp.flush()`.

---

## 📁 Struktur Direktori

```text
AsistenPenilaianGAS/
├── package.json               # Konfigurasi npm & skrip clasp
├── .claspignore               # Berkas yang diabaikan saat push ke GAS
├── .gitignore                 # Berkas kredensial & node_modules
├── jsconfig.json              # IntelliSense VS Code untuk Apps Script
├── README.md                  # Dokumentasi proyek
└── src/                       # Direktori sumber skrip Google Apps Script
    ├── appsscript.json        # Manifest GAS (OAuth Scopes: Drive, Sheets, Fetch)
    ├── Config.js              # Manajemen PropertiesService & Default Settings
    ├── DriveService.js        # Pencarian & konversi berkas tugas di Google Drive
    ├── GeminiService.js       # Integrasi Gemini Multimodal API & JSON Schema
    ├── GradingEngine.js       # Pemroses batch sheet, template, & checkpoint timeout
    ├── Menu.js                # Custom Menu toolbar, triggers, & API bridge
    └── Sidebar.html           # UI Sidebar Pengaturan & Kontrol Penilaian
```

---

## 🚀 Panduan Setup & Langkah Pertama

### Langkah 1: Aktifkan Google Apps Script API (Wajib Satu Kali)
Sebelum dapat menggunakan `clasp login` dan membuat proyek:
1. Buka browser dan kunjungi: **[Google Apps Script User Settings](https://script.google.com/home/usersettings)**.
2. Pastikan opsi **Google Apps Script API** diubah ke posisi **ON** (Aktif).

---

### Langkah 2: Otentikasi Login Clasp
Jalankan perintah berikut di terminal:

```powershell
npm run login
```
*atau:*
```powershell
npx clasp login
```
Browser akan terbuka otomatis meminta izin akses Google Apps Script. Pilih akun Google Anda dan setujui izinnya. Setelah berhasil, token tersimpan di komputer Anda (`~/.clasprc.json`).

---

### Langkah 3: Membuat Proyek Google Sheets Baru Terikat (Container-bound)
Buat Google Spreadsheet baru yang langsung terikat dengan skrip ini:

```powershell
npm run create:sheet
```
*atau secara manual:*
```powershell
npx clasp create --type sheets --title "Asisten Penilaian Mahasiswa Multimodal" --rootDir ./src
```

> **Catatan jika ingin menghubungkan ke Spreadsheet yang sudah ada:**
> Jika Anda sudah memiliki Google Sheets, salin `scriptId` dari editor Apps Script spreadsheet tersebut, lalu buat berkas `.clasp.json` di root:
> ```json
> {
>   "scriptId": "YOUR_SCRIPT_ID_HERE",
>   "rootDir": "./src"
> }
> ```

---

### Langkah 4: Push Kode Lokal ke Google Apps Script
Kirim seluruh kode dari folder `src/` ke Google Sheets:

```powershell
npm run push
```

Untuk membuka Google Spreadsheet dan editor skrip langsung di browser:
```powershell
npm run open
```

---

## ⚙️ Penggunaan di Google Sheets

1. **Buka Google Spreadsheet** yang telah dibuat/di-push.
2. Refresh halaman. Menu kustom **🎓 Asisten Penilaian** akan muncul di bilah toolbar atas.
3. Klik **🎓 Asisten Penilaian > ⚙️ Pengaturan & Rubrik**:
   - **Tab API**: Masukkan Gemini API Key Anda (dapat diperoleh gratis dari [Google AI Studio](https://aistudio.google.com/app/apikey)) dan klik *Uji Koneksi*.
   - **Tab Drive**: Masukkan ID atau URL Folder Google Drive tempat berkas tugas mahasiswa dikumpulkan, lalu klik *Verifikasi Akses*.
   - **Tab Rubrik**: Sesuaikan deskripsi kriteria dan bobot nilai sesuai mata kuliah Anda (tersedia preset Cepat).
   - Klik **💾 Simpan Pengaturan**.
4. Klik **📋 Siapkan Kolom Template Sheet** untuk membuat kolom standar (`NIM`, `Nama Mahasiswa`, `Berkas`, `Nilai Total`, `Rincian Nilai`, `Catatan & Masukan AI`, `Status Penilaian`, `Waktu Penilaian`).
5. Masukkan daftar mahasiswa (NIM dan Nama).
6. Klik **▶️ Mulai / Lanjutkan Penilaian**. Pantau nilai dan ulasan terisi secara otomatis baris demi baris!

---

## 🛡️ Keamanan & Kredensial
- API Key Gemini disimpan di `PropertiesService.getUserProperties()`, sehingga API Key bersifat privat bagi akun dosen/penilai dan **tidak akan terekspos** jika file Google Sheets dibagikan kepada asisten atau mahasiswa.
- Berkas `.clasprc.json` dan token otentikasi diabaikan oleh `.gitignore`.
