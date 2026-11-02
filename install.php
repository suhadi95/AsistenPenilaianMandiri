<?php
/**
 * @fileoverview Web Installer & Database Migrator untuk Asisten Penilaian Mandiri
 */

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Helper.php';

$res = null;
$error = null;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'POST' || (php_sapi_name() === 'cli')) {
    try {
        $res = Database::initDatabase();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎓 Installer & Migrasi Basis Data - Asisten Penilaian Mandiri</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-2xl shadow-xl border border-slate-200 p-6 sm:p-8">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-12 h-12 bg-blue-600 text-white rounded-xl flex items-center justify-center text-2xl shadow-lg shadow-blue-500/30">
                🎓
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Asisten Penilaian Mandiri</h1>
                <p class="text-xs text-slate-500">Web Installer & Verifikasi Konfigurasi</p>
            </div>
        </div>

        <?php if ($res): ?>
            <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                <div class="flex items-center space-x-2 font-semibold">
                    <span>✅ Inisialisasi Berhasil!</span>
                </div>
                <p class="mt-1 text-xs"><?= htmlspecialchars($res['message']) ?> Driver aktif: <strong><?= htmlspecialchars($res['driver']) ?></strong></p>
            </div>
            <div class="flex justify-end">
                <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-5 py-2.5 rounded-xl shadow-lg transition">
                    Buka Aplikasi Web 🚀
                </a>
            </div>
        <?php elseif ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                <div class="flex items-center space-x-2 font-semibold">
                    <span>❌ Gagal Melakukan Inisialisasi</span>
                </div>
                <p class="mt-1 text-xs"><?= htmlspecialchars($error) ?></p>
            </div>
            <form method="POST">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm py-2.5 rounded-xl shadow-lg transition">
                    Coba Lagi 🔄
                </button>
            </form>
        <?php else: ?>
            <?php 
                $config = Database::loadConfig(); 
                $driver = $config['db_driver'] ?? 'sqlite';
            ?>
            <div class="space-y-4 mb-6">
                <p class="text-sm text-slate-600 leading-relaxed">
                    Halaman ini akan memeriksa dan membuat tabel basis data yang diperlukan (Mata Kuliah, Kelas, Mahasiswa, Tugas, Nilai, Pengaturan) secara otomatis.
                </p>
                <div class="bg-slate-100 p-4 rounded-xl text-xs space-y-1.5 font-mono text-slate-700">
                    <p><strong>Driver Database:</strong> <?= htmlspecialchars(strtoupper($driver)) ?></p>
                    <?php if ($driver === 'mysql'): ?>
                        <p><strong>Host:</strong> <?= htmlspecialchars($config['mysql']['host']) ?></p>
                        <p><strong>Database:</strong> <?= htmlspecialchars($config['mysql']['database']) ?></p>
                        <p><strong>User:</strong> <?= htmlspecialchars($config['mysql']['username']) ?></p>
                    <?php else: ?>
                        <p><strong>SQLite File:</strong> <?= htmlspecialchars($config['sqlite']['path']) ?></p>
                    <?php endif; ?>
                    <p><strong>Model AI Default:</strong> <?= htmlspecialchars($config['gemini']['default_model']) ?></p>
                </div>
            </div>

            <form method="POST">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm py-3 rounded-xl shadow-lg shadow-blue-500/20 transition flex items-center justify-center gap-2">
                    <span>⚡ Jalankan Inisialisasi Basis Data Sekarang</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
