# Quick Start Guide QRIS Paylabs - PHP

Repository ini berisi contoh implementasi integrasi QRIS Paylabs menggunakan PHP.

## Fitur

- Pembuatan QRIS (Create QRIS) menggunakan cURL.
- Signatur otomatis menggunakan RSA-SHA256 sesuai standar Paylabs.
- Penanganan Callback dengan PHP built-in server.
- Generasi `requestId` dan Timestamp WIB (+07:00) otomatis.

## Persiapan

1. **Install Dependensi:**

   ```bash
   composer install
   ```

2. **Konfigurasi Environment:**
   Copy file `.env.example` ke `.env` dan isi dengan kredensial Anda:

   ```bash
   cp .env.example .env
   ```

   Edit file `.env`:

   ```env
   PAYLABS_BASE_URL=https://sit-pay.paylabs.co.id
   MERCHANT_ID="ID_MERCHANT_ANDA"
   QRIS_CREATE_ENDPOINT="/payment/v2.3/qris/create"
   MERCHANT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n..."
   PAYLABS_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n..."
   ```

## Penggunaan

### 1. Membuat QRIS

Untuk menjalankan script pembuatan QRIS:

```bash
php generateQris.php
```

Script ini akan menghasilkan link QRIS dan QR Code format teks yang bisa digunakan untuk simulasi pembayaran.

### 2. Menjalankan Server Callback

Untuk menerima notifikasi status pembayaran dari Paylabs:

```bash
php -S localhost:3000
```

Server akan berjalan di `http://localhost:3000/verifyCallback.php`.

## Simulasi Callback dengan Ngrok

Karena server callback berjalan di local, Anda memerlukan **ngrok** untuk mengekspos server tersebut ke internet agar Paylabs bisa mengirimkan notifikasi.

1. **Install Ngrok:** (Jika belum)
   [Download Ngrok](https://ngrok.com/download)

2. **Jalankan Ngrok:**
   Ekspos port 3000:

   ```bash
   ngrok http 3000
   ```

3. **Dapatkan URL Publik:**
   Ngrok akan memberikan URL seperti `https://a1b2-c3d4.ngrok-free.dev`.

4. **Update Notify URL:**
   Pastikan `notifyUrl` pada `generateQris.php` menggunakan URL ngrok + `/verifyCallback.php`.

5. **Test Callback:**
   Setelah melakukan pembayaran di lingkungan Sandbox/SIT, Paylabs akan mengirimkan POST request ke URL tersebut.

## Struktur Proyek

- `generateQris.php`: Script untuk melakukan request pembuatan QRIS.
- `PaylabsSignature.php`: Helper class untuk menangani pembuatan dan verifikasi signatur.
- `verifyCallback.php`: Endpoint untuk menerima dan memverifikasi notifikasi callback.
- `composer.json`: Konfigurasi Composer dan dependensi.
- `.env.example`: Template konfigurasi environment.

## FAQ

### 1. Bagaimana cara mengatasi `sign error`?

Jika Anda mendapatkan error `sign error`, validasi string to sign dan signature Anda menggunakan [Paylabs Signature Playground](https://docs.paylabs.co.id/id/docs/v4.8.1/tools/signature-playground).

### 2. Mengapa muncul error `Conflict`?

Error `Conflict` biasanya terjadi karena:

- **Request Dobel**: `merchantTradeNo` atau `requestId` sudah pernah digunakan.
- **Timestamp Tidak Sesuai**: Pastikan timezone adalah GMT+7 (WIB).
