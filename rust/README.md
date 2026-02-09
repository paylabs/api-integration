# Quick Start Guide QRIS Paylabs - Rust

Repository ini berisi contoh implementasi integrasi QRIS Paylabs menggunakan Rust.

## Fitur

- Pembuatan QRIS (Create QRIS) menggunakan `reqwest`.
- Signatur otomatis menggunakan RSA-SHA256 sesuai standar Paylabs.
- Penanganan Callback dengan Actix-web.
- Generasi `requestId` dan Timestamp WIB (+07:00) otomatis.

## Persiapan

1. **Pastikan Rust Terinstall:**

   ```bash
   rustc --version  # Minimal Rust 1.70
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
cargo run --bin generate_qris
```

Script ini akan menghasilkan link QRIS dan QR Code format teks yang bisa digunakan untuk simulasi pembayaran.

### 2. Menjalankan Server Callback

Untuk menerima notifikasi status pembayaran dari Paylabs:

```bash
cargo run --bin callback_server
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
   Pastikan field `notifyUrl` pada `src/main.rs` menggunakan URL ngrok tersebut.

5. **Test Callback:**
   Setelah melakukan pembayaran di lingkungan Sandbox/SIT, Paylabs akan mengirimkan POST request ke URL tersebut.

## Struktur Proyek

- `src/main.rs`: Entry point untuk pembuatan QRIS.
- `src/signature.rs`: Module untuk pembuatan signatur.
- `src/callback.rs`: Server Actix-web untuk callback.
- `Cargo.toml`: Konfigurasi project dan dependensi.
- `.env.example`: Template konfigurasi environment.

## Troubleshooting

### 1. Bagaimana cara mengatasi `sign error`?

Jika Anda mendapatkan error `sign error`, validasi string to sign dan signature Anda menggunakan [Paylabs Signature Playground](https://docs.paylabs.co.id/id/docs/v4.8.1/tools/signature-playground).

### 2. Mengapa muncul error `Conflict`?

Error `Conflict` biasanya terjadi karena:

- **Request Dobel**: `merchantTradeNo` atau `requestId` sudah pernah digunakan.
- **Timestamp Tidak Sesuai**: Pastikan timezone adalah GMT+7 (WIB).

### 3. Error: `linker 'link.exe' not found` atau `cannot open input file 'kernel32.lib'`

Jika Anda menjalankan Rust di Windows dan mendapatkan error linker, ini berarti sistem Anda kekurangan komponen build C++ atau Windows SDK.

**Solusi:**

1. Download dan install [Visual Studio Build Tools](https://visualstudio.microsoft.com/visual-cpp-build-tools/).
2. Saat instalasi, pilih workload **"Desktop development with C++"**.
3. Pastikan centang opsi berikut di panel sebelah kanan:
   - **MSVC v143 - VS 2022 C++ x64/x86 build tools**
   - **Windows 10/11 SDK** (penting untuk `kernel32.lib`)
4. Restart terminal atau restart komputer setelah instalasi selesai.

Jika Anda sudah menginstall Build Tools tapi tetap error, coba jalankan perintah build dari **Developer PowerShell for VS 2022** atau jalankan perintah ini terlebih dahulu:

```powershell
& "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat" x64
```
