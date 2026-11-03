# Print bridge di PC Windows

PC ini hanya menyampaikan perintah cetak dari server POS ke printer LAN. POS dan datanya tetap di server.

## Jaringan

- PC harus satu jaringan (satu router) dengan printer LAN, supaya bisa menjangkau IP printer.
- PC harus bisa mengakses internet (hanya keluar) untuk menghubungi server POS.
- Tablet kasir, waiter, dan dapur tidak perlu satu jaringan dengan PC atau printer. Mereka hanya perlu internet.

## Pasang

1. Pasang Node.js LTS (versi 18 atau lebih baru) dari nodejs.org. Biarkan opsi "Add to PATH" aktif.
2. Buat folder `C:\pos-bridge`.
3. Di POS: Pengaturan > Printer. Tambahkan printer dengan alamat IP dan port 9100. Tekan **Buat token**.
4. Unduh `print-bridge.mjs` dari kartu Jembatan cetak di halaman itu, dan salin bersama `start-print-bridge.bat` ke `C:\pos-bridge`.
5. Buka `start-print-bridge.bat` dengan Notepad. Isi `PRINT_BRIDGE_URL` dan `PRINT_BRIDGE_TOKEN`.
6. Klik dua kali `start-print-bridge.bat`. Status di halaman Printer berubah menjadi "Terhubung".

## Jalan otomatis saat PC menyala

Buka Command Prompt sebagai Administrator, lalu jalankan:

```
schtasks /Create /TN "POS Print Bridge" /TR "C:\pos-bridge\start-print-bridge.bat" /SC ONSTART /RU SYSTEM /RL HIGHEST /F
```

Tugas ini berjalan saat PC menyala, tanpa perlu login. Untuk mencoba tanpa restart: `schtasks /Run /TN "POS Print Bridge"`.

## Catatan

- Log ada di `C:\pos-bridge\print-bridge.log`.
- Jika PC mati, cetakan ke printer LAN berhenti. Tiket yang menunggu lebih dari 30 menit ditandai gagal dan bisa dicetak ulang dari halaman Printer.
- Jika token diganti, perbarui `start-print-bridge.bat`.
- Beri IP tetap pada printer (lewat router atau pengaturan printer), supaya alamatnya tidak berubah.
