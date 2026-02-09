# Quick Start Guide QRIS Paylabs - Node.js

Repository ini berisi contoh implementasi integrasi QRIS Paylabs menggunakan Node.js (ES Modules).

## Fitur

- Pembuatan QRIS (Create QRIS) menggunakan built-in `fetch` API.
- Signatur otomatis menggunakan RSA-SHA256 sesuai standar Paylabs.
- Penanganan Callback dengan Express.js.
- Generasi `requestId` dan Timestamp WIB (+07:00) otomatis.

## Persiapan

1. **Install Dependensi:**

   ```bash
   npm install
   ```

2. **Konfigurasi Environment:**
   Buat file `.env` di direktori root dan isi dengan kredensial Anda:
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
npm run qris
```

Script ini akan menghasilkan link QRIS dan QR Code format teks yang bisa digunakan untuk simulasi pembayaran.

### 2. Menjalankan Server Callback

Untuk menerima notifikasi status pembayaran dari Paylabs:

```bash
npm run callback
```

Server akan berjalan di `http://localhost:3000/callback`.

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
   Pastikan field `notifyUrl` pada `generateQris.js` menggunakan URL ngrok tersebut:

   ```javascript
   notifyUrl: "https://a1b2-c3d4.ngrok-free.dev/callback";
   ```

5. **Test Callback:**
   Setelah melakukan pembayaran di lingkungan Sandbox/SIT, Paylabs akan mengirimkan POST request ke URL tersebut. Anda bisa melihat log verifikasi signatur di terminal tempat Anda menjalankan `npm run callback`.

## Struktur Proyek

- `generateQris.js`: Script untuk melakukan request pembuatan QRIS.
- `paylabsSignature.js`: Helper untuk menangani pembuatan signatur dan timestamp.
- `verifyCallback.js`: Server Express untuk menerima dan memverifikasi notifikasi callback.
- `package.json`: Konfigurasi proyek dan dependensi.

## FAQ (Frequently Asked Questions)

### 1. Bagaimana cara mengatasi `sign error`?

Jika Anda mendapatkan error `sign error`, Anda bisa memvalidasi string to sign dan signature Anda menggunakan [Paylabs Signature Playground](https://docs.paylabs.co.id/id/docs/v4.8.1/tools/signature-playground). Pastikan urutan parameter dan private key yang digunakan sudah sesuai.

### 2. Mengapa muncul error `Conflict`?

Error `Conflict` biasanya terjadi karena dua hal:

- **Request Dobel**: `merchantTradeNo` atau `requestId` sudah pernah digunakan sebelumnya. Gunakan ID yang unik untuk setiap request.
- **Timestamp Tidak Sesuai**: Pastikan timezone yang digunakan adalah GMT+7 (WIB). Proyek ini sudah dikonfigurasi untuk secara otomatis menggunakan offset `+07:00`.
