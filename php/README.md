# Quick Start Guide Paylabs - PHP

Repository ini berisi contoh implementasi integrasi layanan **Paylabs** menggunakan PHP. Modul ini mencakup berbagai jenis transaksi seperti QRIS, SNAP VA, dan transaksi umum lainnya.

## Fitur

- **Dukungan Multi-Layanan**: Pembuatan transaksi QRIS, SNAP VA, dan General Transaction.
- **Signatur Otomatis**: Logika pembuatan signatur RSA-SHA256 sesuai standar Paylabs (termasuk penanganan SNAP).
- **Penanganan Callback**: Endpoint untuk menerima dan memverifikasi notifikasi callback.
- **Konfigurasi Mudah**: Penggunaan file `.env` untuk manajemen kredensial.
- **Bypass SSL**: Konfigurasi cURL otomatis untuk mengabaikan verifikasi SSL di lingkungan SIT/Sandbox.

## Persiapan

1. **Install Dependensi:**
   Pastikan Anda telah menginstal [Composer](https://getcomposer.org/), lalu jalankan:

   ```bash
   composer install
   ```

2. **Konfigurasi Environment:**
   Copy file `.env.example` ke `.env` dan isi dengan kredensial Anda:
   ```bash
   cp .env.example .env
   ```
   Edit file `.env` dengan `MERCHANT_ID`, `PRIVATE_KEY`, dan `PUBLIC_KEY` Anda.

## Penggunaan

### 1. Membuat Transaksi

Untuk menjalankan script pembuatan transaksi (default: SNAP VA):

```bash
php generateTransaction.php
```

Anda dapat mengubah jenis transaksi yang dijalankan dengan mengedit bagian akhir file `generateTransaction.php`.

### 2. Menjalankan Server Callback

Untuk menerima notifikasi status pembayaran dari Paylabs secara lokal:

```bash
php -S localhost:3000 verifyCallback.php
```

Server akan melayani endpoint verifikasi di `http://localhost:3000/verifyCallback.php`.

## Simulasi Callback dengan Ngrok

Gunakan **ngrok** untuk mengekspos server lokal Anda ke internet:

1. **Jalankan Ngrok:**
   ```bash
   ngrok http 3000
   ```
2. **Update Notify URL:**
   Pastikan field `notifyUrl` pada request (`generateTransaction.php`) menggunakan URL ngrok Anda (misal: `https://a1b2.ngrok-free.dev/verifyCallback.php`).

## Struktur Proyek

- `generateTransaction.php`: Script utama untuk membuat berbagai jenis transaksi.
- `PaylabsSignature.php`: Helper class inti untuk signatur, timestamp, dan request cURL.
- `verifyCallback.php`: Endpoint contoh untuk menerima callback.
- `composer.json`: Dependensi library (seperti `vlucas/phpdotenv`).

## FAQ

### 1. Mengapa muncul error SSL?

Kami telah menyertakan opsi `CURLOPT_SSL_VERIFYPEER => false` di `PaylabsSignature.php` untuk memudahkan integrasi di sandbox. Pastikan opsi ini ditinjau kembali saat berpindah ke lingkungan Production.

### 2. Bagaimana cara validasi signatur secara manual?

Gunakan [Paylabs Signature Playground](https://docs.paylabs.co.id/id/docs/v4.8.1/tools/signature-playground) untuk membandingkan `String to Sign` dan hasil `Signature` Anda.

---

© 2026 Paylabs. All rights reserved.
