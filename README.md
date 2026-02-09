# Paylabs Quick Start Guide

Repositori ini berisi kumpulan contoh implementasi (Quick Start Guide) untuk integrasi layanan **Paylabs** dalam berbagai bahasa pemrograman. Setiap modul dirancang untuk memudahkan developer dalam mengintegrasikan fitur seperti pembayaran QRIS, pembuatan signatur (HMAC/RSA), dan penanganan callback.

## Modul Tersedia

Pilih bahasa pemrograman yang sesuai dengan tumpukan teknologi Anda:

- [**Node.js**](./node.js) - Implementasi menggunakan Express.js dan modul `crypto` bawaan.
- [**Go**](./go) - Implementasi menggunakan modul standar Go.
- [**Java**](./java) - Implementasi menggunakan Maven/Gradle.
- [**Python**](./python) - Implementasi menggunakan `requests` dan `cryptography`.
- [**PHP**](./php) - Implementasi PHP native.
- [**.NET**](./dotnet) - Implementasi menggunakan C# / .NET Core.
- [**Rust**](./rust) - Implementasi menggunakan `tokio` dan `reqwest`.

## Fitur Utama

- **Pembuatan Transaksi**: Contoh request untuk pembuatan QRIS dan layanan lainnya.
- **Signatur Otomatis**: Logika pembuatan signatur RSA-SHA256 sesuai standar keamanan Paylabs.
- **Penanganan Callback**: Server contoh untuk menerima dan memverifikasi notifikasi callback status transaksi.
- **Konfigurasi Environment**: Penggunaan file `.env` untuk memudahkan pengaturan kredensial (`Merchant ID`, `Keys`, dll).

## Persiapan Umum

1. Clone repositori ini:
   ```bash
   git clone https://github.com/ahmadeko2017/paylabs-quick-start-guide.git
   cd paylabs-quick-start-guide
   ```
2. Pilih direktori bahasa yang diinginkan.
3. Baca `README.md` di dalam masing-masing direktori untuk instruksi spesifik instalasi dan penggunaan.

## Dokumentasi Resmi

Untuk detail API yang lebih lengkap, silakan kunjungi [Paylabs Documentation Center](https://docs.paylabs.co.id/).

---

© 2026 Paylabs. All rights reserved.
