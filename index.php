<?php
require_once __DIR__ . '/core/Database.php';
// Otomatis inisialisasi basis data jika belum ada
Database::initDatabase();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Asisten Penilaian Mahasiswa - Portal Akademik</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- SheetJS CDN untuk Ekspor Excel (.xlsx) Multi-Sheet -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <!-- API Gateway Client & Adapter -->
  <script src="assets/js/api.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          screens: {
            'xs': '420px'
          },
          colors: {
            brand: {
              50: '#eff6ff',
              100: '#dbeafe',
              500: '#3b82f6',
              600: '#2563eb',
              700: '#1d4ed8',
              900: '#1e3a8a'
            }
          }
        }
      }
    }
  </script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .spinner {
      border: 2px solid rgba(255,255,255,0.3);
      border-radius: 50%;
      border-top-color: #fff;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    * { -webkit-tap-highlight-color: transparent; }
    .table-scroll-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col bg-slate-50">

  <!-- ======================================================== -->
  <!-- LOGIN SCREEN (Ditampilkan jika belum autentikasi)        -->
  <!-- ======================================================== -->
  <div id="loginScreenContainer" class="hidden min-h-screen bg-slate-900 flex flex-col justify-center items-center p-4">
    <div class="max-w-md w-full bg-slate-800 border border-slate-700/80 rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6">
      <div class="text-center space-y-2">
        <img src="assets/img/logo.svg" alt="Logo" class="w-16 h-16 mx-auto mb-2 shadow-lg shadow-blue-500/25 rounded-2xl" />
        <h2 class="text-xl sm:text-2xl font-bold text-white tracking-tight">Asisten Penilaian Mandiri</h2>
        <p class="text-xs text-slate-400">Portal Evaluasi Akademik &amp; Penilaian AI Multimodal</p>
      </div>

      <!-- Login Form -->
      <form id="loginForm" onsubmit="handleLoginSubmit(event)" class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase mb-1.5">Alamat Email Kampus</label>
          <input type="email" id="loginEmail" required placeholder="nama@kampus.ac.id" class="w-full text-sm px-4 py-3 bg-slate-900 border border-slate-700 rounded-xl focus:outline-none focus:border-blue-500 text-white placeholder-slate-500 transition">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-300 uppercase mb-1.5">Kata Sandi (Password)</label>
          <input type="password" id="loginPassword" required placeholder="••••••••" class="w-full text-sm px-4 py-3 bg-slate-900 border border-slate-700 rounded-xl focus:outline-none focus:border-blue-500 text-white placeholder-slate-500 transition">
        </div>

        <div id="loginAlert" class="hidden p-3 rounded-xl text-xs font-medium"></div>

        <button type="submit" id="btnLoginSubmit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm py-3 rounded-xl shadow-lg shadow-blue-600/30 transition flex items-center justify-center gap-2">
          <span>Masuk ke Portal</span>
        </button>
      </form>

      <div class="pt-4 border-t border-slate-700/60 text-center space-y-1">
        <p class="text-[11px] text-slate-400">
          Akun Dosen didaftarkan oleh Administrator sistem institusi.
        </p>
      </div>
    </div>
  </div>

  <!-- ======================================================== -->
  <!-- MAIN APP WRAPPER (Ditampilkan setelah login)             -->
  <!-- ======================================================== -->
  <div id="appWrapper" class="flex-1 flex flex-col">

    <!-- TOP NAVBAR -->
    <header class="bg-slate-900 text-white shadow-md sticky top-0 z-40">
      <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14 sm:h-16 gap-2">
          
          <!-- Logo & Brand -->
          <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
            <img src="assets/img/logo.svg" alt="Logo" class="w-9 h-9 rounded-xl shadow-md shadow-blue-500/20 shrink-0" />
            <div>
              <h1 class="font-bold text-sm sm:text-base tracking-tight leading-none text-white">Asisten Penilaian</h1>
              <p class="text-[10px] sm:text-xs text-slate-400 mt-0.5 hidden xs:block">Portal Akademik</p>
            </div>
          </div>

          <!-- Selector Kelas Aktif -->
          <div class="flex items-center space-x-1.5 bg-slate-800/90 border border-slate-700/80 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-xl max-w-[140px] xs:max-w-[185px] sm:max-w-xs shrink min-w-0">
            <span class="text-xs text-slate-400 font-medium hidden md:inline shrink-0">Kelas:</span>
            <select id="headerClassSelect" onchange="switchActiveClass(this.value)" class="bg-transparent text-xs sm:text-sm font-semibold text-white focus:outline-none cursor-pointer truncate w-full">
              <option value="" class="bg-slate-800 text-slate-300">-- Pilih Kelas --</option>
            </select>
          </div>

          <!-- Right Quick Info & Actions -->
          <div class="flex items-center space-x-1.5 sm:space-x-2.5 shrink-0">
            <!-- Tombol Panduan di Atas (Terpisah dari Tab Operasional) -->
            <button onclick="switchTab('tab-guide')" id="headerBtnGuide" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs font-semibold bg-indigo-600/30 hover:bg-indigo-600 text-indigo-200 hover:text-white border border-indigo-400/40 transition shadow-xs cursor-pointer" title="Buka Panduan Penggunaan">
              <svg class="w-4 h-4 shrink-0 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
              <span class="font-medium">Panduan</span>
            </button>

            <div id="aiModelBadge" class="hidden lg:flex items-center space-x-1.5 bg-emerald-950/60 border border-emerald-500/30 text-emerald-400 text-xs px-2.5 py-1 rounded-full font-medium">
              <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
              <span id="badgeModelName">gemini-3.5-flash-lite</span>
            </div>

            <!-- Profile Info & Role Badge -->
            <div class="flex items-center space-x-2 pl-2 sm:pl-3 border-l border-slate-800">
              <div class="text-right hidden sm:block">
                <div id="headerUserName" class="text-xs font-semibold text-slate-200 truncate max-w-[130px]">-</div>
                <div id="headerUserRole" class="text-[10px] text-slate-400 font-medium capitalize">-</div>
              </div>
              <button onclick="switchTab('tab-settings')" class="p-1.5 sm:p-2 text-slate-300 hover:text-white hover:bg-slate-800 rounded-lg transition" title="Pengaturan Akun &amp; AI">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              </button>
              <button onclick="handleLogout()" class="p-1.5 sm:p-2 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded-lg transition" title="Keluar (Logout)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
              </button>
            </div>
          </div>

        </div>
      </div>
    </header>

    <!-- IMPERSONATION / LOGIN AS WARNING BAR (ADMIN ONLY) -->
    <div id="impersonationBanner" class="hidden bg-amber-500 text-slate-950 px-3 sm:px-6 py-2 text-xs font-semibold shadow-md border-b border-amber-600 flex items-center justify-between gap-3 sticky top-0 sm:top-0 z-40">
      <div class="flex items-center gap-2 truncate">
        <svg class="w-4 h-4 text-slate-950 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <span class="truncate">Mode Penyamaran: Anda sedang masuk sebagai <strong id="impersonatedUserName" class="underline">Dosen</strong> (<span id="impersonatedUserEmail">email</span>).</span>
      </div>
      <button onclick="exitImpersonation()" class="px-2.5 sm:px-3 py-1 bg-slate-900 hover:bg-slate-800 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow-xs cursor-pointer shrink-0">
        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        <span>Kembali ke Akun Admin</span>
      </button>
    </div>

    <!-- NAVIGATION TABS -->
    <nav class="bg-white border-b border-slate-200 sticky top-14 sm:top-16 z-30 shadow-xs">
      <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8">
        <div class="flex space-x-1 sm:space-x-2 overflow-x-auto no-scrollbar py-2 text-xs sm:text-sm font-medium">
          <button onclick="switchTab('tab-dashboard')" id="nav-tab-dashboard" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap active text-blue-600 bg-blue-50 font-semibold shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            <span class="hidden xs:inline">Dashboard</span><span class="xs:hidden">Dash</span>
          </button>
          <button onclick="switchTab('tab-classes')" id="nav-tab-classes" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            <span><span class="hidden sm:inline">Kelola </span>MK &amp; Kelas</span>
          </button>
          <button onclick="switchTab('tab-tasks')" id="nav-tab-tasks" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
            <span><span class="hidden sm:inline">Kelola </span>Tugas</span>
          </button>
          <button onclick="switchTab('tab-grading')" id="nav-tab-grading" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
            <span>Penilaian</span>
          </button>
          <button onclick="switchTab('tab-reports')" id="nav-tab-reports" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            <span>Rekapitulasi</span>
          </button>
          <!-- Tab Khusus Admin: Kelola Pengguna -->
          <button onclick="switchTab('tab-users')" id="nav-tab-users" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 hidden flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
            <span>Kelola Pengguna</span>
          </button>
          <button onclick="switchTab('tab-settings')" id="nav-tab-settings" class="tab-btn px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition whitespace-nowrap shrink-0 flex items-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
            <span>Pengaturan</span>
          </button>
        </div>
      </div>
    </nav>

    <!-- MAIN CONTAINER -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6">

    <!-- GLOBAL NOTIFICATION BANNER -->
    <div id="globalAlert" class="hidden mb-6 p-4 rounded-xl text-sm flex items-start justify-between shadow-sm">
      <div class="flex items-center space-x-2">
        <span id="globalAlertIcon" class="w-5 h-5 shrink-0 flex items-center"></span>
        <span id="globalAlertMsg" class="font-medium"></span>
      </div>
      <button onclick="hideGlobalAlert()" class="text-slate-400 hover:text-slate-600 ml-4 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>

    <!-- ======================================================== -->
    <!-- TAB 1: DASHBOARD                                         -->
    <!-- ======================================================== -->
    <section id="tab-dashboard" class="tab-content active space-y-6">
      
      <!-- Welcome Banner -->
      <div class="bg-gradient-to-r from-slate-900 via-blue-950 to-slate-900 text-white p-5 sm:p-8 rounded-2xl shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <span class="inline-block bg-blue-500/20 text-blue-300 border border-blue-400/30 text-[11px] sm:text-xs px-2.5 py-0.5 sm:py-1 rounded-full font-medium mb-2">Portal Penilaian Mandiri</span>
          <h2 class="text-xl sm:text-2xl md:text-3xl font-bold tracking-tight" id="dashClassTitle">Selamat Datang di Portal Penilaian</h2>
          <p class="text-slate-400 text-xs sm:text-sm mt-1 max-w-xl leading-relaxed">
            Kelola data kelas, mahasiswa, tugas multimodal di Google Drive, serta lakukan penilaian otomatis berbasis rubrik dengan Gemini AI.
          </p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 sm:gap-2.5 w-full md:w-auto">
          <button onclick="switchTab('tab-guide')" class="bg-indigo-600/90 hover:bg-indigo-600 text-white font-medium px-4 py-2.5 rounded-xl border border-indigo-400/30 text-xs sm:text-sm transition flex items-center justify-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
            <span>Panduan</span>
          </button>
          <button onclick="switchTab('tab-classes')" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2.5 rounded-xl shadow-lg text-xs sm:text-sm transition flex items-center justify-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            <span>Kelola Kelas</span>
          </button>
          <button onclick="switchTab('tab-grading')" class="bg-white/10 hover:bg-white/20 text-white font-medium px-4 py-2.5 rounded-xl border border-white/20 text-xs sm:text-sm transition flex items-center justify-center gap-1.5">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
            <span>Mulai Penilaian</span>
          </button>
        </div>
      </div>

      <!-- Stat Cards Grid -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4">
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center space-x-3 sm:space-x-4">
          <div class="w-10 h-10 sm:w-12 sm:h-12 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
          </div>
          <div class="min-w-0">
            <p class="text-[10px] sm:text-xs font-semibold uppercase text-slate-400 truncate">Mata Kuliah</p>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-900" id="statTotalCourses">0</h3>
          </div>
        </div>
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center space-x-3 sm:space-x-4">
          <div class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
          </div>
          <div class="min-w-0">
            <p class="text-[10px] sm:text-xs font-semibold uppercase text-slate-400 truncate">Total Kelas</p>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-900" id="statTotalClasses">0</h3>
          </div>
        </div>
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center space-x-3 sm:space-x-4">
          <div class="w-10 h-10 sm:w-12 sm:h-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5" /></svg>
          </div>
          <div class="min-w-0">
            <p class="text-[10px] sm:text-xs font-semibold uppercase text-slate-400 truncate">Mahasiswa</p>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-900" id="statTotalStudents">0</h3>
          </div>
        </div>
        <div class="bg-white p-3.5 sm:p-5 rounded-2xl border border-slate-200/80 shadow-xs flex items-center space-x-3 sm:space-x-4">
          <div class="w-10 h-10 sm:w-12 sm:h-12 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
          </div>
          <div class="min-w-0">
            <p class="text-[10px] sm:text-xs font-semibold uppercase text-slate-400 truncate">Total Tugas</p>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-900" id="statTotalTasks">0</h3>
          </div>
        </div>
      </div>

      <!-- Quick Tasks Overview in Active Class -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="font-bold text-base text-slate-900">Daftar Tugas Kelas Aktif</h3>
            <p class="text-xs text-slate-500">Status kelengkapan penilaian tugas untuk kelas yang sedang dipilih</p>
          </div>
          <button onclick="openCreateTaskModal()" class="text-blue-600 hover:text-blue-700 font-medium text-xs flex items-center gap-1">
            + Tambah Tugas
          </button>
        </div>

        <div id="dashTasksList" class="space-y-3">
          <div class="text-center py-8 text-slate-400 text-sm">Memuat data tugas...</div>
        </div>
      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 2: KELOLA KELAS & MAHASISWA                          -->
    <!-- ======================================================== -->
    <section id="tab-classes" class="tab-content space-y-6">
      
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
        
        <!-- KOLOM 1: DAFTAR MATA KULIAH (3 cols) -->
        <div class="lg:col-span-3 space-y-4">
          <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-3">
              <div>
                <h3 class="font-bold text-slate-900 text-sm sm:text-base">Mata Kuliah</h3>
                <p class="text-[11px] text-slate-400">Pilih untuk kelola kelas</p>
              </div>
              <button onclick="openCourseModal()" class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg transition" title="Tambah Mata Kuliah Baru">
                + MK Baru
              </button>
            </div>

            <div id="coursesListContainer" class="space-y-2 max-h-[550px] overflow-y-auto pr-1">
              <!-- Rendered via JS -->
            </div>
          </div>
        </div>

        <!-- KOLOM 2: DAFTAR KELAS DALAM MK (3 cols) -->
        <div class="lg:col-span-3 space-y-4">
          <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-3">
              <div>
                <h3 class="font-bold text-slate-900 text-sm sm:text-base">Daftar Kelas</h3>
                <p class="text-[11px] text-slate-400 truncate max-w-[130px]" id="classSectionSubtitle">Pilih mata kuliah</p>
              </div>
              <button onclick="openClassModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg transition" title="Tambah Kelas Baru">
                + Kelas
              </button>
            </div>

            <div id="classesListContainer" class="space-y-2 max-h-[550px] overflow-y-auto pr-1">
              <!-- Rendered via JS -->
            </div>
          </div>
        </div>

        <!-- KOLOM 3: DAFTAR MAHASISWA KELAS (6 cols) -->
        <div class="lg:col-span-6 space-y-4">
          <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
              <div>
                <h3 class="font-bold text-slate-900 text-sm sm:text-base" id="studentListTitle">Daftar Mahasiswa</h3>
                <p class="text-xs text-slate-500" id="studentListSubtitle">Pilih kelas di sebelah kiri</p>
              </div>
              <div class="flex items-center space-x-2">
                <button onclick="openImportStudentsModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold px-3 py-2 rounded-xl transition flex items-center gap-1.5">
                  <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg> <span>Impor Massal</span>
                </button>
                <button onclick="openAddStudentModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-2 rounded-xl transition flex items-center gap-1.5">
                  + Mahasiswa
                </button>
              </div>
            </div>

            <!-- Search Bar -->
            <div class="mt-3 mb-3">
              <input type="text" id="studentSearchInput" oninput="filterStudents(this.value)" placeholder="Cari berdasarkan NIM atau Nama..." class="w-full text-xs px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
            </div>

            <!-- Table Mahasiswa -->
            <div class="overflow-x-auto rounded-xl border border-slate-200">
              <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 uppercase font-semibold border-b border-slate-200">
                  <tr>
                    <th class="px-3 py-2.5 w-10 text-center">No</th>
                    <th class="px-3 py-2.5 w-28">NIM</th>
                    <th class="px-3 py-2.5">Nama Lengkap</th>
                    <th class="px-3 py-2.5 w-16 text-center">Aksi</th>
                  </tr>
                </thead>
                <tbody id="studentsTableBody" class="divide-y divide-slate-100">
                  <tr>
                    <td colspan="4" class="text-center py-8 text-slate-400">Pilih kelas terlebih dahulu</td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>
        </div>

      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 3: KELOLA TUGAS                                      -->
    <!-- ======================================================== -->
    <section id="tab-tasks" class="tab-content space-y-6">
      
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm">
        <div>
          <h2 class="text-base sm:text-lg font-bold text-slate-900">Manajemen Tugas Mata Kuliah</h2>
          <p class="text-xs text-slate-500 mt-0.5">1 Tugas dibuat di tingkat Mata Kuliah dan otomatis berlaku untuk seluruh kelas tanpa input ulang soal atau rubrik</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
          <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl">
            <span class="text-xs text-slate-500 font-medium">Mata Kuliah:</span>
            <select id="taskCourseSelect" onchange="switchTaskCourse(this.value)" class="bg-transparent text-xs font-semibold text-slate-800 focus:outline-none cursor-pointer">
              <!-- Rendered via JS -->
            </select>
          </div>
          <button onclick="openCreateTaskModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-4 py-2.5 rounded-xl shadow-md transition flex items-center gap-1.5">
            + Buat Tugas Baru
          </button>
        </div>
      </div>

      <!-- Grid Cards Tugas -->
      <div id="tasksGridContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <!-- Rendered via JS -->
      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 4: KOREKSI & PENILAIAN TUGAS                         -->
    <!-- ======================================================== -->
    <section id="tab-grading" class="tab-content space-y-6">
      
      <!-- Task Selector & Info Bar -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
          <div class="flex-1">
            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Pilih Tugas yang Ingin Dinilai:</label>
            <select id="gradingTaskSelect" onchange="loadGradingTable(this.value)" class="w-full md:w-96 text-sm font-semibold text-slate-900 bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 focus:outline-none focus:border-blue-500">
              <option value="">-- Pilih Tugas --</option>
            </select>
          </div>

          <!-- Action Buttons Group (2 Baris: Penilaian & Sumber Berkas) -->
          <div class="flex flex-col gap-2 w-full md:w-auto items-start md:items-end shrink-0">
            <!-- Baris Atas: Aksi Penilaian (Pilihan, Nilai Semua, Reset Gagal) -->
            <div class="flex flex-wrap items-center gap-2">
              <button onclick="gradeSelectedStudents()" id="btnGradeSelected" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 disabled:opacity-50 cursor-pointer" disabled>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span>Pilihan (<span id="selectedCountBadge">0</span>)</span>
              </button>
              <button onclick="gradeAllStudents()" id="btnGradeAll" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-3.5 sm:px-4 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                <span>Nilai Semua</span>
              </button>
              <button onclick="resetFailedGrades()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 font-semibold text-xs px-3 py-2 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer" title="Reset status mahasiswa yang gagal agar dapat dinilai ulang">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                <span>Reset Gagal</span>
              </button>
              <button type="button" onclick="runIntegrityCheck()" id="btnIntegrityCheck" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs" title="Deteksi jawaban copy-paste antar mahasiswa dan temukan residu percakapan bot AI yang tertinggal">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                <span>Cek Integritas (Plagiasi & AI)</span>
              </button>
            </div>

            <!-- Baris Bawah: Sumber Berkas (Sinkron Drive, Upload ZIP, Folder Kelas, Buka Drive) -->
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" onclick="syncCurrentTaskDriveFolder()" id="btnSyncDrive" class="bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sky-700 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Sinkronkan berkas tugas langsung dari Google Drive (OAuth)">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                <span>Sinkron Drive</span>
              </button>
              <button type="button" onclick="openUploadZipModal()" id="btnUploadZip" class="bg-indigo-50 border border-indigo-200 hover:bg-indigo-100 text-indigo-700 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Unggah 1 berkas ZIP berisi tugas seluruh mahasiswa">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                <span>Upload ZIP</span>
              </button>
              <button type="button" onclick="openClassFolderOverrideModal()" id="btnClassFolderOverride" class="bg-amber-50 border border-amber-200 hover:bg-amber-100 text-amber-800 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Atur Folder Google Drive khusus untuk kelas ini jika berbeda dari kelas lain">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                <span>Folder Kelas</span>
              </button>
              <button type="button" onclick="openCurrentTaskFolder()" id="btnOpenTaskFolder" class="bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-slate-700 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Buka folder Google Drive tempat berkas jawaban mahasiswa tersimpan">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                <span>Buka Drive</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Task Metadata Cards -->
        <div id="gradingTaskInfo" class="hidden grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
          <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
            <span class="text-slate-400 font-medium">Bobot Nilai Akhir:</span>
            <p class="font-bold text-slate-800 text-sm mt-0.5" id="taskInfoWeight">0%</p>
          </div>
          <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
            <span class="text-slate-400 font-medium">Berkas Soal Acuan:</span>
            <p class="font-semibold text-blue-600 truncate mt-0.5" id="taskInfoQuestion">Ada</p>
          </div>
          <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
            <div class="flex items-center justify-between">
              <span class="text-slate-400 font-medium">Folder Jawaban Mahasiswa:</span>
              <button type="button" onclick="openClassFolderOverrideModal()" class="text-[10px] text-blue-600 hover:underline font-semibold" title="Atur Folder Drive Khusus Kelas"><span class="inline-flex items-center gap-1"><span>Atur</span><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span></button>
            </div>
            <p class="font-semibold text-slate-800 truncate mt-0.5" id="taskInfoFolder">Terhubung</p>
          </div>
        </div>

        <!-- Statistik & Filter Penilaian Cepat -->
        <div id="gradingStatsBar" class="hidden grid grid-cols-2 sm:grid-cols-5 gap-2.5 pt-1">
          <!-- Total Mahasiswa -->
          <button type="button" onclick="filterGradingTableByStatus('all')" id="statFilterAll" class="grading-stat-card text-left p-3 rounded-2xl border transition cursor-pointer bg-slate-50 hover:bg-slate-100/80 border-slate-200 ring-2 ring-blue-500 shadow-2xs" title="Tampilkan seluruh mahasiswa">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-bold text-slate-600">Total Mahasiswa</span>
              <span class="p-1 rounded-md bg-slate-200/70 text-slate-700 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></span>
            </div>
            <div class="flex items-baseline gap-1 mt-1.5">
              <span class="text-xl font-extrabold text-slate-900" id="statCountTotal">0</span>
              <span class="text-[11px] text-slate-400 font-medium">Mhs</span>
            </div>
          </button>

          <!-- Selesai Dinilai -->
          <button type="button" onclick="filterGradingTableByStatus('selesai')" id="statFilterSelesai" class="grading-stat-card text-left p-3 rounded-2xl border transition cursor-pointer bg-emerald-50/70 hover:bg-emerald-100/80 border-emerald-200 shadow-2xs" title="Klik untuk memfilter: Hanya yang sudah selesai dinilai">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-bold text-emerald-800">Selesai Dinilai</span>
              <span class="p-1 rounded-md bg-emerald-200/70 text-emerald-800 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></span>
            </div>
            <div class="flex items-baseline gap-1 mt-1.5">
              <span class="text-xl font-extrabold text-emerald-700" id="statCountSelesai">0</span>
              <span class="text-[11px] text-emerald-600 font-bold" id="statPctSelesai">(0%)</span>
            </div>
          </button>

          <!-- Belum Dinilai -->
          <button type="button" onclick="filterGradingTableByStatus('belum')" id="statFilterBelum" class="grading-stat-card text-left p-3 rounded-2xl border transition cursor-pointer bg-blue-50/70 hover:bg-blue-100/80 border-blue-200 shadow-2xs" title="Klik untuk memfilter: Mahasiswa yang belum dinilai">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-bold text-blue-800">Belum Dinilai</span>
              <span class="p-1 rounded-md bg-blue-200/70 text-blue-800 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            </div>
            <div class="flex items-baseline gap-1 mt-1.5">
              <span class="text-xl font-extrabold text-blue-700" id="statCountBelum">0</span>
              <span class="text-[11px] text-blue-600 font-bold" id="statPctBelum">(0%)</span>
            </div>
          </button>

          <!-- Belum Kumpul -->
          <button type="button" onclick="filterGradingTableByStatus('belum_kumpul')" id="statFilterBelumKumpul" class="grading-stat-card text-left p-3 rounded-2xl border transition cursor-pointer bg-amber-50/70 hover:bg-amber-100/80 border-amber-200 shadow-2xs" title="Klik untuk memfilter: Mahasiswa belum mengumpulkan jawaban">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-bold text-amber-800">Belum Kumpul</span>
              <span class="p-1 rounded-md bg-amber-200/70 text-amber-800 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></span>
            </div>
            <div class="flex items-baseline gap-1 mt-1.5">
              <span class="text-xl font-extrabold text-amber-700" id="statCountBelumKumpul">0</span>
              <span class="text-[11px] text-amber-600 font-bold" id="statPctBelumKumpul">(0%)</span>
            </div>
          </button>

          <!-- Gagal / Perlu Reset -->
          <button type="button" onclick="filterGradingTableByStatus('gagal')" id="statFilterGagal" class="grading-stat-card text-left p-3 rounded-2xl border transition cursor-pointer bg-rose-50/70 hover:bg-rose-100/80 border-rose-200 shadow-2xs col-span-2 sm:col-span-1" title="Klik untuk memfilter: Penilaian yang gagal/error">
            <div class="flex items-center justify-between">
              <span class="text-[11px] font-bold text-rose-800">Gagal / Error</span>
              <span class="p-1 rounded-md bg-rose-200/70 text-rose-800 flex items-center justify-center"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></span>
            </div>
            <div class="flex items-baseline gap-1 mt-1.5">
              <span class="text-xl font-extrabold text-rose-700" id="statCountGagal">0</span>
              <span class="text-[11px] text-rose-600 font-bold" id="statPctGagal">(0%)</span>
            </div>
          </button>
        </div>

        <!-- Live Grading Progress Panel -->
        <div id="gradingProgressPanel" class="hidden bg-gradient-to-r from-slate-900 via-blue-950 to-indigo-950 text-white p-4 sm:p-5 rounded-2xl shadow-lg border border-blue-800/40 space-y-3.5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center space-x-3">
              <span class="spinner inline-block w-4 h-4 border-2 border-blue-400 border-t-white"></span>
              <div>
                <h4 class="font-bold text-sm text-white flex items-center gap-2">
                  <span id="progressPanelTitle">Sedang Mengevaluasi Jawaban Mahasiswa...</span>
                </h4>
                <p class="text-xs text-blue-200/90 mt-0.5" id="progressPanelSubtitle">Mempersiapkan antrean penilaian...</p>
              </div>
            </div>
            <div class="flex items-center space-x-2.5">
              <span id="progressPanelCounter" class="bg-blue-800/80 border border-blue-700/60 px-3 py-1 rounded-xl text-xs font-mono font-bold text-blue-200">0 / 0 (0%)</span>
              <button type="button" onclick="abortGradingQueue()" id="btnAbortGrading" class="px-3 py-1.5 bg-rose-500/80 hover:bg-rose-600 text-white font-semibold rounded-xl text-xs transition flex items-center gap-1 shadow-sm">
                <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 5h10v10H5V5z" clip-rule="evenodd"/></svg> <span>Hentikan</span>
              </button>
            </div>
          </div>

          <!-- Animated Progress Bar -->
          <div class="w-full bg-slate-800/90 h-3 rounded-full overflow-hidden p-0.5 border border-blue-700/40">
            <div id="progressBarFill" class="bg-gradient-to-r from-blue-400 via-sky-300 to-emerald-400 h-full rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
          </div>

          <!-- Live Log Accordion/Scrollable Box -->
          <div id="gradingProgressBox" class="bg-slate-950/90 text-sky-300 p-3 rounded-xl font-mono text-[11px] max-h-32 overflow-y-auto border border-blue-900/60 space-y-1"></div>
        </div>

        <!-- Completion Alert Banner -->
        <div id="gradingCompletionBanner" class="hidden p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm">
          <div class="flex items-center space-x-3.5">
            <div class="w-10 h-10 bg-emerald-100 text-emerald-700 rounded-xl flex items-center justify-center shrink-0"><svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <div>
              <h4 class="font-bold text-emerald-950 text-sm" id="completionTitle">Penilaian Selesai!</h4>
              <p class="text-xs text-emerald-800 mt-0.5" id="completionDesc">Seluruh mahasiswa dalam antrean telah berhasil dievaluasi dan dicatat ke sistem.</p>
            </div>
          </div>
          <div class="flex items-center space-x-2 shrink-0">
            <button type="button" onclick="switchTab('tab-reports')" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl shadow-sm transition flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg> <span>Buka Rekapitulasi</span>
            </button>
            <button type="button" onclick="document.getElementById('gradingCompletionBanner').classList.add('hidden')" class="px-2.5 py-2 text-slate-400 hover:text-slate-600 text-xs rounded-xl hover:bg-emerald-100 transition">
              <span class="flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg><span>Tutup</span></span>
            </button>
          </div>
        </div>

      </div>

      <!-- Grading Table -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-3.5 bg-slate-50/80 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
          <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-slate-800 uppercase tracking-wide">Daftar Mahasiswa</span>
            <span id="gradingFilterBadge" class="text-[11px] font-semibold text-blue-600 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-md">(Semua)</span>
          </div>
          <div class="w-full sm:w-72">
            <input type="text" id="gradingSearchInput" oninput="filterGradingTable()" placeholder="Cari NIM atau Nama mahasiswa..." class="w-full text-xs px-3.5 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 shadow-2xs">
          </div>
        </div>
        <div class="block sm:hidden text-[10px] text-slate-400 bg-slate-50 text-center py-1.5 border-b border-slate-100">
          <span class="inline-flex items-center gap-1"><span>Geser tabel ke kanan untuk melihat rincian &amp; status</span><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></span>
        </div>
        <div class="overflow-x-auto table-scroll-container">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 uppercase font-semibold border-b border-slate-200">
              <tr>
                <th class="px-4 py-3 w-10 text-center">
                  <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this.checked)" class="rounded text-blue-600 focus:ring-0">
                </th>
                <th class="px-4 py-3 w-28">NIM</th>
                <th class="px-4 py-3 w-48">Nama Mahasiswa</th>
                <th class="px-4 py-3 w-32">Berkas Jawaban</th>
                <th class="px-4 py-3 w-20 text-center">Nilai</th>
                <th class="px-4 py-3">Rincian Poin Soal</th>
                <th class="px-4 py-3 w-36 text-center">Integritas (Plagiasi & AI)</th>
                <th class="px-4 py-3 w-28 text-center">Status</th>
                <th class="px-4 py-3 w-20 text-center">Aksi</th>
              </tr>
            </thead>
            <tbody id="gradingTableBody" class="divide-y divide-slate-100">
              <tr>
                <td colspan="9" class="text-center py-12 text-slate-400">Pilih tugas terlebih dahulu untuk menampilkan daftar mahasiswa</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 5: REKAPITULASI & LAPORAN NILAI                      -->
    <!-- ======================================================== -->
    <section id="tab-reports" class="tab-content space-y-6">
      
      <!-- Header Actions -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div>
          <h2 class="text-base sm:text-lg font-bold text-slate-900">Rekapitulasi Nilai Kelas</h2>
          <p class="text-xs text-slate-500 mt-0.5">Laporan nilai akumulatif seluruh tugas, rata-rata, dan nilai akhir terbobot</p>
        </div>
        <div class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 w-full sm:w-auto">
          <button type="button" onclick="copyAllReportData()" id="btnCopyAllReport" class="bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-slate-700 font-semibold text-xs px-3 sm:px-3.5 py-2.5 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5" title="Salin seluruh tabel matriks nilai untuk dipaste ke Excel atau Word">
            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg> <span>Salin Semua</span>
          </button>
          <button type="button" onclick="downloadClassReportExcel()" id="btnDownloadExcel" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-3 sm:px-4 py-2.5 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Download file Excel (.xlsx)">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> <span id="btnDownloadExcelText">Download Excel</span>
          </button>
        </div>
      </div>

      <!-- Search & Helper Hint -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white px-4 py-3 rounded-2xl border border-slate-200 shadow-sm text-xs">
        <div class="flex items-center gap-2 flex-1 max-w-sm">
          <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" id="reportSearchInput" oninput="filterReportTable(this.value)" placeholder="Cari mahasiswa di rekapitulasi (NIM atau Nama)..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 focus:outline-none focus:border-blue-500 text-xs text-slate-800">
        </div>
        <div class="text-[11px] text-slate-500 flex items-center gap-2">
          <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-mono text-[10px]">Tips</span>
          <span>Klik judul kolom untuk urutkan (sort). Klik ikon salin pada judul kolom untuk menyalin nilai ke SIAKAD/Excel.</span>
        </div>
      </div>

      <!-- Report Matrix Table -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="block sm:hidden text-[10px] text-slate-400 bg-slate-50 text-center py-1.5 border-b border-slate-100">
          <span class="inline-flex items-center gap-1"><span>Geser tabel ke kanan untuk melihat seluruh nilai tugas</span><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></span>
        </div>
        <div class="overflow-x-auto table-scroll-container" id="reportTableContainer">
          <div class="text-center py-12 text-slate-400 text-sm">Memuat rekapitulasi nilai...</div>
        </div>
      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 6: KELOLA PENGGUNA (ADMIN ONLY)                      -->
    <!-- ======================================================== -->
    <section id="tab-users" class="tab-content space-y-6">
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
              <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg> <span>Manajemen Pengguna &amp; Dosen</span>
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">Kelola akun pengajar, hak akses peran sistem, dan status aktivasi</p>
          </div>
          <button onclick="openCreateUserModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-4 py-2.5 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 shrink-0">
            <span>+</span> <span>Tambah Pengguna Baru</span>
          </button>
        </div>

        <div id="usersAlert" class="hidden p-3 rounded-xl text-xs font-medium"></div>

        <!-- Users Table -->
        <div class="table-scroll-container border border-slate-200 rounded-xl overflow-hidden">
          <table class="w-full text-xs text-left">
            <thead class="bg-slate-50 text-slate-600 uppercase font-semibold border-b border-slate-200">
              <tr>
                <th class="p-3">Nama Lengkap &amp; Email</th>
                <th class="p-3">Peran (Role)</th>
                <th class="p-3">Status</th>
                <th class="p-3">Google Drive</th>
                <th class="p-3">Gemini API Key</th>
                <th class="p-3">Terdaftar</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody id="usersTableBody" class="divide-y divide-slate-100">
              <tr>
                <td colspan="7" class="p-6 text-center text-slate-400">Memuat data pengguna...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ======================================================== -->
    <!-- TAB 7: PENGATURAN                                        -->
    <!-- ======================================================== -->
    <section id="tab-settings" class="tab-content space-y-6 max-w-2xl mx-auto">
      
      <!-- 1. KARTU PROFIL PENGGUNA -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
        <div>
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> <span>Profil Akun Saya</span>
          </h2>
          <p class="text-xs text-slate-500 mt-0.5">Perbarui nama tampilan dan kata sandi akun Anda</p>
        </div>

        <div class="space-y-3.5 text-xs">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Nama Lengkap &amp; Gelar</label>
            <input type="text" id="myProfileNama" placeholder="Nama Lengkap" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Alamat Email</label>
            <input type="email" id="myProfileEmail" placeholder="nama@kampus.ac.id" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-slate-100">
            <div>
              <label class="block font-semibold text-slate-700 mb-1">Ubah Kata Sandi Baru</label>
              <input type="password" id="myProfilePassword" placeholder="Kosongkan jika tidak diubah" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
            </div>
            <div>
              <label class="block font-semibold text-slate-700 mb-1">Konfirmasi Kata Sandi Baru</label>
              <input type="password" id="myProfilePasswordConfirm" placeholder="Ulangi sandi baru" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
            </div>
          </div>

          <div id="profileAlert" class="hidden p-3 rounded-xl text-xs font-medium"></div>

          <div class="pt-2">
            <button onclick="saveMyProfile()" id="btnSaveProfile" class="w-full sm:w-auto px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-semibold transition">
              Simpan Perubahan Profil
            </button>
          </div>
        </div>
      </div>

      <!-- 2. KARTU PENGATURAN GEMINI AI (BYOK - PER PENGGUNA) -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-6">
        <div>
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg> <span>Pengaturan AI Gemini Saya</span>
          </h2>
          <p class="text-xs text-slate-500 mt-0.5">Konfigurasi Gemini API Key pribadi Anda (Bring Your Own Key) untuk evaluasi tugas mahasiswa</p>
        </div>

        <div class="space-y-4">
          <!-- API Key Input -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Gemini API Key Pribadi</label>
            <div class="flex gap-2">
              <input type="password" id="inputApiKey" placeholder="AIzaSy... / AQ.Ab8..." class="flex-1 text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-mono">
              <button type="button" onclick="toggleApiKeyInputVisibility()" class="px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-medium" title="Tampilkan/Sembunyikan API Key"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></button>
            </div>
            <p class="text-xs text-slate-400 mt-1.5">
              API key tersimpan aman di profil akun Anda. Dapatkan kuota gratis di <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-blue-600 underline font-medium">Google AI Studio</a>.
            </p>
          </div>

          <!-- Model Selector -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Pilihan Model Gemini</label>
            <select id="selectModelName" class="w-full text-sm px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-medium">
              <option value="gemini-3.5-flash-lite" selected>gemini-3.5-flash-lite (Rekomendasi Utama - Cepat & Kuota Luas)</option>
              <option value="gemini-2.5-flash">gemini-2.5-flash (Model Terbaru 2.5)</option>
              <option value="gemini-2.0-flash-lite">gemini-2.0-flash-lite (Ringan & Cepat)</option>
              <option value="gemini-1.5-flash">gemini-1.5-flash</option>
              <option value="gemini-1.5-pro">gemini-1.5-pro (Penalaran Kompleks)</option>
            </select>
          </div>

          <!-- Fitur Cerdas: Content-Based Matching Info -->
          <div class="p-4 bg-blue-50/70 rounded-xl border border-blue-100 flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <div class="text-xs">
              <p class="font-bold text-blue-900">Pencocokan Cerdas Isi Dokumen (Aktif)</p>
              <p class="text-slate-600 mt-1 leading-relaxed">
                Jika berkas mahasiswa di Google Drive tidak memuat NIM/Nama pada nama filenya (misalnya <code>Tugas1.pdf</code> atau <code>Laporan.docx</code>), sistem secara otomatis memeriksa teks atau cover di dalam berkas (Google Docs, Word DOCX, PDF, dan Teks/Kode) untuk mencocokkan identitas mahasiswa.
              </p>
            </div>
          </div>

          <!-- Test Connection Button -->
          <div>
            <button onclick="testApiConnection()" id="btnTestApi" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs py-2.5 rounded-xl transition flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> <span>Uji Koneksi API Key</span>
            </button>
            <div id="testApiAlert" class="hidden mt-2 p-3 rounded-xl text-xs"></div>
          </div>

          <!-- Save Settings -->
          <div class="pt-4 border-t border-slate-100">
            <button onclick="saveAppSettings()" id="btnSaveSettings" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm py-3 rounded-xl shadow-md transition flex items-center justify-center gap-2">
              <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> <span>Simpan Pengaturan AI</span>
            </button>
            <div id="saveSettingsAlert" class="hidden mt-2 p-3 rounded-xl text-xs"></div>
          </div>
        </div>
      </div>

      <!-- 3. KARTU INTEGRASI GOOGLE DRIVE PENGGUNA (1-KLIK) -->
      <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
              <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg> <span>Google Drive Saya</span>
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">Hubungkan akun Google Drive Anda dengan 1-klik untuk menyinkronkan berkas tugas mahasiswa langsung dari folder Drive Anda</p>
          </div>
          <div id="googleOAuthStatusBadge">
            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">Memeriksa status...</span>
          </div>
        </div>

        <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 text-xs text-slate-600 leading-relaxed">
          <strong>Praktis &amp; Aman:</strong> Anda cukup menekan tombol di bawah dan memilih akun Google kampus Anda. Sistem tidak memerlukan konfigurasi teknis apa pun dari Anda.
        </div>

        <div id="googleOAuthAlert" class="hidden p-3 rounded-xl text-xs"></div>

        <!-- Tombol Aksi OAuth Pengguna -->
        <div class="flex flex-col sm:flex-row items-center gap-2 pt-2">
          <button type="button" onclick="startGoogleOAuthFlow()" id="btnConnectGoogle" class="w-full sm:flex-1 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-semibold shadow-xs transition flex items-center justify-center gap-2">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg> <span>Hubungkan Google Drive Saya</span>
          </button>
          <button type="button" onclick="testGoogleDriveAccess()" id="btnTestGoogleDrive" class="hidden w-full sm:w-auto px-4 py-2.5 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 rounded-xl text-xs font-semibold transition flex items-center justify-center gap-1.5">
            <svg class="w-4 h-4 text-sky-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> <span>Uji Akses Drive</span>
          </button>
          <button type="button" onclick="disconnectGoogleOAuth()" id="btnDisconnectGoogle" class="hidden w-full sm:w-auto px-4 py-2.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-semibold transition flex items-center justify-center gap-1.5">
            <svg class="w-4 h-4 text-rose-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg> <span>Putuskan Sambungan</span>
          </button>
        </div>
      </div>

      <!-- 4. KARTU ADMIN: KREDENSIAL GLOBAL GOOGLE OAUTH INSTITUSI (KHUSUS ADMIN) -->
      <div id="adminGlobalOAuthConfig" class="hidden bg-white p-6 rounded-2xl border border-indigo-200/80 shadow-sm space-y-5">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-lg font-bold text-indigo-950 flex items-center gap-2">
              <svg class="w-5 h-5 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg> <span>Kredensial Global Google OAuth (Khusus Admin)</span>
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">Konfigurasi pusat Client ID &amp; Secret agar seluruh dosen dapat menghubungkan Google Drive dengan 1-klik</p>
          </div>
          <span class="bg-indigo-100 text-indigo-800 text-[10px] font-bold px-2.5 py-1 rounded-full uppercase">Admin Only</span>
        </div>

        <!-- Panduan Singkat Google Cloud Console -->
        <div class="p-4 bg-indigo-50/70 rounded-xl border border-indigo-100 space-y-2 text-xs">
          <p class="font-bold text-indigo-950 flex items-center gap-1.5">
            <svg class="w-4 h-4 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <span>Cara Menyiapkan OAuth Client ID Institusi di Google Cloud:</span>
          </p>
          <ol class="list-decimal list-inside space-y-1 text-slate-600 text-[11px] leading-relaxed">
            <li>Buka <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="text-blue-600 font-semibold underline">Google Cloud Console &gt; Credentials</a>.</li>
            <li>Pastikan <strong>Google Drive API</strong> sudah diaktifkan di menu <em>Enabled APIs &amp; Services</em>.</li>
            <li>Klik <strong>+ Create Credentials &gt; OAuth client ID</strong>, pilih Application type: <strong>Web application</strong>.</li>
            <li>Tambahkan <strong>Authorized redirect URIs</strong> persis seperti tautan di bawah ini:</li>
          </ol>
          <div class="flex items-center gap-2 pt-1">
            <input type="text" id="oauthRedirectUriText" readonly value="https://apm.suhadip.com/oauth_callback.php" class="flex-1 text-[11px] font-mono px-3 py-1.5 bg-white border border-indigo-200 rounded-lg text-slate-700 select-all">
            <button type="button" onclick="copyOauthRedirectUri()" id="btnCopyUri" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition shrink-0">
              <svg class="w-3.5 h-3.5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>Salin URI
            </button>
          </div>
        </div>

        <div class="space-y-4">
          <!-- Google Client ID -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Global Google OAuth Client ID</label>
            <input type="text" id="inputGoogleClientId" placeholder="Contoh: 123456789-abc.apps.googleusercontent.com" class="w-full text-xs px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-mono">
          </div>

          <!-- Google Client Secret -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Global Google OAuth Client Secret</label>
            <div class="flex gap-2">
              <input type="password" id="inputGoogleClientSecret" placeholder="GOCSPX-..." class="flex-1 text-xs px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-mono">
              <button type="button" onclick="toggleClientSecretVisibility()" class="px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-medium" title="Tampilkan/Sembunyikan Client Secret"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></button>
            </div>
          </div>

          <div id="adminOAuthAlert" class="hidden p-3 rounded-xl text-xs font-medium"></div>

          <div class="pt-2">
            <button type="button" onclick="saveAdminGlobalOAuth()" id="btnSaveAdminOAuth" class="w-full sm:w-auto px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-xs transition flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> <span>Simpan Kredensial Global Institusi</span>
            </button>
          </div>
        </div>
      </div>

      <!-- 5. KARTU ADMIN: ZONA PEMELIHARAAN & RESET DATABASE (KHUSUS ADMIN) -->
      <div id="adminResetDbSection" class="hidden bg-white p-6 rounded-2xl border border-rose-200 shadow-sm space-y-4">
        <h4 class="font-bold text-slate-800 text-xs uppercase tracking-wider text-rose-600 flex items-center gap-1.5">
          <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> <span>Pemeliharaan &amp; Reset Basis Data (Khusus Admin)</span>
        </h4>
        <div class="p-3 bg-rose-50/80 border border-rose-200 rounded-xl text-xs text-rose-900 leading-relaxed">
          Jika basis data lama bermasalah, Administrator dapat mengosongkan dan menginisialisasi ulang database dengan struktur baru yang bersih.
        </div>
        <button onclick="confirmResetDatabase()" id="btnResetDatabase" class="w-full bg-white hover:bg-rose-50 text-rose-700 border border-rose-300 font-bold text-xs py-2.5 rounded-xl shadow-xs transition flex items-center justify-center gap-2">
          <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> <span>Reset / Mulai Bersih Database</span>
        </button>
        <div id="resetDbAlert" class="hidden mt-2 p-3 rounded-xl text-xs font-medium"></div>
      </div>

    </section>

    <!-- ======================================================== -->
    <!-- TAB 8: PANDUAN PENGGUNAAN LENGKAP                        -->
    <!-- ======================================================== -->
    <section id="tab-guide" class="tab-content space-y-6">
      
      <!-- Guide Hero Banner -->
      <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-blue-950 text-white p-5 sm:p-7 rounded-2xl shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-indigo-900/50">
        <div>
          <div class="inline-flex items-center gap-1.5 bg-indigo-500/20 text-indigo-300 border border-indigo-400/30 text-[11px] px-3 py-1 rounded-full font-semibold mb-2">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
            <span>Pusat Bantuan &amp; Dokumentasi Resmi</span>
          </div>
          <h2 class="text-xl sm:text-2xl font-bold tracking-tight">Panduan Lengkap Asisten Penilaian Mandiri (APM)</h2>
          <p class="text-slate-300 text-xs sm:text-sm mt-1 max-w-2xl leading-relaxed">
            Petunjuk operasional praktis langkah demi langkah: mulai dari persiapan akun &amp; Gemini API, manajemen kelas &amp; mahasiswa, sinkronisasi Google Drive, hingga penilaian cerdas multimodal.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
          <button onclick="switchTab('tab-settings')" class="bg-white/10 hover:bg-white/20 text-white font-semibold text-xs px-3.5 py-2 rounded-xl border border-white/20 transition flex items-center gap-1.5 cursor-pointer">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
            <span>Pengaturan</span>
          </button>
          <button onclick="switchTab('tab-classes')" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-3.5 py-2 rounded-xl shadow-xs transition flex items-center gap-1.5 cursor-pointer">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            <span>Kelola Kelas</span>
          </button>
        </div>
      </div>

      <!-- Guide Sub-Navigation Pills -->
      <div class="bg-white p-2 rounded-2xl border border-slate-200 shadow-xs flex items-center space-x-1.5 overflow-x-auto no-scrollbar text-xs font-semibold">
        <button type="button" onclick="switchGuideTopic('guide-api')" id="btn-guide-api" class="guide-nav-btn px-3.5 py-2 rounded-xl text-blue-700 bg-blue-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer active">
          <svg class="w-4 h-4 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> <span>1. Persiapan &amp; API Key</span>
        </button>
        <button type="button" onclick="switchGuideTopic('guide-courses')" id="btn-guide-courses" class="guide-nav-btn px-3.5 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg> <span>2. Mata Kuliah</span>
        </button>
        <button type="button" onclick="switchGuideTopic('guide-classes')" id="btn-guide-classes" class="guide-nav-btn px-3.5 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg> <span>3. Kelola Kelas &amp; Mahasiswa</span>
        </button>
        <button type="button" onclick="switchGuideTopic('guide-grading')" id="btn-guide-grading" class="guide-nav-btn px-3.5 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138z"/></svg> <span>4. Cara Memberikan Penilaian</span>
        </button>
        <button type="button" onclick="switchGuideTopic('guide-reports')" id="btn-guide-reports" class="guide-nav-btn px-3.5 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg> <span>5. Rekapitulasi &amp; Ekspor</span>
        </button>
        <button type="button" onclick="switchGuideTopic('guide-faq')" id="btn-guide-faq" class="guide-nav-btn px-3.5 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition whitespace-nowrap shrink-0 flex items-center gap-1.5 cursor-pointer">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <span>6. Tanya Jawab (FAQ)</span>
        </button>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 1: PERSIAPAN & API KEY               -->
      <!-- ========================================== -->
      <div id="guide-api" class="guide-topic-section space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 1</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> <span>Persiapan Akun, Gemini API Key &amp; Google Drive</span>
              </h3>
            </div>
            <span class="bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2.5 py-1 rounded-lg border border-emerald-200">100% Gratis</span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Langkah A: Dapatkan Gemini API Key -->
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-3">
              <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
                <span class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs">1</span>
                <span>Cara Mendapatkan Gemini API Key Pribadi</span>
              </div>
              <p class="text-xs text-slate-600 leading-relaxed">
                Setiap dosen menggunakan kuota gratisnya masing-masing dari Google AI Studio tanpa biaya langganan:
              </p>
              <ol class="list-decimal list-inside text-xs text-slate-700 space-y-1.5 pl-1 leading-relaxed">
                <li>Buka situs resmi: <a href="https://aistudio.google.com/" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-semibold">aistudio.google.com ↗</a></li>
                <li>Masuk menggunakan <strong>Akun Google</strong> Anda.</li>
                <li>Pilih menu <strong>"Get API key"</strong> di panel sebelah kiri.</li>
                <li>Klik tombol biru <strong>"Create API key"</strong>.</li>
                <li>Salin kode kunci API yang dihasilkan (diawali teks <code class="bg-slate-200 px-1 py-0.5 rounded text-[11px]">AIzaSy...</code>).</li>
                <li>Buka tab <button type="button" onclick="switchTab('tab-settings')" class="text-blue-600 underline font-semibold cursor-pointer">Pengaturan</button> &rarr; tempel ke kolom <strong>Gemini API Key Pribadi</strong> &rarr; klik <strong>Simpan API Key</strong>.</li>
                <li>Klik tombol <strong>Uji Koneksi</strong> untuk memastikan status aktif.</li>
              </ol>
              <div class="p-2.5 bg-blue-50 border border-blue-200/60 rounded-xl text-[11px] text-blue-900 leading-relaxed">
                <strong>Rekomendasi Model:</strong> Pilih <strong><code class="font-bold">gemini-3.5-flash-lite</code></strong>. Model ini sangat cepat, hemat kuota gratis (hingga 1.500 request/hari), dan akurat membaca berkas PDF/gambar tugas.
              </div>
            </div>

            <!-- Langkah B: Hubungkan Google Drive -->
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-3">
              <div class="flex items-center gap-2 text-slate-900 font-bold text-sm">
                <span class="w-6 h-6 rounded-full bg-emerald-600 text-white flex items-center justify-center text-xs">2</span>
                <span>1-Klik Hubungkan Akun Google Drive (OAuth 2.0)</span>
              </div>
              <p class="text-xs text-slate-600 leading-relaxed">
                Menghubungkan Drive memungkinkan sistem mengunduh berkas jawaban mahasiswa secara otomatis seperti Google Apps Script:
              </p>
              <ol class="list-decimal list-inside text-xs text-slate-700 space-y-1.5 pl-1 leading-relaxed">
                <li>Buka menu <button type="button" onclick="switchTab('tab-settings')" class="text-blue-600 underline font-semibold cursor-pointer">Pengaturan</button>.</li>
                <li>Gulir ke kartu <strong>Integrasi Akun Google Drive (OAuth 2.0)</strong>.</li>
                <li>Klik tombol hijau <strong>"Hubungkan Google Drive Saya"</strong>.</li>
                <li>Pilih akun Google tempat Anda menyimpan folder tugas mahasiswa.</li>
                <li>Klik <strong>Lanjutkan / Izinkan</strong> pada halaman persetujuan Google.</li>
                <li>Selesai! Status akan berubah menjadi <span class="text-emerald-700 font-bold">Terhubung: email_anda@kampus.ac.id</span>.</li>
              </ol>
              <div class="p-2.5 bg-emerald-50 border border-emerald-200/60 rounded-xl text-[11px] text-emerald-900 leading-relaxed">
                <strong>Privasi Aman:</strong> Sistem hanya meminta izin baca (<i>drive.readonly</i>) untuk mengambil berkas tugas mahasiswa Anda. Dosen lain tidak dapat mengakses folder Drive Anda.
              </div>
            </div>
          </div>

          <!-- Note Khusus Administrator -->
          <div class="p-4 bg-purple-50/70 border border-purple-200 rounded-xl text-xs space-y-2">
            <div class="flex items-center gap-2 text-purple-950 font-bold">
              <svg class="w-4 h-4 text-purple-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138z"/></svg> <span>Catatan Khusus Administrator Sistem</span>
            </div>
            <p class="text-purple-900 leading-relaxed">
              Dosen lain <strong>TIDAK PERLU</strong> membuka Google Cloud Console. Administrator cukup sekali membuat <strong>OAuth 2.0 Web Client ID</strong> di Google Cloud Console, lalu memasukkan <i>Client ID</i> dan <i>Client Secret</i> di tab Pengaturan &rarr; <i>Pengaturan Global OAuth</i>. Seluruh dosen tinggal menekan 1 tombol untuk menghubungkan Drive mereka masing-masing.
            </p>
          </div>
        </div>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 2: MANAJEMEN MATA KULIAH (MK)        -->
      <!-- ========================================== -->
      <div id="guide-courses" class="guide-topic-section hidden space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 2</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg> <span>Manajemen Mata Kuliah (MK) Induk</span>
              </h3>
            </div>
            <button type="button" onclick="switchTab('tab-classes')" class="text-xs text-blue-600 hover:underline font-semibold cursor-pointer">Buka Kelola MK &rarr;</button>
          </div>

          <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-3 text-xs text-slate-700">
            <h4 class="font-bold text-slate-900 text-sm">Konsep Mata Kuliah sebagai Payung Utama Kurikulum</h4>
            <p class="text-slate-600 leading-relaxed">
              Di sistem APM, <strong>Mata Kuliah</strong> berfungsi sebagai entitas induk yang menaungi beberapa kelas paralel (misalnya Kelas A, B, dan C) serta kumpulan tugas-tugas terpusat. Dengan konsep ini, Anda cukup membuat soal dan rubrik tugas 1 kali saja di tingkat Mata Kuliah, dan tugas tersebut otomatis dapat dinilai untuk seluruh kelas paralel.
            </p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2">
              <div class="p-3 bg-white rounded-xl border border-slate-200 space-y-1">
                <span class="font-bold text-blue-600">Langkah 1:</span>
                <p class="text-slate-600">Buka menu <strong>Kelola MK &amp; Kelas</strong> &rarr; klik tombol <strong>"+ Tambah MK Baru"</strong>.</p>
              </div>
              <div class="p-3 bg-white rounded-xl border border-slate-200 space-y-1">
                <span class="font-bold text-blue-600">Langkah 2:</span>
                <p class="text-slate-600">Isi <strong>Nama Mata Kuliah</strong> (contoh: <i>Analisis &amp; Perancangan Sistem</i>), <strong>Kode MK</strong> (<i>APSI101</i>), dan <strong>Semester</strong>.</p>
              </div>
              <div class="p-3 bg-white rounded-xl border border-slate-200 space-y-1">
                <span class="font-bold text-blue-600">Langkah 3:</span>
                <p class="text-slate-600">Klik <strong>Simpan Mata Kuliah</strong>. Sekarang Anda siap menambahkan kelas-kelas paralel di bawah mata kuliah ini.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 3: KELOLA KELAS DAN MAHASISWA        -->
      <!-- ========================================== -->
      <div id="guide-classes" class="guide-topic-section hidden space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 3</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg> <span>Kelola Kelas dan Data Mahasiswa</span>
              </h3>
            </div>
            <button type="button" onclick="switchTab('tab-classes')" class="text-xs text-blue-600 hover:underline font-semibold cursor-pointer">Buka Kelola Kelas &rarr;</button>
          </div>

          <div class="space-y-4 text-xs text-slate-700">
            <!-- Langkah 1: Buat Kelas Paralel -->
            <div class="flex items-start gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200/80">
              <span class="w-7 h-7 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">1</span>
              <div class="space-y-1.5 w-full">
                <h4 class="font-bold text-slate-900 text-sm">Membuat Kelas Paralel (A, B, C, dst.)</h4>
                <p class="text-slate-600 leading-relaxed">
                  Pada tab <strong>Kelola MK &amp; Kelas</strong>, di bawah kartu Mata Kuliah yang sesuai, klik tombol <strong>"+ Tambah Kelas Baru"</strong>. Masukkan nama kelas (contoh: <code class="bg-slate-200 px-1.5 py-0.5 rounded font-bold">Kelas A</code> atau <code class="bg-slate-200 px-1.5 py-0.5 rounded font-bold">Kelas B (Sore)</code>). Setiap kelas akan memiliki daftar mahasiswa dan rekapitulasi nilai tersendiri.
                </p>
              </div>
            </div>

            <!-- Langkah 2: Import Cepat Mahasiswa Masal -->
            <div class="flex items-start gap-3 p-4 bg-blue-50/70 border border-blue-200/80 rounded-xl">
              <span class="w-7 h-7 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">2</span>
              <div class="space-y-2.5 w-full">
                <div class="flex items-center justify-between">
                  <h4 class="font-bold text-indigo-950 text-sm">Import Cepat Mahasiswa Masal (Salin-Tempel dari Excel / SIAKAD)</h4>
                  <span class="bg-indigo-100 text-indigo-800 text-[10px] font-bold px-2 py-0.5 rounded-full">Sangat Praktis</span>
                </div>
                <p class="text-indigo-950 leading-relaxed">
                  Anda <strong>TIDAK PERLU</strong> mengetik nama mahasiswa satu per satu! Cukup salin dua kolom (NIM dan Nama) langsung dari portal akademik kampus atau Microsoft Excel:
                </p>
                <ol class="list-decimal list-inside space-y-1.5 text-indigo-900 pl-1 leading-relaxed">
                  <li>Buka kelas yang dituju &rarr; klik tombol ungu <strong>"Tambah Masal"</strong>.</li>
                  <li>Buka file Excel daftar hadir atau halaman SIAKAD &rarr; blok kolom <strong>NIM</strong> dan <strong>Nama</strong> &rarr; tekan <kbd class="bg-white px-1.5 py-0.5 rounded border border-indigo-200 font-mono font-bold">Ctrl + C</kbd> (Salin).</li>
                  <li>Kembali ke kotak dialog sistem &rarr; tekan <kbd class="bg-white px-1.5 py-0.5 rounded border border-indigo-200 font-mono font-bold">Ctrl + V</kbd> (Tempel).</li>
                  <li>Klik tombol <strong>"Simpan Mahasiswa"</strong>. Puluhan mahasiswa langsung masuk dalam 3 detik!</li>
                </ol>
                <div class="bg-slate-900 text-slate-200 p-3 rounded-xl font-mono text-[11px] space-y-1">
                  <div class="text-slate-400 font-sans text-[10px] uppercase font-bold tracking-wider">Format yang Didukung Otomatis:</div>
                  <div>2488010001	Najwa Ramadhan</div>
                  <div>2488010011	Tanu Hasyim</div>
                  <div>2488010012, Faiz Alfaresi</div>
                </div>
                <p class="text-[11px] text-slate-500">
                  Sistem otomatis mendeteksi pemisah tabulasi Excel, tanda koma, titik koma, dan spasi, serta otomatis menyaring duplikasi data.
                </p>
              </div>
            </div>

            <!-- Langkah 3: Tambah Manual & Manajemen Mahasiswa -->
            <div class="flex items-start gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200/80">
              <span class="w-7 h-7 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-xs shrink-0 mt-0.5">3</span>
              <div class="space-y-1.5 w-full">
                <h4 class="font-bold text-slate-900 text-sm">Tambah Manual &amp; Perubahan Data Mahasiswa</h4>
                <p class="text-slate-600 leading-relaxed">
                  Jika ada mahasiswa susulan atau perbaikan nama, klik tombol <strong>"+ Tambah Mahasiswa"</strong> untuk menambah 1 orang. Untuk mengedit atau menghapus data mahasiswa yang salah, klik tombol aksi <strong>Edit</strong> atau <strong>Hapus</strong> pada baris nama mahasiswa yang bersangkutan.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 4: CARA MEMBERIKAN PENILAIAN         -->
      <!-- ========================================== -->
      <div id="guide-grading" class="guide-topic-section hidden space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 4</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138z"/></svg> <span>Cara Memberikan Penilaian Tugas (Panduan Lengkap)</span>
              </h3>
            </div>
            <button type="button" onclick="switchTab('tab-grading')" class="text-xs text-blue-600 hover:underline font-semibold cursor-pointer">Buka Penilaian &rarr;</button>
          </div>

          <!-- Flowchart Visual -->
          <div class="p-4 bg-slate-900 text-slate-100 rounded-xl space-y-3">
            <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Alur Kerja 4 Langkah Penilaian:</span>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 text-center text-xs">
              <div class="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                <div class="w-6 h-6 mx-auto mb-1 flex items-center justify-center text-blue-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
                <div class="font-bold text-white">1. Siapkan Tugas</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Buat soal &amp; rubrik AI</div>
              </div>
              <div class="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                <div class="w-6 h-6 mx-auto mb-1 flex items-center justify-center text-sky-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></div>
                <div class="font-bold text-white">2. Sinkron Drive</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Unduh berkas jawaban</div>
              </div>
              <div class="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                <div class="text-lg mb-1">▶️</div>
                <div class="font-bold text-white">3. Nilai Otomatis</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Antrean AI mengevaluasi</div>
              </div>
              <div class="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                <div class="w-6 h-6 mx-auto mb-1 flex items-center justify-center text-indigo-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></div>
                <div class="font-bold text-white">4. Tinjau &amp; Koreksi</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Otoritas dosen penuh</div>
              </div>
            </div>
          </div>

          <div class="space-y-4 text-xs text-slate-700">
            <!-- Tahap 1: Pembuatan Tugas & Rubrik Otomatis AI -->
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]">A</span>
                <span>Langkah 1: Menyiapkan Tugas &amp; Ekstraksi Rubrik Otomatis oleh Gemini AI</span>
              </h4>
              <p class="text-slate-600 leading-relaxed">
                Buka tab <strong>Kelola Tugas</strong> &rarr; klik <strong>"+ Buat Tugas Baru"</strong>. Tentukan judul tugas dan bobot persentase nilai akhir (misal <i>25.00%</i>). Unggah lembar soal tugas (format PDF/DOCX) lalu klik tombol:
              </p>
              <div class="py-1">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white rounded-xl font-bold text-xs shadow-xs">
                  <svg class="w-4 h-4 text-purple-600 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg> <span>Ekstrak dari Soal</span>
                </span>
              </div>
              <p class="text-slate-600 leading-relaxed">
                Gemini AI akan membedah butir pertanyaan pada lembar soal dan otomatis merumuskan butir-butir rubrik penilaian objektif dengan skor total 100. Masukkan link atau ID Folder Google Drive tempat mahasiswa mengumpulkan tugas &rarr; klik <strong>Simpan Tugas</strong>.
              </p>
            </div>

            <!-- Tahap 2: Sinkronisasi Berkas Jawaban -->
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]">B</span>
                <span>Langkah 2: Sinkronisasi Berkas Mahasiswa</span>
              </h4>
              <p class="text-slate-600 leading-relaxed">
                Sebelum memulai penilaian, Anda perlu mengunduh berkas jawaban mahasiswa ke server dengan salah satu cara berikut:
              </p>
              <ul class="list-disc list-inside space-y-1.5 pl-1 text-slate-700">
                <li><strong>Dari Tab Kelola Tugas (Rekomendasi):</strong> Klik tombol <strong>"Sinkron Drive"</strong> pada kartu tugas. Berkas otomatis disebarkan ke semua kelas paralel di bawah mata kuliah tersebut.</li>
                <li><strong>Dari Tab Penilaian:</strong> Pilih tugas &rarr; klik tombol <strong>"Sinkron Drive"</strong> di bilah tombol atas.</li>
                <li><strong>Tanpa Google Drive (Upload ZIP):</strong> Jika tugas dikumpulkan via flashdisk/email, klik tombol <strong>"Upload ZIP Tugas"</strong> untuk mengunggah arsip berkas sekaligus.</li>
              </ul>
            </div>

            <!-- Tahap 3: Menjalankan Penilaian Otomatis -->
            <div class="p-4 bg-blue-50/70 border border-blue-200/80 rounded-xl space-y-2.5">
              <h4 class="font-bold text-blue-950 text-sm flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]">C</span>
                <span>Langkah 3: Menjalankan Penilaian Otomatis Berbasis AI</span>
              </h4>
              <p class="text-blue-900 leading-relaxed">
                Pilih tugas yang ingin dinilai pada tab <strong>Penilaian</strong>. Anda memiliki 3 mode penilaian yang fleksibel:
              </p>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                <div class="p-3 bg-white rounded-xl border border-blue-200 space-y-1">
                  <span class="font-bold text-emerald-700">1. Nilai Semua</span>
                  <p class="text-slate-600">Menilai seluruh mahasiswa satu kelas secara otomatis antrean (*queue*) dengan animasi *live progress bar*.</p>
                </div>
                <div class="p-3 bg-white rounded-xl border border-blue-200 space-y-1">
                  <span class="font-bold text-purple-700">2. Nilai Pilihan</span>
                  <p class="text-slate-600">Centang kotak beberapa mahasiswa tertentu, lalu klik tombol <i>Pilihan (N)</i> untuk menilai hanya yang ditandai.</p>
                </div>
                <div class="p-3 bg-white rounded-xl border border-blue-200 space-y-1">
                  <span class="font-bold text-blue-700">3. Penilaian Satuan</span>
                  <p class="text-slate-600">Klik tombol <i>Detail</i> pada baris mahasiswa untuk menilai atau mengevaluasi ulang secara individual.</p>
                </div>
              </div>
            </div>

            <!-- Tahap 4: Memahami Status & 1-Klik Buka Berkas -->
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]">D</span>
                <span>Langkah 4: Memahami Status Penilaian &amp; Fitur 1-Klik Buka Berkas</span>
              </h4>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                <div class="p-2.5 bg-white rounded-xl border border-emerald-200">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Selesai</span>
                  <p class="text-[11px] text-slate-600 mt-1">Tugas selesai dievaluasi AI. Nilai total dan rincian poin per butir soal otomatis tercatat.</p>
                </div>
                <div class="p-2.5 bg-white rounded-xl border border-amber-200">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Belum Kumpul</span>
                  <p class="text-[11px] text-slate-600 mt-1">Mahasiswa tidak mengumpulkan berkas atau nama file tidak memuat identitas. Nilai tercatat <code class="font-bold">-</code>.</p>
                </div>
                <div class="p-2.5 bg-white rounded-xl border border-rose-200">
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">Gagal</span>
                  <p class="text-[11px] text-slate-600 mt-1">Kendala kuota API/jaringan. Klik tombol <i>Reset Gagal</i> lalu klik <i>Nilai Semua</i> lagi.</p>
                </div>
              </div>
              <div class="p-3 bg-indigo-50 border border-indigo-200 rounded-xl text-indigo-900 mt-2 space-y-1">
                <strong>Fitur 1-Klik Buka Berkas:</strong> Pada kolom <i>Berkas Jawaban</i>, klik nama file (misal: <code class="bg-indigo-100 px-1 py-0.5 rounded font-bold">2488010001_Tugas1.pdf ↗</code>) untuk membuka dan membaca langsung dokumen asli mahasiswa di Google Drive / peramban.
              </div>
            </div>

            <!-- Tahap 5: Koreksi Nilai Manual Dosen -->
            <div class="p-4 bg-emerald-50/70 border border-emerald-200/80 rounded-xl space-y-2">
              <h4 class="font-bold text-emerald-950 text-sm flex items-center gap-2">
                <span class="w-5 h-5 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px]">E</span>
                <span>Langkah 5: Otoritas Dosen &amp; Koreksi Manual Nilai</span>
              </h4>
              <p class="text-emerald-900 leading-relaxed">
                AI bertindak sebagai asisten pemeriksa awal. <strong>Dosen memiliki kendali mutlak atas nilai mahasiswa</strong>. Jika Anda ingin merevisi nilai, klik tombol <strong>Detail</strong> pada baris mahasiswa &rarr; ubah angka nilai atau deskripsi evaluasi sesuka Anda &rarr; klik <strong>"Simpan Perubahan Nilai"</strong>.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 5: REKAPITULASI & EKSPOR             -->
      <!-- ========================================== -->
      <div id="guide-reports" class="guide-topic-section hidden space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 5</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg> <span>Rekapitulasi Nilai Akhir &amp; Ekspor Laporan Resmi</span>
              </h3>
            </div>
            <button type="button" onclick="switchTab('tab-reports')" class="text-xs text-blue-600 hover:underline font-semibold cursor-pointer">Buka Rekapitulasi &rarr;</button>
          </div>

          <div class="space-y-4 text-xs text-slate-700">
            <div class="p-4 bg-slate-50 rounded-xl border border-slate-200/80 space-y-2">
              <h4 class="font-bold text-slate-900 text-sm">Perhitungan Nilai Akhir Otomatis Berdasarkan Bobot</h4>
              <p class="text-slate-600 leading-relaxed">
                Di tab <strong>Rekapitulasi</strong>, sistem secara otomatis mengagregasi nilai seluruh tugas mahasiswa sesuai bobot persentase masing-masing (misal Tugas 1: 25%, Tugas 2: 25%, UAS: 50%). Nilai Akhir dan Konversi Nilai Huruf (A, B+, B, C+, C, D, E) langsung terkalkulasi secara akurat.
              </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="p-4 bg-emerald-50/70 border border-emerald-200 rounded-xl space-y-2">
                <h4 class="font-bold text-emerald-950 text-sm flex items-center gap-1.5">
                  <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> <span>Ekspor Data ke File Excel</span>
                </h4>
                <p class="text-emerald-900 leading-relaxed">
                  Klik tombol <strong>"Ekspor Excel"</strong> untuk mengunduh rekapitulasi nilai lengkap seluruh tugas yang dapat langsung diimpor ke sistem SIAKAD kampus Anda.
                </p>
              </div>

              <div class="p-4 bg-purple-50/70 border border-purple-200 rounded-xl space-y-2">
                <h4 class="font-bold text-purple-950 text-sm flex items-center gap-1.5">
                  <svg class="w-4 h-4 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg> <span>Cetak Berita Acara &amp; Simpan PDF</span>
                </h4>
                <p class="text-purple-900 leading-relaxed">
                  Klik tombol <strong>"Cetak / Simpan PDF"</strong> untuk mencetak lembar rekapitulasi nilai resmi yang telah diformat rapi standar akademik, lengkap dengan kolom tanda tangan dosen pengampu.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================== -->
      <!-- TOPIK 6: FAQ & TROUBLESHOOTING             -->
      <!-- ========================================== -->
      <div id="guide-faq" class="guide-topic-section hidden space-y-4">
        <div class="bg-white p-5 sm:p-6 rounded-2xl border border-slate-200 shadow-sm space-y-5">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
              <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Materi 6</span>
              <h3 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-2 mt-0.5">
                <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <span>Tanya Jawab (FAQ) &amp; Solusi Kendala Umum</span>
              </h3>
            </div>
            <span class="text-xs text-slate-400">Troubleshooting</span>
          </div>

          <div class="space-y-3 text-xs">
            <!-- FAQ 1 -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="text-blue-600">Q:</span> Bagaimana format penamaan berkas mahasiswa yang dianjurkan?
              </h4>
              <p class="text-slate-600 leading-relaxed pl-5">
                <strong>A:</strong> Mahasiswa cukup mencantumkan <strong>NIM</strong> pada nama berkasnya (contoh: <code class="bg-slate-200 px-1 py-0.5 rounded">2488010001_Tugas1.pdf</code> atau <code class="bg-slate-200 px-1 py-0.5 rounded">Tugas Mandiri (2488010001).pdf</code>). Jika nama berkas tidak memuat NIM, sistem akan mencoba mencocokkan berdasarkan Nama Mahasiswa atau teks di dalam dokumen.
              </p>
            </div>

            <!-- FAQ 2 -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="text-blue-600">Q:</span> Muncul pesan error "Batas kuota Gemini API tercapai (Rate Limit 429)" saat menilai kelas besar?
              </h4>
              <p class="text-slate-600 leading-relaxed pl-5">
                <strong>A:</strong> Google AI Studio membatasi kecepatan panggilan gratis sekitar 15 request per menit (RPM). Jika Anda menilai kelas besar (>20 mahasiswa), cukup tunggu sekitar 60 detik lalu klik tombol <strong>"Reset Gagal"</strong> dan klik <strong>"Nilai Semua"</strong> lagi. Mahasiswa yang sudah dinilai tidak akan dinilai ulang.
              </p>
            </div>

            <!-- FAQ 3 -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="text-blue-600">Q:</span> Apakah berkas Google Docs (.gdoc) mahasiswa dapat dibaca?
              </h4>
              <p class="text-slate-600 leading-relaxed pl-5">
                <strong>A:</strong> Ya! Saat Anda menekan tombol <i>Sinkron Drive</i>, sistem secara otomatis mengekspor berkas Google Docs dan Google Sheets mahasiswa menjadi format PDF sehingga Gemini AI dapat mengevaluasinya secara sempurna.
              </p>
            </div>

            <!-- FAQ 4 -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1">
              <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                <span class="text-blue-600">Q:</span> Apakah dosen tetap bisa mengoreksi nilai yang diberikan oleh AI?
              </h4>
              <p class="text-slate-600 leading-relaxed pl-5">
                <strong>A:</strong> Tentu saja. Dosen memiliki otoritas penuh. Anda cukup klik tombol <strong>Detail</strong> pada baris mahasiswa, lalu Anda bebas mengubah angka nilai, merevisi rincian poin, atau menambahkan catatan khusus dosen, kemudian klik <strong>Simpan Perubahan Nilai</strong>.
              </p>
            </div>
          </div>
        </div>
      </div>

    </section>

  </main>
  </div> <!-- end #appWrapper -->

  <!-- ======================================================== -->
  <!-- MODAL DIALOGS                                            -->
  <!-- ======================================================== -->

  <!-- MODAL TAMBAH/EDIT PENGGUNA (ADMIN ONLY) -->
  <div id="userModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-slate-900 text-base" id="userModalTitle">Tambah Pengguna Baru</h3>
        <button onclick="closeModal('userModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <input type="hidden" id="modalUserId">
      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Nama Lengkap &amp; Gelar</label>
          <input type="text" id="modalUserNama" placeholder="Contoh: Dr. Budi Santoso, M.Kom" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Alamat Email (Login)</label>
          <input type="email" id="modalUserEmail" placeholder="Contoh: budi@kampus.ac.id" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Kata Sandi (Password)</label>
          <input type="password" id="modalUserPassword" placeholder="Minimal 6 karakter (kosongkan jika tidak diubah)" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Peran (Role)</label>
            <select id="modalUserRole" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-semibold">
              <option value="dosen">Dosen</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Status Akun</label>
            <select id="modalUserStatus" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-semibold">
              <option value="aktif">Aktif</option>
              <option value="nonaktif">Nonaktif</option>
            </select>
          </div>
        </div>
      </div>

      <div id="userModalAlert" class="hidden p-2 rounded-lg text-xs font-medium"></div>

      <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <button onclick="closeModal('userModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitSaveUser()" id="btnSubmitUser" class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold text-xs">Simpan Pengguna</button>
      </div>
    </div>
  </div>

  <!-- 0. MODAL TAMBAH/EDIT MATA KULIAH -->
  <div id="courseModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-slate-900 text-base" id="courseModalTitle">Tambah Mata Kuliah Baru</h3>
        <button onclick="closeModal('courseModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <input type="hidden" id="modalCourseId">
      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Nama Mata Kuliah</label>
          <input type="text" id="modalCourseName" placeholder="Contoh: Pemrograman Web" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Kode Mata Kuliah (Opsional)</label>
          <input type="text" id="modalCourseKode" placeholder="Contoh: IF210" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Semester / Tahun Akademik</label>
          <input type="text" id="modalCourseSemester" placeholder="Contoh: Ganjil 2026/2027" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button onclick="closeModal('courseModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitSaveCourse()" id="btnSubmitCourse" class="px-4 py-2 bg-purple-600 text-white rounded-xl font-semibold text-xs">Simpan Mata Kuliah</button>
      </div>
    </div>
  </div>

  <!-- 1. MODAL TAMBAH/EDIT KELAS -->
  <div id="classModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-slate-900 text-base" id="classModalTitle">Tambah Kelas Baru</h3>
        <button onclick="closeModal('classModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <input type="hidden" id="modalClassId">
      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Mata Kuliah Induk</label>
          <select id="modalClassCourseSelect" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-semibold text-slate-800">
            <!-- options populated via JS -->
          </select>
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Nama Kelas</label>
          <input type="text" id="modalClassName" placeholder="Contoh: Kelas A" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Semester / Tahun Akademik (Opsional)</label>
          <input type="text" id="modalClassSemester" placeholder="Contoh: Ganjil 2026/2027" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button onclick="closeModal('classModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitSaveClass()" id="btnSubmitClass" class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold text-xs">Simpan Kelas</button>
      </div>
    </div>
  </div>

  <!-- 2. MODAL TAMBAH MAHASISWA TUNGGAL -->
  <div id="studentModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-slate-900 text-base">Tambah Mahasiswa</h3>
        <button onclick="closeModal('studentModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <div class="space-y-3 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">NIM Mahasiswa</label>
          <input type="text" id="modalStudentNim" placeholder="Contoh: 2024001" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Nama Mahasiswa</label>
          <input type="text" id="modalStudentName" placeholder="Contoh: Budi Santoso" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button onclick="closeModal('studentModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitSaveStudent()" class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold text-xs">Simpan</button>
      </div>
    </div>
  </div>

  <!-- 3. MODAL IMPOR MAHASISWA MASSAL -->
  <div id="importModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div>
          <h3 class="font-bold text-slate-900 text-base">Impor Mahasiswa Massal</h3>
          <p class="text-xs text-slate-400">Salin & tempel daftar NIM dan Nama dari Excel / Word / SIAKAD</p>
        </div>
        <button onclick="closeModal('importModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <div>
        <textarea id="importTextarea" rows="8" placeholder="Contoh format per baris:
2024001	Budi Santoso
2024002	Siti Rahmawati
2024003	Ahmad Hidayat" class="w-full font-mono text-xs p-3 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500"></textarea>
        <p class="text-[11px] text-slate-400 mt-1.5">
          *Pemisah antara NIM dan Nama dapat menggunakan tombol Tab, Koma (,), Titik koma (;), atau Spasi.
        </p>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button onclick="closeModal('importModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitImportStudents()" id="btnSubmitImport" class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold text-xs">Impor Sekarang</button>
      </div>
    </div>
  </div>

  <!-- 4. MODAL TAMBAH/EDIT TUGAS -->
  <div id="taskModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-xl w-full p-4 sm:p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-slate-900 text-base" id="taskModalTitle">Buat Tugas Baru</h3>
        <button onclick="closeModal('taskModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <input type="hidden" id="modalTaskId">
      <div class="space-y-3.5 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Mata Kuliah</label>
          <select id="modalTaskCourseId" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-semibold text-slate-800">
            <!-- options populated via JS -->
          </select>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Judul Tugas</label>
          <input type="text" id="modalTaskTitle" placeholder="Contoh: Tugas 1 - Perancangan Database" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Bobot Persentase Nilai Akhir (%)</label>
            <input type="number" id="modalTaskWeight" placeholder="Contoh: 30" min="0" max="100" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
          </div>
          <div>
            <label class="block font-semibold text-slate-700 mb-1">Deskripsi Singkat</label>
            <input type="text" id="modalTaskDesc" placeholder="Opsional: Keterangan tugas" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Berkas Soal Tugas / Kunci Jawaban</label>
          <div class="space-y-2">
            <!-- Pilihan 1: Upload Langsung -->
            <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
              <span class="text-[11px] font-semibold text-slate-700 block">Opsi A: Unggah Langsung Berkas Soal (.pdf, .docx, .txt, gambar, .heic)</span>
              <input type="file" id="modalTaskQuestionFile" accept=".pdf,.docx,.doc,.txt,.png,.jpg,.jpeg,.webp,.heic,.heif" class="w-full text-xs file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer text-slate-600">
            </div>

            <!-- Pilihan 2: Link Google Drive -->
            <div>
              <span class="text-[11px] font-semibold text-slate-700 block mb-1">Opsi B: Tautan / ID Berkas Soal di Google Drive</span>
              <div class="flex gap-2">
                <input type="text" id="modalTaskQuestionRef" placeholder="Tempel URL atau ID berkas soal di Drive (PDF, Docs, Gambar)" class="flex-1 p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
                <button type="button" onclick="verifyTaskQuestion()" id="btnVerifyTask" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-xl text-xs font-semibold">Cek Soal</button>
              </div>
              <div id="verifyTaskAlert" class="hidden mt-1.5 p-2 rounded-lg text-[11px]"></div>
            </div>
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Tautan / ID Folder Jawaban Mahasiswa di Google Drive</label>
          <div class="flex gap-2">
            <input type="text" id="modalTaskFolderId" placeholder="Tempel URL atau ID Folder Google Drive" class="flex-1 p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
            <button type="button" onclick="verifyDriveFolder()" id="btnVerifyFolder" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 rounded-xl text-xs font-semibold">Cek Folder</button>
          </div>
          <div id="verifyFolderAlert" class="hidden mt-1.5 p-2 rounded-lg text-[11px]"></div>
        </div>

        <!-- Rubrik & Porsi Nilai Box -->
        <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 space-y-2">
          <div class="flex items-center justify-between">
            <label class="font-bold text-slate-800">Rincian Porsi Poin & Rubrik</label>
            <button type="button" onclick="autoExtractRubricInModal()" id="btnAutoExtract" class="text-xs bg-blue-600 hover:bg-blue-700 text-white font-semibold px-2.5 py-1 rounded-lg transition flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg> <span>Ekstrak dari Soal</span>
            </button>
          </div>
          <div id="autoExtractAlert" class="hidden p-2 rounded-lg text-[11px]"></div>
          <textarea id="modalTaskRubric" rows="5" placeholder="Tuliskan butir kriteria dan porsi poin nilai..." class="w-full font-mono text-[11px] p-2.5 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500"></textarea>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <button onclick="closeModal('taskModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitSaveTask()" id="btnSubmitTask" class="px-4 py-2 bg-blue-600 text-white rounded-xl font-semibold text-xs">Simpan Tugas</button>
      </div>
    </div>
  </div>

  <!-- 5. MODAL DETAIL / KOREKSI MANUAL NILAI -->
  <div id="gradeDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-4xl w-full p-4 sm:p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div>
          <h3 class="font-bold text-slate-900 text-base sm:text-lg" id="detailModalStudentName">Detail Penilaian Mahasiswa</h3>
          <p class="text-xs text-slate-400 mt-0.5" id="detailModalStudentNim">NIM: -</p>
        </div>
        <button onclick="closeModal('gradeDetailModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition cursor-pointer"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <input type="hidden" id="detailGradeTaskId">
      <input type="hidden" id="detailGradeClassId">
      <input type="hidden" id="detailGradeNim">
      <input type="hidden" id="detailGradeNama">

      <!-- Failure Alert Box (Muncul jika penilaian berstatus Gagal) -->
      <div id="detailFailureAlert" class="hidden p-3.5 bg-rose-50 border border-rose-200 rounded-xl space-y-1.5">
        <div class="font-bold text-rose-900 text-xs flex items-center gap-1.5">
          <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> <span>Penyebab Penilaian Gagal:</span>
        </div>
        <p class="text-rose-800 text-xs font-medium" id="detailFailureReason">-</p>
        <p class="text-[11px] text-rose-700 bg-white/80 p-2 rounded-lg border border-rose-100" id="detailFailureAdvice">Solusi: -</p>
      </div>

      <!-- AI Bot Residue Alert Box -->
      <div id="detailAiAlert" class="hidden p-3.5 bg-rose-50/90 border border-rose-300 rounded-xl space-y-2 shadow-2xs">
        <div class="font-bold text-rose-900 text-xs flex items-center justify-between">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg> <span>Terindikasi AI (Residu Percakapan Bot Terlupa Dihapus):</span>
          </span>
          <button type="button" onclick="setZeroPenaltyGrade('ai')" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-bold cursor-pointer transition shadow-2xs" title="Terapkan nilai 0 karena bukti residu bot AI">Beri Nilai 0</button>
        </div>
        <p class="text-[11px] text-rose-800 font-semibold" id="detailAiSummary">-</p>
        <div class="text-rose-950 text-xs font-mono bg-white p-2.5 rounded-lg border border-rose-200 whitespace-pre-wrap max-h-36 overflow-y-auto leading-relaxed" id="detailAiSnippets">-</div>
      </div>

      <!-- Plagiarism Alert Box -->
      <div id="detailPlagiarismAlert" class="hidden p-3.5 bg-amber-50/90 border border-amber-300 rounded-xl space-y-2 shadow-2xs">
        <div class="font-bold text-amber-900 text-xs flex items-center justify-between">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4 text-amber-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg> <span>Indikasi Plagiarisme Antar Mahasiswa:</span>
          </span>
          <button type="button" onclick="setZeroPenaltyGrade('plagiarism')" class="px-2.5 py-1 bg-amber-700 hover:bg-amber-800 text-white rounded-lg text-[10px] font-bold cursor-pointer transition shadow-2xs" title="Terapkan nilai 0 karena plagiasi">Beri Nilai 0</button>
        </div>
        <p class="text-amber-900 text-xs font-semibold" id="detailPlagiarismReason">-</p>
        <div class="text-amber-950 text-[11px] font-mono bg-white p-2 rounded-lg border border-amber-200 max-h-24 overflow-y-auto space-y-1" id="detailPlagiarismSnippets">-</div>
      </div>

      <div class="space-y-4 text-xs">
        <!-- Nilai Total Akhir Banner Card -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-slate-50 to-blue-50/40 p-3.5 rounded-xl border border-slate-200">
          <div>
            <label class="block font-bold text-slate-800 text-sm">Nilai Total Akhir (Skala 0 - 100)</label>
            <p class="text-[11px] text-slate-500 mt-0.5">Nilai akumulatif dari butir kriteria rubrik. Anda dapat mengoreksi nilai ini secara manual.</p>
          </div>
          <div class="flex items-center gap-2 self-start sm:self-auto">
            <input type="number" step="0.1" id="detailGradeTotalScore" min="0" max="100" class="w-28 text-xl font-extrabold text-blue-700 p-2 bg-white border-2 border-blue-300 rounded-xl focus:outline-none focus:border-blue-600 text-center shadow-xs">
            <span class="text-xs font-bold text-slate-400">/ 100</span>
          </div>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
              <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg> <span>Rincian Skor Per Butir Soal & Alasan Penilaian</span>
            </label>
            <span class="text-[11px] text-slate-400 font-mono hidden sm:inline">Format: • Kriteria: Skor/Maks Poin (Alasan/Kekurangan)</span>
          </div>
          <textarea id="detailGradeCriteria" rows="6" class="w-full font-mono text-[11px] sm:text-xs p-3 bg-slate-50/70 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 focus:bg-white transition leading-relaxed text-slate-800"></textarea>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
              <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> <span>Catatan, Justifikasi & Saran AI</span>
            </label>
            <span class="text-[11px] text-slate-400 hidden sm:inline">Termasuk Kelebihan Jawaban, Poin Kekurangan & Saran Revisi</span>
          </div>
          <textarea id="detailGradeFeedback" rows="9" class="w-full text-xs p-3.5 bg-slate-50/70 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 focus:bg-white transition leading-relaxed text-slate-800"></textarea>
        </div>
      </div>

      <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-100">
        <button type="button" onclick="closeModal('gradeDetailModal')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-semibold text-xs transition cursor-pointer">Tutup</button>
        <button type="button" onclick="submitSaveManualGrade()" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs transition cursor-pointer shadow-xs">Simpan Perubahan Nilai</button>
      </div>
    </div>
  </div>

  <!-- 6. MODAL UNGGAH BERKAS TUGAS MAHASISWA -->
  <div id="uploadZipModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div>
          <h3 class="font-bold text-slate-900 text-base">Unggah Berkas Tugas Mahasiswa</h3>
          <p class="text-xs text-slate-400">Unggah ZIP atau pilih banyak file sekaligus</p>
        </div>
        <button onclick="closeModal('uploadZipModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <div class="space-y-3 text-xs">
        <div class="p-3 bg-blue-50 text-blue-800 rounded-xl border border-blue-200">
          <p class="font-semibold text-slate-800">Cara Praktis dari Google Drive / LMS:</p>
          <p class="mt-1 text-[11px] leading-relaxed">
            Buka folder jawaban mahasiswa di <strong>Google Drive / Classroom</strong> &rarr; klik menu folder &rarr; pilih <strong>Download</strong> (Google akan membuat 1 file .ZIP otomatis). Unggah file ZIP tersebut ke sini atau pilih berkas tugas langsung.
          </p>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Pilih Berkas ZIP atau File Tugas (.zip, .pdf, .docx, .heic, dll)</label>
          <input type="file" id="inputZipFile" accept=".zip,.pdf,.docx,.doc,.txt,.png,.jpg,.jpeg,.webp,.heic,.heif" multiple class="w-full text-xs p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500">
          <p class="text-[10px] text-slate-400 mt-1">Dapat memilih 1 file .zip atau menandai banyak berkas sekaligus.</p>
        </div>
        <div id="uploadZipAlert" class="hidden p-2.5 rounded-xl text-xs font-medium"></div>
      </div>

      <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <button onclick="closeModal('uploadZipModal')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
        <button onclick="submitUploadZip()" id="btnSubmitZip" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-semibold text-xs flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg> <span>Unggah &amp; Proses</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Modal Atur Folder Khusus Kelas -->
  <div id="classFolderOverrideModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-4 sm:p-6 shadow-2xl space-y-4">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <div>
          <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg> <span>Atur Folder Drive Khusus Kelas</span>
          </h3>
          <p class="text-xs text-slate-500 mt-0.5" id="overrideModalSubTitle">Pilih folder Google Drive untuk pengumpulan tugas kelas ini</p>
        </div>
        <button onclick="closeModal('classFolderOverrideModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <div class="space-y-3.5 text-xs">
        <div class="p-3 bg-blue-50/70 border border-blue-200/60 rounded-xl space-y-1">
          <span class="font-semibold text-blue-900 flex items-center gap-1.5">
            <span>ℹ️</span> <span>Folder Utama Tugas (Default):</span>
          </span>
          <p class="text-blue-800 break-all font-mono text-[11px]" id="overrideTaskMainFolderText">-</p>
          <p class="text-[10px] text-blue-600">Secara bawaan, seluruh kelas di bawah mata kuliah ini menggunakan folder utama di atas.</p>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">
            Folder Google Drive Khusus Kelas Ini:
          </label>
          <input type="text" id="inputClassOverrideFolder" placeholder="Tautan / ID Folder Drive Khusus (Kosongkan jika mengikuti folder utama)" class="w-full text-xs p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-mono">
          <p class="text-[10px] text-slate-400 mt-1">
            Isi jika kelas ini memiliki tautan folder Google Drive terpisah. Kosongkan jika kelas ini mengumpulkan di folder bersama yang sama dengan kelas lain.
          </p>
        </div>

        <div id="overrideModalStatusBadge" class="hidden p-2.5 rounded-xl text-xs font-medium"></div>
      </div>

      <div class="flex flex-col sm:flex-row justify-between gap-2 pt-2 border-t border-slate-100">
        <button type="button" onclick="resetClassFolderOverride()" id="btnResetOverride" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-medium text-xs transition">
          ↺ Gunakan Folder Utama
        </button>
        <div class="flex gap-2">
          <button type="button" onclick="closeModal('classFolderOverrideModal')" class="px-3.5 py-2 bg-slate-100 text-slate-600 rounded-xl font-medium text-xs">Batal</button>
          <button type="button" onclick="saveClassFolderOverride()" id="btnSaveOverride" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold text-xs flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4 text-white inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Simpan</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 8. MODAL ANALISIS INTEGRITAS: PLAGIARISME & RESIDU BOT AI -->
  <div id="integrityReportModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4">
    <div class="bg-white rounded-2xl max-w-4xl w-full p-4 sm:p-6 shadow-2xl space-y-4 max-h-[92vh] flex flex-col">
      <!-- Modal Header -->
      <div class="flex items-center justify-between pb-3 border-b border-slate-100 shrink-0">
        <div class="flex items-center gap-2.5">
          <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 border border-rose-200 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          </div>
          <div>
            <h3 class="font-bold text-slate-900 text-base sm:text-lg flex items-center gap-2">
              <span>Laporan Integritas Akademik & Plagiarisme</span>
            </h3>
            <p class="text-xs text-slate-400" id="integrityModalSubtitle">Analisis kesamaan berkas antar mahasiswa dan residu percakapan chatbot AI</p>
          </div>
        </div>
        <button onclick="closeModal('integrityReportModal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition cursor-pointer"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
      </div>

      <!-- Ringkasan Statistik Kartu -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 shrink-0" id="integrityStatsCards">
        <div class="bg-slate-50 border border-slate-200 p-3 rounded-xl">
          <span class="text-[11px] font-semibold text-slate-500">Total Diperiksa</span>
          <p class="text-xl font-extrabold text-slate-800 mt-0.5" id="integStatTotal">0 Mhs</p>
        </div>
        <div class="bg-rose-50 border border-rose-200 p-3 rounded-xl cursor-pointer hover:bg-rose-100/70 transition" onclick="switchIntegrityTab('ai')">
          <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-rose-800 flex items-center gap-1"><svg class="w-3.5 h-3.5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>Terindikasi AI</span></span>
            <span class="text-[9px] bg-rose-200 text-rose-800 px-1.5 py-0.2 rounded font-bold">Pasti AI</span>
          </div>
          <p class="text-xl font-extrabold text-rose-700 mt-0.5" id="integStatAi">0 Mhs</p>
        </div>
        <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl cursor-pointer hover:bg-amber-100/70 transition" onclick="switchIntegrityTab('plag')">
          <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-amber-900 flex items-center gap-1"><svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>Plagiasi Kritis</span></span>
            <span class="text-[9px] bg-amber-200 text-amber-900 px-1.5 py-0.2 rounded font-bold">≥ 70%</span>
          </div>
          <p class="text-xl font-extrabold text-amber-800 mt-0.5" id="integStatHighPlag">0 Pasang</p>
        </div>
        <div class="bg-emerald-50 border border-emerald-200 p-3 rounded-xl">
          <span class="text-[11px] font-semibold text-emerald-800 flex items-center gap-1"><svg class="w-3.5 h-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Kemiripan Wajar</span></span>
          <p class="text-xl font-extrabold text-emerald-700 mt-0.5" id="integStatClean">0 Mhs</p>
        </div>
      </div>

      <!-- Tab Navigasi Modal -->
      <div class="flex items-center gap-1 border-b border-slate-200 text-xs shrink-0">
        <button type="button" onclick="switchIntegrityTab('plag')" id="tabBtnIntegPlag" class="px-3.5 py-2 font-bold border-b-2 border-blue-600 text-blue-600 transition flex items-center gap-1.5 cursor-pointer">
          <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>Kemiripan Antar Mahasiswa</span></span>
          <span id="badgeCountPlag" class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-100 text-amber-800 font-mono">0</span>
        </button>
        <button type="button" onclick="switchIntegrityTab('ai')" id="tabBtnIntegAi" class="px-3.5 py-2 font-medium text-slate-500 hover:text-slate-800 transition flex items-center gap-1.5 cursor-pointer">
          <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>Residu Chatbot AI</span></span>
          <span id="badgeCountAi" class="px-1.5 py-0.2 rounded-full text-[10px] bg-rose-100 text-rose-800 font-mono">0</span>
        </button>
        <button type="button" onclick="switchIntegrityTab('all')" id="tabBtnIntegAll" class="px-3.5 py-2 font-medium text-slate-500 hover:text-slate-800 transition flex items-center gap-1.5 cursor-pointer">
          <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg><span>Rekap Seluruh Kelas</span></span>
        </button>
      </div>

      <!-- Tab Konten: Scrollable Area -->
      <div class="flex-1 overflow-y-auto space-y-3 text-xs pr-1">
        <!-- 1. TAB KEMIRIPAN (PLAGIASI ANTAR MAHASISWA) -->
        <div id="integTabPlagContent" class="space-y-3">
          <div id="integPairsList" class="space-y-3">
            <!-- Dynamic Pairs -->
          </div>
        </div>

        <!-- 2. TAB RESIDU BOT AI -->
        <div id="integTabAiContent" class="hidden space-y-3">
          <div id="integAiList" class="space-y-3">
            <!-- Dynamic AI Flagged Students -->
          </div>
        </div>

        <!-- 3. TAB REKAP SELURUH KELAS -->
        <div id="integTabAllContent" class="hidden">
          <div class="overflow-x-auto border border-slate-200 rounded-xl">
            <table class="w-full text-left text-xs">
              <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                <tr>
                  <th class="px-3.5 py-2.5">NIM</th>
                  <th class="px-3.5 py-2.5">Nama Mahasiswa</th>
                  <th class="px-3.5 py-2.5 text-center">Tingkat Kemiripan</th>
                  <th class="px-3.5 py-2.5">Kemiripan Tertinggi Dengan</th>
                  <th class="px-3.5 py-2.5 text-center">Residu AI</th>
                  <th class="px-3.5 py-2.5 text-center">Aksi</th>
                </tr>
              </thead>
              <tbody id="integAllTableBody" class="divide-y divide-slate-100">
                <!-- Dynamic rows -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="flex justify-between items-center pt-3 border-t border-slate-100 text-xs shrink-0">
        <span class="text-slate-400 text-[11px]" id="integrityAnalyzedAtTime">Dipindai: -</span>
        <div class="flex gap-2">
          <button type="button" onclick="runIntegrityCheck()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-medium transition flex items-center gap-1 cursor-pointer">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Pindai Ulang</span>
          </button>
          <button type="button" onclick="closeModal('integrityReportModal')" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold transition cursor-pointer">
            Tutup
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
    // State Aplikasi
    let appState = {
      currentUser: null,
      courses: [],
      classes: [],
      activeCourseId: '',
      activeClassId: '',
      currentStudents: [],
      currentTasks: [],
      currentGrades: [],
      selectedGradingTaskId: '',
      selectedStudentNims: new Set(),
      settings: {}
    };

    let appInitialized = false;
    function safeInitApp() {
      if (appInitialized) return;
      appInitialized = true;
      initApp();
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      setTimeout(safeInitApp, 1);
    } else {
      window.addEventListener('DOMContentLoaded', safeInitApp);
      window.addEventListener('load', safeInitApp);
    }

    function initApp() {
      showLoading(true);
      google.script.run
        .withSuccessHandler(function(data) {
          showLoading(false);

          // Cek autentikasi sesi pengguna
          if (!data.authenticated || !data.currentUser) {
            appState.currentUser = null;
            const loginScreen = document.getElementById('loginScreenContainer');
            const appWrap = document.getElementById('appWrapper');
            if (loginScreen) loginScreen.classList.remove('hidden');
            if (appWrap) appWrap.classList.add('hidden');
            return;
          }

          // Pengguna telah login
          appState.currentUser = data.currentUser;
          const loginScreen = document.getElementById('loginScreenContainer');
          const appWrap = document.getElementById('appWrapper');
          if (loginScreen) loginScreen.classList.add('hidden');
          if (appWrap) appWrap.classList.remove('hidden');

          // Perbarui informasi profil di header
          const nameEl = document.getElementById('headerUserName');
          const roleEl = document.getElementById('headerUserRole');
          if (nameEl) nameEl.textContent = data.currentUser.nama || data.currentUser.nama_lengkap || 'Pengguna';
          if (roleEl) {
            const isAdmin = data.currentUser.role === 'admin';
            roleEl.textContent = isAdmin ? 'Administrator' : 'Dosen';
            roleEl.className = isAdmin ? 'text-[10px] text-purple-400 font-bold uppercase tracking-wider' : 'text-[10px] text-blue-400 font-medium capitalize';
          }

          // Impersonation Banner (Mode Penyamaran Administrator)
          const impBanner = document.getElementById('impersonationBanner');
          if (impBanner) {
            if (data.currentUser && data.currentUser.isImpersonating) {
              impBanner.classList.remove('hidden');
              impBanner.classList.add('flex');
              const uName = document.getElementById('impersonatedUserName');
              const uEmail = document.getElementById('impersonatedUserEmail');
              if (uName) uName.textContent = data.currentUser.nama || data.currentUser.nama_lengkap || 'Dosen';
              if (uEmail) uEmail.textContent = data.currentUser.email || '';
            } else {
              impBanner.classList.add('hidden');
              impBanner.classList.remove('flex');
            }
          }

          // Tab & Bagian Khusus Administrator
          const navUsers = document.getElementById('nav-tab-users');
          const adminOAuth = document.getElementById('adminGlobalOAuthConfig');
          const adminReset = document.getElementById('adminResetDbSection');
          if (data.currentUser.role === 'admin' && !data.currentUser.isImpersonating) {
            if (navUsers) {
              navUsers.classList.remove('hidden');
              navUsers.classList.add('flex');
            }
            if (adminOAuth) adminOAuth.classList.remove('hidden');
            if (adminReset) adminReset.classList.remove('hidden');
          } else {
            if (navUsers) {
              navUsers.classList.add('hidden');
              navUsers.classList.remove('flex');
            }
            if (adminOAuth) adminOAuth.classList.add('hidden');
            if (adminReset) adminReset.classList.add('hidden');
          }

          // Nilai awal formulir profil di pengaturan
          const profNama = document.getElementById('myProfileNama');
          const profEmail = document.getElementById('myProfileEmail');
          if (profNama) profNama.value = data.currentUser.nama_lengkap || '';
          if (profEmail) profEmail.value = data.currentUser.email || '';

          appState.courses = data.courses || [];
          appState.classes = data.classes || [];
          appState.settings = data.settings || {};
          
          if (appState.settings.modelName) {
            document.getElementById('badgeModelName').textContent = appState.settings.modelName;
            document.getElementById('selectModelName').value = appState.settings.modelName;
          }
          if (appState.settings.apiKey) {
            document.getElementById('inputApiKey').value = appState.settings.apiKey;
          } else {
            document.getElementById('inputApiKey').value = '';
          }
          if (appState.settings.googleOAuth) {
            renderGoogleOAuthStatus(appState.settings.googleOAuth);
          }

          // Cek parameter callback Google OAuth dari URL
          const currentUrl = window.location.href;
          if (currentUrl.includes('oauth=connected')) {
            showGlobalAlert('Akun Google Drive berhasil terhubung! Anda sekarang dapat menyinkronkan tugas langsung dari Google Drive.', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
            switchTab('tab-settings');
          } else if (currentUrl.includes('oauth=failed')) {
            showGlobalAlert('Gagal menghubungkan Google Drive. Periksa kembali izin akun Google Anda.', 'error');
            window.history.replaceState({}, document.title, window.location.pathname);
            switchTab('tab-settings');
          }

          // Inisialisasi activeCourseId dan activeClassId
          let initialClass = null;
          if (appState.settings.activeClassId) {
            initialClass = appState.classes.find(c => c.id_kelas === appState.settings.activeClassId);
          }
          if (!initialClass && appState.classes.length > 0) {
            initialClass = appState.classes[0];
          }

          if (initialClass) {
            appState.activeClassId = initialClass.id_kelas;
            appState.activeCourseId = initialClass.id_mata_kuliah || (appState.courses.length > 0 ? appState.courses[0].id_mata_kuliah : '');
          } else if (appState.courses.length > 0) {
            appState.activeCourseId = appState.courses[0].id_mata_kuliah;
          }

          renderClassDropdowns();
          renderCourseDropdowns();
          renderCoursesList();
          renderClassesList();

          if (appState.activeClassId) {
            switchActiveClass(appState.activeClassId);
          } else if (appState.activeCourseId) {
            switchTaskCourse(appState.activeCourseId);
            showLoading(false);
          } else {
            showLoading(false);
          }
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal menginisialisasi sistem: ' + err.message, 'error');
        })
        .apiGetInitialData();
    }

    // Navigasi Tab
    function switchTab(tabId) {
      if (tabId === 'reports') tabId = 'tab-reports';
      if (tabId === 'classes') tabId = 'tab-classes';
      if (tabId === 'tasks') tabId = 'tab-tasks';
      if (tabId === 'grading') tabId = 'tab-grading';
      if (tabId === 'dashboard') tabId = 'tab-dashboard';
      if (tabId === 'settings') tabId = 'tab-settings';
      if (tabId === 'guide') tabId = 'tab-guide';

      appState.activeTab = tabId;

      document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'text-blue-600', 'bg-blue-50', 'font-semibold');
        btn.classList.add('text-slate-600');
      });
      document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

      const activeBtn = document.getElementById('nav-' + tabId);
      if (activeBtn) {
        activeBtn.classList.add('active', 'text-blue-600', 'bg-blue-50', 'font-semibold');
        activeBtn.classList.remove('text-slate-600');
      }
      const targetSection = document.getElementById(tabId);
      if (targetSection) targetSection.classList.add('active');

      // Indikator aktif pada tombol Panduan di Header Atas
      const headerGuideBtn = document.getElementById('headerBtnGuide');
      if (headerGuideBtn) {
        if (tabId === 'tab-guide') {
          headerGuideBtn.className = 'inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-600 text-white border border-indigo-400 shadow-md transition cursor-pointer ring-2 ring-indigo-400/50';
        } else {
          headerGuideBtn.className = 'inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs font-semibold bg-indigo-600/30 hover:bg-indigo-600 text-indigo-200 hover:text-white border border-indigo-400/40 transition shadow-xs cursor-pointer';
        }
      }

      // Re-sinkronisasi & penyegaran data setiap kali ganti tab agar data selalu mutakhir
      if (tabId === 'tab-dashboard') {
        renderDashboardStats();
        if (appState.activeClassId) {
          loadClassDetails(appState.activeClassId);
        }
      } else if (tabId === 'tab-classes') {
        renderCoursesList();
        renderClassesList();
        if (appState.activeClassId) {
          loadClassDetails(appState.activeClassId);
        }
      } else if (tabId === 'tab-tasks') {
        if (appState.activeCourseId) {
          switchTaskCourse(appState.activeCourseId);
        }
      } else if (tabId === 'tab-grading') {
        renderGradingTaskSelect();
        if (appState.selectedGradingTaskId && appState.activeClassId) {
          loadGradingTable(appState.selectedGradingTaskId);
        }
      } else if (tabId === 'tab-reports') {
        if (appState.activeClassId) {
          loadClassReport(appState.activeClassId);
        }
      } else if (tabId === 'tab-users') {
        loadAdminUsers();
      }
    }

    function switchGuideTopic(topicId) {
      document.querySelectorAll('.guide-nav-btn').forEach(btn => {
        btn.classList.remove('active', 'text-blue-700', 'bg-blue-50');
        btn.classList.add('text-slate-600');
      });
      document.querySelectorAll('.guide-topic-section').forEach(sec => {
        sec.classList.add('hidden');
      });

      const activeBtn = document.getElementById('btn-' + topicId);
      if (activeBtn) {
        activeBtn.classList.add('active', 'text-blue-700', 'bg-blue-50');
        activeBtn.classList.remove('text-slate-600');
      }

      const targetSec = document.getElementById(topicId);
      if (targetSec) {
        targetSec.classList.remove('hidden');
      }
    }

    // Render Dropdown Header (Grouped by Course)
    function renderClassDropdowns() {
      const select = document.getElementById('headerClassSelect');
      if (!select) return;
      select.innerHTML = '<option value="" class="bg-slate-800 text-slate-300">-- Pilih Kelas --</option>';

      appState.courses.forEach(course => {
        const optGroup = document.createElement('optgroup');
        optGroup.label = (course.kode_mk ? '[' + course.kode_mk + '] ' : '') + course.nama_mata_kuliah;
        optGroup.className = 'bg-slate-900 text-slate-300 font-bold';

        const classesInCourse = appState.classes.filter(c => c.id_mata_kuliah === course.id_mata_kuliah);
        classesInCourse.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.id_kelas;
          opt.textContent = c.nama_kelas + ' (' + c.studentCount + ' Mhs)';
          opt.className = 'bg-slate-800 text-slate-100 font-normal pl-3';
          optGroup.appendChild(opt);
        });

        if (classesInCourse.length > 0) {
          select.appendChild(optGroup);
        }
      });

      // Kelas tanpa Mata Kuliah (jika ada)
      const orphanClasses = appState.classes.filter(c => !c.id_mata_kuliah);
      if (orphanClasses.length > 0) {
        const optGroup = document.createElement('optgroup');
        optGroup.label = 'Kelas Lainnya';
        orphanClasses.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.id_kelas;
          opt.textContent = c.nama_kelas + ' (' + c.studentCount + ' Mhs)';
          opt.className = 'bg-slate-800 text-slate-100';
          optGroup.appendChild(opt);
        });
        select.appendChild(optGroup);
      }

      if (appState.activeClassId) {
        select.value = appState.activeClassId;
      }
    }

    // Render Pilihan Mata Kuliah di Tab Tugas & Modal
    function renderCourseDropdowns() {
      // 1. Dropdown di Tab 3 (Kelola Tugas)
      const taskSelect = document.getElementById('taskCourseSelect');
      if (taskSelect) {
        taskSelect.innerHTML = appState.courses.map(c => 
          `<option value="${c.id_mata_kuliah}" ${c.id_mata_kuliah === appState.activeCourseId ? 'selected' : ''}>${c.kode_mk ? '[' + c.kode_mk + '] ' : ''}${c.nama_mata_kuliah}</option>`
        ).join('');
      }

      // 2. Dropdown di Modal Tambah/Edit Kelas
      const modalClassSelect = document.getElementById('modalClassCourseSelect');
      if (modalClassSelect) {
        modalClassSelect.innerHTML = appState.courses.map(c => 
          `<option value="${c.id_mata_kuliah}">${c.kode_mk ? '[' + c.kode_mk + '] ' : ''}${c.nama_mata_kuliah}</option>`
        ).join('');
      }

      // 3. Dropdown di Modal Tambah/Edit Tugas
      const modalTaskSelect = document.getElementById('modalTaskCourseId');
      if (modalTaskSelect) {
        modalTaskSelect.innerHTML = appState.courses.map(c => 
          `<option value="${c.id_mata_kuliah}">${c.kode_mk ? '[' + c.kode_mk + '] ' : ''}${c.nama_mata_kuliah}</option>`
        ).join('');
      }
    }

    // Ganti Kelas Aktif
    function switchActiveClass(classId) {
      if (!classId) return;
      appState.activeClassId = classId;
      const headerSelect = document.getElementById('headerClassSelect');
      if (headerSelect) headerSelect.value = classId;

      const currentClass = appState.classes.find(c => c.id_kelas === classId);
      if (currentClass) {
        appState.activeCourseId = currentClass.id_mata_kuliah;
        const course = appState.courses.find(c => c.id_mata_kuliah === currentClass.id_mata_kuliah);
        const courseTitle = course ? (' • ' + course.nama_mata_kuliah) : '';
        document.getElementById('dashClassTitle').textContent = currentClass.nama_kelas + courseTitle;
        
        const taskSelect = document.getElementById('taskCourseSelect');
        if (taskSelect) taskSelect.value = appState.activeCourseId;
      }

      renderCoursesList();
      renderClassesList();
      loadClassDetails(classId);
    }

    // Ganti Mata Kuliah Aktif untuk Tab Tugas
    function switchTaskCourse(courseId) {
      if (!courseId) return;
      appState.activeCourseId = courseId;
      const taskSelect = document.getElementById('taskCourseSelect');
      if (taskSelect) taskSelect.value = courseId;

      showLoading(true);
      google.script.run
        .withSuccessHandler(function(tasks) {
          showLoading(false);
          appState.currentTasks = tasks || [];
          renderTasksGrid();
          renderDashboardStats();
          renderGradingTaskSelect();
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal memuat tugas mata kuliah: ' + err.message, 'error');
        })
        .apiGetTasksByCourse(courseId);
    }

    function loadClassDetails(classId) {
      if (!classId) return;
      showLoading(true);
      // Ambil Mahasiswa kelas & Tugas mata kuliahnya
      google.script.run
        .withSuccessHandler(function(students) {
          appState.currentStudents = students || [];
          renderStudentsTable(appState.currentStudents);

          // Update studentCount di appState.classes agar kartu kelas & dropdown selalu sinkron
          const targetClass = appState.classes.find(c => c.id_kelas === classId);
          if (targetClass) {
            targetClass.studentCount = appState.currentStudents.length;
            renderClassesList();
            renderClassDropdowns();
          }

          // Ambil tugas yang berlaku untuk kelas ini
          google.script.run
            .withSuccessHandler(function(tasks) {
              showLoading(false);
              appState.currentTasks = tasks || [];
              renderDashboardStats();
              renderTasksGrid();
              renderGradingTaskSelect();

              // Sinkronkan tab aktif saat ini dengan data kelas baru secara instan
              const currentTabId = appState.activeTab || document.querySelector('.tab-content.active')?.id || 'tab-dashboard';
              if (currentTabId === 'tab-reports') {
                loadClassReport(classId);
              } else if (currentTabId === 'tab-tasks' && appState.activeCourseId) {
                switchTaskCourse(appState.activeCourseId);
              }
            })
            .withFailureHandler(function(err) {
              showLoading(false);
              showGlobalAlert('Gagal memuat tugas: ' + err.message, 'error');
            })
            .apiGetTasksByClass(classId);
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal memuat mahasiswa: ' + err.message, 'error');
        })
        .apiGetStudents(classId);
    }

    function reloadAllData(targetCourseId = null, targetClassId = null) {
      showLoading(true);
      google.script.run
        .withSuccessHandler(function(data) {
          appState.courses = data.courses || [];
          appState.classes = data.classes || [];
          appState.settings = data.settings || {};

          renderClassDropdowns();
          renderCourseDropdowns();
          renderCoursesList();
          renderClassesList();

          if (targetCourseId) {
            appState.activeCourseId = targetCourseId;
          } else if (!appState.activeCourseId && appState.courses.length > 0) {
            appState.activeCourseId = appState.courses[0].id_mata_kuliah;
          }

          if (targetClassId) {
            switchActiveClass(targetClassId);
          } else {
            const matchingClass = appState.classes.find(c => c.id_mata_kuliah === appState.activeCourseId) || appState.classes[0];
            if (matchingClass) {
              switchActiveClass(matchingClass.id_kelas);
            } else {
              showLoading(false);
              renderDashboardStats();
              renderTasksGrid();
            }
          }
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal memuat ulang data: ' + err.message, 'error');
        })
        .apiGetInitialData();
    }

    // ==========================================
    // RENDER: DASHBOARD
    // ==========================================

    function renderDashboardStats() {
      const elCourses = document.getElementById('statTotalCourses');
      if (elCourses) elCourses.textContent = appState.courses.length;
      document.getElementById('statTotalClasses').textContent = appState.classes.length;
      document.getElementById('statTotalStudents').textContent = appState.currentStudents.length;
      document.getElementById('statTotalTasks').textContent = appState.currentTasks.length;

      // Render daftar tugas dashboard
      const listContainer = document.getElementById('dashTasksList');
      if (!appState.currentTasks || appState.currentTasks.length === 0) {
        listContainer.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs">Belum ada tugas pada mata kuliah kelas ini. Buka tab "Kelola Tugas" untuk membuat tugas.</div>';
        return;
      }

      listContainer.innerHTML = appState.currentTasks.map(t => {
        const course = appState.courses ? appState.courses.find(c => c.id_mata_kuliah === (t.id_mata_kuliah || appState.activeCourseId)) : null;
        const total = (typeof t.totalStudents !== 'undefined' && t.totalStudents > 0)
          ? t.totalStudents
          : ((course && course.studentCount) ? course.studentCount : (appState.currentStudents ? appState.currentStudents.length : 0));
        const effectiveTotal = Math.max(total, t.gradedCount || 0);
        const pct = effectiveTotal > 0 ? Math.min(100, Math.round(((t.gradedCount || 0) / effectiveTotal) * 100)) : 0;
        return `
          <div class="p-3.5 bg-slate-50 hover:bg-slate-100/80 rounded-xl border border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition">
            <div class="flex items-center space-x-3">
              <div class="w-9 h-9 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center shrink-0"><svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
              <div>
                <h4 class="font-bold text-slate-800 text-xs">${t.judul_tugas}</h4>
                <p class="text-[11px] text-slate-400">Bobot: ${t.bobot_persentase > 0 ? t.bobot_persentase + '%' : 'Rata-rata'} • Rata-rata: ${t.averageScore > 0 ? t.averageScore : '-'}</p>
              </div>
            </div>
            <div class="flex items-center space-x-3">
              <div class="w-28 sm:w-36">
                <div class="flex justify-between text-[11px] mb-1">
                  <span class="text-slate-400 font-medium">Dinilai</span>
                  <span class="font-bold text-slate-700">${t.gradedCount || 0}/${effectiveTotal} (${pct}%)</span>
                </div>
                <div class="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                  <div class="bg-blue-600 h-full rounded-full transition-all" style="width: ${pct}%"></div>
                </div>
              </div>
              <div class="flex items-center space-x-2">
                <button onclick="editTask('${t.id_tugas}')" class="px-2.5 py-1.5 bg-white border border-slate-200 hover:border-blue-400 hover:text-blue-600 text-slate-700 rounded-lg text-xs font-semibold transition flex items-center gap-1 shadow-sm" title="Edit Tugas">
                  <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> <span>Edit</span>
                </button>
                <button onclick="goToGradingTask('${t.id_tugas}')" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition shadow-sm">
                  Nilai
                </button>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    // ==========================================
    // RENDER: KELOLA MATA KULIAH & KELAS (TAB 2)
    // ==========================================

    function renderCoursesList() {
      const container = document.getElementById('coursesListContainer');
      if (!container) return;
      if (appState.courses.length === 0) {
        container.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs">Belum ada mata kuliah. Klik "+ MK Baru".</div>';
        return;
      }

      container.innerHTML = appState.courses.map(c => {
        const isActive = c.id_mata_kuliah === appState.activeCourseId;
        return `
          <div onclick="selectCourseInTab('${c.id_mata_kuliah}')" class="p-3 rounded-xl border cursor-pointer transition ${isActive ? 'bg-purple-50/90 border-purple-500 shadow-sm' : 'bg-white border-slate-200 hover:border-slate-300'}">
            <div class="flex items-start justify-between gap-1">
              <div class="min-w-0">
                <h4 class="font-bold text-xs truncate ${isActive ? 'text-purple-900' : 'text-slate-800'}">${c.nama_mata_kuliah}</h4>
                <p class="text-[10px] text-slate-500 mt-0.5">${c.kode_mk ? c.kode_mk + ' • ' : ''}${c.semester || 'Reguler'}</p>
              </div>
              <div class="flex space-x-0.5 shrink-0">
                <button onclick="event.stopPropagation(); editCourse('${c.id_mata_kuliah}')" class="p-1 text-slate-400 hover:text-purple-600 text-xs" title="Edit Mata Kuliah"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                <button onclick="event.stopPropagation(); confirmDeleteCourse('${c.id_mata_kuliah}', '${c.nama_mata_kuliah}')" class="p-1 text-slate-400 hover:text-red-600 text-xs" title="Hapus Mata Kuliah"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
              </div>
            </div>
            <div class="mt-2 flex items-center gap-1.5 text-[9px] text-slate-400">
              <span class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-medium">${c.classCount} Kelas</span>
              <span class="bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded font-medium">${c.taskCount} Tugas</span>
            </div>
          </div>
        `;
      }).join('');
    }

    function selectCourseInTab(courseId) {
      appState.activeCourseId = courseId;
      renderCoursesList();
      renderClassesList();

      // Pilih kelas pertama di mata kuliah tersebut jika ada
      const classesInCourse = appState.classes.filter(c => c.id_mata_kuliah === courseId);
      if (classesInCourse.length > 0) {
        switchActiveClass(classesInCourse[0].id_kelas);
      } else {
        appState.activeClassId = '';
        renderStudentsTable([]);
        document.getElementById('studentListTitle').textContent = 'Daftar Mahasiswa';
        document.getElementById('studentListSubtitle').textContent = 'Buat kelas baru pada mata kuliah ini.';
      }
    }

    function renderClassesList() {
      const container = document.getElementById('classesListContainer');
      if (!container) return;

      const course = appState.courses.find(c => c.id_mata_kuliah === appState.activeCourseId);
      const subtitle = document.getElementById('classSectionSubtitle');
      if (subtitle) {
        subtitle.textContent = course ? course.nama_mata_kuliah : 'Semua Kelas';
      }

      const filteredClasses = appState.activeCourseId 
        ? appState.classes.filter(c => c.id_mata_kuliah === appState.activeCourseId)
        : appState.classes;

      if (filteredClasses.length === 0) {
        container.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs">Belum ada kelas pada mata kuliah ini. Klik "+ Kelas".</div>';
        return;
      }

      container.innerHTML = filteredClasses.map(c => {
        const isActive = c.id_kelas === appState.activeClassId;
        return `
          <div onclick="switchActiveClass('${c.id_kelas}')" class="p-3 rounded-xl border cursor-pointer transition ${isActive ? 'bg-blue-50/80 border-blue-500 shadow-sm' : 'bg-white border-slate-200 hover:border-slate-300'}">
            <div class="flex items-center justify-between">
              <h4 class="font-bold text-xs ${isActive ? 'text-blue-900' : 'text-slate-800'}">${c.nama_kelas}</h4>
              <div class="flex space-x-0.5">
                <button onclick="event.stopPropagation(); editClass('${c.id_kelas}')" class="p-1 text-slate-400 hover:text-blue-600 text-xs"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                <button onclick="event.stopPropagation(); confirmDeleteClass('${c.id_kelas}', '${c.nama_kelas}')" class="p-1 text-slate-400 hover:text-red-600 text-xs"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
              </div>
            </div>
            <p class="text-[10px] text-slate-500 mt-0.5">${c.semester || 'Reguler'}</p>
            <div class="mt-2 flex items-center gap-2 text-[10px] text-slate-400">
              <span class="bg-slate-100 px-2 py-0.5 rounded-md font-medium text-slate-600">${c.studentCount} Mahasiswa</span>
            </div>
          </div>
        `;
      }).join('');
    }

    function renderStudentsTable(students) {
      const tbody = document.getElementById('studentsTableBody');
      const currentClass = appState.classes.find(c => c.id_kelas === appState.activeClassId);
      
      if (currentClass) {
        document.getElementById('studentListTitle').textContent = 'Mahasiswa: ' + currentClass.nama_kelas;
        document.getElementById('studentListSubtitle').textContent = 'Total: ' + students.length + ' Mahasiswa terdaftar';
      }

      if (!students || students.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-slate-400">Belum ada mahasiswa di kelas ini. Klik "+ Mahasiswa" atau "<svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg> <span>Impor Massal</span>".</td></tr>';
        return;
      }

      tbody.innerHTML = students.map((s, idx) => `
        <tr class="hover:bg-slate-50 transition">
          <td class="px-3 py-2 text-center text-slate-400 font-medium">${idx + 1}</td>
          <td class="px-3 py-2 font-mono font-semibold text-slate-700">${s.nim}</td>
          <td class="px-3 py-2 font-medium text-slate-900">${s.nama_mahasiswa}</td>
          <td class="px-3 py-2 text-center">
            <button onclick="confirmDeleteStudent('${s.id_mahasiswa}', '${s.nama_mahasiswa}')" class="text-slate-400 hover:text-red-600 transition p-1 inline-flex items-center" title="Hapus Mahasiswa">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
          </td>
        </tr>
      `).join('');
    }

    function filterStudents(keyword) {
      const q = (keyword || '').toLowerCase();
      const filtered = appState.currentStudents.filter(s => 
        s.nim.toLowerCase().includes(q) || s.nama_mahasiswa.toLowerCase().includes(q)
      );
      renderStudentsTable(filtered);
    }

    // ==========================================
    // RENDER: KELOLA TUGAS (TAB 3)
    // ==========================================

    function renderTasksGrid() {
      const container = document.getElementById('tasksGridContainer');
      if (!container) return;
      if (!appState.currentTasks || appState.currentTasks.length === 0) {
        container.innerHTML = '<div class="col-span-full text-center py-12 text-slate-400 text-sm bg-white rounded-2xl border border-slate-200">Belum ada tugas pada mata kuliah ini. Klik "+ Buat Tugas Baru".</div>';
        return;
      }

      const course = appState.courses.find(c => c.id_mata_kuliah === appState.activeCourseId);
      const courseName = course ? course.nama_mata_kuliah : 'Mata Kuliah';

      container.innerHTML = appState.currentTasks.map(t => {
        const total = (typeof t.totalStudents !== 'undefined' && t.totalStudents > 0)
          ? t.totalStudents
          : ((course && course.studentCount) ? course.studentCount : (appState.currentStudents ? appState.currentStudents.length : 0));
        const effectiveTotal = Math.max(total, t.gradedCount || 0);
        const pct = effectiveTotal > 0 ? Math.min(100, Math.round(((t.gradedCount || 0) / effectiveTotal) * 100)) : 0;
        return `
          <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition flex flex-col justify-between space-y-4">
            <div>
              <div class="flex items-start justify-between gap-2">
                <span class="bg-blue-50 text-blue-700 text-[10px] font-bold px-2.5 py-1 rounded-lg uppercase">
                  Bobot: ${t.bobot_persentase > 0 ? t.bobot_persentase + '%' : 'Standar'}
                </span>
                <div class="flex space-x-1">
                  <button onclick="editTask('${t.id_tugas}')" class="text-slate-400 hover:text-blue-600 p-1 text-xs" title="Edit Tugas"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                  <button onclick="confirmDeleteTask('${t.id_tugas}', '${t.judul_tugas}')" class="text-slate-400 hover:text-red-600 p-1 text-xs" title="Hapus Tugas"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                </div>
              </div>
              <h3 class="font-bold text-slate-900 text-sm mt-2">${t.judul_tugas}</h3>
              <p class="text-xs text-slate-400 line-clamp-2 mt-1">${t.deskripsi || 'Tidak ada deskripsi.'}</p>
              
              <div class="mt-2.5 flex flex-wrap gap-1.5 items-center">
                <div class="inline-flex items-center gap-1 bg-purple-50 text-purple-700 text-[10px] font-medium px-2 py-0.5 rounded-md">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                  <span>Seluruh kelas ${courseName}</span>
                </div>
                ${t.drive_folder_id ? `
                  <div class="inline-flex items-center gap-1 bg-sky-50 text-sky-700 text-[10px] font-semibold px-2 py-0.5 rounded-md border border-sky-100" title="Folder Drive Tugas: ${t.drive_folder_id}">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                    <span>Drive Terhubung</span>
                  </div>
                ` : `
                  <div class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 text-[10px] font-medium px-2 py-0.5 rounded-md border border-amber-100">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    <span>Belum Ada Folder Drive</span>
                  </div>
                `}

                <div id="cardSubmissionsBadge_${t.id_tugas}" class="inline-flex items-center">
                  ${(typeof t.submissionCount !== 'undefined' && t.submissionCount > 0) ? `
                    <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 text-[10px] font-bold px-2 py-0.5 rounded-md border border-emerald-200" title="${t.submissionCount} berkas jawaban mahasiswa tersimpan di server">
                      <svg class="w-3 h-3 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> <span>${t.submissionCount} Berkas di Server</span>
                    </span>
                  ` : (t.drive_folder_id ? `
                    <span class="inline-flex items-center gap-1 bg-sky-50 text-sky-700 text-[10px] font-medium px-2 py-0.5 rounded-md border border-sky-100" title="Klik tombol Sinkron Drive untuk mengunduh berkas jawaban mahasiswa">
                      <svg class="w-3 h-3 text-sky-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> <span>0 Berkas (Klik Sinkron)</span>
                    </span>
                  ` : '')}
                </div>
              </div>
            </div>

            <div class="space-y-3 pt-3 border-t border-slate-100 text-xs">
              <div>
                <div class="flex justify-between text-[11px] mb-1">
                  <span class="text-slate-400">Progres Penilaian</span>
                  <span class="font-bold text-slate-700">${t.gradedCount || 0}/${effectiveTotal} (${pct}%)</span>
                </div>
                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                  <div class="bg-emerald-500 h-full rounded-full transition-all" style="width: ${pct}%"></div>
                </div>
              </div>

              ${t.drive_folder_id ? `
                <div class="space-y-2">
                  <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="syncTaskDriveFromCard('${t.id_tugas}', this)" id="btnCardSync_${t.id_tugas}" class="bg-sky-50 hover:bg-sky-100 border border-sky-200 text-sky-800 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer" title="Sinkronkan berkas tugas mahasiswa dari Google Drive untuk seluruh kelas di mata kuliah ini">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                      <span>Sinkron Drive</span>
                    </button>
                    <button type="button" onclick="openTaskDriveFolderById('${t.id_tugas}')" class="bg-white hover:bg-slate-50 border border-slate-200 hover:border-slate-300 text-slate-700 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer" title="Buka folder Google Drive tempat pengumpulan berkas jawaban mahasiswa">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                      <span>Folder Drive</span>
                    </button>
                  </div>
                  <button onclick="goToGradingTask('${t.id_tugas}')" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 px-3 rounded-xl text-xs shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                    <span>Mulai Penilaian</span>
                  </button>
                </div>
              ` : `
                <div class="space-y-2">
                  <button onclick="editTask('${t.id_tugas}')" class="w-full bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer" title="Atur tautan folder Google Drive untuk tugas ini">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    <span>Hubungkan Folder Drive</span>
                  </button>
                  <button onclick="goToGradingTask('${t.id_tugas}')" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-xl text-xs shadow-sm transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                    <span>Koreksi &amp; Nilai Tugas Ini</span>
                  </button>
                </div>
              `}
            </div>
          </div>
        `;
      }).join('');
    }

    // ==========================================
    // RENDER: KOREKSI & PENILAIAN TUGAS (TAB 4)
    // ==========================================

    function renderGradingTaskSelect() {
      const select = document.getElementById('gradingTaskSelect');
      if (!select) return;
      select.innerHTML = '<option value="">-- Pilih Tugas --</option>';
      appState.currentTasks.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id_tugas;
        opt.textContent = t.judul_tugas + ' (Bobot: ' + t.bobot_persentase + '%)';
        select.appendChild(opt);
      });

      // Periksa apakah selectedGradingTaskId sebelumnya masih ada pada daftar tugas kelas ini
      const isTaskValid = appState.currentTasks.some(t => t.id_tugas === appState.selectedGradingTaskId);

      if (isTaskValid && appState.selectedGradingTaskId) {
        select.value = appState.selectedGradingTaskId;
        loadGradingTable(appState.selectedGradingTaskId);
      } else if (appState.currentTasks.length > 0) {
        // Otomatis pilih tugas pertama dari kelas baru agar langsung menampilkan nilai
        appState.selectedGradingTaskId = appState.currentTasks[0].id_tugas;
        select.value = appState.selectedGradingTaskId;
        loadGradingTable(appState.selectedGradingTaskId);
      } else {
        appState.selectedGradingTaskId = '';
        select.value = '';
        loadGradingTable('');
      }
    }

    function goToGradingTask(taskId) {
      appState.selectedGradingTaskId = taskId;
      switchTab('tab-grading');
      const select = document.getElementById('gradingTaskSelect');
      if (select) {
        select.value = taskId;
        loadGradingTable(taskId);
      }
    }

    function openTaskDriveFolderById(taskId) {
      if (!taskId) return;
      const task = appState.currentTasks.find(t => t.id_tugas === taskId);
      const folderIdOrUrl = task ? (task.drive_folder_id || '') : '';
      if (!folderIdOrUrl) {
        return alert('Folder Google Drive untuk tugas ini belum diatur. Silakan klik tombol "Edit Tugas" untuk menambahkan link folder.');
      }
      let targetUrl = String(folderIdOrUrl).trim();
      if (!targetUrl.startsWith('http://') && !targetUrl.startsWith('https://')) {
        targetUrl = 'https://drive.google.com/drive/folders/' + folderIdOrUrl;
      }
      window.open(targetUrl, '_blank', 'noopener,noreferrer');
    }

    async function openCurrentTaskFolder() {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;
      if (!taskId) {
        return alert('Pilih tugas terlebih dahulu pada menu dropdown.');
      }
      const task = appState.currentTasks.find(t => t.id_tugas === taskId);
      let folderIdOrUrl = task ? (task.drive_folder_id || '') : '';

      try {
        if (classId) {
          const folderInfo = await AppAPI.getClassTaskFolder(taskId, classId);
          if (folderInfo && folderInfo.activeFolder) {
            folderIdOrUrl = folderInfo.activeFolder;
          }
        }
      } catch (e) {}

      if (!folderIdOrUrl) {
        return alert('Folder Google Drive tempat berkas jawaban mahasiswa belum diatur untuk tugas ini.');
      }

      let targetUrl = String(folderIdOrUrl).trim();
      if (!targetUrl.startsWith('http://') && !targetUrl.startsWith('https://')) {
        targetUrl = 'https://drive.google.com/drive/folders/' + folderIdOrUrl;
      }
      window.open(targetUrl, '_blank', 'noopener,noreferrer');
    }

    function openStudentSubmissionFile(fileName, driveUrl = '') {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;
      if (!fileName || fileName === '-') return;

      // 1. Jika URL Google Drive langsung tersedia, buka langsung di Google Drive
      if (driveUrl && (driveUrl.startsWith('http://') || driveUrl.startsWith('https://'))) {
        window.open(driveUrl, '_blank', 'noopener,noreferrer');
        return;
      }

      // 2. Fallback ke endpoint server yang otomatis membuka / redirect ke berkas
      const viewUrl = 'api.php?action=viewSubmissionFile&taskId=' + encodeURIComponent(taskId) + '&classId=' + encodeURIComponent(classId) + '&fileName=' + encodeURIComponent(fileName);
      window.open(viewUrl, '_blank', 'noopener,noreferrer');
    }

    function renderFileNameCell(fileName, driveUrl = '') {
      if (!fileName || fileName === '-') return '<span class="text-slate-400 text-xs">-</span>';
      const isContent = fileName.includes('[via isi]');
      const cleanName = fileName.replace('[via isi]', '').trim();

      const escapedName = cleanName.replace(/'/g, "\\'");
      const escapedUrl = (driveUrl || '').replace(/'/g, "\\'");

      const contentBadge = isContent
        ? '<span class="bg-amber-100 text-amber-800 text-[9px] font-bold px-1.5 py-0.5 rounded shrink-0 flex items-center gap-0.5" title="Dicocokkan via isi dokumen"><svg class="w-2.5 h-2.5 text-amber-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Isi</span></span>'
        : '';

      return `
        <div class="inline-flex items-center gap-1 max-w-full">
          <button type="button" onclick="openStudentSubmissionFile('${escapedName}', '${escapedUrl}')" class="text-blue-600 hover:text-blue-800 hover:underline font-semibold text-left truncate flex items-center gap-1 cursor-pointer transition text-xs" title="Buka berkas ${cleanName} langsung">
            <span class="truncate">${cleanName}</span>
            <span class="text-[10px] text-blue-500 shrink-0">↗</span>
          </button>
          ${contentBadge}
        </div>
      `;
    }

    let gradingStatusFilter = 'all';

    function filterGradingTable() {
      renderGradingTableRows(appState.currentGrades);
    }

    function filterGradingTableByStatus(status) {
      if (gradingStatusFilter === status && status !== 'all') {
        gradingStatusFilter = 'all';
      } else {
        gradingStatusFilter = status;
      }

      // Update highlight ring styling pada kartu statistik
      document.querySelectorAll('.grading-stat-card').forEach(c => {
        c.classList.remove('ring-2', 'ring-blue-500', 'ring-emerald-500', 'ring-amber-500', 'ring-rose-500');
      });

      const ringMap = {
        'all': { id: 'statFilterAll', ring: 'ring-blue-500' },
        'selesai': { id: 'statFilterSelesai', ring: 'ring-emerald-500' },
        'belum': { id: 'statFilterBelum', ring: 'ring-blue-500' },
        'belum_kumpul': { id: 'statFilterBelumKumpul', ring: 'ring-amber-500' },
        'gagal': { id: 'statFilterGagal', ring: 'ring-rose-500' }
      };

      const cur = ringMap[gradingStatusFilter] || ringMap['all'];
      const el = document.getElementById(cur.id);
      if (el) el.classList.add('ring-2', cur.ring);

      const labelMap = {
        'all': '(Semua)',
        'selesai': '(Hanya: Selesai)',
        'belum': '(Hanya: Belum Dinilai)',
        'belum_kumpul': '(Hanya: Belum Kumpul)',
        'gagal': '(Hanya: Gagal/Error)'
      };
      const badge = document.getElementById('gradingFilterBadge');
      if (badge) badge.textContent = labelMap[gradingStatusFilter] || '(Semua)';

      renderGradingTableRows(appState.currentGrades);
    }

    function updateGradingStats(grades) {
      const statsBar = document.getElementById('gradingStatsBar');
      if (!statsBar) return;

      if (!grades || grades.length === 0) {
        statsBar.classList.add('hidden');
        return;
      }

      statsBar.classList.remove('hidden');

      const total = grades.length;
      let countSelesai = 0;
      let countBelum = 0;
      let countBelumKumpul = 0;
      let countGagal = 0;

      grades.forEach(g => {
        const st = (g.status || '').toLowerCase();
        if (st === 'selesai') {
          countSelesai++;
        } else if (st.includes('belum mengumpulkan') || st.includes('tidak mengumpulkan')) {
          countBelumKumpul++;
        } else if (st.startsWith('gagal')) {
          countGagal++;
        } else {
          countBelum++;
        }
      });

      const calcPct = (cnt) => total > 0 ? Math.round((cnt / total) * 100) + '%' : '0%';

      const elTotal = document.getElementById('statCountTotal');
      if (elTotal) elTotal.textContent = total;

      const elSelesai = document.getElementById('statCountSelesai');
      if (elSelesai) elSelesai.textContent = countSelesai;
      const elPctSelesai = document.getElementById('statPctSelesai');
      if (elPctSelesai) elPctSelesai.textContent = `(${calcPct(countSelesai)})`;

      const elBelum = document.getElementById('statCountBelum');
      if (elBelum) elBelum.textContent = countBelum;
      const elPctBelum = document.getElementById('statPctBelum');
      if (elPctBelum) elPctBelum.textContent = `(${calcPct(countBelum)})`;

      const elBelumKumpul = document.getElementById('statCountBelumKumpul');
      if (elBelumKumpul) elBelumKumpul.textContent = countBelumKumpul;
      const elPctBelumKumpul = document.getElementById('statPctBelumKumpul');
      if (elPctBelumKumpul) elPctBelumKumpul.textContent = `(${calcPct(countBelumKumpul)})`;

      const elGagal = document.getElementById('statCountGagal');
      if (elGagal) elGagal.textContent = countGagal;
      const elPctGagal = document.getElementById('statPctGagal');
      if (elPctGagal) elPctGagal.textContent = `(${calcPct(countGagal)})`;
    }

    function getFilteredGradingStudents() {
      const grades = appState.currentGrades || [];
      const query = (document.getElementById('gradingSearchInput')?.value || '').trim().toLowerCase();

      return grades.filter(g => {
        const st = (g.status || '').toLowerCase();
        let matchStatus = true;
        if (gradingStatusFilter === 'selesai') {
          matchStatus = st === 'selesai';
        } else if (gradingStatusFilter === 'belum_kumpul') {
          matchStatus = st.includes('belum mengumpulkan') || st.includes('tidak mengumpulkan');
        } else if (gradingStatusFilter === 'gagal') {
          matchStatus = st.startsWith('gagal');
        } else if (gradingStatusFilter === 'belum') {
          matchStatus = st !== 'selesai' && !st.includes('belum mengumpulkan') && !st.includes('tidak mengumpulkan') && !st.startsWith('gagal');
        }

        if (!matchStatus) return false;

        if (query) {
          const nim = (g.nim || '').toLowerCase();
          const nama = (g.nama_mahasiswa || '').toLowerCase();
          return nim.includes(query) || nama.includes(query);
        }

        return true;
      });
    }

    function loadGradingTable(taskId) {
      if (typeof gradingQueue !== 'undefined' && gradingQueue.isRunning) {
        alert('Penilaian sedang berlangsung. Harap hentikan atau tunggu antrean selesai sebelum mengganti tugas.');
        return;
      }

      if (!taskId) {
        document.getElementById('gradingTaskInfo').classList.add('hidden');
        document.getElementById('gradingStatsBar')?.classList.add('hidden');
        document.getElementById('gradingTableBody').innerHTML = '<tr><td colspan="9" class="text-center py-12 text-slate-400">Pilih tugas terlebih dahulu</td></tr>';
        return;
      }

      appState.selectedGradingTaskId = taskId;
      appState.selectedStudentNims.clear();
      updateSelectedCountBadge();

      // Reset filter status & pencarian saat tugas dimuat
      gradingStatusFilter = 'all';
      const searchInp = document.getElementById('gradingSearchInput');
      if (searchInp) searchInp.value = '';
      const filterBadge = document.getElementById('gradingFilterBadge');
      if (filterBadge) filterBadge.textContent = '(Semua)';
      document.querySelectorAll('.grading-stat-card').forEach(c => {
        c.classList.remove('ring-2', 'ring-blue-500', 'ring-emerald-500', 'ring-amber-500', 'ring-rose-500');
      });
      document.getElementById('statFilterAll')?.classList.add('ring-2', 'ring-blue-500');

      const progressPanel = document.getElementById('gradingProgressPanel');
      if (progressPanel) progressPanel.classList.add('hidden');
      const completionBanner = document.getElementById('gradingCompletionBanner');
      if (completionBanner) completionBanner.classList.add('hidden');

      const task = appState.currentTasks.find(t => t.id_tugas === taskId);
      if (task) {
        document.getElementById('gradingTaskInfo').classList.remove('hidden');
        document.getElementById('taskInfoWeight').textContent = (t => (t.bobot_persentase > 0 ? t.bobot_persentase + '%' : 'Rata-rata'))(task);
        document.getElementById('taskInfoQuestion').textContent = task.task_file_ref ? 'Tercatat di Drive' : 'Belum diisi';
        document.getElementById('taskInfoFolder').textContent = task.drive_folder_id ? 'Tercatat di Drive' : 'Belum diisi';
      }

      // Cek status folder drive khusus kelas
      if (appState.activeClassId) {
        AppAPI.getClassTaskFolder(taskId, appState.activeClassId)
          .then(info => {
            const btnOverride = document.getElementById('btnClassFolderOverride');
            if (btnOverride) {
              if (info && info.isOverridden) {
                btnOverride.className = 'bg-purple-100 border border-purple-300 hover:bg-purple-200 text-purple-900 font-bold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer';
                btnOverride.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg> <span>Folder Kelas (Khusus)</span>';
              } else {
                btnOverride.className = 'bg-amber-50 border border-amber-200 hover:bg-amber-100 text-amber-800 font-semibold text-xs px-3 sm:px-3.5 py-2 rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer';
                btnOverride.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg> <span>Folder Kelas</span>';
              }
            }
            const folderBadge = document.getElementById('taskInfoFolder');
            if (folderBadge && info && info.isOverridden) {
              folderBadge.title = 'Menggunakan Folder Khusus Kelas: ' + info.classFolder;
            }
          })
          .catch(() => {});
      }

      const tbody = document.getElementById('gradingTableBody');
      tbody.innerHTML = '<tr><td colspan="8" class="text-center py-8 text-slate-400"><span class="spinner inline-block w-4 h-4 mr-2 border-slate-400 border-t-blue-600"></span>Memuat daftar penilaian mahasiswa...</td></tr>';

      google.script.run
        .withSuccessHandler(function(grades) {
          appState.currentGrades = grades || [];
          renderGradingTableRows(appState.currentGrades);

          // Tampilkan status jumlah berkas tugas mahasiswa di server
          const count = typeof appState.uploadedSubmissionCount !== 'undefined'
            ? appState.uploadedSubmissionCount
            : (grades.uploadedCount || 0);
          const folderBadge = document.getElementById('taskInfoFolder');
          const isOAuthConnected = appState.settings?.googleOAuth?.isConnected;
          if (folderBadge) {
            if (count > 0) {
              folderBadge.innerHTML = `<span class="inline-flex items-center gap-1 text-emerald-600 font-bold"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>${count} Berkas di Server</span></span>`;
            } else if (isOAuthConnected) {
              folderBadge.innerHTML = `<span class="inline-flex items-center gap-1 text-sky-600 font-semibold cursor-pointer hover:underline" onclick="syncCurrentTaskDriveFolder()" title="Klik untuk sinkronkan berkas langsung dari Google Drive"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>0 Berkas (Klik Sinkron Drive)</span></span>`;
            } else {
              folderBadge.innerHTML = `<span class="inline-flex items-center gap-1 text-amber-600 font-semibold cursor-pointer hover:underline" onclick="openUploadZipModal()" title="Klik untuk mengunggah berkas"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>0 Berkas (Upload ZIP)</span></span>`;
            }
          }
        })
        .withFailureHandler(function(err) {
          showGlobalAlert('Gagal memuat penilaian tugas: ' + err.message, 'error');
        })
        .apiGetGradesByTask(taskId, appState.activeClassId);
    }

    function renderGradingTableRows(grades) {
      const tbody = document.getElementById('gradingTableBody');
      if (!grades || grades.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-8 text-slate-400">Belum ada mahasiswa di kelas ini.</td></tr>';
        updateGradingStats([]);
        return;
      }

      updateGradingStats(grades);

      const filtered = getFilteredGradingStudents();

      if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-8 text-slate-400">Tidak ada mahasiswa dengan filter atau kata kunci pencarian tersebut.</td></tr>';
        return;
      }

      tbody.innerHTML = filtered.map(g => {
        const isCompleted = g.status && g.status.toLowerCase() === 'selesai';
        const isFailed = g.status && g.status.toLowerCase().startsWith('gagal');
        const isGrading = g.status && g.status.toLowerCase().includes('sedang');
        const isUnsubmitted = g.status && (g.status.toLowerCase().includes('belum mengumpulkan') || g.status.toLowerCase().includes('tidak mengumpulkan'));

        let statusBadge = '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600">Belum Dinilai</span>';
        let pointsContent = g.rincian_poin || '-';
        if (isCompleted) {
          statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700"><svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Selesai</span></span>';
        } else if (isUnsubmitted) {
          statusBadge = `
            <button type="button" onclick="openGradeDetailModal('${g.nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 hover:bg-amber-200 text-amber-800 transition inline-flex items-center gap-1 border border-amber-200 cursor-pointer shadow-2xs" title="Mahasiswa belum mengumpulkan berkas tugas">
              <svg class="w-3 h-3 text-amber-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>Belum Kumpul</span>
            </button>
          `;
          pointsContent = `<span class="text-amber-700 text-[11px] italic font-medium">Belum mengumpulkan tugas</span>`;
        } else if (isFailed) {
          const cleanReason = (g.status || 'Gagal').replace(/^Gagal:\s*/i, '');
          statusBadge = `
            <button type="button" onclick="openGradeDetailModal('${g.nim}')" class="px-2.5 py-1 rounded-full text-[10px] font-semibold bg-rose-100 hover:bg-rose-200 text-rose-800 transition inline-flex items-center gap-1 cursor-pointer shadow-sm text-left" title="Klik untuk rincian penyebab: ${cleanReason}">
              <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg><span>Gagal</span>
              <span class="text-[9px] text-rose-600 underline">Rincian</span>
            </button>
          `;
          pointsContent = `<span class="inline-flex items-center gap-1 text-rose-600 text-[11px] font-medium" title="${g.status}"><svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>${cleanReason}</span></span>`;
        } else if (isGrading) {
          statusBadge = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700 animate-pulse"><svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Menilai...</span></span>';
        }

        // Render Lencana Integritas (Plagiasi & AI)
        let integrityBadge = '<span class="text-slate-400 text-[10px]">-</span>';
        const hasAi = Number(g.ai_detected) === 1;
        const simScore = (g.similarity_score !== null && g.similarity_score !== undefined && g.similarity_score !== '') ? Number(g.similarity_score) : null;

        if (hasAi && simScore !== null && simScore >= 70) {
          integrityBadge = `
            <button type="button" onclick="openIntegrityModalForStudent('${g.nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 transition shadow-2xs inline-flex items-center gap-1 cursor-pointer" title="Terdeteksi Residu Chatbot AI & Kemiripan ${simScore}%">
              <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>AI &amp; ${simScore}%</span>
            </button>
          `;
        } else if (hasAi) {
          integrityBadge = `
            <button type="button" onclick="openIntegrityModalForStudent('${g.nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 transition shadow-2xs inline-flex items-center gap-1 cursor-pointer" title="Terdeteksi residu percakapan chatbot AI yang lupa dihapus mahasiswa">
              <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>Terindikasi AI</span>
            </button>
          `;
        } else if (simScore !== null) {
          if (simScore >= 70) {
            integrityBadge = `
              <button type="button" onclick="openIntegrityModalForStudent('${g.nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 transition shadow-2xs inline-flex items-center gap-1 cursor-pointer" title="Kemiripan tinggi ${simScore}% dengan NIM ${g.similarity_target_nim || '-'}">
                <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>${simScore}% Plagiasi</span>
              </button>
            `;
          } else if (simScore >= 40) {
            integrityBadge = `
              <button type="button" onclick="openIntegrityModalForStudent('${g.nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 hover:bg-amber-200 text-amber-800 border border-amber-300 transition shadow-2xs inline-flex items-center gap-1 cursor-pointer" title="Kemiripan sedang ${simScore}% dengan NIM ${g.similarity_target_nim || '-'}">
                <svg class="w-3 h-3 text-amber-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>${simScore}% Mirip</span>
              </button>
            `;
          } else {
            integrityBadge = `
              <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 inline-flex items-center gap-0.5" title="Tingkat kemiripan wajar (${simScore}%)">
                <svg class="w-3 h-3 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Bersih (${simScore}%)</span>
              </span>
            `;
          }
        } else if (isCompleted) {
          integrityBadge = `
            <button type="button" onclick="runIntegrityCheck()" class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 hover:bg-slate-200 text-slate-500 transition cursor-pointer" title="Klik 'Cek Integritas' untuk memindai plagiasi & AI">
              Belum Dicek
            </button>
          `;
        }

        const isChecked = appState.selectedStudentNims.has(g.nim);

        return `
          <tr id="row-student-${g.nim}" class="hover:bg-slate-50/80 transition ${isChecked ? 'bg-blue-50/40' : ''} ${isUnsubmitted ? 'bg-amber-50/20' : ''}">
            <td class="px-4 py-2.5 text-center">
              <input type="checkbox" onchange="toggleStudentSelection('${g.nim}', this.checked)" ${isChecked ? 'checked' : ''} class="rounded text-blue-600 focus:ring-0 cursor-pointer">
            </td>
            <td class="px-4 py-2.5 font-mono font-semibold text-slate-700">${g.nim}</td>
            <td class="px-4 py-2.5 font-medium text-slate-900">${g.nama_mahasiswa}</td>
            <td id="file-cell-${g.nim}" class="px-4 py-2.5 text-slate-500 truncate max-w-[170px]" title="${g.file_name || '-'}">${isUnsubmitted ? '<span class="text-slate-400 text-xs">-</span>' : renderFileNameCell(g.file_name, g.drive_file_url)}</td>
            <td id="score-cell-${g.nim}" class="px-4 py-2.5 text-center font-bold text-sm ${isCompleted ? 'text-blue-700' : 'text-slate-400'}">${g.nilai_total !== null && g.nilai_total !== undefined ? g.nilai_total : '-'}</td>
            <td id="points-cell-${g.nim}" class="px-4 py-2.5 text-slate-600 line-clamp-1 max-w-[200px]" title="${g.rincian_poin || g.status || '-'}">${pointsContent}</td>
            <td id="integrity-cell-${g.nim}" class="px-4 py-2.5 text-center">${integrityBadge}</td>
            <td id="status-cell-${g.nim}" class="px-4 py-2.5 text-center">${statusBadge}</td>
            <td class="px-4 py-2.5 text-center">
              <button onclick="openGradeDetailModal('${g.nim}')" class="px-2.5 py-1 bg-slate-100 hover:bg-blue-50 hover:text-blue-600 rounded-lg text-slate-600 font-medium transition" title="Lihat Ulasan & Koreksi Manual">
                <span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg><span>Detail</span></span>
              </button>
            </td>
          </tr>
        `;
      }).join('');
    }

    function toggleStudentSelection(nim, checked) {
      if (checked) {
        appState.selectedStudentNims.add(nim);
      } else {
        appState.selectedStudentNims.delete(nim);
      }
      updateSelectedCountBadge();
    }

    function toggleSelectAll(checked) {
      if (checked) {
        const visibleStudents = getFilteredGradingStudents();
        visibleStudents.forEach(g => appState.selectedStudentNims.add(g.nim));
      } else {
        appState.selectedStudentNims.clear();
      }
      renderGradingTableRows(appState.currentGrades);
      updateSelectedCountBadge();
    }

    function updateSelectedCountBadge() {
      const count = appState.selectedStudentNims.size;
      document.getElementById('selectedCountBadge').textContent = count;
      document.getElementById('btnGradeSelected').disabled = count === 0 || (typeof gradingQueue !== 'undefined' && gradingQueue.isRunning);
    }

    // ==========================================
    // CLIENT-DRIVEN REAL-TIME GRADING QUEUE
    // ==========================================

    const gradingQueue = {
      isRunning: false,
      isAborted: false,
      items: [],
      totalCount: 0,
      currentIndex: 0,
      successCount: 0,
      failedCount: 0,
      taskId: null,
      classId: null,
      originalDocTitle: document.title
    };

    function startGradingQueue(queueItems, queueTitle = 'Penilaian Mahasiswa') {
      if (gradingQueue.isRunning) {
        return alert('Penilaian sedang berlangsung. Harap tunggu atau klik "Hentikan" terlebih dahulu.');
      }
      if (!queueItems || queueItems.length === 0) {
        return alert('Tidak ada mahasiswa dalam antrean penilaian.');
      }
      if (!appState.activeClassId) {
        return alert('Pilih kelas terlebih dahulu.');
      }
      if (typeof appState.uploadedSubmissionCount !== 'undefined' && appState.uploadedSubmissionCount === 0) {
        const isOAuthConnected = appState.settings?.googleOAuth?.isConnected;
        const task = appState.currentTasks.find(t => t.id_tugas === appState.selectedGradingTaskId);
        const hasDriveFolder = task && task.drive_folder_id;

        if (isOAuthConnected && hasDriveFolder) {
          showGlobalAlert('Berkas di server masih kosong. Menyinkronkan berkas tugas otomatis dari Google Drive...', 'info');
          syncCurrentTaskDriveFolder(true)
            .then(() => {
              startGradingQueue(queueItems, queueTitle);
            })
            .catch(err => {
              alert('Gagal menyinkronkan berkas tugas dari Google Drive: ' + err.message);
            });
          return;
        }

        showGlobalAlert('Belum ada berkas tugas mahasiswa yang diunggah ke server untuk kelas ini. Silakan klik "Sinkron Drive" atau "Upload ZIP Tugas".', 'warning');
        openUploadZipModal();
        return;
      }

      gradingQueue.isRunning = true;
      gradingQueue.isAborted = false;
      gradingQueue.items = queueItems;
      gradingQueue.totalCount = queueItems.length;
      gradingQueue.currentIndex = 0;
      gradingQueue.successCount = 0;
      gradingQueue.failedCount = 0;
      gradingQueue.taskId = appState.selectedGradingTaskId;
      gradingQueue.classId = appState.activeClassId;
      gradingQueue.originalDocTitle = document.title;

      const banner = document.getElementById('gradingCompletionBanner');
      if (banner) banner.classList.add('hidden');

      const panel = document.getElementById('gradingProgressPanel');
      if (panel) panel.classList.remove('hidden');
      document.getElementById('progressPanelTitle').textContent = queueTitle;
      document.getElementById('progressPanelSubtitle').textContent = 'Mempersiapkan antrean ' + queueItems.length + ' mahasiswa...';
      document.getElementById('gradingProgressBox').innerHTML = '';

      document.getElementById('btnGradeSelected').disabled = true;
      document.getElementById('btnGradeAll').disabled = true;

      updateGradingProgressUI();
      showProgressLog('▶️ Memulai antrean penilaian: ' + queueItems.length + ' mahasiswa akan dinilai secara otomatis.');

      processNextQueueItem();
    }

    function processNextQueueItem() {
      if (!gradingQueue.isRunning) return;

      if (gradingQueue.isAborted) {
        finishGradingQueue(true);
        return;
      }

      if (gradingQueue.currentIndex >= gradingQueue.totalCount) {
        finishGradingQueue(false);
        return;
      }

      const item = gradingQueue.items[gradingQueue.currentIndex];
      const nim = item.nim;
      const nama = item.nama_mahasiswa || item.nama || nim;
      const currentNumber = gradingQueue.currentIndex + 1;

      document.getElementById('progressPanelSubtitle').textContent = 'Sedang mengevaluasi (' + currentNumber + '/' + gradingQueue.totalCount + '): ' + nim + ' - ' + nama;
      document.title = '(' + currentNumber + '/' + gradingQueue.totalCount + ') Menilai ' + nama + '... - Asisten Penilaian';
      showProgressLog('[' + currentNumber + '/' + gradingQueue.totalCount + '] Memeriksa berkas & mengevaluasi jawaban NIM: ' + nim + ' (' + nama + ')...');

      updateStudentRowState(nim, 'grading');

      // Evaluasi 1 mahasiswa dengan menyertakan classId
      google.script.run
        .withSuccessHandler(function(res) {
          if (!gradingQueue.isRunning) return;

          if (res.success) {
            if (res.status === 'Belum Mengumpulkan' || res.isSubmitted === false) {
              updateStudentRowState(nim, 'unsubmitted', res);
              showProgressLog('[LEWATI] NIM: ' + nim + ' (' + nama + ') belum mengumpulkan tugas.');
            } else {
              gradingQueue.successCount++;
              updateStudentRowState(nim, 'success', res);
              const contentNote = (res.matchedBy === 'content' || (res.fileName && res.fileName.includes('[via isi]'))) ? ' [Ditemukan via Isi Berkas]' : '';
              showProgressLog('[SUKSES] NIM: ' + nim + ' (' + nama + ') berhasil dinilai. Skor: ' + res.score + ' (' + (res.fileName || 'Berkas Drive') + contentNote + ')');
            }
          } else {
            gradingQueue.failedCount++;
            updateStudentRowState(nim, 'failed', res);
            showProgressLog('[GAGAL] NIM: ' + nim + ' (' + nama + ') gagal: ' + (res.error || res.status) + (res.advice ? ' (Solusi: ' + res.advice + ')' : ''));
          }

          gradingQueue.currentIndex++;
          updateGradingProgressUI();

          setTimeout(processNextQueueItem, 500);
        })
        .withFailureHandler(function(err) {
          if (!gradingQueue.isRunning) return;

          gradingQueue.failedCount++;
          const errRes = {
            success: false,
            nim: nim,
            nama: nama,
            error: err.message,
            status: 'Gagal: ' + err.message,
            catatan_ai: '[ERROR JARINGAN/SERVER]:\n' + err.message
          };
          updateStudentRowState(nim, 'failed', errRes);
          showProgressLog('[ERROR] NIM: ' + nim + ' (' + nama + ') gagal: ' + err.message);

          gradingQueue.currentIndex++;
          updateGradingProgressUI();

          setTimeout(processNextQueueItem, 1000);
        })
        .apiGradeSingleStudent(item.taskId || gradingQueue.taskId, gradingQueue.classId, nim);
    }

    function updateStudentRowState(nim, state, data) {
      const row = document.getElementById('row-student-' + nim);
      const scoreCell = document.getElementById('score-cell-' + nim);
      const pointsCell = document.getElementById('points-cell-' + nim);
      const integrityCell = document.getElementById('integrity-cell-' + nim);
      const statusCell = document.getElementById('status-cell-' + nim);
      const fileCell = document.getElementById('file-cell-' + nim);

      if (state === 'grading') {
        if (row) row.className = 'bg-amber-50/70 border-l-4 border-amber-400 transition';
        if (statusCell) statusCell.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800 animate-pulse"><svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Menilai...</span></span>';
      } else if (state === 'unsubmitted' || (data && (data.status === 'Belum Mengumpulkan' || data.isSubmitted === false))) {
        if (row) row.className = 'bg-amber-50/20 transition';
        if (scoreCell) {
          scoreCell.textContent = '-';
          scoreCell.className = 'px-4 py-2.5 text-center font-bold text-sm text-slate-400';
        }
        if (pointsCell) {
          pointsCell.innerHTML = '<span class="text-amber-700 text-[11px] italic font-medium">Belum mengumpulkan tugas</span>';
        }
        if (fileCell) {
          fileCell.innerHTML = '<span class="text-slate-400 text-xs">-</span>';
        }
        if (statusCell) {
          statusCell.innerHTML = `
            <button type="button" onclick="openGradeDetailModal('${nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 hover:bg-amber-200 text-amber-800 transition inline-flex items-center gap-1 border border-amber-200 cursor-pointer shadow-2xs" title="Mahasiswa belum mengumpulkan berkas tugas">
              <svg class="w-3 h-3 text-amber-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span>Belum Kumpul</span>
            </button>
          `;
        }

        const idx = appState.currentGrades.findIndex(g => g.nim === nim);
        if (idx !== -1) {
          appState.currentGrades[idx].nilai_total = null;
          appState.currentGrades[idx].rincian_poin = '-';
          appState.currentGrades[idx].catatan_ai = data ? (data.catatan_ai || 'Mahasiswa belum mengumpulkan berkas tugas di folder Drive/server.') : 'Mahasiswa belum mengumpulkan berkas tugas di folder Drive/server.';
          appState.currentGrades[idx].file_name = '-';
          appState.currentGrades[idx].status = 'Belum Mengumpulkan';
        }
      } else if (state === 'success' && data) {
        if (row) row.className = 'bg-emerald-50/60 transition';
        if (scoreCell) {
          scoreCell.textContent = data.score;
          scoreCell.className = 'px-4 py-2.5 text-center font-bold text-sm text-blue-700';
        }
        if (pointsCell) {
          pointsCell.textContent = data.rincian_poin || '-';
          pointsCell.title = data.rincian_poin || '-';
        }
        if (fileCell) {
          fileCell.innerHTML = renderFileNameCell(data.fileName, data.driveUrl || '');
        }
        if (statusCell) {
          statusCell.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700"><svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Selesai</span></span>';
        }
        if (data.ai_detected !== undefined && integrityCell) {
          if (Number(data.ai_detected) === 1) {
            integrityCell.innerHTML = `
              <button type="button" onclick="openIntegrityModalForStudent('${nim}')" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 transition shadow-2xs inline-flex items-center gap-1 cursor-pointer" title="Terdeteksi residu percakapan chatbot AI yang lupa dihapus mahasiswa">
                <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>Terindikasi AI</span>
              </button>
            `;
          }
        }

        const idx = appState.currentGrades.findIndex(g => g.nim === nim);
        if (idx !== -1) {
          appState.currentGrades[idx].nilai_total = data.score;
          appState.currentGrades[idx].rincian_poin = data.rincian_poin;
          appState.currentGrades[idx].catatan_ai = data.catatan_ai;
          appState.currentGrades[idx].file_name = data.fileName;
          appState.currentGrades[idx].drive_file_url = data.driveUrl || '';
          appState.currentGrades[idx].status = 'Selesai';
          if (data.ai_detected !== undefined) {
            appState.currentGrades[idx].ai_detected = data.ai_detected;
            appState.currentGrades[idx].ai_detected_details = data.ai_detected_details;
          }
        }
      } else if (state === 'failed' && data) {
        if (row) row.className = 'bg-rose-50/60 transition';
        const cleanReason = (data.error || data.status || 'Gagal').replace(/^Gagal:\s*/i, '');
        if (pointsCell) {
          pointsCell.innerHTML = '<span class="inline-flex items-center gap-1 text-rose-600 text-[11px] font-medium" title="' + cleanReason + '"><svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z\"/></svg><span>' + cleanReason + '</span></span>';
        }
        if (statusCell) {
          statusCell.innerHTML = `
            <button type="button" onclick="openGradeDetailModal('${nim}')" class="px-2.5 py-1 rounded-full text-[10px] font-semibold bg-rose-100 hover:bg-rose-200 text-rose-800 transition inline-flex items-center gap-1 cursor-pointer shadow-sm text-left" title="Klik untuk rincian: ${cleanReason}">
              <svg class="w-3 h-3 text-rose-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg><span>Gagal</span>
              <span class="text-[9px] text-rose-600 underline">Rincian</span>
            </button>
          `;
        }

        const idx = appState.currentGrades.findIndex(g => g.nim === nim);
        if (idx !== -1) {
          appState.currentGrades[idx].status = data.status || ('Gagal: ' + cleanReason);
          appState.currentGrades[idx].catatan_ai = data.catatan_ai || cleanReason;
        }
      }

      // Perbarui statistik secara instan setelah setiap evaluasi mahasiswa selesai
      updateGradingStats(appState.currentGrades);
    }

    function updateGradingProgressUI() {
      const cur = gradingQueue.currentIndex;
      const total = gradingQueue.totalCount;
      const pct = total > 0 ? Math.round((cur / total) * 100) : 0;

      const fill = document.getElementById('progressBarFill');
      if (fill) fill.style.width = pct + '%';

      const counter = document.getElementById('progressPanelCounter');
      if (counter) counter.textContent = cur + ' / ' + total + ' (' + pct + '%)';
    }

    function abortGradingQueue() {
      if (!gradingQueue.isRunning) return;
      const confirmAbort = confirm('Apakah Anda yakin ingin menghentikan antrean penilaian? Mahasiswa yang sudah dinilai akan tetap tersimpan.');
      if (confirmAbort) {
        gradingQueue.isAborted = true;
        document.getElementById('progressPanelSubtitle').textContent = 'Menghentikan antrean penilaian...';
        showProgressLog('Pengguna meminta penghentian antrean...');
      }
    }

    function finishGradingQueue(wasAborted) {
      gradingQueue.isRunning = false;
      document.title = gradingQueue.originalDocTitle || 'Asisten Penilaian Mahasiswa';

      document.getElementById('btnGradeSelected').disabled = false;
      document.getElementById('btnGradeAll').disabled = false;
      updateSelectedCountBadge();

      const panel = document.getElementById('gradingProgressPanel');
      if (panel) panel.classList.add('hidden');

      const banner = document.getElementById('gradingCompletionBanner');
      if (banner) {
        banner.classList.remove('hidden');
        if (wasAborted) {
          document.getElementById('completionTitle').textContent = 'Penilaian Dihentikan';
          document.getElementById('completionDesc').textContent = 'Antrean dihentikan: ' + gradingQueue.successCount + ' mahasiswa berhasil dinilai, ' + gradingQueue.failedCount + ' gagal.';
        } else {
          document.getElementById('completionTitle').textContent = 'Penilaian Selesai!';
          document.getElementById('completionDesc').textContent = 'Seluruh antrean telah selesai dievaluasi: ' + gradingQueue.successCount + ' berhasil, ' + gradingQueue.failedCount + ' gagal.';
          playSuccessChime();
        }
      }

      showProgressLog(wasAborted ? 'Antrean penilaian dihentikan.' : 'Antrean penilaian selesai seluruhnya!');

      // Perbarui statistik tugas di latar belakang agar data di tab Dashboard & Tugas seketika baru
      if (appState.activeClassId) {
        google.script.run
          .withSuccessHandler(function(tasks) {
            appState.currentTasks = tasks || [];
            renderDashboardStats();
          })
          .apiGetTasksByClass(appState.activeClassId);
      }
    }

    function playSuccessChime() {
      try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.12); // A5
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.4);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.45);
      } catch (e) {}
    }

    function gradeSelectedStudents() {
      const taskId = appState.selectedGradingTaskId;
      const nims = Array.from(appState.selectedStudentNims);
      if (!taskId) return alert('Pilih tugas terlebih dahulu.');
      if (nims.length === 0) return alert('Pilih minimal satu mahasiswa yang ingin dinilai.');

      const items = appState.currentGrades.filter(g => nims.includes(g.nim));
      startGradingQueue(items, 'Menilai ' + items.length + ' Mahasiswa Terpilih');
    }

    function gradeAllStudents() {
      const taskId = appState.selectedGradingTaskId;
      if (!taskId) return alert('Pilih tugas terlebih dahulu.');

      const unassessed = appState.currentGrades.filter(g => !g.status || g.status.toLowerCase() !== 'selesai');
      if (unassessed.length === 0) {
        if (!confirm('Seluruh mahasiswa sudah selesai dinilai. Apakah Anda ingin menilai ulang seluruh mahasiswa?')) {
          return;
        }
        startGradingQueue(appState.currentGrades, 'Menilai Ulang Seluruh Mahasiswa (' + appState.currentGrades.length + ' Mhs)');
        return;
      }

      startGradingQueue(unassessed, 'Menilai Mahasiswa Belum Selesai (' + unassessed.length + ' Mhs)');
    }

    function resetFailedGrades() {
      const taskId = appState.selectedGradingTaskId;
      if (!taskId) return;
      if (!appState.activeClassId) return alert('Pilih kelas terlebih dahulu.');

      if (typeof gradingQueue !== 'undefined' && gradingQueue.isRunning) {
        return alert('Harap tunggu antrean penilaian selesai sebelum melakukan reset.');
      }

      google.script.run
        .withSuccessHandler(function(res) {
          showGlobalAlert(res.message, 'info');
          loadGradingTable(taskId);
        })
        .withFailureHandler(function(err) {
          showGlobalAlert('Gagal reset: ' + err.message, 'error');
        })
        .apiResetFailedGrades(taskId, appState.activeClassId);
    }

    function showProgressLog(msg) {
      const box = document.getElementById('gradingProgressBox');
      if (!box) return;
      const p = document.createElement('p');
      p.className = 'leading-relaxed break-words';
      p.textContent = '[' + new Date().toLocaleTimeString('id-ID') + '] ' + msg;
      box.appendChild(p);
      box.scrollTop = box.scrollHeight;
    }

    // ==========================================
    // REKAPITULASI NILAI (TAB 5)
    // ==========================================

    let reportSortState = { colKey: null, direction: 'asc' };
    let currentReportData = null;

    function loadClassReport(classId) {
      const container = document.getElementById('reportTableContainer');
      if (!classId) {
        if (container) {
          container.innerHTML = '<div class="text-center py-12 text-slate-400 text-sm">Pilih kelas pada menu di atas untuk menampilkan rekapitulasi nilai.</div>';
        }
        return;
      }
      if (container) {
        container.innerHTML = '<div class="text-center py-12 text-slate-400 text-sm"><span class="spinner inline-block w-4 h-4 mr-2 border-slate-400 border-t-blue-600"></span>Memuat rekapitulasi nilai...</div>';
      }

      google.script.run
        .withSuccessHandler(function(report) {
          currentReportData = report;
          reportSortState = { colKey: null, direction: 'asc' };
          renderReportTable();
        })
        .withFailureHandler(function(err) {
          container.innerHTML = '<div class="text-center py-12 text-rose-500 text-sm">Gagal memuat rekapitulasi: ' + err.message + '</div>';
        })
        .apiGetClassReport(classId);
    }

    function filterReportTable(query) {
      renderReportTable();
    }

    function handleSortReport(colKey) {
      if (reportSortState.colKey === colKey) {
        reportSortState.direction = reportSortState.direction === 'asc' ? 'desc' : 'asc';
      } else {
        reportSortState.colKey = colKey;
        reportSortState.direction = (colKey === 'nim' || colKey === 'nama') ? 'asc' : 'desc';
      }
      renderReportTable();
    }

    function getFilteredAndSortedReportStudents() {
      if (!currentReportData || !currentReportData.students) return [];
      const query = (document.getElementById('reportSearchInput')?.value || '').trim().toLowerCase();

      let list = currentReportData.students.filter(s => {
        if (!query) return true;
        return (s.nim && s.nim.toLowerCase().includes(query)) ||
               (s.nama_mahasiswa && s.nama_mahasiswa.toLowerCase().includes(query));
      });

      if (reportSortState.colKey) {
        const key = reportSortState.colKey;
        const dir = reportSortState.direction === 'asc' ? 1 : -1;

        list.sort((a, b) => {
          let valA, valB;
          if (key === 'nim') {
            valA = a.nim || '';
            valB = b.nim || '';
            return valA.localeCompare(valB) * dir;
          } else if (key === 'nama') {
            valA = a.nama_mahasiswa || '';
            valB = b.nama_mahasiswa || '';
            return valA.localeCompare(valB) * dir;
          } else if (key === 'avg') {
            valA = Number(a.averageScore) || 0;
            valB = Number(b.averageScore) || 0;
            return (valA - valB) * dir;
          } else if (key === 'final') {
            valA = Number(a.finalScore) || 0;
            valB = Number(b.finalScore) || 0;
            return (valA - valB) * dir;
          } else {
            valA = a.taskScores && a.taskScores[key] !== null ? Number(a.taskScores[key]) : -1;
            valB = b.taskScores && b.taskScores[key] !== null ? Number(b.taskScores[key]) : -1;
            return (valA - valB) * dir;
          }
        });
      }

      return list;
    }

    function renderReportTable() {
      const container = document.getElementById('reportTableContainer');
      if (!currentReportData || !currentReportData.tasks) {
        container.innerHTML = '<div class="text-center py-12 text-slate-400 text-sm">Belum ada data tugas pada kelas ini.</div>';
        return;
      }

      const report = currentReportData;
      const tasks = report.tasks;
      const students = getFilteredAndSortedReportStudents();

      let headerHtml = `
        <table class="w-full text-left text-xs" id="rekapMatrixTable">
          <thead class="bg-slate-900 text-white font-semibold select-none">
            <tr>
              <th class="px-3.5 py-3.5 w-12 text-center">No</th>
              <th onclick="handleSortReport('nim')" class="px-4 py-3.5 w-32 cursor-pointer hover:bg-slate-800 transition">
                <div class="flex items-center justify-between gap-1">
                  <span>NIM</span>
                  <span class="text-[10px] text-slate-400">${sortIndicator('nim')}</span>
                </div>
              </th>
              <th onclick="handleSortReport('nama')" class="px-4 py-3.5 w-52 cursor-pointer hover:bg-slate-800 transition">
                <div class="flex items-center justify-between gap-1">
                  <span>Nama Mahasiswa</span>
                  <span class="text-[10px] text-slate-400">${sortIndicator('nama')}</span>
                </div>
              </th>
      `;

      tasks.forEach(t => {
        const weightLabel = t.bobot_persentase > 0 ? (' (' + t.bobot_persentase + '%)') : '';
        headerHtml += `
          <th class="px-4 py-3.5 text-center min-w-[140px] hover:bg-slate-800 transition group relative">
            <div class="flex items-center justify-between gap-1">
              <span onclick="handleSortReport('${t.id_tugas}')" class="cursor-pointer truncate max-w-[120px]" title="${t.judul_tugas}${weightLabel}">
                ${t.judul_tugas}${weightLabel}
              </span>
              <div class="flex items-center gap-1">
                <button type="button" onclick="copyReportColumn('${t.id_tugas}', '${t.judul_tugas}')" class="text-slate-400 hover:text-white text-[11px] p-0.5 rounded transition" title="Salin seluruh nilai kolom ${t.judul_tugas}">
                  <svg class="w-3.5 h-3.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                </button>
                <span onclick="handleSortReport('${t.id_tugas}')" class="cursor-pointer text-[10px] text-slate-400">${sortIndicator(t.id_tugas)}</span>
              </div>
            </div>
          </th>
        `;
      });

      headerHtml += `
              <th onclick="handleSortReport('avg')" class="px-4 py-3.5 text-center w-28 bg-slate-800 cursor-pointer hover:bg-slate-700 transition">
                <div class="flex items-center justify-between gap-1">
                  <span>Rata-rata</span>
                  <div class="flex items-center gap-1">
                    <button type="button" onclick="event.stopPropagation(); copyReportColumn('avg', 'Rata-rata')" class="text-slate-400 hover:text-white text-[11px] p-0.5 rounded" title="Salin seluruh nilai kolom Rata-rata"><svg class="w-3.5 h-3.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg></button>
                    <span class="text-[10px] text-slate-400">${sortIndicator('avg')}</span>
                  </div>
                </div>
              </th>
              <th onclick="handleSortReport('final')" class="px-4 py-3.5 text-center w-36 bg-blue-900/80 cursor-pointer hover:bg-blue-800 transition">
                <div class="flex items-center justify-between gap-1">
                  <span>Nilai Terbobot</span>
                  <div class="flex items-center gap-1">
                    <button type="button" onclick="event.stopPropagation(); copyReportColumn('final', 'Nilai Akhir Terbobot')" class="text-blue-200 hover:text-white text-[11px] p-0.5 rounded" title="Salin seluruh nilai kolom Nilai Terbobot"><svg class="w-3.5 h-3.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg></button>
                    <span class="text-[10px] text-blue-200">${sortIndicator('final')}</span>
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
      `;

      if (students.length === 0) {
        headerHtml += '<tr><td colspan="' + (5 + tasks.length) + '" class="text-center py-10 text-slate-400">Tidak ada data mahasiswa yang cocok dengan pencarian.</td></tr>';
      } else {
        students.forEach((s, idx) => {
          headerHtml += `
            <tr class="hover:bg-slate-50 transition">
              <td class="px-3.5 py-2.5 text-center text-slate-400 font-mono text-[11px]">${idx + 1}</td>
              <td class="px-4 py-2.5 font-mono font-semibold text-slate-700">${s.nim}</td>
              <td class="px-4 py-2.5 font-medium text-slate-900">${s.nama_mahasiswa}</td>
          `;

          tasks.forEach(t => {
            const score = s.taskScores ? s.taskScores[t.id_tugas] : null;
            const displayScore = (score !== null && score !== undefined) ? score : '-';
            const colorClass = score !== null ? (score >= 70 ? 'text-emerald-700 font-bold' : 'text-slate-700 font-semibold') : 'text-slate-300';
            headerHtml += `<td class="px-4 py-2.5 text-center ${colorClass}">${displayScore}</td>`;
          });

          headerHtml += `
              <td class="px-4 py-2.5 text-center font-bold text-slate-700 bg-slate-50/50">${s.averageScore > 0 ? s.averageScore : '-'}</td>
              <td class="px-4 py-2.5 text-center font-extrabold text-blue-700 bg-blue-50/40 text-sm">${s.finalScore > 0 ? s.finalScore : '-'}</td>
            </tr>
          `;
        });
      }

      headerHtml += '</tbody></table>';
      container.innerHTML = headerHtml;
    }

    function sortIndicator(key) {
      if (reportSortState.colKey !== key) return '⇅';
      return reportSortState.direction === 'asc' ? '▲' : '▼';
    }

    function copyReportColumn(colKey, colTitle) {
      const students = getFilteredAndSortedReportStudents();
      if (!students || students.length === 0) {
        return alert('Tidak ada data baris yang dapat disalin.');
      }

      const lines = students.map(s => {
        if (colKey === 'nim') return s.nim;
        if (colKey === 'nama') return s.nama_mahasiswa;
        if (colKey === 'avg') return s.averageScore > 0 ? String(s.averageScore) : '';
        if (colKey === 'final') return s.finalScore > 0 ? String(s.finalScore) : '';
        const sc = s.taskScores ? s.taskScores[colKey] : null;
        return (sc !== null && sc !== undefined) ? String(sc) : '';
      });

      const text = lines.join('\n');
      copyTextToClipboard(text, 'Kolom "' + colTitle + '" (' + lines.length + ' baris) berhasil disalin ke clipboard!');
    }

    function copyAllReportData() {
      if (!currentReportData || !currentReportData.tasks) {
        return alert('Data rekapitulasi belum dimuat.');
      }
      const tasks = currentReportData.tasks;
      const students = getFilteredAndSortedReportStudents();

      const headers = ['No', 'NIM', 'Nama Mahasiswa'];
      tasks.forEach(t => headers.push(t.judul_tugas + (t.bobot_persentase > 0 ? ' (' + t.bobot_persentase + '%)' : '')));
      headers.push('Rata-rata');
      headers.push('Nilai Terbobot');

      const rows = [headers.join('\t')];
      students.forEach((s, idx) => {
        const r = [idx + 1, s.nim, s.nama_mahasiswa];
        tasks.forEach(t => {
          const score = s.taskScores ? s.taskScores[t.id_tugas] : null;
          r.push(score !== null && score !== undefined ? score : '');
        });
        r.push(s.averageScore > 0 ? s.averageScore : '');
        r.push(s.finalScore > 0 ? s.finalScore : '');
        rows.push(r.join('\t'));
      });

      const fullText = rows.join('\n');
      copyTextToClipboard(fullText, 'Seluruh tabel matriks rekapitulasi (' + students.length + ' mahasiswa) berhasil disalin!');
    }

    function copyTextToClipboard(text, successMsg) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
          showGlobalAlert(successMsg, 'success');
        }).catch(function() {
          fallbackCopyText(text, successMsg);
        });
      } else {
        fallbackCopyText(text, successMsg);
      }
    }

    function fallbackCopyText(text, successMsg) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        showGlobalAlert(successMsg, 'success');
      } catch (e) {
        alert('Gagal menyalin otomatis. Harap gunakan browser modern.');
      }
      document.body.removeChild(ta);
    }

    function downloadClassReportExcel() {
      if (!appState.activeClassId) return alert('Pilih kelas terlebih dahulu.');
      const btn = document.getElementById('btnDownloadExcel');
      const btnText = document.getElementById('btnDownloadExcelText');
      if (btn) btn.disabled = true;
      if (btnText) btnText.textContent = 'Menyiapkan Excel...';

      google.script.run
        .withSuccessHandler(function(exportData) {
          if (btn) btn.disabled = false;
          if (btnText) btnText.textContent = 'Download Excel';

          if (!exportData || !exportData.success) {
            return alert('Gagal menyiapkan data ekspor: ' + (exportData ? exportData.message : 'Unknown error'));
          }

          try {
            if (typeof XLSX === 'undefined') {
              throw new Error('Pustaka SheetJS (XLSX) belum siap dimuat di peramban Anda.');
            }

            const wb = XLSX.utils.book_new();
            const report = exportData.report;
            const tasks = report.tasks || [];
            const students = report.students || [];
            const classInfo = exportData.classInfo || {};

            // Sheet 1: Rekapitulasi Nilai Kelas
            const rekapRows = [];
            rekapRows.push(['REKAPITULASI PENILAIAN MAHASISWA']);
            rekapRows.push(['Mata Kuliah: ' + (classInfo.mata_kuliah || '-') + ' | Kelas: ' + (classInfo.nama_kelas || '-') + ' | Semester: ' + (classInfo.semester || '-')]);
            rekapRows.push([]);

            const headerRow = ['No', 'NIM', 'Nama Mahasiswa'];
            tasks.forEach(t => {
              headerRow.push(t.judul_tugas + (t.bobot_persentase > 0 ? ' (' + t.bobot_persentase + '%)' : ''));
            });
            headerRow.push('Rata-rata');
            headerRow.push('Nilai Akhir Terbobot');
            rekapRows.push(headerRow);

            students.forEach((s, idx) => {
              const r = [idx + 1, s.nim, s.nama_mahasiswa];
              tasks.forEach(t => {
                const sc = s.taskScores ? s.taskScores[t.id_tugas] : null;
                r.push(sc !== null && sc !== undefined ? sc : '-');
              });
              r.push(s.averageScore > 0 ? s.averageScore : 0);
              r.push(s.finalScore > 0 ? s.finalScore : 0);
              rekapRows.push(r);
            });

            const wsRekap = XLSX.utils.aoa_to_sheet(rekapRows);
            XLSX.utils.book_append_sheet(wb, wsRekap, 'Rekapitulasi Kelas');

            // Sheet 2 dst: Rincian Tiap Tugas
            const taskDetails = exportData.taskDetails || [];
            taskDetails.forEach((td, tIdx) => {
              const tTask = td.task;
              const tGrades = td.grades || [];

              const rawSheetName = 'Tugas ' + (tIdx + 1) + ' - ' + (tTask.judul_tugas || 'Detail');
              const safeSheetName = rawSheetName.replace(/[\\/*?:\[\]]/g, ' ').substring(0, 30);

              const detailRows = [];
              detailRows.push(['DETAIL PENILAIAN: ' + tTask.judul_tugas.toUpperCase()]);
              detailRows.push(['Bobot: ' + (tTask.bobot_persentase || 0) + '% | Soal: ' + (tTask.task_file_ref || '-')]);
              detailRows.push(['Rubrik Acuan: ' + (tTask.rubrik_text ? tTask.rubrik_text.replace(/\r?\n/g, ' ') : '-')]);
              detailRows.push([]);

              detailRows.push(['No', 'NIM', 'Nama Mahasiswa', 'Berkas Jawaban', 'Nilai Total', 'Rincian Poin Soal', 'Catatan AI', 'Status', 'Waktu Penilaian']);

              tGrades.forEach(g => {
                detailRows.push([
                  g.no,
                  g.nim,
                  g.nama_mahasiswa,
                  g.file_name,
                  g.nilai_total,
                  g.rincian_poin,
                  g.catatan_ai,
                  g.status,
                  g.waktu_penilaian
                ]);
              });

              const wsDetail = XLSX.utils.aoa_to_sheet(detailRows);
              XLSX.utils.book_append_sheet(wb, wsDetail, safeSheetName);
            });

            const cleanClassName = (classInfo.nama_kelas || 'Kelas').replace(/[\\/*?:\s]/g, '_');
            const fileName = 'Rekap_Nilai_' + cleanClassName + '_' + new Date().toISOString().substring(0, 10) + '.xlsx';

            XLSX.writeFile(wb, fileName);
            showGlobalAlert('File Excel multi-sheet berhasil diunduh: ' + fileName, 'success');
          } catch (excelErr) {
            alert('Gagal membuat file Excel: ' + excelErr.message);
          }
        })
        .withFailureHandler(function(err) {
          if (btn) btn.disabled = false;
          if (btnText) btnText.textContent = 'Download Excel';
          alert('Gagal mengambil data rekapitulasi: ' + err.message);
        })
        .apiGetClassFullExportData(appState.activeClassId);
    }

    // ==========================================
    // MODAL HANDLERS
    // ==========================================

    function openModal(modalId) {
      document.getElementById(modalId).classList.remove('hidden');
    }

    function closeModal(modalId) {
      document.getElementById(modalId).classList.add('hidden');
    }

    function openUploadZipModal() {
      const taskId = appState.selectedGradingTaskId;
      if (!taskId) return alert('Pilih tugas terlebih dahulu!');
      if (!appState.activeClassId) return alert('Pilih kelas terlebih dahulu!');
      document.getElementById('inputZipFile').value = '';
      const alertBox = document.getElementById('uploadZipAlert');
      if (alertBox) alertBox.classList.add('hidden');
      openModal('uploadZipModal');
    }

    async function submitUploadZip() {
      const fileInput = document.getElementById('inputZipFile');
      const files = fileInput.files;
      if (!files || files.length === 0) return alert('Pilih berkas tugas terlebih dahulu!');

      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;
      const btn = document.getElementById('btnSubmitZip');
      const alertBox = document.getElementById('uploadZipAlert');

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner inline-block w-3.5 h-3.5 border-2 border-white/50 border-t-white mr-1"></span> Mengunggah & Memproses...';
      if (alertBox) {
        alertBox.className = 'p-2.5 rounded-xl text-xs bg-blue-50 text-blue-800 border border-blue-200 block';
        alertBox.textContent = 'Sedang mengunggah dan memproses berkas di server...';
      }

      try {
        const res = await AppAPI.uploadSubmissionsZip(taskId, classId, files);
        if (alertBox) {
          alertBox.className = 'p-2.5 rounded-xl text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 block';
          alertBox.textContent = res.message;
        }
        showGlobalAlert(res.message, 'success');
        setTimeout(() => {
          closeModal('uploadZipModal');
          btn.disabled = false;
          btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg> <span>Unggah &amp; Proses</span>';
          loadGradingTable(taskId);
        }, 1200);
      } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg> <span>Unggah &amp; Proses</span>';
        if (alertBox) {
          alertBox.className = 'p-2.5 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block';
          alertBox.textContent = 'Gagal mengunggah: ' + err.message;
        }
      }
    }

    // 0. Mata Kuliah Modal
    function openCourseModal(courseData = null) {
      document.getElementById('courseModalTitle').textContent = courseData ? 'Edit Mata Kuliah' : 'Tambah Mata Kuliah Baru';
      document.getElementById('modalCourseId').value = courseData ? courseData.id_mata_kuliah : '';
      document.getElementById('modalCourseKode').value = courseData ? (courseData.kode_mk || '') : '';
      document.getElementById('modalCourseName').value = courseData ? courseData.nama_mata_kuliah : '';
      document.getElementById('modalCourseSemester').value = courseData ? (courseData.semester || '') : '';
      openModal('courseModal');
    }

    function editCourse(courseId) {
      const target = appState.courses.find(c => c.id_mata_kuliah === courseId);
      if (target) openCourseModal(target);
    }

    function submitSaveCourse() {
      const name = document.getElementById('modalCourseName').value.trim();
      if (!name) return alert('Nama mata kuliah wajib diisi!');

      const data = {
        id_mata_kuliah: document.getElementById('modalCourseId').value,
        kode_mk: document.getElementById('modalCourseKode').value.trim(),
        nama_mata_kuliah: name,
        semester: document.getElementById('modalCourseSemester').value.trim()
      };

      const btn = document.getElementById('btnSubmitCourse');
      if (btn) { btn.disabled = true; btn.textContent = 'Menyimpan...'; }

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) { btn.disabled = false; btn.textContent = 'Simpan Mata Kuliah'; }
          closeModal('courseModal');
          showGlobalAlert('Mata kuliah berhasil disimpan!', 'success');
          reloadAllData(res.id_mata_kuliah);
        })
        .withFailureHandler(function(err) {
          if (btn) { btn.disabled = false; btn.textContent = 'Simpan Mata Kuliah'; }
          alert('Gagal menyimpan mata kuliah: ' + err.message);
        })
        .apiSaveCourse(data);
    }

    function confirmDeleteCourse(courseId, courseName) {
      if (!confirm('Hapus Mata Kuliah "' + courseName + '"?\n\nPERINGATAN: Seluruh kelas, tugas, mahasiswa, dan nilai di bawah mata kuliah ini akan ikut terhapus!')) {
        return;
      }
      showLoading(true);
      google.script.run
        .withSuccessHandler(function(res) {
          showLoading(false);
          showGlobalAlert(res.message, 'success');
          reloadAllData();
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal menghapus mata kuliah: ' + err.message, 'error');
        })
        .apiDeleteCourse(courseId);
    }

    // 1. Kelas Modal
    function openClassModal(classData = null) {
      if (!appState.courses || appState.courses.length === 0) {
        alert('Belum ada mata kuliah yang terdaftar.\nSistem akan membuka form Tambah Mata Kuliah terlebih dahulu agar kelas Anda terhubung ke mata kuliah.');
        openCourseModal();
        return;
      }
      const titleEl = document.getElementById('classModalTitle');
      if (titleEl) titleEl.textContent = classData ? 'Edit Kelas' : 'Tambah Kelas Baru';

      const idEl = document.getElementById('modalClassId');
      if (idEl) idEl.value = classData ? classData.id_kelas : '';

      const nameEl = document.getElementById('modalClassName');
      if (nameEl) nameEl.value = classData ? classData.nama_kelas : '';

      const semEl = document.getElementById('modalClassSemester');
      if (semEl) semEl.value = classData ? (classData.semester || '') : '';

      renderCourseDropdowns();
      const selectCourse = document.getElementById('modalClassCourseSelect');
      if (selectCourse) {
        selectCourse.value = classData ? classData.id_mata_kuliah : (appState.activeCourseId || (appState.courses.length > 0 ? appState.courses[0].id_mata_kuliah : ''));
      }

      openModal('classModal');
    }

    function editClass(classId) {
      const target = appState.classes.find(c => c.id_kelas === classId);
      if (target) openClassModal(target);
    }

    function submitSaveClass() {
      const selectCourse = document.getElementById('modalClassCourseSelect');
      const courseId = selectCourse ? selectCourse.value : (appState.activeCourseId || (appState.courses.length > 0 ? appState.courses[0].id_mata_kuliah : ''));
      const nameEl = document.getElementById('modalClassName');
      const name = nameEl ? nameEl.value.trim() : '';

      if (!name) return alert('Nama kelas wajib diisi!');
      if (!courseId) return alert('Pilih mata kuliah induk terlebih dahulu!');

      const idEl = document.getElementById('modalClassId');
      const semEl = document.getElementById('modalClassSemester');

      const data = {
        id_kelas: idEl ? idEl.value : '',
        id_mata_kuliah: courseId,
        nama_kelas: name,
        semester: semEl ? semEl.value.trim() : ''
      };

      const btn = document.getElementById('btnSubmitClass');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';
      }

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Simpan Kelas';
          }
          closeModal('classModal');
          showGlobalAlert('Kelas berhasil disimpan!', 'success');
          reloadAllData(courseId, res.id_kelas);
        })
        .withFailureHandler(function(err) {
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Simpan Kelas';
          }
          alert('Gagal menyimpan kelas: ' + err.message);
        })
        .apiSaveClass(data);
    }

    function confirmDeleteClass(classId, className) {
      if (!confirm('Hapus kelas "' + className + '"?\nSeluruh data mahasiswa dan riwayat nilai kelas ini akan ikut terhapus.')) {
        return;
      }
      showLoading(true);
      google.script.run
        .withSuccessHandler(function(res) {
          showLoading(false);
          showGlobalAlert(res.message, 'success');
          reloadAllData(appState.activeCourseId);
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          showGlobalAlert('Gagal menghapus kelas: ' + err.message, 'error');
        })
        .apiDeleteClass(classId);
    }

    // 2. Mahasiswa Modal
    function openAddStudentModal() {
      if (!appState.activeClassId) return alert('Pilih kelas terlebih dahulu!');
      document.getElementById('modalStudentNim').value = '';
      document.getElementById('modalStudentName').value = '';
      openModal('studentModal');
    }

    function submitSaveStudent() {
      const nim = document.getElementById('modalStudentNim').value.trim();
      const nama = document.getElementById('modalStudentName').value.trim();
      if (!nim || !nama) return alert('NIM dan Nama Mahasiswa wajib diisi!');

      google.script.run
        .withSuccessHandler(function(res) {
          closeModal('studentModal');
          showGlobalAlert('Mahasiswa berhasil ditambahkan!', 'success');
          loadClassDetails(appState.activeClassId);
        })
        .withFailureHandler(function(err) {
          alert('Gagal menyimpan mahasiswa: ' + err.message);
        })
        .apiSaveStudent(appState.activeClassId, { nim: nim, nama_mahasiswa: nama });
    }

    function openImportStudentsModal() {
      if (!appState.activeClassId) return alert('Pilih kelas terlebih dahulu!');
      document.getElementById('importTextarea').value = '';
      openModal('importModal');
    }

    function submitImportStudents() {
      const rawText = document.getElementById('importTextarea').value;
      if (!rawText.trim()) return alert('Daftar mahasiswa masih kosong!');

      const btn = document.getElementById('btnSubmitImport');
      btn.disabled = true;
      btn.textContent = 'Mengimpor...';

      google.script.run
        .withSuccessHandler(function(res) {
          btn.disabled = false;
          btn.textContent = 'Impor Sekarang';
          closeModal('importModal');
          showGlobalAlert(res.message, 'success');
          loadClassDetails(appState.activeClassId);
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.textContent = 'Impor Sekarang';
          alert('Gagal mengimpor: ' + err.message);
        })
        .apiSaveStudentsBulk(appState.activeClassId, rawText);
    }

    function confirmDeleteStudent(studentId, studentName) {
      if (!confirm('Hapus mahasiswa "' + studentName + '" dari kelas ini?')) return;
      google.script.run
        .withSuccessHandler(function(res) {
          showGlobalAlert(res.message, 'success');
          loadClassDetails(appState.activeClassId);
        })
        .withFailureHandler(function(err) {
          showGlobalAlert('Gagal menghapus mahasiswa: ' + err.message, 'error');
        })
        .apiDeleteStudent(studentId);
    }

    // 3. Tugas Modal
    function openCreateTaskModal() {
      if (!appState.courses || appState.courses.length === 0) {
        alert('Buat mata kuliah terlebih dahulu sebelum membuat tugas!');
        openCourseModal();
        return;
      }
      renderCourseDropdowns();
      const titleEl = document.getElementById('taskModalTitle');
      if (titleEl) titleEl.textContent = 'Buat Tugas Baru';

      const idEl = document.getElementById('modalTaskId');
      if (idEl) idEl.value = '';
      const tTitleEl = document.getElementById('modalTaskTitle');
      if (tTitleEl) tTitleEl.value = '';
      const tDescEl = document.getElementById('modalTaskDesc');
      if (tDescEl) tDescEl.value = '';
      const tWeightEl = document.getElementById('modalTaskWeight');
      if (tWeightEl) tWeightEl.value = '25';
      const tRefEl = document.getElementById('modalTaskQuestionRef');
      if (tRefEl) tRefEl.value = '';
      const tFolderEl = document.getElementById('modalTaskFolderId');
      if (tFolderEl) tFolderEl.value = '';
      const tRubricEl = document.getElementById('modalTaskRubric');
      if (tRubricEl) {
        tRubricEl.value = `## PANDUAN PORSI NILAI TUGAS (TOTAL: 100 POIN)

1. Soal 1 (Porsi: 35 Poin)
   - Ketepatan konsep, logika pemikiran, dan keakuratan jawaban.

2. Soal 2 (Porsi: 40 Poin)
   - Struktur, kelengkapan kode/dokumen, implementasi teknis.

3. Kerapian & Format (Porsi: 25 Poin)
   - Format penulisan, cover, identitas mahasiswa, dan kerapian berkas.`;
      }

      const courseSelect = document.getElementById('modalTaskCourseId');
      if (courseSelect) {
        courseSelect.value = appState.activeCourseId || (appState.courses.length > 0 ? appState.courses[0].id_mata_kuliah : '');
      }

      const vTaskAlert = document.getElementById('verifyTaskAlert');
      if (vTaskAlert) vTaskAlert.classList.add('hidden');
      const vFolderAlert = document.getElementById('verifyFolderAlert');
      if (vFolderAlert) vFolderAlert.classList.add('hidden');
      const aExtAlert = document.getElementById('autoExtractAlert');
      if (aExtAlert) aExtAlert.classList.add('hidden');
      const qFileInput = document.getElementById('modalTaskQuestionFile');
      if (qFileInput) qFileInput.value = '';

      openModal('taskModal');
    }

    function editTask(taskId) {
      showLoading(true);
      google.script.run
        .withSuccessHandler(function(task) {
          showLoading(false);
          if (!task) return alert('Tugas tidak ditemukan!');

          document.getElementById('taskModalTitle').textContent = 'Edit Tugas Mata Kuliah';
          document.getElementById('modalTaskId').value = task.id_tugas;
          document.getElementById('modalTaskTitle').value = task.judul_tugas;
          document.getElementById('modalTaskDesc').value = task.deskripsi || '';
          document.getElementById('modalTaskWeight').value = task.bobot_persentase || 0;
          document.getElementById('modalTaskQuestionRef').value = task.task_file_ref || '';
          document.getElementById('modalTaskFolderId').value = task.drive_folder_id || '';
          document.getElementById('modalTaskRubric').value = task.rubrik_text || '';

          const courseSelect = document.getElementById('modalTaskCourseId');
          if (courseSelect) courseSelect.value = task.id_mata_kuliah;

          document.getElementById('verifyTaskAlert').classList.add('hidden');
          document.getElementById('verifyFolderAlert').classList.add('hidden');
          document.getElementById('autoExtractAlert').classList.add('hidden');
          const qFileInput = document.getElementById('modalTaskQuestionFile');
          if (qFileInput) qFileInput.value = '';

          openModal('taskModal');
        })
        .withFailureHandler(function(err) {
          showLoading(false);
          alert('Gagal memuat tugas: ' + err.message);
        })
        .apiGetTaskById(taskId, appState.activeClassId);
    }

    function submitSaveTask() {
      const title = document.getElementById('modalTaskTitle').value.trim();
      const courseId = document.getElementById('modalTaskCourseId').value;
      if (!title) return alert('Judul tugas wajib diisi!');
      if (!courseId) return alert('Pilih mata kuliah terlebih dahulu!');

      const data = {
        id_tugas: document.getElementById('modalTaskId').value,
        id_mata_kuliah: courseId,
        judul_tugas: title,
        deskripsi: document.getElementById('modalTaskDesc').value.trim(),
        bobot_persentase: Number(document.getElementById('modalTaskWeight').value) || 0,
        task_file_ref: document.getElementById('modalTaskQuestionRef').value.trim(),
        drive_folder_id: document.getElementById('modalTaskFolderId').value.trim(),
        rubrik_text: document.getElementById('modalTaskRubric').value.trim()
      };

      const btn = document.getElementById('btnSubmitTask');
      btn.disabled = true;
      btn.textContent = 'Menyimpan...';

      google.script.run
        .withSuccessHandler(function(res) {
          const savedTaskId = (res && res.task && res.task.id_tugas) || data.id_tugas || document.getElementById('modalTaskId').value;
          const fileInput = document.getElementById('modalTaskQuestionFile');

          if (fileInput && fileInput.files && fileInput.files.length > 0 && savedTaskId) {
            const formData = new FormData();
            formData.append('taskId', savedTaskId);
            formData.append('questionFile', fileInput.files[0]);

            fetch('api.php?action=uploadTaskQuestion', {
              method: 'POST',
              body: formData
            }).finally(() => {
              finishSave();
            });
          } else {
            finishSave();
          }

          function finishSave() {
            btn.disabled = false;
            btn.textContent = 'Simpan Tugas';
            closeModal('taskModal');
            showGlobalAlert('Tugas berhasil disimpan untuk seluruh kelas pada mata kuliah ini!', 'success');

            if (appState.activeClassId) {
              loadClassDetails(appState.activeClassId);
            } else {
              switchTaskCourse(courseId);
            }
          }
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.textContent = 'Simpan Tugas';
          alert('Gagal menyimpan tugas: ' + err.message);
        })
        .apiSaveTask(data);
    }

    function confirmDeleteTask(taskId, taskTitle) {
      if (!confirm('Hapus tugas "' + taskTitle + '"?\nSeluruh riwayat nilai mahasiswa untuk tugas ini di seluruh kelas akan ikut terhapus.')) return;
      google.script.run
        .withSuccessHandler(function(res) {
          showGlobalAlert(res.message, 'success');
          if (appState.activeClassId) {
            loadClassDetails(appState.activeClassId);
          } else {
            switchTaskCourse(appState.activeCourseId);
          }
        })
        .withFailureHandler(function(err) {
          showGlobalAlert('Gagal menghapus tugas: ' + err.message, 'error');
        })
        .apiDeleteTask(taskId);
    }

    function verifyTaskQuestion() {
      const ref = document.getElementById('modalTaskQuestionRef').value.trim();
      const alertBox = document.getElementById('verifyTaskAlert');
      const btn = document.getElementById('btnVerifyTask');

      if (!ref) {
        alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-amber-50 text-amber-700 border border-amber-200';
        alertBox.textContent = 'Masukkan URL atau ID file soal di Google Drive terlebih dahulu.';
        alertBox.classList.remove('hidden');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Mengecek...';
      alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-slate-100 text-slate-600';
      alertBox.textContent = 'Mengecek akses berkas soal...';
      alertBox.classList.remove('hidden');

      google.script.run
        .withSuccessHandler(function(res) {
          btn.disabled = false;
          btn.textContent = 'Cek Soal';
          if (res && res.success) {
            alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-emerald-50 text-emerald-800 border border-emerald-200 font-medium';
            alertBox.textContent = res.message;
          } else {
            alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium';
            alertBox.textContent = (res && res.message) || 'Berkas tidak dapat diakses.';
          }
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.textContent = 'Cek Soal';
          alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium';
          alertBox.textContent = 'Gagal verifikasi: ' + (err.message || err);
        })
        .apiValidateTaskFile(ref);
    }

    function verifyDriveFolder() {
      const input = document.getElementById('modalTaskFolderId').value.trim();
      const alertBox = document.getElementById('verifyFolderAlert');
      const btn = document.getElementById('btnVerifyFolder');

      if (!input) {
        alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-amber-50 text-amber-700 border border-amber-200';
        alertBox.textContent = 'Masukkan URL atau ID folder Google Drive terlebih dahulu.';
        alertBox.classList.remove('hidden');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Mengecek...';
      alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-slate-100 text-slate-600';
      alertBox.textContent = 'Menghubungkan ke folder Drive...';
      alertBox.classList.remove('hidden');

      google.script.run
        .withSuccessHandler(function(res) {
          btn.disabled = false;
          btn.textContent = 'Cek Folder';
          if (res.success) {
            alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-emerald-50 text-emerald-800 border border-emerald-200 font-medium';
            alertBox.textContent = res.message;
            document.getElementById('modalTaskFolderId').value = res.folderId;
          } else {
            alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium';
            alertBox.textContent = res.message;
          }
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.textContent = 'Cek Folder';
          alertBox.className = 'mt-1.5 p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium';
          alertBox.textContent = 'Gagal verifikasi: ' + err.message;
        })
        .apiValidateDriveFolder(input);
    }

    function autoExtractRubricInModal() {
      const fileInput = document.getElementById('modalTaskQuestionFile');
      const ref = document.getElementById('modalTaskQuestionRef').value.trim();
      const alertBox = document.getElementById('autoExtractAlert');
      const btn = document.getElementById('btnAutoExtract');

      const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
      if (!hasFile && !ref) {
        alert('Pilih berkas soal dari komputer ATAU masukkan tautan berkas soal di Google Drive terlebih dahulu!');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Menganalisis...';
      alertBox.className = 'p-2 rounded-lg text-[11px] bg-blue-50 text-blue-700 border border-blue-200 block';
      alertBox.textContent = 'Gemini AI sedang membaca berkas soal dan menyusun rubrik porsi poin...';

      const handleSuccess = function(res) {
        btn.disabled = false;
        btn.textContent = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg> <span>Ekstrak dari Soal</span>';
        if (res && res.success) {
          document.getElementById('modalTaskRubric').value = res.rubricText || '';
          alertBox.className = 'p-2 rounded-lg text-[11px] bg-emerald-50 text-emerald-800 border border-emerald-200 font-medium block';
          alertBox.textContent = res.message || 'Berhasil menyusun rubrik dari dokumen soal!';
        } else {
          alertBox.className = 'p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium block';
          alertBox.textContent = (res && res.message) || 'Gagal mengekstrak rubrik.';
        }
      };

      const handleFailure = function(err) {
        btn.disabled = false;
        btn.textContent = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg> <span>Ekstrak dari Soal</span>';
        alertBox.className = 'p-2 rounded-lg text-[11px] bg-rose-50 text-rose-800 border border-rose-200 font-medium block';
        alertBox.textContent = 'Gagal ekstrak: ' + (err.message || err);
      };

      if (hasFile) {
        const formData = new FormData();
        formData.append('questionFile', fileInput.files[0]);
        formData.append('model', (appState.settings && appState.settings.modelName) || 'gemini-3.5-flash-lite');

        fetch('api.php?action=extractRubricFromTask', {
          method: 'POST',
          body: formData
        })
        .then(r => r.json())
        .then(handleSuccess)
        .catch(handleFailure);
      } else {
        google.script.run
          .withSuccessHandler(handleSuccess)
          .withFailureHandler(handleFailure)
          .apiExtractRubricFromTask(ref, (appState.settings && appState.settings.modelName) || 'gemini-3.5-flash-lite');
      }
    }

    // 4. Detail / Koreksi Manual Modal
    function openGradeDetailModal(nim) {
      const grade = appState.currentGrades.find(g => g.nim === nim);
      if (!grade) return;

      document.getElementById('detailGradeTaskId').value = appState.selectedGradingTaskId;
      document.getElementById('detailGradeClassId').value = appState.activeClassId;
      document.getElementById('detailGradeNim').value = grade.nim;
      document.getElementById('detailGradeNama').value = grade.nama_mahasiswa;

      document.getElementById('detailModalStudentName').textContent = grade.nama_mahasiswa;
      const fileLinkHtml = grade.file_name && grade.file_name !== '-'
        ? ` • Berkas: <a href="javascript:void(0)" onclick="openStudentSubmissionFile('${grade.file_name.replace(/'/g, "\\'")}', '${(grade.drive_file_url || '').replace(/'/g, "\\'")}')" class="text-blue-600 hover:text-blue-800 underline font-semibold cursor-pointer inline-flex items-center gap-0.5" title="Buka berkas jawaban langsung">${grade.file_name} ↗</a>`
        : '';
      document.getElementById('detailModalStudentNim').innerHTML = 'NIM: ' + grade.nim + fileLinkHtml;
      document.getElementById('detailGradeTotalScore').value = grade.nilai_total !== null && grade.nilai_total !== undefined ? grade.nilai_total : '';
      document.getElementById('detailGradeCriteria').value = grade.rincian_poin || '';
      document.getElementById('detailGradeFeedback').value = grade.catatan_ai || '';

      const failureBox = document.getElementById('detailFailureAlert');
      const isFailed = grade.status && grade.status.toLowerCase().startsWith('gagal');

      if (isFailed && failureBox) {
        failureBox.classList.remove('hidden');
        let cleanReason = grade.status.replace(/^Gagal:\s*/i, '');
        let advice = 'Periksa apakah nama file atau isi berkas mahasiswa di Drive memuat NIM atau nama yang sesuai.';

        const legacyDelim = '\u{1F4A1} Rekomendasi Solusi:';
        if (grade.catatan_ai && (grade.catatan_ai.includes('[Rekomendasi Solusi]:') || grade.catatan_ai.includes(legacyDelim))) {
          const delim = grade.catatan_ai.includes('[Rekomendasi Solusi]:') ? '[Rekomendasi Solusi]:' : legacyDelim;
          const parts = grade.catatan_ai.split(delim);
          if (parts[1]) advice = parts[1].trim();
        }

        document.getElementById('detailFailureReason').textContent = cleanReason;
        document.getElementById('detailFailureAdvice').textContent = 'Solusi: ' + advice;
      } else if (failureBox) {
        failureBox.classList.add('hidden');
      }

      // Cek Indikasi Residu Bot AI
      const aiBox = document.getElementById('detailAiAlert');
      const hasAi = Number(grade.ai_detected) === 1;
      if (hasAi && aiBox) {
        aiBox.classList.remove('hidden');
        let aiDetails = null;
        try {
          aiDetails = typeof grade.ai_detected_details === 'string' ? JSON.parse(grade.ai_detected_details) : grade.ai_detected_details;
        } catch (e) {}

        const summaryEl = document.getElementById('detailAiSummary');
        const snippetsEl = document.getElementById('detailAiSnippets');
        if (summaryEl) summaryEl.textContent = (aiDetails && aiDetails.summary) || 'Terdeteksi residu percakapan chatbot AI yang lupa dihapus.';
        
        if (snippetsEl && aiDetails && aiDetails.matches && aiDetails.matches.length > 0) {
          const matchTexts = aiDetails.matches.map(m => `[Baris ~${m.lineNumber || '-'}] ${m.category || 'Residu Bot'}:\n${m.snippet || m.matchedText}`).join('\n\n');
          snippetsEl.textContent = matchTexts;
        } else if (snippetsEl) {
          snippetsEl.textContent = 'Ditemukan sisa percakapan bot AI pada berkas jawaban.';
        }
      } else if (aiBox) {
        aiBox.classList.add('hidden');
      }

      // Cek Indikasi Plagiarisme Antar Mahasiswa
      const plagBox = document.getElementById('detailPlagiarismAlert');
      const simScore = (grade.similarity_score !== null && grade.similarity_score !== undefined && grade.similarity_score !== '') ? Number(grade.similarity_score) : null;
      if (simScore !== null && simScore >= 40 && plagBox) {
        plagBox.classList.remove('hidden');
        let simDetails = null;
        try {
          simDetails = typeof grade.similarity_details === 'string' ? JSON.parse(grade.similarity_details) : grade.similarity_details;
        } catch (e) {}

        const reasonEl = document.getElementById('detailPlagiarismReason');
        const snippetsEl = document.getElementById('detailPlagiarismSnippets');
        const targetNim = grade.similarity_target_nim || (simDetails && simDetails.highestMatch && simDetails.highestMatch.targetNim) || '-';
        const targetNama = (simDetails && simDetails.highestMatch && simDetails.highestMatch.targetNama) || '';
        
        if (reasonEl) {
          reasonEl.textContent = `Tingkat kemiripan ${simScore}% dengan NIM ${targetNim} ${targetNama ? '(' + targetNama + ')' : ''}`;
        }
        
        if (snippetsEl) {
          const phrases = (simDetails && simDetails.matchingPhrases) || [];
          if (phrases.length > 0) {
            snippetsEl.innerHTML = phrases.map((p, idx) => `<div><span class="text-amber-800 font-bold">Kutipan Identik #${idx+1} (${p.wordCount} kata):</span><br><span class="bg-amber-100/80 px-1 py-0.5 rounded text-amber-950">"${p.phrase}"</span></div>`).join('');
          } else {
            snippetsEl.textContent = 'Ditemukan kesamaan struktur dan kalimat dengan rekan sekelas.';
          }
        }
      } else if (plagBox) {
        plagBox.classList.add('hidden');
      }

      openModal('gradeDetailModal');
    }

    function setZeroPenaltyGrade(reason) {
      const scoreInp = document.getElementById('detailGradeTotalScore');
      const feedbackInp = document.getElementById('detailGradeFeedback');
      if (scoreInp) scoreInp.value = 0;
      if (feedbackInp) {
        const penaltyNote = reason === 'ai'
          ? '\n\n[PENALTI INTEGRITAS AKADEMIK]:\nNilai akhir ditetapkan 0 karena terbukti menyalin jawaban mentah dari Chatbot AI (ditemukan sisa percakapan bot yang tertinggal).'
          : '\n\n[PENALTI INTEGRITAS AKADEMIK]:\nNilai akhir ditetapkan 0 karena terindikasi plagiarisme (kemiripan tinggi dengan jawaban mahasiswa lain).';
        feedbackInp.value = feedbackInp.value.trim() + penaltyNote;
      }
      showGlobalAlert('Nilai total telah disesuaikan menjadi 0. Klik "Simpan Perubahan Nilai" untuk mencatat ke sistem.', 'warning');
    }

    function submitSaveManualGrade() {
      const taskId = document.getElementById('detailGradeTaskId').value;
      const classId = document.getElementById('detailGradeClassId').value;
      const nim = document.getElementById('detailGradeNim').value;
      const nama = document.getElementById('detailGradeNama').value;
      const scoreVal = document.getElementById('detailGradeTotalScore').value;

      const data = {
        id_tugas: taskId,
        id_kelas: classId,
        nim: nim,
        nama_mahasiswa: nama,
        nilai_total: scoreVal !== '' ? Number(scoreVal) : null,
        rincian_poin: document.getElementById('detailGradeCriteria').value.trim(),
        catatan_ai: document.getElementById('detailGradeFeedback').value.trim(),
        status: scoreVal !== '' ? 'Selesai' : 'Belum Dinilai',
        waktu_penilaian: new Date().toLocaleString('id-ID')
      };

      google.script.run
        .withSuccessHandler(function(res) {
          closeModal('gradeDetailModal');
          showGlobalAlert('Perubahan nilai mahasiswa berhasil disimpan!', 'success');
          loadGradingTable(taskId);
        })
        .withFailureHandler(function(err) {
          alert('Gagal menyimpan nilai: ' + err.message);
        })
        .apiSaveGradeManual(data);
    }

    // ==========================================
    // INTEGRITAS AKADEMIK: PLAGIASI & AI BOT RESIDUE
    // ==========================================

    let lastIntegrityReportData = null;
    let activeIntegrityTab = 'plag';

    function runIntegrityCheck() {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;

      if (!taskId) {
        return alert('Pilih tugas terlebih dahulu pada menu dropdown.');
      }
      if (!classId) {
        return alert('Pilih kelas terlebih dahulu.');
      }

      const btn = document.getElementById('btnIntegrityCheck');
      const originalText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-3.5 h-3.5 border-2 border-rose-600 border-t-white"></span> <span>Memindai...</span>';
      }

      showGlobalAlert('Memindai seluruh berkas tugas kelas untuk mendeteksi plagiarisme dan residu bot AI...', 'info');

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
          }

          if (!res || !res.success) {
            showGlobalAlert('Gagal menjalankan analisis integritas: ' + ((res && res.message) || 'Kesalahan sistem'), 'error');
            return;
          }

          lastIntegrityReportData = res;
          showGlobalAlert(res.message || 'Analisis integritas selesai.', 'success');

          // Muat ulang tabel penilaian agar badge terbaru tampil
          loadGradingTable(taskId);

          // Tampilkan modal hasil integritas
          renderIntegrityReportModal(res);
          openModal('integrityReportModal');
        })
        .withFailureHandler(function(err) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
          showGlobalAlert('Gagal memeriksa integritas: ' + err.message, 'error');
        })
        .apiCheckTaskIntegrity(taskId, classId);
    }

    function switchIntegrityTab(tab) {
      activeIntegrityTab = tab;
      const tabPlag = document.getElementById('integTabPlagContent');
      const tabAi = document.getElementById('integTabAiContent');
      const tabAll = document.getElementById('integTabAllContent');

      const btnPlag = document.getElementById('tabBtnIntegPlag');
      const btnAi = document.getElementById('tabBtnIntegAi');
      const btnAll = document.getElementById('tabBtnIntegAll');

      [tabPlag, tabAi, tabAll].forEach(t => t?.classList.add('hidden'));
      [btnPlag, btnAi, btnAll].forEach(b => {
        b?.classList.remove('border-b-2', 'border-blue-600', 'text-blue-600', 'font-bold');
        b?.classList.add('text-slate-500', 'font-medium');
      });

      if (tab === 'ai') {
        tabAi?.classList.remove('hidden');
        btnAi?.classList.add('border-b-2', 'border-blue-600', 'text-blue-600', 'font-bold');
        btnAi?.classList.remove('text-slate-500', 'font-medium');
      } else if (tab === 'all') {
        tabAll?.classList.remove('hidden');
        btnAll?.classList.add('border-b-2', 'border-blue-600', 'text-blue-600', 'font-bold');
        btnAll?.classList.remove('text-slate-500', 'font-medium');
      } else {
        tabPlag?.classList.remove('hidden');
        btnPlag?.classList.add('border-b-2', 'border-blue-600', 'text-blue-600', 'font-bold');
        btnPlag?.classList.remove('text-slate-500', 'font-medium');
      }
    }

    function openIntegrityModalForStudent(nim) {
      const grade = appState.currentGrades.find(g => g.nim === nim);
      if (!grade) return;

      const hasAi = Number(grade.ai_detected) === 1;
      const simScore = (grade.similarity_score !== null && grade.similarity_score !== undefined) ? Number(grade.similarity_score) : 0;

      if (!lastIntegrityReportData) {
        runIntegrityCheck();
        return;
      }

      renderIntegrityReportModal(lastIntegrityReportData);
      openModal('integrityReportModal');

      if (hasAi && simScore < 70) {
        switchIntegrityTab('ai');
      } else {
        switchIntegrityTab('plag');
      }
    }

    function renderIntegrityReportModal(data) {
      if (!data) return;
      const stats = data.stats || {};

      document.getElementById('integStatTotal').textContent = (stats.totalWithFiles ?? stats.totalStudents ?? 0) + ' Mhs';
      document.getElementById('integStatAi').textContent = (stats.aiDetectedCount ?? 0) + ' Mhs';
      document.getElementById('integStatHighPlag').textContent = (stats.highPlagiarismCount ?? 0) + ' Mhs';
      document.getElementById('integStatClean').textContent = (stats.cleanCount ?? 0) + ' Mhs';

      document.getElementById('badgeCountPlag').textContent = (data.suspiciousPairs || []).length;
      document.getElementById('badgeCountAi').textContent = (data.aiFlaggedStudents || []).length;
      document.getElementById('integrityAnalyzedAtTime').textContent = 'Dipindai pada: ' + (stats.analyzedAt || new Date().toLocaleString('id-ID'));

      // Render 1: Pasangan Kemiripan Tinggi
      const pairsContainer = document.getElementById('integPairsList');
      const pairs = data.suspiciousPairs || [];
      if (pairs.length === 0) {
        pairsContainer.innerHTML = `
          <div class="text-center py-8 bg-slate-50 rounded-xl border border-slate-200 text-slate-500 space-y-1">
            <div class="w-10 h-10 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></div>
            <p class="font-bold text-slate-700">Tidak Ditemukan Pasangan Kemiripan Mencurigakan</p>
            <p class="text-[11px] text-slate-400">Seluruh berkas jawaban mahasiswa yang diunggah memiliki variasi kalimat unik di bawah ambang batas plagiasi 40%.</p>
          </div>
        `;
      } else {
        pairsContainer.innerHTML = pairs.map((pair, pIdx) => {
          const isCritical = pair.percentage >= 70;
          const badgeClass = isCritical ? 'bg-rose-100 text-rose-800 border-rose-300' : 'bg-amber-100 text-amber-800 border-amber-300';
          const phrases = pair.matchingPhrases || [];

          return `
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 space-y-2.5">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                  <span class="px-2 py-0.5 rounded-md font-bold text-xs border ${badgeClass}">
                    ${pair.percentage}% Kesamaan (${pair.severity})
                  </span>
                  <span class="text-[11px] text-slate-400">Jaccard: ${pair.jaccard}%</span>
                </div>
                <button type="button" onclick="togglePairDiff(${pIdx})" id="btnToggleDiff-${pIdx}" class="text-blue-600 hover:text-blue-800 font-semibold text-xs inline-flex items-center gap-1 cursor-pointer">
                  <span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Lihat Kutipan Identik (${phrases.length})</span></span>
                </button>
              </div>

              <!-- Dua Mahasiswa yang Mirip -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div class="p-2.5 bg-white rounded-lg border border-slate-200">
                  <div class="font-bold text-slate-900">${pair.namaA}</div>
                  <div class="text-[11px] text-slate-500 font-mono">NIM: ${pair.nimA}</div>
                  <div class="text-[10px] text-slate-400 truncate mt-0.5">Berkas: ${pair.fileNameA}</div>
                </div>
                <div class="p-2.5 bg-white rounded-lg border border-slate-200">
                  <div class="font-bold text-slate-900">${pair.namaB}</div>
                  <div class="text-[11px] text-slate-500 font-mono">NIM: ${pair.nimB}</div>
                  <div class="text-[10px] text-slate-400 truncate mt-0.5">Berkas: ${pair.fileNameB}</div>
                </div>
              </div>

              <!-- Kotak Kutipan Identik Collapsible -->
              <div id="pairDiffBox-${pIdx}" class="hidden p-3 bg-amber-50/70 rounded-lg border border-amber-200 space-y-2">
                <div class="font-bold text-amber-900 text-[11px] flex items-center gap-1">
                  <svg class="w-3.5 h-3.5 text-amber-800 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> <span>Kalimat / Blok Teks yang Identik Antara Kedua Mahasiswa:</span>
                </div>
                ${phrases.length > 0 ? phrases.map((ph, phIdx) => `
                  <div class="bg-white p-2 rounded border border-amber-100 text-[11px]">
                    <span class="font-bold text-amber-800">#${phIdx + 1} (${ph.wordCount} kata sama persis):</span>
                    <p class="font-mono text-slate-800 mt-0.5 bg-amber-100/60 p-1.5 rounded">"${ph.phrase}"</p>
                  </div>
                `).join('') : '<p class="text-slate-400 italic text-[11px]">Tidak ada blok kalimat panjang berturut-turut.</p>'}
              </div>
            </div>
          `;
        }).join('');
      }

      // Render 2: Residu Chatbot AI
      const aiContainer = document.getElementById('integAiList');
      const aiStudents = data.aiFlaggedStudents || [];
      if (aiStudents.length === 0) {
        aiContainer.innerHTML = `
          <div class="text-center py-8 bg-slate-50 rounded-xl border border-slate-200 text-slate-500 space-y-1">
            <div class="w-10 h-10 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></div>
            <p class="font-bold text-slate-700">Tidak Ditemukan Residu Chatbot AI</p>
            <p class="text-[11px] text-slate-400">Tidak ada mahasiswa yang kedapatan menyalin kalimat khas percakapan chatbot AI (seperti "Apakah jawaban ini sudah cukup?", "Semoga membantu", dsb).</p>
          </div>
        `;
      } else {
        aiContainer.innerHTML = aiStudents.map(st => {
          const scan = st.aiScan || {};
          const matches = scan.matches || [];

          return `
            <div class="p-3.5 bg-rose-50/70 rounded-xl border border-rose-200 space-y-2.5">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                  <h4 class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg> <span>${st.nama}</span>
                    <span class="text-slate-500 font-mono text-xs">(${st.nim})</span>
                  </h4>
                  <p class="text-[11px] text-slate-500 mt-0.5">Berkas: <span class="font-medium">${st.fileName}</span></p>
                </div>
                <div class="flex items-center gap-2">
                  <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-200 text-rose-900">
                    ${matches.length} Residu Bot Terdeteksi
                  </span>
                  <button type="button" onclick="openGradeDetailModal('${st.nim}')" class="px-2.5 py-1 bg-white hover:bg-rose-100 text-rose-700 border border-rose-300 rounded-lg font-semibold text-xs transition cursor-pointer">
                    <span class="inline-flex items-center gap-1"><span>Koreksi Nilai</span><svg class="w-3 h-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                  </button>
                </div>
              </div>

              <!-- Kutipan Residu Bot Yang Lupa Dihapus -->
              <div class="space-y-1.5">
                ${matches.map(m => `
                  <div class="p-2.5 bg-white rounded-lg border border-rose-200 space-y-1 text-xs">
                    <div class="flex items-center justify-between text-[11px]">
                      <span class="font-bold text-rose-700">${m.category}</span>
                      <span class="text-slate-400 font-mono">Baris ~${m.lineNumber}</span>
                    </div>
                    <div class="font-mono text-rose-950 bg-rose-50/60 p-2 rounded border border-rose-100 whitespace-pre-wrap text-[11px] leading-relaxed">${m.snippet || m.matchedText}</div>
                  </div>
                `).join('')}
              </div>
            </div>
          `;
        }).join('');
      }

      // Render 3: Tabel Rekap Seluruh Kelas
      const allTbody = document.getElementById('integAllTableBody');
      const allStudents = appState.currentGrades || [];
      allTbody.innerHTML = allStudents.map(g => {
        const hasAi = Number(g.ai_detected) === 1;
        const sim = (g.similarity_score !== null && g.similarity_score !== undefined && g.similarity_score !== '') ? Number(g.similarity_score) : null;
        let simBadge = '<span class="text-slate-400 text-xs">-</span>';
        if (sim !== null) {
          if (sim >= 70) simBadge = `<span class="px-2 py-0.5 rounded font-bold text-rose-700 bg-rose-100">${sim}%</span>`;
          else if (sim >= 40) simBadge = `<span class="px-2 py-0.5 rounded font-bold text-amber-800 bg-amber-100">${sim}%</span>`;
          else simBadge = `<span class="px-2 py-0.5 rounded font-medium text-emerald-700 bg-emerald-50">${sim}%</span>`;
        }

        const aiBadge = hasAi 
          ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold text-rose-700 bg-rose-100 text-[10px]"><svg class="w-3 h-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg><span>Terindikasi AI</span></span>'
          : '<span class="text-slate-400 text-xs">Bersih</span>';

        return `
          <tr class="hover:bg-slate-50/80">
            <td class="px-3.5 py-2 font-mono font-semibold text-slate-700">${g.nim}</td>
            <td class="px-3.5 py-2 font-medium text-slate-900">${g.nama_mahasiswa}</td>
            <td class="px-3.5 py-2 text-center">${simBadge}</td>
            <td class="px-3.5 py-2 text-slate-600 font-mono text-[11px]">${g.similarity_target_nim || '-'}</td>
            <td class="px-3.5 py-2 text-center">${aiBadge}</td>
            <td class="px-3.5 py-2 text-center">
              <button onclick="openGradeDetailModal('${g.nim}')" class="px-2 py-1 text-blue-600 hover:underline font-semibold cursor-pointer">
                Detail
              </button>
            </td>
          </tr>
        `;
      }).join('');
    }

    function togglePairDiff(pIdx) {
      const box = document.getElementById('pairDiffBox-' + pIdx);
      const btn = document.getElementById('btnToggleDiff-' + pIdx);
      if (!box) return;
      const isHidden = box.classList.contains('hidden');
      if (isHidden) {
        box.classList.remove('hidden');
        if (btn) btn.innerHTML = '<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Tutup Kutipan Identik</span></span>';
      } else {
        box.classList.add('hidden');
        if (btn) btn.innerHTML = '<span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><span>Lihat Kutipan Identik</span></span>';
      }
    }

    // ==========================================
    // PENGATURAN & API KEY (TAB 6)
    // ==========================================

    function toggleApiKeyInputVisibility() {
      const input = document.getElementById('inputApiKey');
      input.type = input.type === 'password' ? 'text' : 'password';
    }

    function testApiConnection() {
      const key = document.getElementById('inputApiKey').value.trim();
      const model = document.getElementById('selectModelName').value;
      const alertBox = document.getElementById('testApiAlert');
      const btn = document.getElementById('btnTestApi');

      btn.disabled = true;
      btn.textContent = 'Menguji Koneksi...';
      alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-slate-100 text-slate-600 block';
      alertBox.textContent = 'Menghubungkan ke Gemini API...';

      google.script.run
        .withSuccessHandler(function(res) {
          btn.disabled = false;
          btn.textContent = '<svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> <span>Uji Koneksi API Key</span>';
          if (res.success) {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 block font-medium';
            alertBox.textContent = res.message;
          } else {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
            alertBox.textContent = res.message;
          }
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.textContent = '<svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> <span>Uji Koneksi API Key</span>';
          alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
          alertBox.textContent = 'Galat koneksi: ' + err.message;
        })
        .apiTestConnection(key, model);
    }

    function saveAppSettings() {
      const key = document.getElementById('inputApiKey').value.trim();
      const model = document.getElementById('selectModelName').value;
      const btn = document.getElementById('btnSaveSettings');
      const alertBox = document.getElementById('saveSettingsAlert');

      btn.disabled = true;
      btn.textContent = 'Menyimpan...';

      const data = {
        apiKey: key,
        modelName: model,
        activeCourseId: appState.activeCourseId,
        activeClassId: appState.activeClassId
      };

      google.script.run
        .withSuccessHandler(function(res) {
          btn.disabled = false;
          btn.innerHTML = '<svg class="w-4 h-4 text-white inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Simpan Pengaturan</span>';
          if (res.success) {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 block font-medium';
            alertBox.textContent = res.message;
            document.getElementById('badgeModelName').textContent = model;
            appState.settings.modelName = model;
          } else {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
            alertBox.textContent = res.message;
          }
        })
        .withFailureHandler(function(err) {
          btn.disabled = false;
          btn.innerHTML = '<svg class="w-4 h-4 text-white inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Simpan Pengaturan</span>';
          alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
          alertBox.textContent = 'Gagal menyimpan: ' + err.message;
        })
        .apiSaveSettings(data);
    }

    // ==========================================
    // INTEGRASI GOOGLE OAUTH 2.0 & DRIVE
    // ==========================================

    function toggleClientSecretVisibility() {
      const input = document.getElementById('inputGoogleClientSecret');
      if (input) input.type = input.type === 'password' ? 'text' : 'password';
    }

    function copyOauthRedirectUri() {
      const input = document.getElementById('oauthRedirectUriText');
      if (!input) return;
      input.select();
      navigator.clipboard.writeText(input.value).then(() => {
        const btn = document.getElementById('btnCopyUri');
        if (btn) {
          const original = btn.innerHTML;
          btn.innerHTML = '<svg class="w-3.5 h-3.5 text-white inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Disalin!';
          setTimeout(() => { btn.innerHTML = original; }, 2000);
        }
      });
    }

    function renderGoogleOAuthStatus(status) {
      if (!status) return;
      const badge = document.getElementById('googleOAuthStatusBadge');
      const btnConnect = document.getElementById('btnConnectGoogle');
      const btnDisconnect = document.getElementById('btnDisconnectGoogle');
      const btnTestDrive = document.getElementById('btnTestGoogleDrive');
      const inputId = document.getElementById('inputGoogleClientId');
      const redirectUriText = document.getElementById('oauthRedirectUriText');

      if (redirectUriText && status.redirectUri) {
        redirectUriText.value = status.redirectUri;
      }
      if (inputId && status.clientId && !inputId.value) {
        inputId.value = status.clientId;
      }

      if (status.isConnected) {
        if (badge) {
          badge.innerHTML = `<span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Terhubung: <strong class="font-bold">${status.email || 'Google Drive'}</strong></span></span>`;
        }
        if (btnConnect) btnConnect.classList.add('hidden');
        if (btnDisconnect) btnDisconnect.classList.remove('hidden');
        if (btnTestDrive) btnTestDrive.classList.remove('hidden');
      } else {
        if (badge) {
          badge.innerHTML = `<span class="px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Belum Terhubung</span>`;
        }
        if (btnConnect) btnConnect.classList.remove('hidden');
        if (btnDisconnect) btnDisconnect.classList.add('hidden');
        if (btnTestDrive) btnTestDrive.classList.add('hidden');
      }
    }

    function testGoogleDriveAccess() {
      const btn = document.getElementById('btnTestGoogleDrive');
      const alertBox = document.getElementById('googleOAuthAlert');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-3 h-3 border-2 border-sky-600 border-t-white mr-1"></span> Menguji...';
      }
      if (alertBox) {
        alertBox.className = 'p-3 rounded-xl text-xs bg-slate-100 text-slate-700 block';
        alertBox.textContent = 'Menghubungi Google Drive API...';
      }

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4 text-sky-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> <span>Uji Akses Drive</span>';
          }
          if (alertBox) {
            alertBox.className = 'p-3 rounded-xl text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 block font-medium';
            alertBox.textContent = res.message;
          }
          showGlobalAlert(res.message, 'success');
        })
        .withFailureHandler(function(err) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4 text-sky-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> <span>Uji Akses Drive</span>';
          }
          if (alertBox) {
            alertBox.className = 'p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
            alertBox.textContent = err.message;
          }
          showGlobalAlert(err.message, 'error');
        })
        .apiTestGoogleDriveConnection();
    }

    // ==========================================
    // AUTENTIKASI & MANAJEMEN SESI PENGGUNA
    // ==========================================
    async function handleLoginSubmit(e) {
      if (e) e.preventDefault();
      const email = (document.getElementById('loginEmail')?.value || '').trim();
      const password = document.getElementById('loginPassword')?.value || '';
      const alertBox = document.getElementById('loginAlert');
      const btn = document.getElementById('btnLoginSubmit');

      if (!email || !password) return;

      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-4 h-4 mr-2"></span> Memeriksa kredensial...';
      }
      if (alertBox) alertBox.className = 'hidden';

      try {
        const res = await AppAPI.login(email, password);
        if (btn) {
          btn.innerHTML = '<span class="spinner inline-block w-4 h-4 mr-2"></span> Memuat sesi akun baru...';
        }
        // Bersihkan state memori total dan muat ulang halaman secara fresh untuk akun baru
        window.location.reload();
        return;
      } catch (err) {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<span>Masuk ke Portal</span>';
        }
        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-rose-900/60 border border-rose-500/50 text-rose-200 block font-medium';
          alertBox.textContent = err.message || 'Email atau kata sandi tidak cocok.';
        }
      }
    }

    async function handleLogout() {
      if (!confirm('Apakah Anda yakin ingin keluar dari sistem?')) return;
      try {
        await AppAPI.logout();
      } catch (err) {
        console.error(err);
      }
      // Hancurkan memori sesi dan muat ulang halaman ke formulir login bersih
      window.location.reload();
    }

    async function saveMyProfile() {
      const nama = (document.getElementById('myProfileNama')?.value || '').trim();
      const email = (document.getElementById('myProfileEmail')?.value || '').trim();
      const pass = document.getElementById('myProfilePassword')?.value || '';
      const passConf = document.getElementById('myProfilePasswordConfirm')?.value || '';
      const alertBox = document.getElementById('profileAlert');
      const btn = document.getElementById('btnSaveProfile');

      if (!nama || !email) {
        return alert('Nama lengkap dan email tidak boleh kosong.');
      }
      if (pass && pass !== passConf) {
        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-rose-50 border border-rose-200 text-rose-800 block';
          alertBox.textContent = 'Konfirmasi kata sandi baru tidak cocok.';
        }
        return;
      }

      if (btn) btn.disabled = true;
      try {
        const res = await AppAPI.updateOwnProfile({
          nama_lengkap: nama,
          email: email,
          password: pass || undefined
        });
        if (btn) btn.disabled = false;
        appState.currentUser = res.user;
        const nameEl = document.getElementById('headerUserName');
        if (nameEl) nameEl.textContent = res.user.nama_lengkap;
        const pass1 = document.getElementById('myProfilePassword');
        const pass2 = document.getElementById('myProfilePasswordConfirm');
        if (pass1) pass1.value = '';
        if (pass2) pass2.value = '';

        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-emerald-50 border border-emerald-200 text-emerald-800 block font-medium';
          alertBox.textContent = 'Profil Anda berhasil diperbarui.';
          setTimeout(() => alertBox.classList.add('hidden'), 4000);
        }
        showGlobalAlert('Profil berhasil diperbarui.', 'success');
      } catch (err) {
        if (btn) btn.disabled = false;
        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-rose-50 border border-rose-200 text-rose-800 block';
          alertBox.textContent = err.message;
        }
      }
    }

    // ==========================================
    // KREDENSIAL GLOBAL & DRIVE OAUTH (1-KLIK)
    // ==========================================
    async function saveAdminGlobalOAuth() {
      const clientId = (document.getElementById('inputGoogleClientId')?.value || '').trim();
      const clientSecret = (document.getElementById('inputGoogleClientSecret')?.value || '').trim();
      const alertBox = document.getElementById('adminOAuthAlert');
      const btn = document.getElementById('btnSaveAdminOAuth');

      if (!clientId) {
        return alert('Google Client ID institusi wajib diisi.');
      }

      if (btn) btn.disabled = true;
      try {
        const res = await AppAPI.adminSaveGlobalOAuth(clientId, clientSecret);
        if (btn) btn.disabled = false;
        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-emerald-50 border border-emerald-200 text-emerald-800 block font-medium';
          alertBox.textContent = (res.message || 'Kredensial Global Google OAuth berhasil disimpan.');
          setTimeout(() => alertBox.classList.add('hidden'), 4000);
        }
        showGlobalAlert('Kredensial Global Google OAuth berhasil disimpan.', 'success');
      } catch (err) {
        if (btn) btn.disabled = false;
        if (alertBox) {
          alertBox.className = 'p-3 rounded-xl text-xs bg-rose-50 border border-rose-200 text-rose-800 block font-medium';
          alertBox.textContent = err.message;
        }
      }
    }

    function startGoogleOAuthFlow() {
      const btn = document.getElementById('btnConnectGoogle');
      const origText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-3 h-3 mr-1.5"></span> Menghubungi Google...';
      }

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
          }
          if (res.authUrl) {
            window.location.href = res.authUrl;
          } else {
            alert('Gagal menghasilkan URL otorisasi Google.');
          }
        })
        .withFailureHandler(function(err) {
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
          }
          alert('Gagal memulai otorisasi Google: ' + err.message);
        })
        .apiGetGoogleAuthUrl();
    }

    // ==========================================
    // ADMIN ONLY: MANAJEMEN PENGGUNA
    // ==========================================
    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    async function loadAdminUsers() {
      const tbody = document.getElementById('usersTableBody');
      if (!tbody) return;
      tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400">Memuat data pengguna...</td></tr>';

      try {
        const res = await AppAPI.adminGetUsers();
        const users = res.users || [];
        if (users.length === 0) {
          tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400">Belum ada pengguna terdaftar.</td></tr>';
          return;
        }

        tbody.innerHTML = users.map(u => {
          const isMe = appState.currentUser && appState.currentUser.id_user === u.id_user;
          const roleBadge = u.role === 'admin'
            ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700">Administrator</span>'
            : '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-700">Dosen</span>';
          
          const statusBadge = u.status === 'aktif'
            ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700">Aktif</span>'
            : '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 text-rose-700">Nonaktif</span>';

          const driveBadge = u.hasDriveConnected
            ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 truncate max-w-[150px]" title="' + (u.google_connected_email || '') + '"><svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>' + (u.google_connected_email || 'Terhubung') + '</span></span>'
            : '<span class="text-slate-400 text-[11px]">- Belum -</span>';

          const geminiBadge = u.hasApiKey
            ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200"><svg class="w-3 h-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Siap (' + (u.model_name || 'flash-lite') + ')</span></span>'
            : '<span class="text-slate-400 text-[11px]">- Kosong -</span>';

          const userJson = encodeURIComponent(JSON.stringify(u));

          return `
            <tr class="hover:bg-slate-50 transition">
              <td class="p-3">
                <div class="font-bold text-slate-900">${escapeHtml(u.nama_lengkap || '')} ${isMe ? '<span class="text-[10px] text-blue-600 font-semibold">(Anda)</span>' : ''}</div>
                <div class="text-[11px] text-slate-400">${escapeHtml(u.email || '')}</div>
              </td>
              <td class="p-3">${roleBadge}</td>
              <td class="p-3">${statusBadge}</td>
              <td class="p-3">${driveBadge}</td>
              <td class="p-3">${geminiBadge}</td>
              <td class="p-3 text-slate-500 text-[11px]">${u.created_at || '-'}</td>
              <td class="p-3 text-right space-x-1 whitespace-nowrap">
                ${!isMe ? `
                <button onclick="impersonateUser('${u.id_user}', '${escapeHtml(u.nama_lengkap || '')}')" class="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-lg text-[11px] font-bold transition inline-flex items-center gap-1 shadow-2xs cursor-pointer" title="Masuk sebagai ${escapeHtml(u.nama_lengkap || '')} tanpa perlu kata sandi">
                  <svg class="w-3.5 h-3.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg> <span>Login As</span>
                </button>` : ''}
                <button onclick="openEditUserModal('${userJson}')" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-[11px] font-semibold transition">
                  Edit
                </button>
                ${!isMe ? `
                <button onclick="confirmDeleteUser('${u.id_user}', '${escapeHtml(u.nama_lengkap || '')}')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-[11px] font-semibold transition" title="Hapus Pengguna">
                  <svg class="w-3.5 h-3.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>` : ''}
              </td>
            </tr>
          `;
        }).join('');
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-600">Gagal memuat pengguna: ${err.message}</td></tr>`;
      }
    }

    function openCreateUserModal() {
      document.getElementById('userModalTitle').textContent = 'Tambah Pengguna Baru';
      document.getElementById('modalUserId').value = '';
      document.getElementById('modalUserNama').value = '';
      document.getElementById('modalUserEmail').value = '';
      document.getElementById('modalUserPassword').value = '';
      document.getElementById('modalUserRole').value = 'dosen';
      document.getElementById('modalUserStatus').value = 'aktif';
      document.getElementById('userModalAlert').className = 'hidden';
      openModal('userModal');
    }

    function openEditUserModal(userJsonEncoded) {
      const u = JSON.parse(decodeURIComponent(userJsonEncoded));
      document.getElementById('userModalTitle').textContent = 'Edit Pengguna: ' + u.nama_lengkap;
      document.getElementById('modalUserId').value = u.id_user;
      document.getElementById('modalUserNama').value = u.nama_lengkap || '';
      document.getElementById('modalUserEmail').value = u.email || '';
      document.getElementById('modalUserPassword').value = '';
      document.getElementById('modalUserRole').value = u.role || 'dosen';
      document.getElementById('modalUserStatus').value = u.status || 'aktif';
      document.getElementById('userModalAlert').className = 'hidden';
      openModal('userModal');
    }

    async function submitSaveUser() {
      const id = (document.getElementById('modalUserId')?.value || '').trim();
      const nama = (document.getElementById('modalUserNama')?.value || '').trim();
      const email = (document.getElementById('modalUserEmail')?.value || '').trim();
      const pass = document.getElementById('modalUserPassword')?.value || '';
      const role = document.getElementById('modalUserRole')?.value || 'dosen';
      const status = document.getElementById('modalUserStatus')?.value || 'aktif';
      const alertBox = document.getElementById('userModalAlert');
      const btn = document.getElementById('btnSubmitUser');

      if (!nama || !email) {
        if (alertBox) {
          alertBox.className = 'p-2 rounded-lg text-xs bg-rose-50 border border-rose-200 text-rose-800 block';
          alertBox.textContent = 'Nama lengkap dan email wajib diisi.';
        }
        return;
      }

      if (!id && !pass) {
        if (alertBox) {
          alertBox.className = 'p-2 rounded-lg text-xs bg-rose-50 border border-rose-200 text-rose-800 block';
          alertBox.textContent = 'Password wajib diisi untuk pengguna baru.';
        }
        return;
      }

      if (btn) btn.disabled = true;
      try {
        const payload = {
          id_user: id || undefined,
          nama_lengkap: nama,
          email: email,
          role: role,
          status: status
        };
        if (pass) payload.password = pass;

        const res = await AppAPI.adminSaveUser(payload);
        if (btn) btn.disabled = false;
        closeModal('userModal');
        showGlobalAlert(res.message, 'success');
        loadAdminUsers();
      } catch (err) {
        if (btn) btn.disabled = false;
        if (alertBox) {
          alertBox.className = 'p-2 rounded-lg text-xs bg-rose-50 border border-rose-200 text-rose-800 block';
          alertBox.textContent = err.message;
        }
      }
    }

    async function confirmDeleteUser(id_user, nama) {
      if (!confirm(`Hapus pengguna "${nama}"?\n\nPerhatian: Seluruh data mata kuliah, kelas, dan tugas milik dosen ini akan tetap berada di database namun tidak dapat diakses lagi oleh pengguna ini.`)) return;

      try {
        const res = await AppAPI.adminDeleteUser(id_user);
        showGlobalAlert(res.message, 'success');
        loadAdminUsers();
      } catch (err) {
        showGlobalAlert('Gagal menghapus pengguna: ' + err.message, 'error');
      }
    }

    async function impersonateUser(userId, userName) {
      if (!confirm(`Apakah Anda yakin ingin masuk sebagai "${userName}"?\n\nAnda dapat melihat dan memeriksa mata kuliah, kelas, tugas, berkas, serta rekapitulasi akun ini persis seperti pengajar yang bersangkutan tanpa perlu mengetahui kata sandinya.`)) return;

      try {
        showGlobalAlert(`Sedang beralih masuk sebagai ${userName}...`, 'info');
        const res = await AppAPI.impersonateUser(userId);
        if (res.success) {
          window.location.reload();
        } else {
          showGlobalAlert(res.message || 'Gagal masuk sebagai pengguna tersebut.', 'error');
        }
      } catch (err) {
        showGlobalAlert('Gagal masuk sebagai pengguna: ' + err.message, 'error');
      }
    }

    async function exitImpersonation() {
      try {
        showGlobalAlert('Sedang kembali ke akun Administrator...', 'info');
        const res = await AppAPI.revertImpersonation();
        if (res.success) {
          window.location.reload();
        } else {
          showGlobalAlert(res.message || 'Gagal kembali ke akun Administrator.', 'error');
        }
      } catch (err) {
        showGlobalAlert('Gagal kembali ke akun Administrator: ' + err.message, 'error');
      }
    }

    function disconnectGoogleOAuth() {
      if (!confirm('Apakah Anda yakin ingin memutuskan integrasi Google Drive?')) return;

      google.script.run
        .withSuccessHandler(function(res) {
          if (appState.settings && appState.settings.googleOAuth) {
            appState.settings.googleOAuth.isConnected = false;
            appState.settings.googleOAuth.email = '';
          }
          renderGoogleOAuthStatus({ isConnected: false, email: '' });
          showGlobalAlert(res.message, 'info');
        })
        .withFailureHandler(function(err) {
          alert('Gagal memutuskan integrasi: ' + err.message);
        })
        .apiDisconnectGoogleDrive();
    }

    async function syncCurrentTaskDriveFolder(isAuto = false) {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;

      if (!taskId) return alert('Pilih tugas terlebih dahulu!');
      if (!classId) return alert('Pilih kelas terlebih dahulu!');

      const btn = document.getElementById('btnSyncDrive');
      const originalText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-3 h-3 border-2 border-sky-600 border-t-white mr-1"></span> Menyinkronkan...';
      }

      if (isAuto) {
        showProgressLog('Menyinkronkan berkas tugas mahasiswa langsung dari folder Google Drive...');
      } else {
        showGlobalAlert('Sedang menyinkronkan berkas tugas dari Google Drive ke server...', 'info');
      }

      try {
        const res = await AppAPI.syncTaskDriveFolder(taskId, classId, '');
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        showGlobalAlert(res.message, 'success');
        if (isAuto) {
          showProgressLog('[SUKSES] ' + res.message);
        }
        loadGradingTable(taskId);
        return res;
      } catch (err) {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        if (!isAuto) {
          showGlobalAlert('Gagal sinkronisasi Drive: ' + err.message, 'error');
        } else {
          showProgressLog('[GAGAL] Sinkronisasi Drive otomatis: ' + err.message);
        }
        throw err;
      }
    }

    async function syncTaskDriveFromCard(taskId, btnEl) {
      if (!taskId) return;
      const task = appState.currentTasks.find(t => t.id_tugas === taskId);
      const taskTitle = task ? task.judul_tugas : 'Tugas';
      const originalHtml = btnEl ? btnEl.innerHTML : '';

      if (btnEl) {
        btnEl.disabled = true;
        btnEl.className = 'bg-sky-100 border border-sky-300 text-sky-800 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs';
        btnEl.innerHTML = '<span class="spinner inline-block w-3.5 h-3.5 border-2 border-sky-600 border-t-white mr-1"></span> Menyinkronkan...';
      }
      showGlobalAlert(`Sedang menyinkronkan berkas tugas "${taskTitle}" dari Google Drive untuk seluruh kelas...`, 'info');

      try {
        const res = await AppAPI.syncTaskDriveFolder(taskId, '');
        const count = (typeof res.syncedCount !== 'undefined') ? res.syncedCount : (res.totalInDrive || 0);

        // Update badge berkas pada kartu tugas secara instan
        const badgeEl = document.getElementById('cardSubmissionsBadge_' + taskId);
        if (badgeEl) {
          badgeEl.innerHTML = `
            <span class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded-md border border-emerald-300 animate-pulse">
              <svg class="w-3 h-3 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> <span>${count} Berkas di Server</span>
            </span>
          `;
        }

        // Ubah tombol kartu menjadi status berhasil
        if (btnEl) {
          btnEl.disabled = false;
          btnEl.className = 'bg-emerald-50 border border-emerald-300 text-emerald-800 font-bold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs';
          btnEl.innerHTML = `<svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg> <span>${count} Berkas Tersimpan</span>`;
          setTimeout(() => {
            if (btnEl) {
              btnEl.className = 'bg-sky-50 hover:bg-sky-100 border border-sky-200 text-sky-800 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer';
              btnEl.innerHTML = originalHtml;
            }
          }, 4000);
        }

        showGlobalAlert(res.message || `Berhasil menyinkronkan ${count} berkas dari Google Drive!`, 'success');
        
        // Modal feedback langsung agar dosen tahu pasti proses telah selesai
        alert('Sinkronisasi Drive Selesai!\n\n' + (res.message || `Berhasil mengunduh dan menyinkronkan berkas tugas mahasiswa.`) + '\n\nTotal Berkas di Server: ' + count + ' Berkas.');

        if (appState.activeCourseId) {
          switchTaskCourse(appState.activeCourseId);
        }
        if (appState.selectedGradingTaskId === taskId) {
          loadGradingTable(taskId);
        }
      } catch (err) {
        if (btnEl) {
          btnEl.disabled = false;
          btnEl.className = 'bg-rose-50 border border-rose-200 text-rose-700 font-semibold py-2 px-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs';
          btnEl.innerHTML = originalHtml;
        }
        showGlobalAlert('Gagal menyinkronkan tugas dari Drive: ' + err.message, 'error');
        alert('Gagal Sinkronisasi Drive: ' + err.message);
      }
    }

    async function openClassFolderOverrideModal() {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;

      if (!taskId) return alert('Pilih tugas terlebih dahulu pada menu dropdown!');
      if (!classId) return alert('Pilih kelas terlebih dahulu!');

      const task = appState.currentTasks.find(t => t.id_tugas === taskId);
      const activeClass = appState.classes.find(c => c.id_kelas === classId);
      const taskTitle = task ? task.judul_tugas : 'Tugas';
      const className = activeClass ? activeClass.nama_kelas : 'Kelas';

      document.getElementById('overrideModalSubTitle').textContent = `Tugas: "${taskTitle}" — Kelas: "${className}"`;
      document.getElementById('overrideTaskMainFolderText').textContent = (task && task.drive_folder_id) ? task.drive_folder_id : '(Belum diatur pada Kelola Tugas)';

      const input = document.getElementById('inputClassOverrideFolder');
      const statusBadge = document.getElementById('overrideModalStatusBadge');
      if (statusBadge) statusBadge.classList.add('hidden');

      try {
        const info = await AppAPI.getClassTaskFolder(taskId, classId);
        if (input) input.value = info.classFolder || '';
        openModal('classFolderOverrideModal');
      } catch (err) {
        if (input) input.value = '';
        openModal('classFolderOverrideModal');
      }
    }

    async function saveClassFolderOverride() {
      const taskId = appState.selectedGradingTaskId;
      const classId = appState.activeClassId;
      const input = document.getElementById('inputClassOverrideFolder');
      const val = input ? input.value.trim() : '';

      const btn = document.getElementById('btnSaveOverride');
      const originalText = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner inline-block w-3 h-3 border-2 border-white mr-1"></span> Menyimpan...';
      }

      try {
        const res = await AppAPI.saveClassTaskFolder(taskId, classId, val);
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        closeModal('classFolderOverrideModal');
        showGlobalAlert(res.message, 'success');
        loadGradingTable(taskId);
      } catch (err) {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalText;
        }
        alert('Gagal menyimpan folder kelas: ' + err.message);
      }
    }

    async function resetClassFolderOverride() {
      const input = document.getElementById('inputClassOverrideFolder');
      if (input) input.value = '';
      await saveClassFolderOverride();
    }

    function confirmResetDatabase() {
      const msg = "PERINGATAN KERAS:\n\nApakah Anda yakin ingin mengosongkan seluruh database dan menginisialisasi ulang?\n\nSemua mata kuliah, kelas, tugas, mahasiswa, dan riwayat nilai akan di-reset ke kondisi bawaan awal yang bersih.";
      if (!confirm(msg)) return;

      const btn = document.getElementById('btnResetDatabase');
      const alertBox = document.getElementById('resetDbAlert');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Mereset Database...';
      }
      showLoading(true);

      google.script.run
        .withSuccessHandler(function(res) {
          if (btn) {
            btn.disabled = false;
            btn.textContent = '<svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> <span>Reset / Mulai Bersih Database</span>';
          }
          showLoading(false);
          showGlobalAlert(res.message, 'success');
          if (alertBox) {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-emerald-50 text-emerald-800 border border-emerald-200 block font-medium';
            alertBox.textContent = res.message;
            setTimeout(() => alertBox.classList.add('hidden'), 5000);
          }
          // Muat ulang seluruh data aplikasi
          initApp();
        })
        .withFailureHandler(function(err) {
          if (btn) {
            btn.disabled = false;
            btn.textContent = '<svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> <span>Reset / Mulai Bersih Database</span>';
          }
          showLoading(false);
          showGlobalAlert('Gagal mereset database: ' + err.message, 'error');
          if (alertBox) {
            alertBox.className = 'mt-2 p-3 rounded-xl text-xs bg-rose-50 text-rose-800 border border-rose-200 block font-medium';
            alertBox.textContent = 'Galat: ' + err.message;
          }
        })
        .apiResetDatabase();
    }

    function showGlobalAlert(msg, type = 'info') {
      const banner = document.getElementById('globalAlert');
      const icon = document.getElementById('globalAlertIcon');
      const text = document.getElementById('globalAlertMsg');

      if (!banner || !text) return;
      text.textContent = msg;

      if (type === 'error') {
        banner.className = 'mb-6 p-4 rounded-xl text-sm flex items-start justify-between shadow-sm bg-rose-50 border border-rose-200 text-rose-800';
        icon.innerHTML = '<svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
      } else if (type === 'success') {
        banner.className = 'mb-6 p-4 rounded-xl text-sm flex items-start justify-between shadow-sm bg-emerald-50 border border-emerald-200 text-emerald-800';
        icon.innerHTML = '<svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      } else {
        banner.className = 'mb-6 p-4 rounded-xl text-sm flex items-start justify-between shadow-sm bg-blue-50 border border-blue-200 text-blue-800';
        icon.innerHTML = '<svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      }

      banner.classList.remove('hidden');
      setTimeout(function() {
        if (!banner.classList.contains('hidden')) banner.classList.add('hidden');
      }, 7000);
    }

    function hideGlobalAlert() {
      const banner = document.getElementById('globalAlert');
      if (banner) banner.classList.add('hidden');
    }

    function showLoading(show) {
      // Indikator loading halus jika diperlukan
    }
  </script>
</body>
</html>
