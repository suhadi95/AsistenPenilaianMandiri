<?php
/**
 * @fileoverview Google OAuth 2.0 Redirect Callback Handler
 */

require_once __DIR__ . '/services/GoogleDriveService.php';

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;

$success = false;
$userEmail = '';
$errorMessage = '';

if ($error) {
    $errorMessage = "Izin otorisasi ditolak oleh pengguna ({$error}).";
} elseif ($code) {
    try {
        $result = GoogleDriveService::exchangeCode($code);
        $success = true;
        $userEmail = $result['email'] ?? '';
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
} else {
    $errorMessage = "Parameter otorisasi tidak ditemukan.";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Otorisasi Google Drive - Asisten Penilaian</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
  <div class="max-w-md w-full bg-slate-800 border border-slate-700/80 rounded-3xl p-6 sm:p-8 shadow-2xl text-center space-y-5">
    
    <?php if ($success): ?>
      <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-2xl flex items-center justify-center text-3xl mx-auto">
        ✓
      </div>
      <div>
        <h1 class="text-xl font-bold text-white">Google Drive Terhubung!</h1>
        <p class="text-xs text-slate-400 mt-1">Otorisasi OAuth 2.0 berhasil diproses secara aman.</p>
      </div>

      <?php if (!empty($userEmail)): ?>
        <div class="p-3 bg-slate-900/60 rounded-xl border border-slate-700 text-xs">
          <span class="text-slate-400">Akun Terhubung:</span>
          <p class="font-bold text-emerald-400 mt-0.5"><?= htmlspecialchars($userEmail) ?></p>
        </div>
      <?php endif; ?>

      <div class="text-xs text-slate-400 leading-relaxed">
        Mengalihkan Anda kembali ke aplikasi dalam <span id="timer" class="font-bold text-sky-400">3</span> detik...
      </div>

      <div>
        <a href="index.php#settings?oauth=connected" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-semibold shadow-lg transition">
          <span>Kembali ke Aplikasi</span> &rarr;
        </a>
      </div>

      <script>
        let countdown = 3;
        const timerEl = document.getElementById('timer');
        const interval = setInterval(() => {
          countdown--;
          if (timerEl) timerEl.textContent = countdown;
          if (countdown <= 0) {
            clearInterval(interval);
            window.location.href = 'index.php#settings?oauth=connected';
          }
        }, 1000);
      </script>

    <?php else: ?>
      <div class="w-16 h-16 bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-2xl flex items-center justify-center text-3xl mx-auto">
        ✕
      </div>
      <div>
        <h1 class="text-xl font-bold text-white">Otorisasi Gagal</h1>
        <p class="text-xs text-slate-400 mt-1">Terjadi kesalahan saat menghubungkan akun Google.</p>
      </div>

      <div class="p-3 bg-rose-950/40 text-rose-300 border border-rose-800/60 rounded-xl text-xs text-left">
        <strong>Pesan Kesalahan:</strong>
        <p class="mt-1 font-mono text-[11px]"><?= htmlspecialchars($errorMessage) ?></p>
      </div>

      <div>
        <a href="index.php#settings?oauth=failed" class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-xl text-xs font-semibold transition">
          <span>Kembali ke Pengaturan</span> &rarr;
        </a>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
