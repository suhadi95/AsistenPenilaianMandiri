# 🎓 Asisten Penilaian Mandiri (Web Application & AI Multimodal)

Aplikasi web cerdas untuk otomatisasi penilaian tugas mahasiswa berbasis **PHP 8.x** dan **Google Gemini API (Multimodal)**, dirancang khusus agar siap digunakan langsung pada **Shared Hosting (cPanel / DirectAdmin / Apache / LiteSpeed)** maupun lingkungan lokal.

Aplikasi ini merupakan transformasi penuh dari versi Google Apps Script (GAS) terdahulu, menggantikan Google Sheets dengan **Database Relasional (MySQL / SQLite)** guna menghadirkan performa responsif tanpa batasan kuota, timeout 6 menit, atau latensi spreadsheet.

---

## 🌟 Fitur Utama

1. **Evaluasi Multimodal Berbasis AI (Gemini 3.5 Flash-Lite)**:
   - Menggunakan model default **`gemini-3.5-flash-lite`** yang sangat cepat, cerdas, berkuota luas, dan hemat biaya.
   - Format respons terstruktur berbasis **JSON Schema**: menghasilkan skor per butir kriteria, total nilai akumulatif, ulasan ringkas, poin kelebihan, serta saran perbaikan.
   - **Ekstraksi Rubrik Otomatis**: AI menganalisis berkas soal tugas dan menyusun pembagian porsi poin otomatis (akumulasi 100 poin).
   - **Pencocokan Cerdas Isi Dokumen (*Content-Based Matching*)**: Jika berkas mahasiswa diunggah tanpa NIM pada nama file (misal `Tugas1.pdf` atau `Laporan.docx`), AI akan membaca sampul/cover dan teks dokumen untuk mengenali NIM & nama pemilik tugas secara akurat.

2. **Fleksibilitas Pengumpulan Tugas (Upload ZIP & Google Drive)**:
   - **Unggah 1 File ZIP**: Dosen cukup mengunduh berkas pengumpulan tugas dari LMS (Google Classroom, Moodle, Edmodo, dll.) lalu mengunggah berkas ZIP tersebut ke sistem. Server akan mengekstrak otomatis dan memetakan setiap berkas ke mahasiswa yang bersangkutan.
   - **Unggah Berkas Tunggal / Langsung**: Mendukung format PDF, Word (.docx), gambar (PNG/JPG/WebP), teks, dan kode program (Python, JS, HTML, Java, C++, dll.).
   - **Tautan Google Drive**: Tetap kompatibel untuk membaca referensi folder/berkas Google Drive publik.

3. **Struktur Berpusat Mata Kuliah (Course-Centric Multi-Class)**:
   - 1 Mata Kuliah dapat menaungi beberapa kelas paralel (misal: Kelas A, Kelas B, Kelas C).
   - Tugas dibuat satu kali di tingkat Mata Kuliah dan otomatis berlaku untuk seluruh kelas tanpa perlu mengisi ulang berkas soal atau rubrik nilai.
   - Mendukung override folder/berkas khusus jika ada kelas yang memiliki tempat pengumpulan berbeda.

4. **Penilaian Tanpa Batas Waktu (*Continuous Queue*)**:
   - Proses penilaian batch dilakukan per-mahasiswa secara asinkron (*AJAX continuous queue*) dilengkapi bilah progres (*progress bar*), log waktu nyata (*real-time log*), tombol jeda/hentikan, dan nada notifikasi selesai.
   - Bebas dari resiko PHP execution timeout di shared hosting.

5. **Arsitektur Multi-User SaaS & Role-Based Access Control (RBAC)**:
   - **Peran Administrator**: Mengelola pengguna/dosen (tambah, edit, nonaktifkan, reset password), memantau pemakaian, dan mengonfigurasi kredensial global Google OAuth sekali untuk seluruh institusi.
   - **Peran Dosen / Pengajar**: Memiliki ruang kerja mandiri (mata kuliah, kelas, mahasiswa, tugas, dan nilai terisolasi).
   - **Bring Your Own Key (BYOK)**: Dosen menggunakan Gemini API Key masing-masing (kuota gratis dari Google AI Studio) sehingga beban biaya dan batas kuota (*rate limit*) terdistribusi secara independen dan aman.
   - **1-Klik Hubungkan Google Drive**: Dosen cukup menekan tombol "Hubungkan Google Drive Saya" dan login akun Google kampus tanpa perlu membuka Google Cloud Console.

6. **Ekspor & Rekapitulasi Nilai Lengkap**:
   - Rekapitulasi nilai per kelas dengan perhitungan nilai akhir terbobot otomatis berdasarkan persentase tugas.
   - Ekspor laporan multi-sheet langsung ke format **Excel (.xlsx)** (Sheet 1: Rekap Nilai Kelas, Sheet 2 dst: Rincian Poin & Ulasan tiap tugas) serta opsi salin satu-klik untuk SIAKAD.

---

## 💾 Pilihan Basis Data: MySQL vs. SQLite

Sistem dilengkapi **Database Abstraction Layer (PDO)** yang mendukung kedua driver melalui file `config.php`:

| Parameter | MySQL / MariaDB (Direkomendasikan) | SQLite |
| :--- | :--- | :--- |
| **Target Penggunaan** | **Shared Hosting (cPanel / Produksi)** | **Pengembangan Lokal / Portabel** |
| **Kelebihan** | Mendukung konkurensi banyak pengguna, aman saat penilaian batch, terintegrasi phpMyAdmin | Tanpa instalasi database server, cukup satu file `database.sqlite` |
| **Pengaturan** | Buat database & user di cPanel, masukkan ke `config.php` | Sudah otomatis aktif secara *default* |

---

## 📁 Struktur Direktori

```text
AsistenPenilaianMandiri/
├── assets/
│   ├── css/
│   │   └── style.css          # Styling kustom & animasi
│   └── js/
│       └── api.js             # Client API & adapter kompatibilitas UI
├── config.example.php         # Template konfigurasi basis data & Gemini
├── config.php                 # Berkas konfigurasi aktif
├── core/
│   ├── Database.php           # PDO Database Wrapper (MySQL & SQLite) + Auto Migration
│   └── Helper.php             # Utilitas unik ID, sanitasi, dan JSON helper
├── services/
│   ├── UserService.php        # Manajemen Pengguna, Sesi Login, & Otorisasi RBAC
│   ├── GoogleDriveService.php # Integrasi Google OAuth 2.0 & Google Drive API v3
│   ├── GeminiService.php      # Integrasi Gemini 3.5 Flash-Lite (cURL & Multimodal)
│   ├── FileService.php        # Ekstraksi ZIP, parsing DOCX/PDF, & pencocokan file
│   └── GradingService.php     # Orkestrasi penilaian per mahasiswa & batch
├── uploads/                   # Direktori penyimpanan aman berkas tugas & soal
│   ├── .htaccess              # Mencegah eksekusi skrip berbahaya
│   └── .gitkeep
├── .cpanel.yml                # Skrip deployment otomatis cPanel Git Version Control
├── .htaccess                  # Konfigurasi Apache / LiteSpeed (keamanan & UTF-8)
├── .gitignore                 # Berkas kredensial & runtime yang diabaikan
├── database.sql               # Skema DDL tabel database
├── install.php                # Web installer & migrator satu-klik
├── index.php                  # Antarmuka web utama (Single Page Application & SaaS)
├── oauth_callback.php         # Handler callback Google OAuth 2.0
└── api.php                    # REST API Gateway & endpoint handler
```

---

## 🚀 Panduan Instalasi di Shared Hosting (cPanel)

### Langkah 1: Unggah Berkas ke Hosting
1. Masuk ke **cPanel** hosting Anda, lalu buka **File Manager**.
2. Masuk ke direktori `public_html` (atau folder subdomain Anda).
3. Unggah seluruh berkas proyek ini ke dalam direktori tersebut.
4. Pastikan direktori `uploads/` memiliki izin tulis (`permission 755` atau `775`).

---

### Langkah 2: Konfigurasi Basis Data (Pilih Salah Satu)

#### Opsi A: Menggunakan MySQL (Disarankan untuk Produksi)
1. Di cPanel, buka menu **MySQL® Databases**.
2. Buat database baru (misal: `u12345_penilaian`).
3. Buat pengguna database baru dan simpan kata sandinya.
4. Hubungkan pengguna tersebut ke database dengan hak akses penuh (**ALL PRIVILEGES**).
5. Salin berkas `config.example.php` menjadi `config.php`:
   ```php
   return [
       'db_driver' => 'mysql',
       'mysql' => [
           'host'     => 'localhost',
           'port'     => 3306,
           'database' => 'u12345_penilaian',
           'username' => 'u12345_user',
           'password' => 'PasswordDatabaseAnda',
           'charset'  => 'utf8mb4'
       ],
       // ...
   ];
   ```
6. Buka URL installer di browser: `https://domain-anda.com/install.php` lalu klik tombol **Jalankan Inisialisasi Basis Data**. Seluruh tabel dan data contoh awal akan otomatis terbuat!

#### Opsi B: Menggunakan SQLite (Zero-Configuration)
Jika tidak ingin membuat database MySQL di cPanel:
1. Pastikan `db_driver` pada `config.php` bernilai `'sqlite'`.
2. Buka `https://domain-anda.com/install.php` untuk memvalidasi dan menginisialisasi basis data `database.sqlite` secara otomatis.

---

### Langkah 3: Konfigurasi Gemini API Key
1. Dapatkan API Key Gemini gratis dari **[Google AI Studio](https://aistudio.google.com/app/apikey)**.
2. Buka aplikasi web Anda (`https://domain-anda.com`).
3. Klik ikon roda gigi **⚙️ Pengaturan**:
   - Masukkan API Key Anda pada kolom **Gemini API Key**.
   - Pilih model **gemini-3.5-flash-lite**.
   - Klik **🔍 Uji Koneksi API Key** untuk memastikan koneksi aktif.
   - Klik **💾 Simpan Pengaturan**.

---

## 💻 Menjalankan di Lingkungan Lokal (Local Development)

Jika ingin menjalankan aplikasi di komputer lokal:

1. Pastikan komputer Anda telah terpasang **PHP 8.1+** dengan ekstensi `pdo_sqlite`, `curl`, `zip`, dan `mbstring` aktif.
2. Jalankan PHP Built-in Server di terminal:
   ```powershell
   php -S localhost:8000
   ```
3. Buka browser dan akses:
   ```text
   http://localhost:8000
   ```
4. Aplikasi akan otomatis menginisialisasi database SQLite lokal dan siap digunakan.

---

## 📖 Alur Kerja Penilaian Cepat

1. **Pilih / Buat Mata Kuliah & Kelas**: Di tab **👥 MK & Kelas**, buat mata kuliah (contoh: *Pemrograman Web*) dan kelas paralel (*Kelas A*, *Kelas B*).
2. **Masukkan Mahasiswa**: Gunakan tombol **📋 Impor Massal** untuk menempelkan daftar NIM dan Nama mahasiswa dari Excel atau SIAKAD.
3. **Buat Tugas**: Di tab **📝 Kelola Tugas**, tambahkan tugas baru:
   - Masukkan judul dan bobot nilai.
   - Klik **🤖 Ekstrak dari Soal** untuk membuat rubrik otomatis.
4. **Unggah Berkas Tugas**: Di tab **🎓 Penilaian**:
   - Klik tombol **📦 Upload ZIP Tugas** lalu pilih berkas ZIP tugas mahasiswa dari LMS.
5. **Mulai Penilaian**:
   - Klik **▶️ Nilai Semua** atau centang mahasiswa tertentu lalu klik **🎯 Pilihan**.
   - Perhatikan skor, justifikasi, dan masukan AI terisi secara *real-time*!
6. **Unduh Rekapitulasi**: Di tab **📊 Rekapitulasi**, klik **📥 Download Excel** untuk mengunduh rekapitulasi nilai multi-sheet.

---

## 🛡️ Keamanan & Lisensi
- File konfigurasi `config.php` dan database SQLite dilindungi dari akses langsung publik via `.htaccess`.
- Direktori `uploads/` dilindungi dari eksekusi berkas skrip PHP.
- Proyek ini dirilis di bawah lisensi MIT untuk kemudahan penggunaan di lingkungan akademik.
