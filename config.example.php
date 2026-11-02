<?php
/**
 * Konfigurasi Global Sistem Asisten Penilaian Mandiri
 * Template Konfigurasi untuk Shared Hosting & Lingkungan Lokal
 */

return [
    // Driver Database: 'mysql' (disarankan untuk shared hosting cPanel) atau 'sqlite' (zero-config lokal)
    'db_driver' => 'sqlite',

    // Konfigurasi MySQL / MariaDB (Digunakan jika db_driver = 'mysql')
    'mysql' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'asisten_penilaian',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4'
    ],

    // Konfigurasi SQLite (Digunakan jika db_driver = 'sqlite')
    'sqlite' => [
        'path' => __DIR__ . '/../database.sqlite'
    ],

    // Konfigurasi Gemini API
    'gemini' => [
        'api_key'          => '', // Dapat diisi di sini atau lewat tab Pengaturan di Web
        'default_model'    => 'gemini-3.5-flash-lite',
        'available_models' => [
            'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (Cepat & Kuota Luas - Direkomendasikan)',
            'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
            'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite',
            'gemini-1.5-flash'      => 'Gemini 1.5 Flash',
            'gemini-1.5-pro'        => 'Gemini 1.5 Pro'
        ]
    ],

    // Konfigurasi Penyimpanan Berkas
    'storage' => [
        'upload_dir'       => __DIR__ . '/../uploads',
        'max_file_size_mb' => 25
    ],

    // Opsi Aplikasi
    'app' => [
        'name'    => 'Asisten Penilaian Mandiri',
        'version' => '2.0.0',
        'debug'   => true
    ]
];
