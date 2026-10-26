# PRD — POS System Restaurant

Versi 2.0 · 23 Okt 2026 · @Syahbudin

> **Status dokumen.** Versi ini menggantikan PRD MVP (20 Okt 2026) dan mencerminkan aplikasi yang sudah dibangun, termasuk fase stok/HPP, notifikasi, laporan harian otomatis, dan QRIS online. Bagian 13 dan 14 adalah brief khusus untuk perancang UI/UX (daftar layar, komponen, dan aturan tampilan).

## 1. Executive Summary

Sistem POS web untuk satu outlet kafe/restoran, dibangun dengan Laravel + React (Inertia) dan PostgreSQL, di-deploy ke VPS, dengan jurnal akuntansi otomatis dari setiap transaksi, stok bahan dan HPP per menu, serta notifikasi per peran.

**Problem statement.** Kafe kecil butuh pencatatan order, pembayaran, stok, dan keuangan yang rapi sejak hari pertama, tanpa harus memakai beberapa aplikasi terpisah. POS yang ada di pasaran biasanya mahal, tidak fleksibel soal hak akses, atau tidak menyambungkan penjualan dengan stok dan keuangan.

**Proposed solution.** POS milik sendiri yang mencakup kasir, self-order via QR meja, layar dapur, shift kasir, laporan penjualan, akuntansi double-entry ringan, stok bahan/resep/HPP, belanja dan pemasok, stok opname, pencatatan waste, impor Excel, serta pusat notifikasi. Kode modular: fitur baru ditambah tanpa membongkar inti, dan setiap fitur opsional bisa dimatikan dari pengaturan.

**Prinsip produk.**

- **Sederhana untuk karyawan, ketat untuk uang.** Layar harian dibuat sedikit ketukan; semua perhitungan uang, harga, dan stok diputuskan di server.
- **Setiap fitur punya izin sendiri.** Owner mengatur peran dan izin dari layar Peran & Akses; tidak ada peran yang dikunci di kode.
- **Fitur opsional default mati.** Stok, jurnal stok, QRIS online, dan pengiriman laporan dinyalakan owner saat siap.
- **Jelas siapa melakukan apa.** Order, pembayaran, shift, belanja, opname, dan waste mencatat pelaku; ada jejak aktivitas yang bisa dicari.
- **Notifikasi ringan.** Hanya hal yang perlu ditindak oleh peran itu; data lama dibersihkan otomatis.

**Success criteria (diukur 30 hari setelah go-live):**

- Waktu input order oleh kasir untuk pesanan 3 item ≤ 30 detik (median).
- Selisih kas saat tutup shift ≤ Rp0 untuk ≥ 95% shift, dan setiap selisih tercatat dengan alasan.
- 100% transaksi lunas menghasilkan jurnal akuntansi yang seimbang (debit = kredit), diverifikasi test otomatis.
- Pesanan dari QR meja muncul di layar kasir dan dapur ≤ 3 detik setelah dikirim customer.
- Semua halaman operasional (kasir, dapur, self-order) lolos Lighthouse Performance ≥ 85 dan Accessibility ≥ 90 di profil mobile.
- Setelah stok diaktifkan: selisih stok opname bulanan ≤ 5% dari nilai stok, dan HPP per menu tersedia untuk ≥ 90% menu terjual.
- Laporan harian terkirim otomatis ke saluran owner untuk ≥ 95% hari bisnis.

## 2. Keputusan yang Dikunci & Asumsi

| Area | Keputusan |
| --- | --- |
| Skala | 1 outlet. Semua tabel transaksi tetap punya `outlet_id` agar multi-outlet bisa ditambah tanpa migrasi besar. Multi-outlet ditunda. |
| Stack | Laravel 13, React 19 + Inertia 2, TypeScript, Tailwind CSS 4, PostgreSQL 17. |
| Self-order QR | Customer pesan dari meja; pesanan wajib dikonfirmasi kasir sebelum masuk dapur (atau otomatis bila owner menyalakan konfirmasi otomatis). Pembayaran di kasir, atau lewat QRIS online bila diaktifkan. |
| Pembayaran | Tunai, QRIS, transfer bank sebagai pencatatan oleh kasir. QRIS dinamis dari HP pelanggan tersedia sebagai fitur opsional lewat payment gateway (Midtrans atau mode simulasi), default mati. |
| Pajak & biaya | Semua tarif (PB1, service charge, PPN) diatur dari pengaturan dan default nonaktif. Mode harga termasuk pajak atau belum bisa dipilih. |
| Akuntansi | Double-entry ringan: chart of accounts, jurnal otomatis dari penjualan, pengeluaran, shift, dan (opsional) kejadian stok; laba rugi, neraca sederhana. |
| Stok & HPP | Opsional, default mati. Satuan dasar hanya g, ml, pcs; kemasan beli (botol, karung) dikonversi otomatis. HPP memakai biaya rata-rata bergerak dan dibekukan per item order. |
| Hak akses | Permission per aksi, dikelola per peran dari UI. Lima peran bawaan, peran tambahan bisa dibuat. |
| Notifikasi | Lonceng dalam aplikasi per izin, plus teruskan peringatan penting ke Telegram, email, atau WhatsApp bila diaktifkan. |
| Pengaturan | Semua opsi bisa diubah di layar Pengaturan tanpa ubah kode; tiap perubahan tercatat. |
| Hutang pemasok | Tidak dibuat sekarang (ditunda atas keputusan owner). Belanja dianggap dibayar saat dicatat. |

**Asumsi yang perlu divalidasi:**

- Kafe punya koneksi internet stabil. Saat internet putus, POS tidak bisa dipakai karena berjalan di VPS; ada indikator "Offline" dan prosedur manual darurat.
- Mata uang tunggal Rupiah tanpa desimal; nominal disimpan sebagai `bigint`. Kuantitas bahan memakai desimal 3 angka.
- Zona waktu Asia/Jakarta. Hari bisnis dimulai pada jam batas yang bisa diatur (default 04.00).
- Printer thermal ESC/POS 58 mm dan 80 mm lewat QZ Tray, Bluetooth, atau RawBT; stasiun cetak bisa ditambah dan tiket/struk dirutekan per kategori atau menu.

## 3. Persona & Hak Akses

Lima peran bawaan plus customer tamu. Hak akses berupa permission per aksi yang dikelompokkan di layar Peran & Akses; owner selalu punya semua izin.

| Persona | Perangkat utama | Kebutuhan inti |
| --- | --- | --- |
| Owner | HP, laptop | Laporan penjualan, keuangan, dan stok dari mana saja; mengatur menu, harga, pajak, user, peran, dan pengaturan; menerima laporan harian. |
| Manager / Supervisor | Tablet, laptop | Menyetujui belanja dan stok opname, void dan diskon, buka/tutup shift, input pengeluaran, cek laporan harian dan margin. |
| Kasir | Tablet 10", HP | Input order cepat, terima order QR, terima pembayaran, cetak struk, buka/tutup shift sendiri. |
| Waiter | HP | Input order dari meja, lihat status meja, antar pesanan yang siap. |
| Dapur / Bar | Tablet di dapur | Antrean pesanan per stasiun, tandai sedang dibuat dan siap. |
| Karyawan belanja / gudang | HP | Mencatat belanja bahan, menghitung stok opname, mencatat bahan terbuang. Izin diberikan lewat peran tambahan. |
| Customer (tamu) | HP pribadi | Scan QR meja, lihat menu, pesan tanpa login, bayar (opsional QRIS online). |

**Daftar permission (dikelompokkan seperti di layar Peran & Akses):**

| Kelompok | Permission |
| --- | --- |
| Pesanan & pembayaran | Buat / ubah order; Terima pembayaran; Diskon sampai batas kasir; Diskon manual (isi sendiri); Diskon di atas batas; Void & komplimen; Refund; Batalkan pesanan/item (belum dibayar); Pakai Stasiun Cetak; Update status dapur |
| Shift & kas | Buka/tutup shift sendiri; Kelola shift orang lain; Lihat rekap shift sendiri; Input pengeluaran |
| Laporan & akuntansi | Laporan penjualan & dashboard; Laporan kinerja dapur; Laporan margin & HPP; Jejak aktivitas; Lihat laporan akuntansi; Kelola akuntansi; Laporan waste |
| Pengaturan & stok | Kelola menu; Kelola stok, bahan & resep; Kelola printer; Lihat daftar stok; Stok opname; Catat belanja bahan; Lihat dan kelola aset & alat; Impor dari Excel; Kelola data pemasok; Catat bahan/menu terbuang; Pembayaran QRIS online; Kelola daftar diskon; Kelola meja & QR; Harga & pajak; Pengaturan outlet; Kelola user; Kelola peran & akses |

**Default peran bawaan (ringkas):**

| Kemampuan | Owner | Manager | Kasir | Waiter | Dapur |
| --- | --- | --- | --- | --- | --- |
| Buat / ubah order | Ya | Ya | Ya | Ya | Tidak |
| Terima pembayaran | Ya | Ya | Ya | Tidak | Tidak |
| Diskon > batas kasir / Void | Ya | Ya | PIN manager | Tidak | Tidak |
| Refund | Ya | Ya | Tidak | Tidak | Tidak |
| Buka / tutup shift | Ya | Ya (termasuk shift orang lain) | Shift sendiri | Tidak | Tidak |
| Update status dapur | Ya | Ya | Ya | Tidak | Ya |
| Pengeluaran, laporan, akuntansi | Ya | Ya (akuntansi baca) | Rekap shift sendiri | Tidak | Tidak |
| Stok, resep, pemasok, waste, impor | Ya | Ya | Sesuai izin tambahan | Tidak | Tidak |
| Pengaturan, user, peran | Ya | Menu saja | Tidak | Tidak | Tidak |

"PIN manager" berarti kasir bisa melakukan aksi itu setelah manager memasukkan PIN 6 digit di perangkat kasir; nama penyetuju tercatat di audit log. Peran bisa diberi opsi **login PIN** (staf masuk dengan PIN 6 angka); owner dan manager masuk dengan email + password (+ passkey/2FA).

## 4. User Stories & Acceptance Criteria

Modul 4.1–4.15 adalah inti POS; 4.16–4.27 ditambahkan setelah MVP. Setiap kriteria harus lolos test otomatis atau uji manual tertulis.

### 4.1 Menu, Kategori & Modifier

**Story:** Sebagai owner, saya ingin mengatur menu beserta variasinya agar kasir dan customer melihat pilihan yang sama.

- Produk punya: nama, kategori, harga dasar, foto (opsional), deskripsi, SKU/kode, stasiun dapur, status tersedia/habis, urutan tampil.
- Varian (Hot/Iced, Regular/Large) mengganti harga dasar; modifier group (Sugar Level, Extra Shot) menambah harga per opsi.
- Modifier group punya aturan min/maks pilihan dan flag wajib/opsional; server menolak order yang melanggar.
- Tombol "Habis" langsung menyembunyikan produk dari menu QR dan menandainya di layar kasir ≤ 3 detik.
- Perubahan harga tidak mengubah order lama: setiap item order menyimpan snapshot nama, harga, dan modifier.
- Menu bisa diisi dan diperbarui lewat impor Excel (lihat 4.21).

### 4.2 Area & Meja

**Story:** Sebagai waiter, saya ingin melihat status semua meja agar tahu mana yang kosong, terisi, atau menunggu bayar.

- Meja dikelompokkan per area (Indoor, Outdoor, dst.) dengan kapasitas kursi.
- Status meja: Kosong, Terisi, Menunggu Bayar; berubah otomatis mengikuti order.
- Setiap meja punya QR unik berisi token acak; owner bisa membuat ulang token jika QR bocor, dan menonaktifkan QR per meja.
- Pindah meja, gabung meja, dan pindah item antar meja tersedia untuk kasir/manager, tercatat di audit log.
- Halaman cetak QR menghasilkan PDF siap cetak, satu QR per meja.

### 4.3 POS Kasir & Input Order

**Story:** Sebagai kasir atau waiter, saya ingin memasukkan pesanan dengan sedikit ketukan agar antrean tidak menumpuk.

- Tipe order: Dine-in (pilih meja), Takeaway, Dine-in bayar dulu, dan Online/Ojol (label platform, tanpa integrasi).
- Layar POS: tab Meja & order dan Menu, kategori, pencarian nama/kode, keranjang dengan qty, catatan per item, dan dialog varian/modifier.
- Di HP, keranjang dan dialog muncul sebagai bottom sheet; tombol total melayang menunjukkan jumlah item dan nilai.
- Satu meja bisa punya beberapa ronde pesanan dalam satu order; tiap ronde jadi tiket dapur terpisah.
- Order disimpan (open bill) dan dilanjutkan dari perangkat lain.
- Total, pajak, service charge, dan diskon selalu dihitung ulang di server.
- Nomor order unik per hari `ORD-YYMMDD-NNNN`; takeaway mendapat nomor panggil harian (A-012).
- Setiap order, ronde, item, dan pembayaran mencatat siapa yang membuat dan menerima.

### 4.4 Self-Order via QR Meja

**Story:** Sebagai customer, saya ingin scan QR di meja dan memesan sendiri tanpa menunggu waiter.

- Scan QR membuka halaman menu tanpa login dan tanpa instal aplikasi, meja sudah terpilih.
- Halaman menu punya kolom cari (bila menu > 8 item), chip kategori yang menempel di atas, dan daftar menu dengan tombol tambah/pilih.
- Customer memasukkan nama panggilan (wajib) sebelum kirim pesanan.
- Pesanan masuk "Menunggu Konfirmasi", muncul di layar kasir ≤ 3 detik berlabel "QR" dengan pop-up dan bunyi. Kasir menekan **Konfirmasi** atau **Tolak** (alasan wajib, ditampilkan ke customer). Opsi konfirmasi otomatis tersedia di pengaturan.
- Pesanan yang belum dikonfirmasi > 3 menit (bisa diatur) memicu notifikasi ulang.
- Bila meja sudah punya order terbuka, pesanan QR menjadi ronde baru di order yang sama.
- Customer melihat status (Menunggu Konfirmasi, Diterima, Dibuat, Siap, Ditolak) dan total sementara.
- Pembayaran: "Pembayaran di kasir", atau tombol **Bayar dengan QRIS** bila QRIS online aktif (lihat 4.25).
- Anti-spam: maksimal 5 pengiriman per meja per 10 menit.

### 4.5 Dapur / Kitchen Display (KDS)

**Story:** Sebagai staf dapur, saya ingin melihat antrean pesanan untuk stasiun saya agar urutan pembuatan jelas.

- Layar KDS per stasiun menampilkan tiket dengan nomor meja/tipe order, item, modifier, catatan, dan umur tiket.
- Status per item: Baru → Dibuat → Siap; staf mengetuk untuk memajukan.
- Tiket berubah warna setelah ambang waktu (default 10 dan 20 menit, bisa diatur); bunyi untuk tiket baru.
- Cetak tiket ke printer dapur sebagai tambahan atau pengganti layar.
- Item yang di-void setelah dikirim muncul dengan coretan dan label VOID.
- Laporan Kinerja Dapur: waktu penyajian per stasiun dan per menu.

### 4.6 Pembayaran

**Story:** Sebagai kasir, saya ingin mencatat pembayaran beserta akun penerimanya agar owner tahu uang masuk ke mana.

- Metode pembayaran adalah data, terhubung ke satu akun di chart of accounts; ditambah dari pengaturan.
- Tunai: nominal diterima, kembalian dihitung, tombol cepat (uang pas, 50rb, 100rb, 150rb, 200rb).
- QRIS dan transfer manual: kasir mengisi nomor referensi (opsional); tidak ada verifikasi otomatis.
- Pre-bill (cetak tagihan sebelum bayar) dan pembayaran gabungan beberapa metode.
- Order Lunas hanya jika total pembayaran ≥ grand total; setelah lunas order terkunci.
- Setiap pembayaran lunas membuat jurnal dalam transaksi database yang sama.
- Pembayaran ditolak bila tidak ada shift terbuka; manager/owner boleh memakai shift kasir yang sedang terbuka (pengaturan "shift bersama").

### 4.6a Split Bill

**Story:** Sebagai kasir, saya ingin memecah tagihan meja agar tiap tamu membayar bagiannya.

- Tiga mode: **Per item** (qty bisa dipecah), **Bagi rata**, **Nominal bebas**; plus **Bayar sebagian dulu** (pilih item dan jumlah yang dibayar sekarang, sisanya nanti).
- Diskon, service charge, dan pajak dibagi proporsional; pembulatan per tagihan.
- Tiap tagihan punya label, status, metode bayar sendiri; struk per tagihan.
- Order Lunas setelah semua tagihan lunas; status antara "Sebagian Dibayar".
- Pemecahan bisa diubah selama belum ada tagihan lunas; item baru setelah split masuk "Belum dialokasikan".
- Jurnal dibuat per tagihan lunas; laporan penjualan tetap per order induk.

### 4.7 Shift Kasir & Rekap Kas

**Story:** Sebagai manager, saya ingin setiap shift ditutup dengan hitungan kas fisik agar selisih langsung ketahuan.

- Pembayaran butuh shift terbuka. Buka shift: modal awal terisi dari uang yang ditinggal shift sebelumnya, dikonfirmasi atau dikoreksi.
- **Satu shift per toko**, dipakai semua staf: hanya ada satu shift terbuka di outlet pada satu waktu, siapa pun yang membukanya (owner, manager, atau kasir). Semua pembayaran dan kas masuk/keluar staf lain masuk ke shift itu; shift mencatat siapa yang membuka dan menutup, tiap pembayaran mencatat siapa yang menerima, dan tiap kas masuk/keluar mencatat siapa yang mencatat. Semua peran staf bisa melihat shift berjalan dan riwayat shift toko (hanya baca); yang punya izin shift bisa membuka, mencatat kas, dan menutup. Selisih kas dihitung untuk seluruh laci, bukan per orang. Waiter dan dapur tidak diminta membuka shift.
- Shift dibuka dari dalam layar kasir: kasir yang masuk tanpa shift terbuka diminta lewat dialog (sekali per sesi), dan banner di kasir membuka dialog yang sama. Menu Shift tidak ada di sidebar kasir; kas masuk/keluar dan tutup shift dibuka dari tombol shift di layar kasir. Manager dan owner punya menu "Shift & Kas" untuk melihat dan menutup shift orang lain.
- Selama shift: kas masuk/keluar non-penjualan dengan alasan.
- Tutup shift: uang fisik per pecahan; sistem menampilkan kas seharusnya, kas fisik, selisih; uang yang ditinggal dan yang disetor dicatat.
- Selisih ≠ 0 wajib alasan dan dijurnal ke Selisih Kas; manager dinotifikasi.
- Rekap shift bisa dicetak dan (opsional) dikirim ke Telegram/email/WhatsApp.
- Shift yang lupa ditutup memicu notifikasi ke manager.

### 4.8 Diskon, Void & Refund

**Story:** Sebagai owner, saya ingin setiap pengurangan nilai tercatat siapa pelaku dan penyetujunya.

- **Daftar diskon bernama:** owner mendaftarkan diskon seperti "Staff 10%" atau "Owner 20%" di Pengaturan › Promo (nominal atau persen, opsi minta PIN manager, bisa dinonaktifkan). Kasir cukup mengetuk satu diskon tanpa mengisi nilai atau alasan; nama diskon menjadi alasannya dan tercatat di audit log.
- **Diskon manual** (kasir mengetik nilai sendiri) dikendalikan izin tersendiri "Diskon manual". Secara default hanya owner dan manager yang punya izin ini; kasir dan waiter hanya bisa memilih diskon terdaftar (waiter tidak memberi diskon sama sekali). Owner bisa memberikan izin ini ke peran lain kapan saja di Peran & Akses.
- Diskon manual per item atau per order (nominal/persen), alasan wajib; batas diskon kasir tanpa PIN diatur (default 10%).
- Promo terjadwal (Happy Hour) di halaman Diskon & Promo: jam mulai-selesai (boleh melewati tengah malam), hari tertentu, rentang tanggal, dan cakupan seluruh order, kategori, atau menu tertentu. Promo bertanda "boleh digabung" dijumlahkan (total diskon satu item tidak melebihi harganya); promo yang tidak digabung berdiri sendiri dan menggantikan promo lain selama berlaku. Promo yang dihapus kasir dari order tidak dipasang lagi pada ronde berikutnya.
- Void sebelum bayar: butuh PIN manager bila diatur, alasan wajib.
- Komplimen dan staff meal: nilai tidak masuk omzet, tercatat di laporan.
- Refund setelah bayar: manager/owner, membuat jurnal pembalik.
- Laporan Diskon/Pembatalan/Refund menampilkan pelaku dan penyetuju. Tidak ada hard delete.

### 4.9 Pajak, Service Charge & Pembulatan

- Komponen biaya punya nama, tarif, aktif/nonaktif, urutan, dan basis; default semua nonaktif.
- Mode harga inclusive atau exclusive (berlaku untuk order baru).
- Pembulatan: tanpa, Rp100, atau Rp500; selisih di akun tersendiri.
- Rincian per order disimpan sebagai snapshot.

### 4.10 Laporan Penjualan & Dashboard

**Story:** Sebagai owner, saya ingin melihat performa dari HP agar bisa ambil keputusan menu dan jadwal staf.

- Dashboard: omzet hari ini dengan tren vs minggu lalu, jumlah transaksi, rata-rata transaksi, grafik omzet 14 hari, penjualan per jam, produk terlaris.
- Laporan dengan filter tanggal dan pengelompokan: per hari, jam, produk, kategori, metode bayar, kasir, tipe order.
- Laporan Margin & HPP (4.16), Waste (4.19), Kinerja Dapur, Diskon/Void/Refund.
- Ekspor ke Excel dan PDF. Laporan 30 hari tampil ≤ 2 detik untuk 50.000 order.

### 4.11 Akuntansi (Double-Entry Ringan)

- Chart of accounts bawaan (bisa diedit) termasuk Persediaan Bahan, Kas Belanja Bahan, HPP, Beban Bahan Terbuang, Selisih Stok.
- Jurnal otomatis dari pembayaran lunas, refund, selisih shift, kas masuk/keluar, pengeluaran, dan (opsional) kejadian stok.
- Jurnal manual oleh owner dengan validasi debit = kredit; saldo awal sebagai jurnal pembuka.
- Jurnal terposting tidak bisa diedit; koreksi lewat jurnal pembalik.
- Laporan: Buku Besar, Jurnal Umum, Laba Rugi, Neraca sederhana, Arus Kas. Tutup buku bulanan mengunci periode.

### 4.12 Pengeluaran Operasional

- Input: tanggal, kategori beban, nominal, sumber dana (kas laci atau bank), catatan, foto nota.
- Jurnal otomatis: Debit Beban, Kredit akun sumber. Kategori dikelola owner/manager.
- **Dipakai untuk belanja non-stok** (tissue, sabun, plastik, listrik, gaji). Belanja bahan stok dicatat lewat Belanja Bahan (4.17), bukan di sini.

### 4.13 Struk & Printer

- Struk berisi outlet, nomor order, tanggal-jam, kasir, meja, item+modifier, rincian pajak/biaya, metode bayar, kembalian, catatan kaki.
- Printer thermal 58/80 mm lewat QZ Tray, Bluetooth, atau RawBT; fallback cetak browser. Routing tiket/struk per stasiun cetak, kategori, dan menu; **Stasiun Cetak** adalah layar antrean cetak untuk perangkat tanpa printer langsung.
- Setelah pembayaran, struk dicetak otomatis dengan urutan: antrean printer di server bila struk dirutekan ke sebuah printer; cetak langsung dari perangkat bila printer perangkat sudah diatur dan "Cetak langsung" aktif; selain itu jendela cetak browser. Tiap perangkat punya opsi "Cetak struk otomatis setelah bayar" yang bisa dimatikan untuk perangkat tanpa printer; struk tetap tampil di layar dengan tombol Cetak.
- Cetak ulang tercatat berlabel "COPY". Struk digital: tautan publik bertoken. Tombol WhatsApp di layar struk (opsi di Pengaturan › Kasir & struk, default mati) hanya membuka WhatsApp di perangkat kasir dengan tautan struk terisi; tidak memakai gateway dan terpisah dari pengiriman laporan ke owner.

### 4.13a Akun, Keluar & Riwayat Transaksi

- Layar Kasir, Dapur, dan Shift tidak punya sidebar, jadi memakai **menu akun** (tombol bulat berisi inisial) di pojok kanan atas: nama dan email, **Riwayat transaksi** (hanya di Kasir), dan **Keluar**. Keluar tidak menutup shift.
- **Transaksi shift ini:** ringkasan penjualan lunas dan jumlah transaksi, pesanan yang belum selesai, dan yang dibatalkan; daftar bill dengan filter status, siapa yang membuka dan siapa yang menerima pembayaran; tombol Buka pesanan (yang masih terbuka) dan Cetak ulang struk (berlabel COPY). Shift adalah satu kesatuan toko, jadi **semua peran staf melihat semua transaksi shift** (tanpa shift terbuka: transaksi hari ini). Hari-hari sebelumnya lewat laporan. Halaman Shift juga memuat daftar transaksi shift berjalan beserta penerimanya.

### 4.14 Pengaturan, User & Peran

- Profil outlet, jam operasional; metode bayar, pajak, promo.
- Layar Pengaturan hanya berisi opsi sistem, terbagi tab: Operasional (pembulatan, shift bersama, batas diskon, **warna aksen aplikasi**), Pesanan QR, Stok & HPP, Dapur (KDS), Kasir & struk, Keamanan, QRIS Online, Notifikasi.
- Yang sering diubah punya halaman sendiri di menu, dengan izin masing-masing: **Diskon & Promo** (izin "Kelola diskon & promo") dan **Pembayaran & Pajak** (metode bayar, pajak, service charge; izin "Pembayaran & pajak").
- Manajemen user: buat, nonaktifkan, reset password, set PIN, set peran.
- **Peran & Akses:** owner membuat peran baru dan mencentang izin per kelompok; peran bawaan bisa diubah (owner tidak). Opsi login PIN per peran.
- Semua perubahan pengaturan tercatat di audit log.

### 4.15 Fitur Operasional Tambahan

- **Komplimen & staff meal**, **nomor panggil**, **stok porsi** (produk "Habis" saat 0), **pindah item antar meja**.
- **Tidak ada "tutup hari":** yang ada hanya buka dan tutup shift. Laporan harian dikirim begitu shift terakhir ditutup (lihat Laporan harian lewat email); halaman Laporan Harian (Keuangan) menampilkan ringkasan tanggal mana pun secara langsung dan riwayat tanggal yang ada penjualan, tanpa langkah penguncian. Shift yang masih terbuka lebih dari 12 jam memicu notifikasi ke manager (perintah `shifts:remind-open`, tiap 30 menit).
- **Tanggal penjualan tanpa jam operasional:** tidak ada pengaturan jam mulai hari. Selama ada shift terbuka, penjualan masuk ke tanggal shift itu dibuka (shift yang lewat tengah malam tetap satu hari); bila tidak ada shift terbuka, tanggalnya hari ini. Shift yang masih terbuka lebih dari 12 jam dianggap lupa ditutup (notifikasi ke manager).
- **Tutup shift dengan konfirmasi:** uang tunai di laci wajib dihitung dan diisi (server menolak bila kosong); selisih wajib beralasan; menekan Tutup shift membuka dialog ringkasan (kas seharusnya, dihitung, selisih, ditinggal, disetor) dengan tombol Kembali / Ya, tutup shift. Setelah ditutup, transaksi baru memerlukan shift baru. Shift tidak bisa ditutup selama masih ada pesanan toko yang belum lunas (terbuka atau dibayar sebagian, termasuk pesanan QR yang belum dikonfirmasi): halaman Shift menampilkan daftar pesanannya dengan sisa tagihan dan tombol Buka Kasir, tombol Tutup shift dinonaktifkan, dan server menolak dengan pesan yang menyebut pesanannya. Pesanan yang belum ada pembayaran bisa dibatalkan; yang sudah dibayar sebagian harus dilunasi.
- **Pembalikan shift (buka kembali):** izin `reopen-shift` (default owner, manager, kasir). Kartu "Salah tutup shift?" di halaman Shift membuka kembali shift terakhir dengan dialog konfirmasi dan alasan opsional: jurnal selisih kas dan setoran saat tutup dibalik, angka penutupan dihapus, shift terbuka lagi untuk semua staf, manager diberi tahu, dicatat di jejak aktivitas. Tanpa izin Kelola shift orang lain, hanya untuk shift yang ditutup sendiri dalam 30 menit. Tidak bisa bila sudah ada shift sesudahnya. Laporan harian yang sudah terkirim dikirim ulang sebagai "(diperbarui)" bila angkanya berubah setelah ditutup lagi.
- **Laporan Harian** bisa dilihat untuk tanggal mana pun, diunduh (Excel/PDF), dan dikirim ulang ke Telegram, email, atau WhatsApp.
- **Bunyi notifikasi** di perangkat kasir dengan pengaturan volume dan mute; butuh satu ketukan untuk mengaktifkan audio.

### 4.16 Stok Bahan, Resep & HPP

**Story:** Sebagai owner, saya ingin tahu stok bahan dan keuntungan per menu tanpa menghitung manual.

- Opsional (Pengaturan › Stok & HPP). Nonaktif: menu stok disembunyikan dan penjualan tidak menyentuh stok.
- **Bahan** punya nama, satuan dasar (hanya `g`, `ml`, `pcs`), batas menipis, stok, biaya rata-rata. Satuan dasar tidak bisa diubah bila sudah punya riwayat.
- **Kemasan beli** per bahan (misal botol = 750 ml, karung = 25.000 g): saat belanja/opname orang memilih kemasan dan sistem mengonversi ke satuan dasar; konversi ditampilkan di bawah jumlah.
- **Resep** per menu (dan per varian), plus bahan tambahan per modifier. Jumlah memakai satuan dasar.
- **Konsumsi otomatis:** saat order dikonfirmasi, stok berkurang sesuai resep; saat item di-void, stok kembali. Opsi menolak pesanan jika bahan tidak cukup (default mati; stok bisa minus sebagai tanda perlu opname).
- **HPP dibekukan** per item order (`cogs`); kenaikan harga bahan berikutnya tidak mengubah penjualan lama. Biaya rata-rata bergerak dihitung saat belanja disetujui.
- **Peringatan kenaikan harga:** manager diberi tahu bila harga beli naik lebih dari persen tertentu (default 15%) dari biaya rata-rata.
- Layar: **Stok & Bahan** (tab Bahan, Pembelian, Opname staf, Riwayat stok), **Resep & HPP** (daftar menu, biaya, margin), **Daftar Stok** (untuk staf, tanpa harga).
- Laporan **Margin & HPP** per produk, kategori, dan hari dengan ekspor Excel/PDF.

### 4.17 Belanja Bahan & Pemasok

**Story:** Sebagai karyawan yang berbelanja, saya ingin mencatat bahan yang dibeli dengan cepat; sebagai manager, saya ingin menyetujuinya.

- Karyawan dengan izin catat belanja membuka **Belanja Bahan**: tanggal, pemasok (pilihan dari data pemasok), baris bahan (jumlah, satuan, total harga), kirim.
- Pilihan bahan bisa dicari dengan mengetik; hanya bahan terdaftar yang bisa dipilih (bahan baru didaftarkan manager atau lewat impor Excel).
- Persetujuan: belanja staf menunggu manager (default aktif); stok dan biaya rata-rata berubah setelah disetujui. Manager dengan izin kelola stok langsung masuk. Penolakan wajib alasan, terlihat oleh pencatat. Tidak boleh menyetujui belanja sendiri kecuali owner.
- **Data pemasok:** nama, telepon/WA, alamat, catatan, aktif/nonaktif; riwayat belanja per pemasok, total 30 hari, jumlah bahan; tombol chat WhatsApp.
- **Perbandingan harga** antar pemasok per bahan (termurah di atas) dan peringatan kenaikan harga.
- Hutang pemasok tidak termasuk (ditunda).

### 4.18 Stok Opname

- Karyawan dengan izin menghitung stok mengisi jumlah nyata per bahan (boleh dalam kemasan). Secara default stok sistem disembunyikan ("hitung buta") agar jujur.
- Alur: draft → kirim → manager menyetujui atau menolak (alasan). Pengirim tidak boleh menyetujui sendiri kecuali owner; persetujuan otomatis bisa diaktifkan.
- Setelah disetujui, stok disamakan dengan hitungan dan selisih dicatat sebagai pergerakan stok; manager dinotifikasi saat ada hitungan menunggu.
- Mendukung impor Excel.

### 4.19 Bahan & Menu Terbuang (Waste)

- Layar **Bahan Terbuang**: tab Bahan baku (bahan, jumlah, satuan, alasan, catatan) dan Menu jadi (menu, varian, porsi; bahan di resep ikut berkurang).
- Alasan dari daftar yang dikelola manager (Basi/busuk, Kadaluarsa, Tumpah, Rusak/pecah, Salah buat, Lainnya).
- Nilai rupiah dibekukan pada biaya rata-rata saat itu; pelaku tercatat; manager dinotifikasi bila pencatat bukan pengelola stok.
- **Laporan Waste:** nilai total, item dan alasan terbanyak, pengelompokan per item/alasan/pencatat/hari, rincian kejadian, ekspor Excel/PDF.
- **Bunyi berulang sampai ditangani:** pengaturan Pesanan QR › "Bunyi berulang sampai ditangani" (default aktif, bisa dimatikan). Aktif: pesanan QR yang belum dikonfirmasi, panggilan meja yang belum ditandai Selesai, dan tiket dapur yang belum dimulai berbunyi lagi tanpa batas dengan jeda yang diatur di "Jeda bunyi berulang (detik)" (default 5, 2 sampai 60). Mati: bunyi berulang hanya beberapa kali. Tombol speaker di layar tetap bisa membisukan.
- **Mode terang dan gelap:** semua layar (QR pelanggan, kasir, dapur, shift, admin, laporan) harus terbaca di kedua mode. Warna status (hijau, kuning, merah, biru) selalu punya varian gelap sendiri; monogram menu tanpa foto, kartu status, dan lencana ikut tema; struk dan kode QR tetap putih agar terbaca dan terpindai. Diperiksa dengan audit kontras otomatis (rasio teks terhadap latar minimal 3:1) di lebih dari 30 halaman pada mode terang dan gelap.
- **Ikon dan banyak kategori di QR:** halaman QR memakai ikon garis SVG kecil yang seragam (bukan emoji) untuk lonceng, jam, info, cari, tutup, dan centang. Bila kategori lebih dari 4, muncul tombol daftar di sebelah chip untuk membuka sheet "Kategori" (nama dan jumlah menu, ketuk untuk melompat); chip kategori yang aktif otomatis digeser ke tengah bilah saat halaman digulir, dan pilihan dari daftar tidak direbut oleh penyorotan saat menggulir. Pencarian menu tampil bila menu lebih dari 6.
- **Info kafe dan banner di halaman QR:** Pengaturan › Outlet › "Tampil di halaman QR pelanggan" (semua opsional): banner (gambar lebar, otomatis dikecilkan ke WebP maks 1400 px dan 220 KB, bisa diganti atau dihapus), slogan, jam buka, telepon/WhatsApp, Instagram, nama dan password WiFi; logo outlet ikut tampil di samping nama meja. Di HP pelanggan: banner membulat di atas, slogan, chip jam buka, dan tombol Info kafe yang membuka sheet berisi jam, alamat dengan tautan Maps, telepon (tautan panggil), Instagram, dan WiFi dengan tombol Salin password. Bagian yang kosong tidak tampil. Deskripsi menu tampil dua baris di daftar dan utuh di sheet pilihan.
- **Harga per menu di status pesanan QR:** tiap baris item pada Status pesanan menampilkan jumlah, nama, pilihan, catatan pelanggan (bila ada), dan harga baris (item batal dicoret), selain Total sementara.
- **Tampilan QR pelanggan (mobile-first):** halaman meja didesain ulang untuk HP: kepala halaman dengan nama outlet dan nomor meja besar, tombol Panggil waiter, bilah sticky berisi pencarian bulat dan chip kategori yang ikut menyorot saat digulir; daftar menu per kategori dengan nama, deskripsi, harga, dan foto/monogram membulat 104 px dengan tombol + / Pilih menempel di sudut foto (stepper − n + setelah ditambah); menu habis diberi label Habis dan pudar. Bilah bawah mengambang: Lihat status pesanan (titik warna sesuai keadaan) dan Lihat pesanan (jumlah item dan total). Sheet naik dari bawah dengan animasi halus (mengikuti prefers-reduced-motion), tombol aksi menempel di bawah, target sentuh minimal 40 px, input 16 px agar iOS tidak memperbesar halaman; picker varian/pilihan berupa kartu pilih dengan harga tambahan; keranjang berisi stepper dan catatan per item, nama panggilan, dan Kirim Pesanan; status pesanan punya penunjuk progres dan jam kirim: Dikirim → Diterima → Dibuat → Siap bila layar dapur (KDS) aktif; bila KDS tidak dipakai, hanya Dikirim → Diterima (karena status per menu hanya diubah dari layar dapur), dan Diterima dihitung sebagai tahap akhir. Diuji tanpa scroll horizontal di 360 px.
- **Panggil waiter dari meja (QR):** pengaturan Pesanan QR › "Tombol Panggil waiter" (default mati). Pelanggan menekan tombol di halaman QR, memilih keperluan (Butuh bantuan, Minta bill, Lainnya); satu panggilan terbuka per meja, menekan lagi memanggil ulang paling cepat tiap 30 detik. Tujuan dipilih di Pengaturan: layar kasir dan waiter (default aktif) dan/atau layar dapur KDS (default mati), boleh keduanya. Staf melihat strip "Panggilan meja" di atas layar (meja, keperluan, berapa lama, tombol Selesai) dan mendengar bunyi khusus tiap panggilan; panggilan yang belum dijawab berbunyi lagi tiap 45 detik (maks 6 kali); kartu meja di peta meja ditandai "Memanggil". Pelanggan melihat "Menunggu waiter…", lalu "✓ Sudah ditangani" selama beberapa detik, kemudian tombol kembali menjadi "Panggil waiter" untuk panggilan berikutnya. Izin `handle-calls` (default semua peran staf); dicatat di jejak aktivitas; dibatasi laju anti-spam.
- **Laporan harian lewat email:** saluran Email (Pengaturan › Notifikasi) mengirim laporan harian sebagai email berwarna dan rapi dengan lampiran PDF (juga dikirim sebagai file ke Telegram; WhatsApp tetap teks) (bukan teks polos; warna utama mengikuti warna aksen aplikasi, grafik memakai warna berbeda per bagian, jam teramai disorot): penjualan bersih dengan pembanding minggu lalu, jumlah order, rata-rata per order, diskon, refund, grafik batang penjualan per jam (dari jam pertama sampai terakhir ada penjualan; jumlah order di bawah tiap jam, dihitung pada jam order selesai dibayar) beserta tabel jam/order/omzet, bagian Pengeluaran kas (kas keluar dari laci selama shift dan pengeluaran yang dicatat untuk tanggal itu, dengan sumber, pencatat, dan total), grafik "7 hari terakhir" (hari laporan selalu baris terakhir, hari tanpa penjualan bernilai 0), rincian per metode bayar, per tipe order, "Order dicatat oleh" dan "Pembayaran diterima oleh" (bila hari itu hanya satu orang yang melayani, diganti satu baris "Dilayani oleh …"), 5 menu terlaris dengan batang, void, komplimen, selisih kas, dan tombol ke laporan lengkap; teks polos (termasuk rincian per jam, juga dipakai Telegram dan WhatsApp) tetap disertakan sebagai cadangan. Pengiriman memakai Resend (MAIL_MAILER=resend, RESEND_API_KEY, MAIL_FROM_ADDRESS dengan domain terverifikasi) atau SMTP. Dikirim otomatis begitu shift terakhir ditutup; kalau shift dibuka kembali, ada penjualan baru, lalu ditutup lagi, laporan "(diperbarui)" dikirim lagi; angka yang sama tidak dikirim dua kali dan lewat tombol Kirim sekarang di halaman Laporan Harian.
- **Standar laporan (layar, Excel, PDF):** Excel punya blok judul (nama laporan, outlet, periode, waktu cetak dan pencetak), lembar Ringkasan selalu di depan, angka asli dengan pemisah ribuan (rupiah tanpa desimal, angka berdesimal satu desimal), filter di header, header beku dan berulang tiap halaman cetak, nomor halaman, baris Total tebal. PDF punya judul, outlet, periode, pencetak, nomor halaman, dan angka dirapikan. Laporan penjualan untuk dimensi hari/jam/kasir/tipe order merinci Subtotal menu, Diskon, Pajak & biaya, Pembulatan, Refund, Penjualan bersih, dan Rata-rata per order, dengan baris Total dan rincian "dari subtotal ke penjualan bersih". Tanggal dan tipe order tampil terbaca (Jum, 23 Okt 2026; Dine-in). Laporan Diskon/Pembatalan/Refund/Komplimen memuat kolom Waktu, jenis dan nilai diskon yang jelas, jumlah kejadian, dan total per jenis. Laporan Margin, Waste, Aset, dan Pengeluaran memuat baris Total.
- **Navigasi dari layar tanpa sidebar:** menu akun (bulatan inisial) di layar kasir, dapur, shift, dan Hari Ini memuat bagian "Halaman lain": tautan ke semua halaman yang boleh dibuka orang itu menurut izinnya (Daftar Stok, Stok Opname, Belanja Bahan, Bahan Terbuang, Stasiun Cetak, Menu, Laporan, dll.), memakai daftar menu yang sama dengan sidebar. Halaman-halaman itu memakai sidebar yang berisi tautan balik ke Kasir/Dapur.
- **Hari Ini (staf tanpa dashboard):** izin `view-today-summary` (default semua peran: owner, manager, kasir, waiter, dapur; bisa dicabut per peran). Dari menu akun di layar kasir, dapur, dan shift: "Ringkasan hari ini" membuka halaman `/today` tanpa sidebar berisi omzet bersih hari bisnis berjalan, jumlah transaksi, rata-rata, pembanding minggu lalu, penjualan per jam, dan menu terlaris, dengan tombol kembali ke Kasir/Dapur. Dashboard dan laporan lengkap tetap untuk izin `view-sales-reports`.
- **Menu: tersedia, habis, nonaktif, hapus:** Habis = sementara, tetap tampil abu-abu berlabel "Habis". Nonaktif = disembunyikan total dari kasir dan QR tanpa menghapus data, bisa diaktifkan lagi (izin Kelola menu, halaman Admin › Menu, tombol Nonaktifkan/Aktifkan menu, filter Semua/Aktif/Nonaktif, cari). Hapus = soft delete, order lama aman. Server menolak pesanan berisi menu nonaktif.
- **Stok & menu habis dari kasir:** izin `mark-sold-out` (default owner dan manager; bisa diberikan ke kasir/waiter di Peran & Akses). Tombol "Stok & Habis" di layar kasir (di HP lewat menu akun) membuka dialog: cari menu, tandai habis/aktifkan, ubah stok (−/+ atau ketik) untuk menu yang memakai stok, dan buka pilihan menu untuk menandai satu varian atau satu pilihan modifier (mis. Sambal C) habis tanpa mematikan menunya. Pilihan berlaku untuk semua menu yang memakai grup modifier yang sama. Di menu QR pelanggan menu habis tetap tampil, abu-abu berlabel "Habis" dan tidak bisa dipesan; varian/pilihan habis tampil nonaktif berlabel "Habis". Server menolak pesanan yang memuat menu/varian/pilihan habis. Tercatat di jejak aktivitas.
- **Struk Bulanan (PDF):** izin `export-receipts` (owner, manager). Pilih bulan dan tahun (termasuk bulan yang sudah lewat dan beda tahun), sistem membuat SATU PDF berisi struk semua transaksi lunas pada hari usaha di bulan itu. Tidak ada di sidebar: dibuka lewat tombol "Struk bulanan (PDF)" di halaman Laporan Penjualan. Dibuat di latar belakang per 250 struk lalu digabung, status Antre/Sedang dibuat/Siap/Gagal, diunduh sebagai `struk-YYYY-MM.pdf`, disimpan 30 hari lalu dihapus otomatis, permintaan ganda untuk periode yang sama ditolak, dicatat di jejak aktivitas.

### 4.20 Aset & Alat

- Daftar peralatan (nama, kategori, lokasi, kondisi, tanggal beli, nilai) dengan riwayat servis dan perbaikan.
- Peringatan ke manager untuk alat yang rusak atau perlu perhatian. Impor Excel tersedia.

### 4.21 Impor Excel

- Untuk: bahan, belanja, stok opname, resep, menu, aset, pemasok. Setiap jenis punya template yang sudah berisi data terkini dan lembar petunjuk.
- Alur: unggah → **pratinjau** per baris (baru, ubah, ok, galat dengan penjelasan) → terapkan. Galat tidak membatalkan baris lain; tidak ada yang tersimpan sebelum disetujui pengguna.
- Daftar pilihan (bahan, satuan, pemasok) divalidasi; bahan/pemasok baru boleh dibuat dari file bila satuan dasar valid. Butuh izin impor plus izin layar terkait.

### 4.22 Pusat Notifikasi

- Lonceng di header menampilkan notifikasi sesuai izin pengguna: belanja/opname menunggu persetujuan, stok menipis, kenaikan harga, selisih kas, shift lupa ditutup, alat rusak, refund, waste, pembayaran QRIS yang belum tercatat.
- Notifikasi bisa ditandai dibaca, hilang otomatis saat urusannya selesai, dan dibersihkan berkala agar ringan. Pembaruan lewat realtime dengan polling cadangan yang hemat.
- Peringatan penting (stok menipis, selisih kas, shift lupa, alat rusak, QRIS tertahan) dapat diteruskan ke Telegram/email/WhatsApp.

### 4.23 Jejak Aktivitas (Audit Log)

- Layar yang bisa dicari dan difilter: siapa, melakukan apa, kapan, pada apa (order, pembayaran, shift, stok, pengaturan, impor, login gagal).
- Semua aksi sensitif tercatat dengan pelaku, penyetuju (bila ada), dan nilai sebelum/sesudah.

### 4.24 Laporan Harian & Pengiriman Laporan

- Laporan Harian: omzet bersih, diskon, void/komplimen, selisih kas, rincian per metode bayar, daftar shift, riwayat harian dengan grafik.
- Dikirim otomatis (harian, dan opsional tiap shift ditutup) ke Telegram, email (SMTP), dan WhatsApp lewat gateway pihak ketiga; tombol "Kirim sekarang" untuk tanggal yang dipilih. Pengaturan saluran ada di tab Notifikasi.

### 4.25 Pembayaran QRIS Online (Opsional)

**Story:** Sebagai customer, saya ingin membayar dari HP setelah memesan, tanpa antre di kasir.

- Default mati (Pengaturan › QRIS Online). Gateway: Midtrans (QRIS dinamis, bisa dibayar dari BCA, GoPay, OVO, Dana, dll) atau mode Simulasi untuk uji coba.
- Setelah pesanan dikonfirmasi dan tagihan tidak dipecah, halaman QR menampilkan **Bayar dengan QRIS**; kode QR berlaku N menit (default 15) dan halaman otomatis berubah saat lunas.
- Gateway memanggil webhook yang diverifikasi tanda tangan; pembayaran tercatat otomatis sebagai pembayaran QRIS di shift terbuka atas nama sistem "Pembayaran Online". Webhook ganda aman (idempotent).
- Bila tidak ada shift terbuka, tagihan berubah, atau nominal berbeda, pembayaran **tertahan** dan manager mendapat peringatan; manager bisa **Catat ulang** dari layar **Pembayaran Online**, yang juga menampilkan daftar dan status semua transaksi serta cara mengaktifkan.
- Pembayaran manual oleh kasir tetap berjalan seperti biasa.

### 4.26 Peran & Akses

- Lihat 4.14. Setiap fitur baru wajib datang dengan izinnya sendiri dan migrasi yang memberikannya ke peran yang sudah ada.

### 4.27 Pengalaman Pakai di Semua Perangkat

- Tabel di layar admin punya pencarian, filter dropdown otomatis, dan pengurutan dari kepala kolom; di HP berubah menjadi daftar kartu dengan kontrol Urutkan.
- Pilihan dari daftar panjang (bahan, menu) memakai kolom ketik-untuk-cari.
- Dialog di HP muncul sebagai bottom sheet; target sentuh ≥ 44 px.
- Warna aksen aplikasi bisa dipilih owner (Biru, Hijau, Oranye, Ungu); warna status tidak ikut berubah.

## 5. Non-Goals (Belum Dibangun)

- Multi-outlet aktif (skema siap, UI dan laporan lintas outlet belum).
- Mode offline penuh (PWA dengan sinkronisasi); saat ini butuh internet.
- Hutang pemasok dan pembayaran bertahap ke pemasok.
- Program loyalty, member, poin, dan voucher pelanggan.
- Integrasi API GoFood, GrabFood, ShopeeFood.
- Verifikasi otomatis QRIS statis ke bank (QRIS online hanya lewat gateway).
- Aplikasi native Android/iOS; semua lewat browser.
- Payroll, absensi, penjadwalan staf.
- E-Faktur pajak dan pelaporan pajak otomatis.
- Pemesanan ke pemasok otomatis (purchase order) dan stok per gudang.

## 6. Non-Functional Requirements

### 6.1 Responsive & Mobile-First

Desain dimulai dari 360 px, lalu diperluas ke tablet dan desktop.

| Layar | Lebar | Target utama | Layout |
| --- | --- | --- | --- |
| HP | 360–767 px | Customer QR, waiter, karyawan belanja/opname, owner cek laporan | Satu kolom; keranjang dan dialog sebagai bottom sheet; header satu baris; tombol total melayang. |
| Tablet | 768–1279 px | Kasir, KDS | Dua kolom: meja/menu kiri (grid menyesuaikan lebar), panel pesanan kanan 340–380 px. |
| Desktop | ≥ 1280 px | Owner, manager, admin | Sidebar tetap, konten maks 1400 px, tabel penuh. |

- Target sentuh ≥ 44 × 44 px; tombol utama kasir ≥ 48 px tinggi.
- Tidak ada scroll horizontal di 360 px; tabel menjadi kartu di HP.
- Mode terang dan gelap; KDS default gelap.
- Diuji di Chrome Android, Safari iOS, Chrome desktop; minimal satu tablet 10" dan satu HP 6".

### 6.2 Performa

- QR menu: FCP ≤ 1,8 detik di Slow 4G; JS halaman QR ≤ 150 KB gzip.
- API simpan order dan bayar ≤ 300 ms (p95). Update realtime ≤ 3 detik.
- Foto produk WebP ≤ 800 px ≤ 120 KB. Polling notifikasi hemat: tidak berjalan di tab tersembunyi.

### 6.3 Ketersediaan & Keandalan

- Uptime 99,5% pada jam operasional. Backup DB tiap 6 jam ke luar VPS, simpan 30 hari; uji restore bulanan.
- Semua operasi uang (bayar, refund, tutup shift, pembayaran online) idempotent.
- Perintah penghapus database (`migrate:fresh`, `db:wipe`) ditolak di database yang bukan `*_testing`.

### 6.4 Aksesibilitas & Bahasa

- Lighthouse Accessibility ≥ 90; kontras ≥ 4,5:1; fokus terlihat; semua kontrol bisa dipakai dengan keyboard (termasuk kolom ketik-untuk-cari).
- Bahasa Indonesia lewat file terjemahan; format `Rp12.500`, tanggal `20 Okt 2026`; angka tabel memakai angka tabular.

## 7. Technical Specifications

Modular monolith: satu aplikasi Laravel dipecah per domain bisnis.

### 7.1 Stack

| Lapisan | Pilihan |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Frontend | React 19 + Inertia 2 + TypeScript, Vite |
| UI | Tailwind CSS 4 + shadcn/ui (Radix), font Inter, ikon lucide |
| Database | PostgreSQL 17 |
| Cache, queue, session | Redis + Laravel Horizon |
| Realtime | Laravel Reverb + Echo |
| Permission & audit | spatie/laravel-permission, spatie/laravel-activitylog |
| Ekspor & PDF | maatwebsite/excel, dompdf, bacon/bacon-qr-code |
| Cetak | QZ Tray, Bluetooth/RawBT, antrean Stasiun Cetak |
| Pembayaran online | Midtrans Core API (QRIS) melalui adapter gateway |
| Test | Pest (PostgreSQL asli), Playwright (HP 360, tablet 768, desktop 1280) |
| Kualitas | Pint, Larastan level 8, ESLint/oxlint, Prettier |

### 7.2 Struktur Kode

```
app/Domain/
  Catalog/ Floor/ Ordering/ Kitchen/ Payment/ Shift/ Accounting/ Expense/
  Pricing/ Reporting/ Settings/
  Inventory/    Ingredient, IngredientUnit, RecipeLine, StockMovement, Purchase,
                StockCount, Supplier, WasteEntry, InventoryService, WasteService,
                StockCountService, InventoryJournal
  Import/       Importer per jenis, ImportService, TemplateBuilder, SheetReader
  Notification/ Notifier, ReportDelivery, DeliverMessageJob, Alerts
  Printing/     PrintDispatcher, routing stasiun
  Asset/        Asset, ServiceLog
  Payment/Online/  QrisGateway, MidtransGateway, FakeGateway, OnlinePaymentService
```

**Aturan coding:** satu Action = satu use case; operasi uang di `DB::transaction()` dengan `lockForUpdate()`; controller tipis; status memakai Enum; jurnal dibuat sinkron dalam transaksi yang sama; semua uang integer; fitur baru = izin baru + migrasi pemberi izin + pengaturan opsional default mati.

### 7.3 Skema Database (ringkas)

Tabel inti (outlets, users, categories, products, variants, modifiers, areas, tables, orders, order_rounds, order_items, order_charges, discounts, payments, payment_methods, refunds, shifts, cash_movements, tax_rules, accounts, journal_entries, journal_lines, periods, expenses, settings, activity_log, bills, bill_items) sama seperti PRD MVP. Tabel tambahan:

| Tabel | Kolom penting | Catatan |
| --- | --- | --- |
| `ingredients` | name, unit (g/ml/pcs), stock, avg\_cost, min\_stock, preferred\_supplier\_id, is\_active | Biaya rata-rata bergerak. |
| `ingredient_units` | ingredient\_id, name, factor | Kemasan beli. |
| `recipe_lines`, `modifier_ingredients` | product/variant/modifier → ingredient, qty | Resep dan bahan tambahan. |
| `stock_movements` | ingredient\_id, type (purchase/sale/void\_return/waste/opname), qty, unit\_cost, order\_item\_id, purchase\_id, waste\_entry\_id, user\_id | Buku besar stok. |
| `order_items` (tambahan) | cogs, inventory\_consumed | HPP dibekukan. |
| `purchases`, `purchase_items` | supplier\_id, supplier, status (pending/approved/rejected), reviewed\_by, review\_note, total | Persetujuan belanja. |
| `stock_counts`, `stock_count_lines` | status (draft/submitted/approved/rejected), reviewer | Opname. |
| `suppliers` | name, phone, address, note, is\_active | Unik per outlet. |
| `waste_reasons`, `waste_entries` | kind (ingredient/product), qty, unit, reason, cost, user\_id | Waste. |
| `assets`, `asset_services` | kondisi, lokasi, servis | Aset & alat. |
| `app_notifications`, `notification_reads` | kind, title, body, url, level, permissions, dedupe\_key, resolved\_at | Notifikasi per izin. |
| `online_payments` | public\_id, order\_id, bill\_id, provider, provider\_ref, amount, status, qr\_string, expires\_at, paid\_at, payment\_id, applied\_at, error | Pembayaran QRIS online. |
| `printers`, routing | stasiun cetak dan aturan | Tiket/struk per stasiun. |
| kolom pelaku | `*_by` pada order, ronde, pembayaran, bill, shift | Jejak "siapa". |

Konsistensi jurnal: Action menolak entri tidak seimbang, dan constraint trigger PostgreSQL memastikan debit = kredit per entri saat commit.

### 7.4 Pemetaan Jurnal Otomatis

| Kejadian | Debit | Kredit |
| --- | --- | --- |
| Order lunas | Kas Laci / Bank per metode; Diskon Penjualan | Pendapatan Penjualan; Service Charge; Utang Pajak; Selisih Pembulatan |
| Refund | Kebalikan proporsional | Kas Laci / Bank |
| Kas keluar/masuk shift | Beban / Kas Laci | Kas Laci / akun sesuai alasan |
| Selisih kas | Selisih Kas / Kas Laci | Kas Laci / Selisih Kas |
| Pengeluaran | Beban sesuai kategori | Kas Laci atau Bank |
| Belanja bahan disetujui (opsional) | Persediaan Bahan | Kas Belanja Bahan |
| Penjualan dengan resep (opsional) | HPP | Persediaan Bahan (dibalik saat void) |
| Waste (opsional) | Beban Bahan Terbuang | Persediaan Bahan |
| Selisih opname (opsional) | Persediaan Bahan / Selisih Stok | Selisih Stok / Persediaan Bahan |

Jurnal stok aktif hanya bila Pengaturan › Stok & HPP › "Catat stok ke jurnal akuntansi" dinyalakan; berlaku sejak diaktifkan.

### 7.5 Integrasi, Realtime & Terjadwal

- Channel Reverb: pesanan kasir, KDS per stasiun, status meja, notifikasi outlet. Polling cadangan bila WebSocket putus; indikator "Offline".
- Webhook gateway: `POST /webhooks/qris/{provider}` tanpa sesi, dengan verifikasi tanda tangan gateway.
- Terjadwal: backup DB, pengingat shift terlalu lama terbuka (tiap 30 menit), pembersihan notifikasi (harian), penyapuan QRIS (tiap menit).
- Pengiriman laporan dan peringatan: Telegram bot, SMTP, gateway WhatsApp, lewat queue.

### 7.6 Siklus Status Order

Order dari POS dan QR masuk ke order yang sama. Status: Terbuka → Sebagian Dibayar → Lunas, atau Dibatalkan; item: Menunggu konfirmasi → Baru → Dibuat → Siap, atau VOID/Ditolak. Setelah lunas, order hanya bisa dikoreksi lewat refund dengan jurnal pembalik.

## 8. Security & Privacy

**Autentikasi & otorisasi**

- Email + password untuk owner/manager (passkey dan 2FA TOTP didukung; 2FA wajib untuk owner); staf dapat login PIN 6 digit bila perannya mengizinkan.
- PIN manager di-hash; 5 percobaan salah mengunci 15 menit (bisa diatur).
- Semua aksi dicek lewat Policy + permission di server; menyembunyikan tombol bukan pengamanan.

**Halaman publik (QR, struk, webhook)**

- Token meja acak 32 karakter, bisa dibuat ulang; rate limit per IP dan per meja; payload customer tidak dipercaya.
- Webhook pembayaran diverifikasi tanda tangan dan nominal; tidak bergantung pada login.

**Integritas data**

- Tidak ada hard delete untuk order, pembayaran, jurnal, shift, user.
- Idempotency key pada pembayaran; persetujuan dua orang untuk belanja dan opname (pengirim tidak menyetujui sendiri, kecuali owner).
- Audit log mencatat void, refund, diskon, cetak ulang, perubahan harga/pajak/pengaturan, shift, jurnal manual, persetujuan stok, impor, login gagal.
- Rahasia (token bot, kunci gateway) tidak pernah ditampilkan lagi setelah disimpan.

**Infrastruktur**

- HTTPS wajib; firewall hanya 22/80/443; SSH key; Fail2ban; PostgreSQL dan Redis hanya localhost; `.env` di luar web root; `APP_DEBUG=false`.
- Database dev dilindungi dari perintah penghapus; tes memakai database terpisah.

**Privasi**

- Data customer hanya nama panggilan. Nomor HP/email pelanggan tidak disimpan. Bila nanti disimpan, butuh persetujuan eksplisit sesuai UU 27/2022.

## 9. Deployment

- **Server:** satu VPS (Ubuntu 24.04, 2 vCPU/8 GB minimum) menjalankan Nginx, PHP-FPM, PostgreSQL, Redis, Horizon, Reverb (Supervisor). Cron `schedule:run` tiap menit.
- **Alur:** push ke `main` → CI (Pint, Larastan, lint, Pest) → build aset → deploy zero-downtime lewat Deployer → `migrate --force` → restart Horizon dan Reverb. Pengembangan fitur baru dilakukan di branch `dev` dan digabung ke `main` setelah tes lulus; setiap rilis dengan migrasi baru wajib menjalankan `php artisan migrate --force`.
- **Lingkungan:** local (Herd + DBngin), staging, production; database tes `pos_testing` terpisah (`.env.testing`).
- **Backup & monitoring:** `pg_dump` tiap 6 jam ke Cloudflare R2 (retensi 30 hari), snapshot VPS mingguan, UptimeRobot di `/up`, Sentry, Laravel Pulse.

## 10. Testing & Definition of Done

Modul uang (Pricing, Payment, Shift, Accounting, Inventory, Online) wajib coverage ≥ 90%; modul lain ≥ 70%.

| Level | Alat | Wajib menguji |
| --- | --- | --- |
| Unit | Pest | `PriceCalculator`, `Money`, transisi status, konversi satuan. |
| Feature | Pest + PostgreSQL asli | Setiap Action: order, ronde QR, bayar, split, void, refund, shift, jurnal, belanja dan persetujuan, opname, waste, impor, notifikasi, QRIS online (webhook, tanda tangan, shift tertahan). |
| Konkurensi | Pest | Dua kasir membayar order yang sama; idempotency key ganda. |
| End-to-end | Playwright | QR order → KDS → bayar → struk; bayar sebagian; di 360, 768, 1280 px. |
| Uji staf | Manual di staging | 1–2 minggu simulasi sebelum go-live. |

**Invariant akuntansi (setiap CI):** debit = kredit per entri; saldo Kas Laci per shift sesuai rumus; pendapatan Laba Rugi = penjualan lunas − pajak − refund; saldo Persediaan Bahan = nilai stok (bila jurnal stok aktif).

**Definition of Done per fitur:** kriteria bagian 4 terpenuhi dan ada test; lolos Pint, Larastan, lint; tampilan dicek di 360/768/1280 px; aksi sensitif masuk audit log; izin baru dibuat dan diberikan ke peran lama lewat migrasi; pengaturan opsional default mati; migrasi bisa di-rollback; diuji staf non-developer.

## 11. Risiko & Roadmap

### 11.1 Risiko Teknis

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Internet kafe putus | Kasir, KDS, QR berhenti. | Failover modem 4G; nota tulis darurat; mode offline di roadmap. |
| VPS down | Operasional berhenti. | Backup 6 jam, snapshot, runbook restore. |
| Selisih pajak/pembulatan | Laporan tidak cocok. | Satu `PriceCalculator`, unit test, snapshot tarif. |
| Pembayaran ganda | Omzet lebih. | Idempotency, `lockForUpdate`, tombol terkunci. |
| QR meja disalahgunakan | Order palsu. | Rate limit, konfirmasi kasir, token bisa diganti. |
| Data stok tidak rapi | HPP dan laporan margin menyesatkan. | Satuan dikunci, konversi otomatis, opname rutin dengan persetujuan, peringatan stok minus. |
| Pembayaran QRIS online tertahan | Uang masuk tapi order belum lunas. | Tahan dan peringatan ke manager, penyapuan tiap menit, tombol catat ulang. |
| Kunci gateway bocor | Penyalahgunaan akun gateway. | Rahasia disembunyikan di UI, rotasi kunci, mode sandbox untuk uji. |
| Developer tunggal | Pengembangan berhenti. | Struktur domain konsisten, test, CI. |

### 11.2 Roadmap

| Fase | Cakupan | Status |
| --- | --- | --- |
| MVP | Modul 4.1–4.14. | Selesai. |
| v1.1 | Stok/resep/HPP, belanja dan pemasok, opname, waste, aset, impor Excel, notifikasi, jejak aktivitas, laporan harian otomatis dan pengiriman, peran & akses, jurnal stok, QRIS online (opsional), penyegaran UI. | Selesai di branch `dev`; digabung ke `main` setelah uji staf. |
| Berikutnya | Uji staf dan go-live di staging; hutang pemasok; pelanggan, voucher, dan poin; harga per kanal ojol dan komisi; reservasi meja; setor kas ke bank dari UI. | Direncanakan. |
| v2.0 | Mode offline (PWA + sinkronisasi), multi-outlet, integrasi ojol, QRIS dinamis langsung dipakai produksi. | Menunggu validasi kebutuhan. |

## 12. Open Questions

- [ ] Model printer thermal dan metode koneksi per perangkat.
- [ ] Jumlah meja, area, dan nominal modal kembalian awal.
- [ ] Gateway pembayaran: pilihan final dan status verifikasi akun usaha (perorangan atau badan usaha).
- [ ] Apakah karyawan perlu bisa mencatat belanja non-stok (tissue dll) dengan persetujuan manager.
- [ ] Apakah karyawan boleh mengusulkan bahan baru dari form belanja.
- [ ] Rencana jualan via ojol (menentukan harga per kanal).
- [ ] Pendaftaran pajak (PB1/PKP) di masa depan.
- [ ] Warna aksen default final (saat ini Biru) setelah desain baru diputuskan.

## 13. Brief Desain UI/UX

Bagian ini untuk perancang (manusia atau alat desain). Tujuannya: antarmuka modern, sederhana, dan mudah dipakai di HP, tablet, dan desktop, terutama untuk **customer, kasir, dan waiter**.

### 13.1 Prinsip

1. **Kecepatan di atas hiasan.** Kasir dan waiter bekerja sambil berdiri; setiap layar utama menyelesaikan tugasnya dalam sedikit ketukan.
2. **Satu tindakan utama per layar**, ditandai tombol solid warna aksen; tindakan lain berupa tombol outline atau teks.
3. **Jangan terlihat seperti template AI:** tanpa gradien, tanpa efek kaca/glow, tanpa emoji dekoratif, tanpa kartu di dalam kartu, tanpa ikon di setiap baris, tanpa bayangan tebal. Kejelasan dan hierarki lewat tipografi, jarak, dan garis tipis.
4. **Tabel untuk data, kartu seperlunya.** Di desktop data berbentuk tabel dengan pencarian, filter, dan pengurutan; di HP berubah menjadi daftar kartu.
5. **Warna punya arti.** Warna aksen untuk aksi dan navigasi aktif; hijau/kuning/merah hanya untuk status (kosong/terisi/menunggu bayar, siap/dibuat, peringatan). Status tidak ikut berubah saat aksen diganti.
6. **Ramah jempol.** Target sentuh ≥ 44 px, aksi utama di bawah layar di HP, dialog sebagai bottom sheet.
7. **Bahasa Indonesia ringkas**, kalimat aksi jelas ("Kirim ke dapur", "Catat terbuang"), tanpa istilah teknis.
8. **Tetap terbaca saat ramai:** kontras ≥ 4,5:1, ukuran teks minimal 14 px (16 px untuk input di HP), angka tabular.

### 13.2 Token Visual (nilai saat ini; boleh disempurnakan)

| Token | Nilai |
| --- | --- |
| Font | Inter 400/500/600/700; judul halaman 20–24 px semibold, tracking rapat; badan 14 px; label kecil 12–13 px. |
| Latar | Netral dingin sangat terang (`oklch(0.982 0.003 250)`); kartu putih dengan garis 1 px (`oklch(0.915 0.005 250)`). Mode gelap tersedia. |
| Aksen (default Biru) | `oklch(0.5 0.19 262)`; pilihan lain: Hijau, Oranye, Ungu. Teks di atas aksen putih. |
| Status | Hijau (kosong/siap/lunas), Kuning (terisi/dibuat/menunggu), Merah (menunggu bayar/void/bahaya), Biru muda (baru), Abu (menunggu konfirmasi). Selalu dengan teks atau titik, tidak hanya warna. |
| Radius | 8–12 px untuk kontrol dan kartu; 16 px untuk bottom sheet. |
| Bayangan | Hampir tidak ada; hanya pada elemen melayang (tombol total, dropdown, sheet). |
| Jarak | Skala 4 px; padding halaman 16 px di HP, 24 px di desktop; konten admin maks 1400 px. |
| Gerak | Transisi singkat 150–200 ms untuk hover, buka sheet, dan pilih; tidak ada animasi dekoratif. |

### 13.3 Komponen yang Perlu Dirancang

- Tombol (utama, outline, ghost, destruktif) dengan tinggi 40/44/48/56 px; tombol ikon.
- Input teks, angka, rupiah (rata kanan, pemisah ribuan), tanggal, area catatan; **kolom ketik-untuk-cari** (combobox) dengan daftar hasil, sorotan baris aktif, dan pesan "tidak ada yang cocok".
- Select/dropdown, tab (garis bawah untuk halaman; segmented untuk dua pilihan besar di POS), chip kategori.
- **Tabel responsif**: kolom pencarian, filter dropdown otomatis, kepala kolom yang bisa diurutkan, baris hover, kolom Aksi dengan tombol diam (teks/ghost), keadaan kosong, dan versi kartu di HP.
- Kartu statistik (label kecil, angka besar, petunjuk tren dengan ikon naik/turun).
- Grafik sederhana: garis (tren), kolom (per jam), batang horizontal tipis (peringkat), semuanya dengan label sumbu terbaca dan alternatif teks.
- Dialog: tengah layar di tablet/desktop, bottom sheet di HP dengan handle dan **footer menempel** untuk tombol utama.
- Kartu meja (status dengan titik warna, nomor, kapasitas, total), tile menu (nama, harga, sisa stok, label HABIS), baris item pesanan (qty × nama, modifier, catatan, status, aksi Void/Diskon/Komplimen).
- Panel pesanan (header, daftar item per ronde, ringkasan Subtotal/Diskon/Pajak/Total, tombol bayar), dialog bayar (nominal besar, metode, tombol nominal cepat, kembalian), dialog split bill (tiga mode + bayar sebagian).
- Lonceng notifikasi dengan badge dan daftar, toast, banner peringatan (shift belum dibuka, bunyi belum aktif), badge status.
- Sidebar dengan grup (Harian, Kelola, Laporan, Keuangan, Pengaturan), tooltip saat ciut, dan item aktif bertinta aksen.
- Kerangka muat (skeleton), keadaan kosong dengan ajakan tindakan, pesan galat inline dan toast.

### 13.4 Aturan per Perangkat

| Perangkat | Pengguna | Aturan tata letak |
| --- | --- | --- |
| HP (360–767) | Customer, waiter, karyawan belanja/opname | Satu kolom; header satu baris; tab segmented Meja & order / Menu; tiga tombol cepat (Takeaway, Dine-in bayar dulu, Online/Ojol); tombol total melayang di bawah; keranjang, modifier, bayar sebagai bottom sheet; chip kategori menempel; tabel menjadi kartu. |
| Tablet (768–1279) | Kasir, dapur | Dua kolom: kiri meja/menu dengan grid menyesuaikan lebar, kanan panel pesanan tetap terlihat; target sentuh besar; KDS gelap dengan tiket berwarna menurut umur. |
| Desktop (≥1280) | Owner, manager | Sidebar tetap + konten maks 1400 px; tabel penuh dengan toolbar; laporan dengan grafik di atas tabel; dialog di tengah. |

### 13.5 Keadaan yang Harus Didesain

Kosong (belum ada data), memuat, galat (jaringan putus, validasi), sukses (toast), tidak ada izin (menu tersembunyi, bukan error), offline (indikator di header), shift belum dibuka (banner + tombol Buka shift), stok habis, stok menipis, menunggu persetujuan, ditolak dengan alasan, QRIS menunggu/lunas/kedaluwarsa.

## 14. Inventaris Layar

Navigasi staf (sidebar), dengan izin yang menampilkannya:

| Grup | Layar | Pengguna utama | Isi inti |
| --- | --- | --- | --- |
| Harian | **Dashboard** | Owner, manager | Kartu omzet/transaksi/rata-rata, grafik 14 hari dan per jam, produk terlaris. |
| Harian | **Kasir (POS)** | Kasir, waiter | Peta meja, menu, panel pesanan, dialog varian/modifier, bayar, split, diskon, void, komplimen, pindah/gabung meja, inbox pesanan QR, struk. |
| Harian | **Shift** | Kasir, manager | Buka/tutup shift, hitung pecahan, kas masuk/keluar, rekap, selisih. |
| Harian | **Dapur (KDS)** | Dapur | Antrean tiket per stasiun, ubah status item. |
| Harian | **Daftar Stok** | Karyawan | Daftar bahan dan jumlah tanpa harga. |
| Harian | **Stok Opname** | Karyawan, manager | Hitung per bahan (boleh kemasan), kirim, lihat status persetujuan. |
| Harian | **Belanja Bahan** | Karyawan belanja | Form belanja dengan pencarian bahan, riwayat dan status persetujuan, isi dari Excel. |
| Harian | **Bahan Terbuang** | Karyawan, manager | Tab bahan/menu jadi, alasan, catatan; kelola daftar alasan (manager). |
| Harian | **Stasiun Cetak** | Perangkat cetak | Antrean cetak untuk printer tanpa koneksi langsung. |
| Kelola | **Menu** | Owner, manager | Kategori, produk, varian, modifier, stok porsi, foto, impor. |
| Kelola | **Stok & Bahan** | Manager | Tab Bahan, Pembelian (setujui/tolak), Opname staf, Riwayat; ubah/tambah bahan, kemasan, impor. |
| Kelola | **Resep & HPP** | Manager | Resep per menu/varian, biaya dan margin. |
| Kelola | **Diskon & Promo** | Owner | Diskon bernama untuk kasir (Staff 10%, Owner 20%) dan promo terjadwal per jam/hari/tanggal, per kategori atau menu, dengan opsi digabung. |
| Kelola | **Pemasok** | Manager | Tabel pemasok, riwayat, perbandingan harga, impor. |
| Kelola | **Aset & Alat** | Manager | Daftar alat, kondisi, riwayat servis. |
| Kelola | **Meja & QR** | Owner, manager | Area, meja, QR, cetak PDF QR, aktif/nonaktif. |
| Laporan | **Laporan Penjualan**, **Margin & HPP**, **Kinerja Dapur**, **Laporan Waste**, **Diskon/Pembatalan/Refund**, **Struk Bulanan (PDF)** | Owner, manager | Filter tanggal, pengelompokan, grafik, tabel, ekspor Excel/PDF. |
| Laporan | **Pembayaran Online** | Manager | Daftar transaksi QRIS online, status, catat ulang, simulasi; petunjuk aktivasi. |
| Laporan | **Jejak Aktivitas** | Owner, manager | Daftar aksi dengan pencarian dan filter. |
| Keuangan | **Pengeluaran** | Manager | Input pengeluaran, kategori, ekspor. |
| Keuangan | **Pembayaran & Pajak** | Owner | Metode bayar dan rekening penerima, pajak dan service charge. |
| Keuangan | **Akuntansi** | Owner | Chart of accounts, jurnal, buku besar, laba rugi, neraca, arus kas, tutup buku. |
| Keuangan | **Laporan Harian** | Owner, manager | Ringkasan hari bisnis, shift, riwayat, kirim laporan. |
| Pengaturan | **Pengaturan** | Owner | Tab Operasional, Pesanan QR, Stok & HPP, Dapur, Kasir & struk, Keamanan, QRIS Online, Notifikasi. |
| Pengaturan | **Printer**, **User**, **Peran & Akses** | Owner | Perangkat dan routing cetak; akun dan PIN; peran dengan centang izin. |

Halaman publik (tanpa login):

| Layar | Pengguna | Isi inti |
| --- | --- | --- |
| **Menu QR meja** (`/m/{token}`) | Customer | Header outlet dan nomor meja; cari menu; chip kategori; daftar menu dengan tombol + atau Pilih; sheet varian/modifier; keranjang; nama panggilan; status pesanan per ronde; total sementara; tombol Bayar dengan QRIS (bila aktif) dengan kode QR, hitung mundur, dan status lunas. |
| **Struk digital** (`/r/{id}`) | Customer | Struk bertoken yang bisa dibagikan. |
| **Login** | Staf | Login PIN untuk staf; email + password (+ passkey) untuk owner/manager. |

**Alur kunci yang harus mulus:** (1) Customer scan → pilih → pesan → status → (opsional) bayar QRIS. (2) Kasir/waiter pilih meja → tambah menu (varian/modifier) → kirim ke dapur → tambah ronde → bayar → struk. (3) Karyawan belanja: cari bahan → jumlah/satuan/harga → kirim → lihat status persetujuan. (4) Manager: lonceng → setujui belanja/opname → lihat laporan.

- **Istilah dalam bahasa Indonesia:** layar, laporan, email, dan izin memakai "Batalkan" / "Pembatalan" / "Dibatalkan" untuk membatalkan item atau order (bukan "void"), agar mudah dimengerti staf. Nama teknis di kode dan database tidak berubah.
- **Ikon tab browser:** logo outlet (Pengaturan › Outlet) dipakai sebagai ikon tab browser dan ikon layar utama HP di semua halaman, termasuk QR pelanggan; tanpa logo dipakai ikon bawaan. PDF struk bulanan berjalan di antrean khusus `exports` (memori 1,3 GB, batas 30 menit) agar tidak terputus oleh batas worker biasa.
- **Pembayaran hanya di dalam shift terbuka:** server menolak pembayaran (dan refund tunai) tanpa shift terbuka dengan pesan "Buka shift dulu sebelum menerima pembayaran." Di layar kasir, menekan Bayar, Kirim & bayar langsung, atau melanjutkan bayar setelah split tanpa shift terbuka tidak membuka dialog bayar: muncul pesan dan, untuk yang berizin buka shift, dialog Buka shift langsung terbuka; pesanan walk-in juga tidak dikirim sebelum shift dibuka.
- **Instalasi pertama di server:** `php artisan migrate --force` lalu `php artisan pos:install` (menanyakan nama kafe, alamat, dan nama/email/password owner minimal 10 karakter) menyiapkan peran, izin, outlet, bagan akun, metode bayar, dan satu akun owner tanpa data contoh dan tanpa password bawaan; menolak berjalan bila sudah ada pengguna. `db:seed` (akun contoh dengan password dan PIN bawaan) hanya untuk pengembangan lokal dan tidak boleh dijalankan di server.
- **Halaman masuk dengan identitas kafe:** semua halaman masuk (PIN, email, lupa password, password baru, verifikasi dua langkah) tampil dalam kartu bersih dengan logo dan nama kafe (monogram warna aksen bila belum ada logo) dan berbahasa Indonesia. Staf masuk dengan keypad PIN besar yang ramah sentuh (enam titik, masuk otomatis di digit ke-6, titik bergetar dan pesan "PIN salah." bila salah; papan ketik fisik juga bisa dipakai); owner dan manager memakai tombol "Owner / manager? Masuk dengan email" untuk form email dan password (atau passkey). Nama dan logo dikirim ke semua halaman lewat data bersama Inertia (`brand`).
