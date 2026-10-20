# PRD — POS System Restaurant

Oct 20, 2026 · @Syahbudin

## 1. Executive Summary

Sistem POS web untuk satu outlet kafe/restoran, dibangun dengan Laravel + React (Inertia) dan PostgreSQL, di-deploy ke VPS Hostinger, dengan jurnal akuntansi otomatis dari setiap transaksi.

**Problem statement.** Kafe baru butuh pencatatan order, pembayaran, dan keuangan yang rapi sejak hari pertama. POS open source yang tersedia tidak ada yang cocok: yang matang bukan POS kasir, yang fiturnya F&B belum teruji.

**Proposed solution.** POS milik sendiri yang mencakup kasir, self-order via QR meja, layar dapur, shift kasir, serta laporan penjualan dan akuntansi double-entry ringan. Kode dibangun modular agar fitur baru bisa ditambah tanpa membongkar inti.

**Success criteria (diukur 30 hari setelah go-live):**

- Waktu input order oleh kasir untuk pesanan 3 item ≤ 30 detik (median).
- Selisih kas saat tutup shift ≤ Rp0 untuk ≥ 95% shift, dan setiap selisih tercatat dengan alasan.
- 100% transaksi lunas menghasilkan jurnal akuntansi yang seimbang (debit = kredit), diverifikasi oleh test otomatis.
- Pesanan dari QR meja muncul di layar kasir dan dapur ≤ 3 detik setelah dikirim customer.
- Semua halaman operasional (kasir, dapur, self-order) lolos skor Lighthouse Performance ≥ 85 dan Accessibility ≥ 90 di profil mobile.

## 2. Keputusan yang Dikunci & Asumsi

Enam keputusan berikut sudah final dan menjadi dasar skema database serta alur aplikasi.

| Area | Keputusan |
| --- | --- |
| Skala | 1 outlet. Semua tabel transaksi tetap punya kolom `outlet_id` agar multi-outlet bisa ditambah tanpa migrasi besar. |
| Stack | Laravel 13, React + Inertia, TypeScript, Tailwind CSS, PostgreSQL. |
| Self-order QR | Customer pesan dari meja; pesanan wajib dikonfirmasi kasir sebelum masuk dapur, dengan notifikasi visual dan suara. Pembayaran di kasir. |
| Pembayaran | Tunai, QRIS, atau transfer bank. Non-tunai hanya pencatatan: kasir memilih akun penerima agar tercatat uang masuk ke rekening mana. Daftar metode dan rekening diatur dari pengaturan karena belum ditentukan. Tidak ada integrasi payment gateway. |
| Pajak & biaya | Saat ini belum ada pajak. Semua tarif (PB1, service charge, PPN) diatur dari pengaturan dan default nonaktif. Mode harga sudah termasuk pajak atau belum bisa dipilih. |
| Akuntansi | Double-entry ringan: chart of accounts, jurnal otomatis dari penjualan dan pengeluaran, laba rugi, neraca sederhana. Tanpa HPP/inventori di MVP. |

**Asumsi yang perlu divalidasi:**

- Kafe punya koneksi internet stabil. Saat internet putus, POS tidak bisa dipakai karena berjalan di VPS (lihat Risiko).
- Mata uang tunggal: Rupiah, tanpa desimal. Semua nominal disimpan sebagai `bigint` rupiah.
- Zona waktu operasional Asia/Jakarta. Hari bisnis dimulai pada jam batas yang bisa diatur (default 04.00), sehingga operasional yang lewat tengah malam tetap masuk tanggal bisnis yang sama.
- Printer belum ditentukan, jadi sistem mendukung printer thermal ESC/POS 58 mm dan 80 mm, koneksi USB maupun LAN. Dapur dan bar saat ini satu stasiun; stasiun baru bisa ditambah dari pengaturan.

## 3. Persona & Hak Akses

Lima peran internal plus customer tamu; hak akses dikelola dengan permission per aksi, bukan per halaman.

| Persona | Perangkat utama | Kebutuhan inti |
| --- | --- | --- |
| Owner | HP, laptop | Melihat laporan penjualan dan keuangan dari mana saja, mengatur menu, harga, pajak, user. |
| Manager / Supervisor | Tablet, laptop | Approve void dan diskon, buka/tutup shift, input pengeluaran, cek laporan harian. |
| Kasir | Tablet 10", HP | Input order cepat, terima order QR, terima pembayaran, cetak struk. |
| Waiter | HP | Input order dari meja, lihat status meja, antar pesanan yang sudah siap. |
| Dapur / Bar | Tablet di dapur | Melihat antrean pesanan per stasiun, tandai sedang dibuat dan siap. |
| Customer (tamu) | HP pribadi | Scan QR meja, lihat menu, pesan tanpa login. |

**Matriks permission (MVP):**

| Permission | Owner | Manager | Kasir | Waiter | Dapur |
| --- | --- | --- | --- | --- | --- |
| Buat / ubah order | Ya | Ya | Ya | Ya | Tidak |
| Terima pembayaran | Ya | Ya | Ya | Tidak | Tidak |
| Diskon ≤ batas kasir | Ya | Ya | Ya | Tidak | Tidak |
| Diskon > batas kasir | Ya | Ya | PIN manager | Tidak | Tidak |
| Void item / order | Ya | Ya | PIN manager | Tidak | Tidak |
| Refund | Ya | Ya | Tidak | Tidak | Tidak |
| Buka / tutup shift | Ya | Ya | Ya (shift sendiri) | Tidak | Tidak |
| Update status dapur | Ya | Ya | Ya | Tidak | Ya |
| Input pengeluaran | Ya | Ya | Tidak | Tidak | Tidak |
| Laporan penjualan | Ya | Ya | Rekap shift sendiri | Tidak | Tidak |
| Laporan akuntansi | Ya | Ya (baca) | Tidak | Tidak | Tidak |
| Kelola menu, harga, pajak, user | Ya | Menu saja | Tidak | Tidak | Tidak |

"PIN manager" berarti kasir bisa melakukan aksi itu setelah manager memasukkan PIN 6 digit di perangkat kasir; nama manager yang menyetujui tercatat di audit log.

## 4. User Stories & Acceptance Criteria

Lima belas modul berikut membentuk MVP; setiap kriteria harus lolos test otomatis atau uji manual tertulis sebelum dianggap selesai.

### 4.1 Menu, Kategori & Modifier

**Story:** Sebagai owner, saya ingin mengatur menu beserta variasinya agar kasir dan customer melihat pilihan yang sama.

- Produk punya: nama, kategori, harga dasar, foto (opsional), deskripsi, SKU/kode, stasiun dapur (Dapur/Bar), status tersedia/habis, urutan tampil.
- Varian (misal Hot/Iced, Regular/Large) mengganti harga dasar; modifier group (misal Sugar Level, Extra Shot) menambah harga per opsi.
- Modifier group punya aturan min/maks pilihan dan flag wajib/opsional; sistem menolak order yang melanggar aturan, di server.
- Tombol "Habis" langsung menyembunyikan produk dari menu QR dan menandainya di layar kasir dalam ≤ 3 detik.
- Perubahan harga tidak mengubah order lama: setiap item order menyimpan snapshot nama, harga, dan modifier saat dipesan.

### 4.2 Area & Meja

**Story:** Sebagai waiter, saya ingin melihat status semua meja agar tahu meja mana yang kosong, terisi, atau menunggu bayar.

- Meja dikelompokkan per area (Indoor, Outdoor, Lantai 2) dengan kapasitas kursi.
- Status meja: Kosong, Terisi, Menunggu Bayar; berubah otomatis mengikuti order.
- Setiap meja punya QR unik berisi token acak (bukan ID berurutan); owner bisa membuat ulang token jika QR bocor.
- Fitur pindah meja dan gabung meja tersedia untuk kasir/manager, tercatat di audit log.
- Halaman cetak QR menghasilkan PDF siap cetak, satu QR per meja dengan nama meja.

### 4.3 POS Kasir & Input Order

**Story:** Sebagai kasir, saya ingin memasukkan pesanan dengan sedikit ketukan agar antrean tidak menumpuk.

- Tipe order: Dine-in (wajib pilih meja), Takeaway, dan Online/Ojol (label platform, tanpa integrasi).
- Layar POS: tab kategori, grid produk, pencarian nama/kode, keranjang dengan qty, catatan per item, dan modal modifier.
- Satu meja bisa punya beberapa ronde pesanan dalam satu order; tiap ronde dikirim ke dapur sebagai tiket terpisah.
- Order bisa disimpan (open bill) dan dilanjutkan dari perangkat lain.
- Total, pajak, service charge, dan diskon selalu dihitung ulang di server; angka dari browser hanya untuk tampilan.
- Nomor order unik per hari dengan format `ORD-YYMMDD-NNNN`, dibuat di server dalam satu transaksi database.

### 4.4 Self-Order via QR Meja

**Story:** Sebagai customer, saya ingin scan QR di meja dan memesan sendiri tanpa menunggu waiter.

- Scan QR membuka halaman menu tanpa login dan tanpa instal aplikasi, dengan meja sudah terpilih otomatis.
- Customer memasukkan nama panggilan (wajib) sebelum kirim pesanan.
- Pesanan QR masuk dengan status "Menunggu Konfirmasi" dan muncul di layar kasir ≤ 3 detik, berlabel "QR", disertai pop-up notifikasi dan bunyi.
- Kasir menekan **Konfirmasi** (pesanan dikirim ke dapur) atau **Tolak** (alasan wajib, ditampilkan ke customer). Sebelum konfirmasi, kasir bisa mengubah item atau qty, misal karena menu habis.
- Pesanan yang belum dikonfirmasi lebih dari 3 menit (bisa diatur) memicu notifikasi dan bunyi ulang sampai ditangani.
- Setelah dikonfirmasi, jika meja sudah punya order terbuka, pesanan QR ditambahkan sebagai ronde baru ke order yang sama.
- Customer melihat status pesanannya (Menunggu Konfirmasi, Diterima, Dibuat, Siap, Ditolak) dan total sementara di halaman yang sama.
- Halaman QR menampilkan pesan "Pembayaran di kasir"; tidak ada tombol bayar.
- Anti-spam: maksimal 5 pengiriman per meja per 10 menit, dan kasir bisa menonaktifkan QR per meja.

### 4.5 Dapur / Kitchen Display (KDS)

**Story:** Sebagai staf dapur, saya ingin melihat antrean pesanan untuk stasiun saya agar urutan pembuatan jelas.

- Layar KDS per stasiun (default satu stasiun "Dapur & Bar", bisa ditambah dari pengaturan) menampilkan tiket dengan nomor meja/tipe order, item, modifier, catatan, dan umur tiket.
- Status per item: Baru → Dibuat → Siap; staf mengetuk untuk memajukan status.
- Tiket berubah warna setelah melewati ambang waktu yang bisa diatur (default 10 dan 20 menit).
- Opsi cetak tiket ke printer dapur, selain atau sebagai pengganti layar. Tiket baru memicu bunyi notifikasi di KDS.
- Item yang di-void setelah dikirim ke dapur muncul dengan coretan dan label VOID, tidak hilang diam-diam.

### 4.6 Pembayaran (Tunai / QRIS)

**Story:** Sebagai kasir, saya ingin mencatat pembayaran beserta akun penerimanya agar owner tahu uang masuk ke mana.

- Metode pembayaran dikelola sebagai data: setiap metode terhubung ke satu akun di chart of accounts (misal Tunai → Kas Laci, QRIS → Bank A, Transfer → Bank B). Metode dan rekening ditambah dari pengaturan tanpa ubah kode.
- Tunai: input nominal diterima, sistem menghitung kembalian, dengan tombol cepat nominal (pas, 50.000, 100.000).
- QRIS dan transfer bank: kasir memilih akun penerima dan bisa mengisi nomor referensi (opsional); tidak ada verifikasi otomatis ke bank. Pre-bill (cetak tagihan sebelum bayar) tersedia agar tamu bisa mengecek pesanan.
- Split payment: satu order bisa dibayar dengan beberapa metode (misal sebagian tunai, sebagian QRIS).
- Split bill: satu order bisa dipecah menjadi beberapa tagihan yang dibayar terpisah (detail di 4.6a).
- Order berstatus Lunas hanya jika total pembayaran ≥ grand total; setelah lunas, order terkunci dari perubahan item.
- Setiap pembayaran lunas otomatis membuat jurnal akuntansi dalam transaksi database yang sama.

### 4.6a Split Bill

**Story:** Sebagai kasir, saya ingin memecah satu tagihan meja menjadi beberapa tagihan agar setiap tamu bisa membayar bagiannya sendiri.

- Tiga mode pemecahan:
  - **Per item**: kasir memilih item ke tagihan mana; qty bisa dipecah (2 kopi → 1 di tagihan A, 1 di tagihan B).
  - **Bagi rata**: grand total dibagi N tagihan; sisa pembagian rupiah dibebankan ke tagihan terakhir.
  - **Nominal bebas**: kasir mengisi nominal per tagihan; total semua tagihan harus sama dengan grand total.
- Diskon order, service charge, dan pajak dibagi proporsional ke setiap tagihan sesuai porsi subtotalnya; pembulatan dihitung per tagihan.
- Setiap tagihan punya label (misal "Tamu 1" atau nama), status sendiri (Belum Bayar, Lunas), dan bisa dibayar dengan metode berbeda.
- Satu tagihan juga bisa dibayar dengan beberapa metode (split payment di dalam split bill).
- Struk dicetak per tagihan, menampilkan item atau porsi yang dibayar dan nomor order induk.
- Order berstatus Lunas hanya setelah semua tagihannya lunas; sebelum itu statusnya "Sebagian Dibayar" dan meja tetap "Menunggu Bayar".
- Pemecahan bisa dibatalkan atau diubah selama belum ada tagihan yang lunas; setelah satu tagihan lunas, item di tagihan itu terkunci.
- Item baru yang ditambahkan setelah split masuk ke tagihan "Belum dialokasikan" dan harus dialokasikan sebelum order bisa lunas.
- Jurnal akuntansi dibuat per tagihan yang lunas, sehingga kas dan pendapatan tercatat sesuai waktu uang diterima.
- Laporan penjualan tetap dihitung per order induk, sehingga jumlah transaksi tidak membengkak karena split.

### 4.7 Shift Kasir & Rekap Kas

**Story:** Sebagai manager, saya ingin setiap shift ditutup dengan hitungan kas fisik agar selisih langsung ketahuan.

- Kasir tidak bisa menerima pembayaran tanpa shift terbuka di perangkatnya.
- Buka shift: modal kembalian di laci terisi otomatis dari uang yang ditinggal shift sebelumnya; kasir menghitung dan mengonfirmasi atau mengoreksi.
- Selama shift: catat kas masuk/keluar non-penjualan (misal beli es batu) dengan alasan.
- Tutup shift: kasir input uang fisik per pecahan; sistem menampilkan kas seharusnya, kas fisik, dan selisih.
- Saat tutup shift, kasir mengisi uang yang ditinggal di laci sebagai modal kembalian shift berikutnya; sisanya dicatat sebagai setoran ke owner atau bank.
- Selisih ≠ 0 wajib diisi alasan; selisih otomatis dijurnal ke akun Selisih Kas.
- Rekap shift bisa dicetak: total per metode bayar, jumlah order, void, diskon, komplimen, refund.

### 4.8 Diskon, Void & Refund

**Story:** Sebagai owner, saya ingin setiap pengurangan nilai transaksi tercatat siapa yang melakukan dan siapa yang menyetujui.

- Diskon per item atau per order, nominal atau persentase, dengan alasan wajib. Batas diskon kasir tanpa PIN manager diatur dari pengaturan (default 10%).
- Promo terjadwal (misal Happy Hour 15.00–17.00) dikonfigurasi dari pengaturan.
- Void sebelum bayar: item atau order dibatalkan, butuh PIN manager, alasan wajib.
- Refund setelah bayar: hanya manager/owner, membuat jurnal pembalik, order asli tidak diubah.
- Tidak ada transaksi yang di-hard-delete; semua pembatalan tercatat sebagai status dan entri audit.

### 4.9 Pajak, Service Charge & Pembulatan

**Story:** Sebagai owner, saya ingin mengatur tarif pajak dan biaya tanpa mengubah kode.

- Setiap komponen biaya punya: nama, tarif persen, aktif/nonaktif, urutan perhitungan, dan basis (subtotal setelah diskon, atau subtotal + komponen sebelumnya).
- Default urutan: Subtotal → Diskon → Service Charge → Pajak (PB1 atau PPN).
- Saat go-live tidak ada pajak aktif: semua komponen default nonaktif dan struk tidak menampilkan baris pajak. Mode harga dipilih dari pengaturan: harga menu sudah termasuk pajak (tax-inclusive) atau belum (tax-exclusive); perubahan mode hanya berlaku untuk order baru.
- Pembulatan grand total bisa diatur: tanpa pembulatan, ke Rp100, atau ke Rp500; selisih pembulatan dicatat di akun tersendiri.
- Rincian setiap komponen disimpan per order (snapshot tarif), sehingga perubahan tarif tidak mengubah laporan lama.

### 4.10 Laporan Penjualan

**Story:** Sebagai owner, saya ingin melihat performa penjualan dari HP agar bisa ambil keputusan menu dan jadwal staf.

- Dashboard: omzet hari ini, jumlah transaksi, rata-rata nilai transaksi, dan perbandingan dengan hari yang sama minggu lalu.
- Laporan dengan filter rentang tanggal: penjualan per hari, per jam, per produk, per kategori, per metode bayar, per kasir, per tipe order.
- Laporan diskon, void, dan refund dengan nama pelaku dan penyetuju.
- Ekspor setiap laporan ke Excel (.xlsx) dan PDF.
- Laporan 30 hari tampil ≤ 2 detik untuk data hingga 50.000 order.

### 4.11 Akuntansi (Double-Entry Ringan)

**Story:** Sebagai owner, saya ingin laba rugi dan posisi kas tersusun otomatis dari transaksi harian.

- Chart of accounts bawaan (bisa diedit): Kas Laci, Bank per rekening QRIS, Pendapatan Penjualan, Pendapatan Service Charge, Utang Pajak, Diskon Penjualan, Selisih Kas, Selisih Pembulatan, Beban per kategori, Modal Pemilik.
- Jurnal otomatis dari: pembayaran lunas, refund, selisih shift, kas masuk/keluar, pengeluaran.
- Jurnal manual oleh owner untuk koreksi, dengan validasi debit = kredit. Saldo awal saat go-live (uang kembalian di laci, saldo rekening, modal) diisi sekali oleh owner sebagai jurnal pembuka terhadap Modal Pemilik.
- Jurnal yang sudah diposting tidak bisa diedit; koreksi lewat jurnal pembalik.
- Laporan: Buku Besar per akun, Jurnal Umum, Laba Rugi per periode, Neraca sederhana, Arus Kas per akun kas/bank.
- Tutup buku bulanan mengunci periode dari jurnal baru.

### 4.12 Pengeluaran Operasional

**Story:** Sebagai manager, saya ingin mencatat pengeluaran harian agar laba rugi akurat.

- Input pengeluaran: tanggal, kategori beban, nominal, sumber dana (Kas Laci atau rekening bank), catatan, foto nota (opsional).
- Setiap pengeluaran membuat jurnal otomatis: Debit Beban, Kredit akun sumber dana.
- Kategori beban dikelola owner (misal Bahan Baku, Gaji, Listrik, Sewa).

### 4.13 Struk & Printer

**Story:** Sebagai kasir, saya ingin mencetak struk dan tiket dapur tanpa dialog cetak browser.

- Struk berisi: nama dan alamat outlet, nomor order, tanggal-jam, kasir, meja, item + modifier, rincian pajak/biaya, metode bayar, kembalian, catatan kaki yang bisa diatur.
- Mendukung printer thermal ESC/POS 58 mm dan 80 mm, koneksi USB maupun LAN, dicetak lewat QZ Tray; printer dan lebar kertas dipilih per perangkat dari pengaturan. Jika gagal, fallback ke cetak browser.
- Cetak ulang struk tercatat di audit log dengan label "COPY".
- Opsi kirim struk digital: link struk publik bertoken yang bisa dibagikan via WhatsApp.

### 4.14 Pengaturan & User

- Profil outlet: nama, alamat, logo, NPWP (opsional), jam operasional.
- Manajemen user: buat, nonaktifkan (tidak dihapus), reset password, set PIN, set role.
- Semua pengaturan tersimpan di tabel `settings` dengan cache; perubahan tercatat di audit log.

### 4.15 Fitur Operasional Tambahan

**Story:** Sebagai owner, saya ingin kasus sehari-hari di kafe tertangani di sistem, bukan dicatat manual di luar POS.

- **Komplimen & staff meal:** item bisa ditandai komplimen atau staff meal dengan alasan dan PIN manager. Nilainya tidak masuk omzet, tercatat di laporan komplimen dengan harga jual, dan belum dijurnal sampai HPP tersedia di v2.0.
- **Nomor panggil:** order takeaway mendapat nomor panggil harian (misal A-012) yang tampil di struk, KDS, dan layar kasir.
- **Stok porsi:** produk bisa diberi stok porsi (misal 20 croissant). Stok berkurang saat order dikonfirmasi, kembali saat item di-void, dan produk otomatis "Habis" saat stok 0. Produk tanpa stok porsi dianggap tidak terbatas.
- **Pindah item antar meja:** kasir bisa memindahkan item yang belum dibayar ke meja atau order lain, tercatat di audit log.
- **Tutup hari (Z-report):** setelah semua shift ditutup, manager menutup hari bisnis. Rekap omzet, metode bayar, void, diskon, komplimen, dan selisih kas dikirim otomatis ke Telegram owner.
- **Pusat notifikasi:** kasir menerima pop-up dan bunyi untuk order QR baru, order QR yang belum dikonfirmasi, dan item yang sudah Siap. Bunyi bisa diatur volumenya dan dimatikan per perangkat. Browser butuh satu ketukan setelah login untuk mengizinkan audio.

## 5. Non-Goals (Tidak Dibangun di MVP)

Daftar ini sengaja dibatasi agar MVP selesai sebelum kafe buka; setiap item bisa masuk roadmap setelah go-live.

- Integrasi payment gateway atau verifikasi QRIS otomatis ke bank.
- Inventori bahan baku, resep, dan HPP per menu.
- Multi-outlet aktif (skema sudah siap, UI dan laporan lintas outlet belum).
- Integrasi API GoFood, GrabFood, ShopeeFood.
- Pembayaran online oleh customer dari halaman QR.
- Program loyalty, member, poin, dan voucher.
- Mode offline penuh (PWA dengan sinkronisasi). MVP butuh internet.
- Aplikasi native Android/iOS. Semua akses lewat browser.
- Payroll, absensi, dan penjadwalan staf.
- E-Faktur pajak dan pelaporan pajak otomatis.

## 6. Non-Functional Requirements

Setiap requirement di bawah punya angka yang bisa diuji; yang tidak bisa diuji belum boleh dianggap requirement.

### 6.1 Responsive & Mobile-First

Desain dimulai dari layar 360 px, lalu diperluas ke tablet dan desktop.

| Layar | Lebar | Target utama | Layout POS |
| --- | --- | --- | --- |
| HP | 360–767 px | Customer QR, waiter, owner cek laporan | Satu kolom; keranjang sebagai bottom sheet dengan tombol total melayang. |
| Tablet | 768–1279 px | Kasir, KDS dapur | Dua kolom: grid produk kiri, keranjang kanan (tetap terlihat). |
| Desktop | ≥ 1280 px | Owner, manager, admin | Dua kolom dengan sidebar navigasi tetap. |

- Target sentuh minimal 44 × 44 px; tombol utama kasir minimal 48 px tinggi.
- Tidak ada scroll horizontal di semua halaman pada lebar 360 px.
- Tabel laporan di HP berubah menjadi daftar kartu, atau tabel dengan kolom pertama sticky.
- Diuji di: Chrome Android, Safari iOS, Chrome desktop; minimal satu tablet Android 10" dan satu HP 6" fisik.
- Mendukung mode terang dan gelap; KDS default gelap agar terbaca di dapur.

### 6.2 Performa

- Halaman QR menu: First Contentful Paint ≤ 1,8 detik di jaringan 4G lambat (profil Lighthouse "Slow 4G").
- Bundle JavaScript halaman QR ≤ 150 KB gzip (code-splitting per halaman Inertia).
- Respons API simpan order dan bayar ≤ 300 ms (p95) di server.
- Update realtime (order baru ke KDS dan kasir) ≤ 3 detik end-to-end.
- Foto produk dikonversi ke WebP, maksimal 800 px, ≤ 120 KB.

### 6.3 Ketersediaan & Keandalan

- Target uptime aplikasi 99,5% per bulan pada jam operasional.
- Backup database otomatis setiap 6 jam, disalin ke penyimpanan di luar VPS, disimpan 30 hari.
- Uji restore backup dilakukan minimal sekali sebulan, dicatat hasilnya.
- Semua operasi uang (bayar, refund, tutup shift) idempotent: tombol ditekan dua kali tidak membuat dua transaksi.

### 6.4 Aksesibilitas & Bahasa

- Skor Lighthouse Accessibility ≥ 90 di halaman kasir, KDS, dan QR.
- Kontras teks minimal 4,5:1 (WCAG AA).
- Bahasa antarmuka Bahasa Indonesia; semua teks lewat file terjemahan agar Bahasa Inggris bisa ditambah tanpa ubah kode.
- Format angka Rupiah `Rp12.500`, tanggal `20 Okt 2026`.

## 7. Technical Specifications

Arsitekturnya modular monolith: satu aplikasi Laravel, dipecah per domain bisnis, agar sederhana di-deploy di satu VPS tapi tetap rapi untuk dikembangkan.

### 7.1 Stack

| Lapisan | Pilihan | Alasan |
| --- | --- | --- |
| Backend | Laravel 13, PHP 8.4 | Ekosistem matang, sesuai keahlian tim. |
| Frontend | React 19 + Inertia 2 + TypeScript, dari Laravel React starter kit | Satu repo, tanpa API terpisah untuk halaman internal. |
| UI | Tailwind CSS 4 + shadcn/ui | Komponen aksesibel, mudah dikustom, ringan. |
| Database | PostgreSQL 17 | Sesuai keputusan; mendukung `CHECK` constraint, partial index, JSONB. |
| Cache, queue, session | Redis + Laravel Horizon | Queue untuk cetak, ekspor laporan, notifikasi. |
| Realtime | Laravel Reverb (WebSocket) + Laravel Echo | Update KDS dan kasir ≤ 3 detik, self-hosted. |
| Data object | spatie/laravel-data + typescript-transformer | Satu definisi DTO menghasilkan tipe TypeScript otomatis. |
| Route typing | Laravel Wayfinder | Route dan controller ter-typing di sisi React. |
| Permission | spatie/laravel-permission | Matriks hak akses di bagian 3. |
| Audit | spatie/laravel-activitylog | Log siapa mengubah apa. |
| Ekspor | maatwebsite/excel, barryvdh/laravel-dompdf | Laporan .xlsx dan PDF. |
| Cetak | QZ Tray (di PC/tablet kasir) | Cetak langsung ke printer thermal ESC/POS. |
| Test | Pest + Playwright | Unit, feature, dan alur end-to-end. |
| Kualitas kode | Laravel Pint, Larastan level 8, ESLint, Prettier | Dijalankan otomatis di CI. |

Stack di atas final untuk MVP. Versi minor dikunci di `composer.json` dan `package-lock.json` saat proyek dimulai; upgrade major hanya lewat PR terpisah dengan seluruh test lulus.

### 7.2 Struktur Kode (Clean Code)

Setiap domain punya folder sendiri; controller tipis, logika bisnis di Action, data antar lapisan lewat DTO.

```
app/
  Domain/
    Catalog/        Product, Category, Variant, ModifierGroup
    Floor/          Area, Table, TableToken
    Ordering/       Order, OrderRound, OrderItem, OrderStatus
    Kitchen/        KitchenTicket, Station
    Payment/        Payment, PaymentMethod
    Shift/          Shift, CashMovement
    Accounting/     Account, JournalEntry, JournalLine, Period
    Expense/        Expense, ExpenseCategory
    Pricing/        TaxRule, Discount, Promotion, PriceCalculator
    Reporting/      Query object per laporan
    Settings/       Setting, OutletProfile
      (per domain: Models/ Actions/ Data/ Enums/ Events/ Policies/ Exceptions/)
  Http/
    Controllers/    tipis: validasi -> panggil Action -> return Inertia/JSON
    Requests/       FormRequest per endpoint
  Support/
    Money.php       value object Rupiah (bigint)
resources/js/
  pages/            satu folder per domain, mengikuti route
  components/ui/    shadcn/ui
  components/pos/   komponen khusus POS (Cart, ProductGrid, ModifierSheet)
  types/generated/  hasil typescript-transformer, tidak diedit manual
```

**Aturan coding:**

- Satu Action = satu use case dengan satu method publik `handle()`, misal `PlaceOrderAction`, `RecordPaymentAction`, `CloseShiftAction`.
- Operasi uang dibungkus `DB::transaction()` dan memakai `lockForUpdate()` pada baris order/shift yang diubah.
- Controller tidak boleh berisi query atau perhitungan; maksimal validasi, panggil Action, kembalikan respons.
- Status memakai PHP Enum; transisi status hanya lewat method yang memvalidasi transisi yang diizinkan.
- Antar domain berkomunikasi lewat Action publik atau Event, bukan saling mengakses model internal.
- Jurnal akuntansi dibuat sinkron di dalam transaksi yang sama dengan pembayaran, bukan lewat queue, agar tidak ada pembayaran tanpa jurnal.
- Semua uang memakai value object `Money` berbasis integer; float dilarang untuk nominal.

### 7.3 Skema Database (PostgreSQL)

Primary key `bigint`; tabel yang terekspos ke publik (meja, struk) memakai kolom `public_id` ULID tambahan. Semua nominal `bigint` rupiah dengan `CHECK (amount >= 0)`.

| Tabel | Kolom penting | Catatan |
| --- | --- | --- |
| `outlets` | name, address, timezone | Satu baris di MVP. |
| `users` | name, email, pin\_hash, is\_active | Soft deactivate, tidak dihapus. |
| `categories` | outlet\_id, name, sort |  |
| `products` | category\_id, name, sku, base\_price, station, is\_available, stock\_qty (null = tidak terbatas), image\_path | Soft delete. |
| `product_variants` | product\_id, name, price | Mengganti harga dasar. |
| `modifier_groups` | name, min\_select, max\_select, is\_required | Relasi many-to-many ke products. |
| `modifiers` | modifier\_group\_id, name, price\_delta |  |
| `areas`, `tables` | name, capacity, qr\_token, status, qr\_enabled | `qr_token` unik, bisa dibuat ulang. |
| `orders` | outlet\_id, number, type, table\_id, status, customer\_name, subtotal, discount\_total, charge\_total, rounding, grand\_total, paid\_total, call\_number, business\_date, opened\_by, closed\_at, shift\_id | `UNIQUE(outlet_id, number)`. |
| `order_rounds` | order\_id, source (pos/qr), status (pending/confirmed/rejected), confirmed\_by, reject\_reason, created\_by | Satu ronde = satu tiket dapur. |
| `order_items` | order\_round\_id, product\_id, name\_snapshot, unit\_price, qty, modifiers\_snapshot (JSONB), note, line\_total, status, voided\_by, void\_reason, comp\_type (complimentary/staff\_meal), comp\_approved\_by | Snapshot harga, tidak berubah. |
| `order_charges` | order\_id, tax\_rule\_id, name\_snapshot, rate, base, amount | Snapshot pajak/biaya. |
| `discounts` | order\_id, order\_item\_id, type, value, amount, reason, approved\_by |  |
| `payments` | order\_id, bill\_id, payment\_method\_id, amount, tendered, change, reference, idempotency\_key, shift\_id, received\_by | `UNIQUE(idempotency_key)`. |
| `payment_methods` | name, type (cash/qris/transfer), account\_id, is\_active | Menentukan uang masuk ke akun mana. |
| `refunds` | payment\_id, amount, reason, approved\_by |  |
| `shifts` | user\_id, device\_label, opening\_cash, expected\_cash, counted\_cash, cash\_left, cash\_deposited, difference, difference\_reason, opened\_at, closed\_at | Satu shift terbuka per user (partial unique index). |
| `cash_movements` | shift\_id, direction, amount, reason, account\_id |  |
| `tax_rules` | name, rate\_bp, sequence, base, is\_inclusive, is\_active | Tarif dalam basis poin (1000 = 10%). |
| `accounts` | code, name, type (asset/liability/equity/revenue/expense), is\_system |  |
| `journal_entries` | date, period\_id, source\_type, source\_id, memo, posted\_at, reversed\_by | Polymorphic ke order/refund/expense/shift. |
| `journal_lines` | journal\_entry\_id, account\_id, debit, credit | `CHECK` hanya salah satu > 0. |
| `periods` | month, year, closed\_at | Periode tertutup menolak jurnal baru. |
| `expenses`, `expense_categories` | date, category\_id, amount, source\_account\_id, note, receipt\_path |  |
| `settings` | key, value (JSONB) | Di-cache. |
| `activity_log` | dari spatie/activitylog | Audit semua aksi sensitif. |

**Tabel split bill:**

| Tabel | Kolom penting | Catatan |
| --- | --- | --- |
| `bills` | order\_id, label, split\_mode (item/equal/amount), subtotal, discount\_total, charge\_total, rounding, grand\_total, paid\_total, status | Order tanpa split tetap punya satu `bill` default agar alur bayar seragam. |
| `bill_items` | bill\_id, order\_item\_id, qty, line\_total | Untuk mode per item; `SUM(qty)` per order\_item tidak boleh melebihi qty item. |

Konsistensi jurnal dijaga dua lapis: Action menolak entri yang tidak seimbang, dan constraint trigger PostgreSQL memastikan jumlah debit sama dengan jumlah kredit per `journal_entry` saat commit.

### 7.4 Pemetaan Jurnal Otomatis

| Kejadian | Debit | Kredit |
| --- | --- | --- |
| Order lunas | Kas Laci / Bank per metode bayar; Diskon Penjualan | Pendapatan Penjualan (subtotal kotor); Pendapatan Service Charge; Utang Pajak; Selisih Pembulatan (atau sebaliknya jika minus) |
| Refund | Kebalikan proporsional dari jurnal order | Kas Laci / Bank |
| Kas keluar di shift | Beban sesuai kategori | Kas Laci |
| Kas masuk di shift | Kas Laci | Akun sesuai alasan (misal Modal Pemilik) |
| Selisih kas kurang | Selisih Kas | Kas Laci |
| Selisih kas lebih | Kas Laci | Selisih Kas |
| Pengeluaran | Beban sesuai kategori | Kas Laci atau Bank |
| Setor kas laci ke bank | Bank | Kas Laci |

### 7.5 Integrasi & Realtime

- Channel Reverb: `outlet.{id}.orders` untuk kasir, `outlet.{id}.kitchen.{station}` untuk KDS, `table.{public_id}` untuk halaman status customer.
- Event yang di-broadcast: `QrOrderSubmitted`, `OrderRoundConfirmed`, `OrderItemStatusChanged`, `OrderPaid`, `ProductAvailabilityChanged`.
- Jika WebSocket putus, frontend polling setiap 10 detik sampai koneksi pulih, dan menampilkan indikator "Offline".
- Halaman QR customer memakai route publik terpisah dengan rate limit dan tanpa sesi admin.
- QZ Tray: sertifikat penandatanganan disimpan di server, request cetak ditandatangani backend agar tidak muncul dialog izin di setiap cetak.

### 7.6 Siklus Status Order

&#91;embedded content: siklus status order · 2 sumber, 7 status\]

Order dari POS dan QR masuk ke order yang sama; setelah lunas, order hanya bisa dikoreksi lewat refund dengan jurnal pembalik.

## 8. Security & Privacy

Prinsip utamanya: setiap rupiah bisa dilacak ke orang dan waktu, dan halaman publik QR tidak membuka akses ke data internal.

**Autentikasi & otorisasi**

- Login staf dengan email + password (bcrypt/argon2), sesi berbasis cookie, timeout 12 jam atau saat shift ditutup.
- PIN manager 6 digit di-hash, maksimal 5 percobaan salah lalu terkunci 15 menit.
- Setiap aksi dicek lewat Policy + permission di server; menyembunyikan tombol di UI bukan pengamanan.
- 2FA (TOTP) wajib untuk akun owner.

**Halaman publik QR**

- Token meja acak 32 karakter, bisa dibuat ulang; token lama langsung tidak berlaku.
- Rate limit per IP dan per meja; customer hanya bisa menambah item dan melihat order mejanya sendiri.
- Harga, ketersediaan, dan aturan modifier divalidasi ulang di server; payload dari customer tidak dipercaya.

**Integritas data**

- Tidak ada hard delete untuk order, pembayaran, jurnal, shift, dan user.
- Idempotency key pada pembayaran mencegah transaksi ganda saat koneksi lambat atau tombol ditekan dua kali.
- Audit log mencatat: void, refund, diskon, cetak ulang, ubah harga, ubah pajak, buka/tutup shift, jurnal manual, login gagal.

**Infrastruktur**

- HTTPS wajib (Let's Encrypt), HSTS aktif.
- Firewall hanya membuka port 22, 80, 443; SSH login dengan key, password login dimatikan, Fail2ban aktif.
- PostgreSQL dan Redis hanya mendengarkan localhost.
- File `.env` di luar web root, `APP_DEBUG=false` di produksi.

**Privasi**

- Data customer yang disimpan hanya nama panggilan dari QR order; tidak ada nomor HP atau email di MVP.
- Jika nanti menyimpan nomor HP untuk struk WhatsApp, perlu persetujuan eksplisit sesuai UU Pelindungan Data Pribadi (UU 27/2022).

## 9. Deployment ke VPS Hostinger

Satu VPS menjalankan seluruh stack; deploy otomatis dari GitHub dengan zero-downtime dan backup ke luar server.

**Spesifikasi server minimum**

| Komponen | Minimum | Catatan |
| --- | --- | --- |
| vCPU / RAM | 2 vCPU / 8 GB | Cukup untuk 1 outlet; PostgreSQL, Redis, Reverb, dan PHP-FPM dalam satu mesin. |
| Disk | 100 GB NVMe | Termasuk foto produk dan backup lokal 7 hari. |
| OS | Ubuntu 24.04 LTS |  |
| Region | Asia (terdekat ke Indonesia) | Latensi rendah untuk kasir dan KDS. |

**Layanan yang berjalan**

- Nginx (reverse proxy + proxy WebSocket ke Reverb), PHP 8.4-FPM, PostgreSQL, Redis.
- Supervisor menjalankan: Horizon (queue), Reverb (WebSocket).
- Cron: `php artisan schedule:run` setiap menit (backup, tutup periode, bersihkan token kedaluwarsa).

**Alur deploy**

1. Push ke branch `main` memicu GitHub Actions: Pint, Larastan, ESLint, test Pest.
2. Jika lulus, build aset frontend di CI lalu deploy lewat Deployer via SSH ke folder rilis baru.
3. Jalankan `migrate --force`, cache config/route/view, lalu ganti symlink `current` ke rilis baru (zero-downtime).
4. Restart Horizon dan Reverb dengan perintah graceful; simpan 5 rilis terakhir untuk rollback cepat.
5. Migrasi yang mengubah data uang wajib punya rencana rollback tertulis di PR.

**Lingkungan**

- `local` (Laravel Herd untuk PHP dan Nginx, DBngin untuk PostgreSQL 17 dan Redis), `staging` (subdomain di VPS yang sama, database terpisah), `production`.
- Versi PostgreSQL di DBngin harus sama dengan produksi (17) agar perilaku query, index, dan constraint identik. Database test terpisah (`pos_testing`) dibuat di instance DBngin yang sama.
- Reverb dan queue di lokal dijalankan dengan `composer run dev` (server, queue, Vite, Reverb sekaligus); CI di GitHub Actions memakai service container PostgreSQL 17 dan Redis.
- Staging dipakai untuk uji coba staf sebelum fitur masuk produksi.

**Backup & monitoring**

- `pg_dump` terkompresi setiap 6 jam via spatie/laravel-backup, dikirim ke Cloudflare R2, retensi 30 hari.
- Aktifkan juga snapshot VPS mingguan dari panel Hostinger sebagai lapisan kedua.
- UptimeRobot cek `/up` setiap 1 menit, notifikasi ke WhatsApp/Telegram owner.
- Error tracking dengan Sentry (paket gratis), metrik server dengan Laravel Pulse.
- Log rotasi harian, disimpan 14 hari.

## 10. Testing & Definition of Done

Modul yang menyentuh uang (Pricing, Payment, Shift, Accounting) wajib coverage test ≥ 90%; modul lain ≥ 70%.

| Level | Alat | Wajib menguji |
| --- | --- | --- |
| Unit | Pest | `PriceCalculator` (semua kombinasi pajak inclusive/exclusive, diskon, pembulatan), `Money`, transisi status order. |
| Feature | Pest + database PostgreSQL asli (bukan SQLite) | Setiap Action: place order, tambah ronde QR, bayar split, void, refund, tutup shift, jurnal seimbang. |
| Konkurensi | Pest, dua proses paralel | Dua kasir membayar order yang sama; dua pembayaran dengan idempotency key sama. |
| End-to-end | Playwright | Alur QR order → KDS → bayar → struk, di viewport 360 px, 768 px, dan 1280 px. |
| Uji staf | Manual di staging | 1–2 minggu simulasi operasional sebelum go-live. |

**Test invariant akuntansi (wajib, dijalankan di setiap CI):**

- Total debit = total kredit untuk setiap `journal_entry`.
- Saldo akun Kas Laci per shift = modal awal + penjualan tunai + kas masuk − kas keluar − refund tunai.
- Total pendapatan di Laba Rugi = total grand total order lunas − pajak − refund untuk periode yang sama.

**Definition of Done per fitur:**

- [ ] Acceptance criteria di bagian 4 terpenuhi dan ada test-nya.
- [ ] Lolos Pint, Larastan level 8, ESLint, tanpa warning baru.
- [ ] Tampilan dicek di 360 px, 768 px, dan 1280 px.
- [ ] Aksi sensitif tercatat di audit log.
- [ ] Migrasi bisa di-rollback, atau rencana rollback ditulis di PR.
- [ ] Sudah diuji di staging oleh minimal satu staf non-developer.

## 11. Risiko & Roadmap

Risiko terbesar adalah internet kafe putus, karena seluruh POS berjalan di VPS dan MVP tidak punya mode offline.

### 11.1 Risiko Teknis

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Internet kafe putus | Kasir, KDS, dan QR order berhenti total. | Router dengan failover modem 4G; prosedur manual darurat (nota tulis) dan input susulan; mode offline dipertimbangkan di v2.0. |
| VPS down atau rusak | Operasional berhenti, risiko kehilangan data. | Backup 6 jam ke luar VPS, snapshot mingguan, runbook restore yang sudah diuji ≤ 60 menit. |
| Selisih perhitungan pajak/pembulatan | Laporan dan jurnal tidak cocok dengan uang nyata. | Satu `PriceCalculator` untuk semua, unit test semua kombinasi, snapshot tarif per order. |
| Pembayaran ganda saat koneksi lambat | Omzet dan kas tercatat lebih. | Idempotency key, `lockForUpdate`, tombol bayar dikunci setelah ditekan. |
| QR meja disalahgunakan (order iseng dari luar kafe) | Dapur membuat pesanan palsu. | Rate limit, kasir bisa menonaktifkan QR per meja, token bisa dibuat ulang, dan setiap order QR wajib dikonfirmasi kasir sebelum masuk dapur. |
| QZ Tray gagal di perangkat kasir | Struk dan tiket dapur tidak tercetak. | Fallback cetak browser dan KDS layar sebagai jalur utama dapur. |
| Scope creep | MVP tidak selesai sebelum kafe buka. | Non-goals di bagian 5 dikunci; fitur baru masuk backlog v1.1. |
| Developer tunggal | Pengembangan berhenti jika developer berhalangan. | Struktur domain yang konsisten, README per domain, CI wajib lulus. |

### 11.2 Roadmap

| Fase | Cakupan | Gate untuk lanjut |
| --- | --- | --- |
| MVP | Modul 4.1–4.14 di bagian 4. | Semua acceptance criteria lulus; 1–2 minggu uji staf di staging tanpa selisih kas yang tidak terjelaskan. |
| v1.1 | Harga per kanal ojol + pencatatan komisi, kasbon/piutang, pencatatan tip, reservasi meja dengan DP, promo lebih lengkap, struk WhatsApp, dashboard owner yang lebih kaya, setor kas ke bank dari UI. | 30 hari operasional stabil, KPI di bagian 1 tercapai. |
| v2.0 | Inventori bahan baku dan HPP per menu, mode offline (PWA + sinkronisasi), multi-outlet, integrasi payment gateway QRIS dinamis, integrasi ojol. | Kebutuhan bisnis tervalidasi; data penjualan v1.x cukup untuk menentukan prioritas. |

&#91;Low Confidence\] Estimasi MVP 6–10 minggu untuk satu developer full-time, ditambah 1–2 minggu uji staf. Angka ini berdasarkan perkiraan umum ukuran cakupan, bukan data historis tim; perbarui setelah sprint pertama selesai.

## 12. Open Questions

Pertanyaan ini perlu dijawab sebelum sprint pertama karena memengaruhi skema atau prioritas.

- [ ] Daftar akun QRIS dan rekening transfer. Diisi dari pengaturan saat setup, tidak menghambat development.
- [ ] Model printer thermal. Tentukan sebelum uji staf agar bisa dites fisik.
- [ ] Jumlah meja dan area. Diisi dari pengaturan saat setup.
- [ ] Batas diskon kasir final (default 10%).
- [ ] Nominal modal kembalian awal di laci.
- [ ] Rencana jualan via ojol, yang menentukan prioritas harga per kanal di v1.1.
- [ ] Pendaftaran pajak (PB1/PKP) di masa depan. Sistem sudah siap, tinggal diaktifkan dari pengaturan.
