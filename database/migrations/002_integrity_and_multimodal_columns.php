<?php
/**
 * Migrasi Kolom Integritas Akademik & Ekstensi Multimodal
 * Menjamin ketersediaan kolom pendukung plagiasi & integrasi Google Drive
 */

return new class {
    public function up(PDO $pdo, string $driver): void {
        // 1. Pastikan kolom id_user ada di courses
        try {
            if ($driver === 'mysql') {
                $col = $pdo->query("SHOW COLUMNS FROM courses LIKE 'id_user'")->fetch();
                if (empty($col)) {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN id_user VARCHAR(64) NULL AFTER id_mata_kuliah");
                }
            } else {
                $cols = $pdo->query("PRAGMA table_info(courses)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                if (!in_array('id_user', $names, true)) {
                    $pdo->exec("ALTER TABLE courses ADD COLUMN id_user VARCHAR(64) NULL");
                }
            }
        } catch (Exception $e) {
            // Kolom mungkin sudah ada
        }

        // 2. Pastikan kolom-kolom integritas ada di grades
        $integrityCols = [
            'drive_file_url'        => ['type' => 'TEXT NULL'],
            'similarity_score'      => ['type' => 'DECIMAL(5,2) NULL'],
            'similarity_target_nim' => ['type' => 'VARCHAR(64) NULL'],
            'similarity_details'    => ['type' => 'MEDIUMTEXT NULL'],
            'ai_detected'           => ['type' => 'INT DEFAULT 0'],
            'ai_detected_details'   => ['type' => 'MEDIUMTEXT NULL']
        ];

        try {
            if ($driver === 'mysql') {
                foreach ($integrityCols as $colName => $def) {
                    $col = $pdo->query("SHOW COLUMNS FROM grades LIKE '{$colName}'")->fetch();
                    if (empty($col)) {
                        $pdo->exec("ALTER TABLE grades ADD COLUMN {$colName} {$def['type']}");
                    }
                }
            } else {
                $cols = $pdo->query("PRAGMA table_info(grades)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                foreach ($integrityCols as $colName => $def) {
                    if (!in_array($colName, $names, true)) {
                        $pdo->exec("ALTER TABLE grades ADD COLUMN {$colName} {$def['type']}");
                    }
                }
            }
        } catch (Exception $e) {
            // Kolom mungkin sudah ada
        }
    }
};
