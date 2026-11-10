-- ==========================================================
-- Skema Basis Data Asisten Penilaian Mandiri
-- Kompatibel dengan MySQL 5.7+ / MariaDB 10.2+ & SQLite
-- ==========================================================

CREATE TABLE IF NOT EXISTS users (
    id_user VARCHAR(64) PRIMARY KEY,
    nama VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) DEFAULT 'dosen',
    status VARCHAR(32) DEFAULT 'active',
    gemini_api_key TEXT NULL,
    model_name VARCHAR(64) DEFAULT 'gemini-3.5-flash-lite',
    google_access_token TEXT NULL,
    google_refresh_token TEXT NULL,
    google_token_expires_at INT DEFAULT 0,
    google_connected_email VARCHAR(255) NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS courses (
    id_mata_kuliah VARCHAR(64) PRIMARY KEY,
    id_user VARCHAR(64) NULL,
    kode_mk VARCHAR(32) DEFAULT '',
    nama_mata_kuliah VARCHAR(255) NOT NULL,
    semester VARCHAR(64) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS classes (
    id_kelas VARCHAR(64) PRIMARY KEY,
    id_mata_kuliah VARCHAR(64) NOT NULL,
    nama_kelas VARCHAR(255) NOT NULL,
    semester VARCHAR(64) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    id_mahasiswa VARCHAR(64) PRIMARY KEY,
    id_kelas VARCHAR(64) NOT NULL,
    nim VARCHAR(64) NOT NULL,
    nama_mahasiswa VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tasks (
    id_tugas VARCHAR(64) PRIMARY KEY,
    id_mata_kuliah VARCHAR(64) NOT NULL,
    judul_tugas VARCHAR(255) NOT NULL,
    deskripsi TEXT,
    task_file_ref TEXT,
    drive_folder_id VARCHAR(255) DEFAULT '',
    rubrik_text MEDIUMTEXT,
    bobot_persentase DECIMAL(5,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS grades (
    id_nilai VARCHAR(64) PRIMARY KEY,
    id_tugas VARCHAR(64) NOT NULL,
    id_kelas VARCHAR(64) NOT NULL,
    nim VARCHAR(64) NOT NULL,
    nama_mahasiswa VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) DEFAULT '',
    drive_file_url TEXT NULL,
    nilai_total DECIMAL(5,2) NULL,
    rincian_poin MEDIUMTEXT,
    catatan_ai MEDIUMTEXT,
    similarity_score DECIMAL(5,2) NULL,
    similarity_target_nim VARCHAR(64) NULL,
    similarity_details MEDIUMTEXT NULL,
    ai_detected INT DEFAULT 0,
    ai_detected_details MEDIUMTEXT NULL,
    status VARCHAR(255) DEFAULT 'Belum Dinilai',
    waktu_penilaian VARCHAR(64) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS class_task_folders (
    id_folder_kelas VARCHAR(64) PRIMARY KEY,
    id_tugas VARCHAR(64) NOT NULL,
    id_kelas VARCHAR(64) NOT NULL,
    drive_folder_id VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(128) PRIMARY KEY,
    setting_value MEDIUMTEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Indeks Tambahan untuk Optimalisasi Pencarian & Kecepatan Query
CREATE INDEX idx_classes_course ON classes(id_mata_kuliah);
CREATE INDEX idx_students_class ON students(id_kelas);
CREATE INDEX idx_students_nim ON students(nim);
CREATE INDEX idx_tasks_course ON tasks(id_mata_kuliah);
CREATE INDEX idx_grades_task_class ON grades(id_tugas, id_kelas);
CREATE INDEX idx_grades_nim ON grades(nim);
CREATE INDEX idx_class_folders ON class_task_folders(id_tugas, id_kelas);
CREATE INDEX idx_courses_user ON courses(id_user);
