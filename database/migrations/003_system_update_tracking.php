<?php
/**
 * Migrasi Pelacakan Pembaruan & Deployment Sistem
 * Membuat tabel migrations dan inisialisasi pengaturan repository
 */

return new class {
    public function up(PDO $pdo, string $driver): void {
        // Buat tabel migrations jika belum ada
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL DEFAULT 1,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }

        // Pengaturan default repositori dan branch
        $defaults = [
            'APP_DEPLOY_REPO'   => 'suhadi95/AsistenPenilaianMandiri',
            'APP_DEPLOY_BRANCH' => 'master'
        ];

        foreach ($defaults as $k => $v) {
            $check = $pdo->prepare("SELECT setting_key FROM settings WHERE setting_key = ?");
            $check->execute([$k]);
            if (!$check->fetch()) {
                $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)");
                $ins->execute([$k, $v, date('Y-m-d H:i:s')]);
            }
        }
    }
};
