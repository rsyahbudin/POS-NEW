# POS Restoran

POS web untuk satu outlet kafe/restoran, dibangun dari [PRD](<PRD — POS System Restaurant.md>):
kasir, self-order QR meja, layar dapur (KDS), shift kasir, split bill, dan akuntansi double-entry ringan.

**Stack:** Laravel 13 · PHP 8.4 · React 19 + Inertia (starter kit) · TypeScript · Tailwind 4 + shadcn/ui ·
PostgreSQL 17 · Redis + Horizon · Reverb (WebSocket) · Pest.

## Menjalankan di lokal

Prasyarat: Laravel Herd, DBngin (PostgreSQL 17 + Redis).

```bash
createdb -h 127.0.0.1 -U postgres pos          # database aplikasi
createdb -h 127.0.0.1 -U postgres pos_testing  # database test (PostgreSQL asli, bukan SQLite)
cp .env.example .env && php artisan key:generate
composer install && npm install
php artisan migrate --seed
composer run dev                               # server, queue/Horizon, Reverb, Vite, log
```

Akun demo (password `password`; PIN manager 6 digit untuk void/diskon di atas batas):

| Peran   | Email            | PIN    |
| ------- | ---------------- | ------ |
| Owner   | owner@pos.test   | 111111 |
| Manager | manager@pos.test | 222222 |
| Kasir   | kasir@pos.test   | 333333 |
| Waiter  | waiter@pos.test  | —      |
| Dapur   | dapur@pos.test   | —      |

Setelah login: `/pos` (kasir), `/kds` (dapur), `/shift`, `/dashboard`, `/admin/*` (back office).
QR meja: `/m/{token}` (token dicetak lewat Admin › Meja & QR › PDF).
Ganti password demo sebelum produksi. `POS_REQUIRE_OWNER_2FA=true` (default di `.env.example`) mewajibkan 2FA owner.

## Test & kualitas

```bash
composer test        # Pint, Larastan level 8, lalu Pest (PostgreSQL)
npm run check        # ESLint + Prettier (vite-plus)
npm run types:check  # TypeScript
npm run e2e          # Playwright: QR -> KDS -> bayar -> struk di 360/768/1280 px
vendor/bin/pest --testsuite=Concurrency   # dua proses PHP nyata berebut bayar bill yang sama
```

Test mencakup invarian akuntansi PRD bagian 10: debit = kredit di setiap jurnal (Action **dan** constraint
trigger PostgreSQL), saldo Kas Laci per shift, dan pendapatan Laba Rugi = grand total − pajak − refund.

## Struktur kode

`app/Domain/{Catalog,Floor,Ordering,Kitchen,Payment,Shift,Accounting,Expense,Pricing,Reporting,Settings}` —
tiap domain punya `Models/ Actions/ Enums/ Events/ Exceptions/`. Satu Action = satu use case dengan satu
`handle()`; uang dibungkus `DB::transaction()` + `lockForUpdate()`; jurnal dibuat sinkron di transaksi yang sama
dengan pembayaran. Semua nominal `bigint` rupiah lewat value object `App\Support\Money`. Controller tipis
(validasi → Action → respons). `PriceCalculator` adalah satu-satunya tempat total dihitung.
Domain `Kitchen` belum punya folder sendiri: tiket dapur = ronde order per stasiun (`stations`, `order_rounds`).

## Cakupan terhadap PRD (modul 4.1–4.15)

Semua modul punya Action, endpoint, halaman, dan test, dengan catatan di bawah.

## Belum selesai / berbeda dari PRD (jujur)

- **Larastan level 8 bersih**, Pint dan ESLint bersih, 144 test lulus — tetapi **coverage belum diukur**
  (PHP lokal tanpa pcov/xdebug); gate `--min=70` di CI belum pernah dijalankan.
- **Playwright e2e** (`e2e/`) memakai Google Chrome terpasang dan database `pos_testing` di port 8123; Reverb
  dimatikan sehingga layar memuat ulang state (target realtime ≤ 3 s tidak diuji di sini).
  **Uji konkurensi** (`tests/Concurrency`) menjalankan dua proses PHP nyata dengan dua koneksi PostgreSQL; terbukti
  gagal bila `lockForUpdate` dicabut. Keduanya perlu `npm run build` dan database `pos_testing`.
- **Lighthouse (Performance ≥ 85 / Accessibility ≥ 90), FCP QR ≤ 1,8 s, p95 ≤ 300 ms, update realtime ≤ 3 s
  belum diukur.** Terukur: bundle JS halaman QR ≈ 112 KB gzip (batas 150 KB). UI diperiksa manual di browser
  (kasir, KDS, QR 375 px); belum diuji di tablet/HP fisik, Safari iOS, atau printer thermal.
- **QZ Tray**: kode cetak + penandatanganan server ada (`php artisan pos:qz-keys`), belum diuji dengan QZ Tray
  dan printer nyata. Jika gagal, otomatis fallback ke cetak browser.
- **`spatie/laravel-data` + typescript-transformer tidak dipakai**: tipe TypeScript untuk respons server ditulis
  tangan di `resources/js/types/pos.ts`. Wayfinder terpasang (bawaan starter), tetapi endpoint JSON POS memakai URL biasa.
- Starter kit membawa **Inertia 3** dan toolchain `vite-plus`, bukan Inertia 2 seperti tertulis di PRD.
- **Sentry dan Laravel Pulse belum dipasang.** Backup `spatie/laravel-backup` + R2 sudah dikonfigurasi,
  restore belum pernah diuji.
- Terjemahan: semua teks UI lewat `t()` (`resources/js/lang`, kunci = teks Indonesia); kamus Inggris baru
  berisi contoh. Pesan error dari server masih Indonesia saja.
- Split bill: setelah ada tagihan lunas, item baru hanya bisa masuk ke tagihan "Belum dialokasikan" pada split
  **per item**; split bagi rata / nominal tidak bisa ditambah item setelah ada yang dibayar. Diskon order nominal
  tetap dibagi proporsional, tetapi tagihan yang dialokasikan ulang setelah split menghitung ulang diskon nominal order.
- Pengeluaran bersumber Kas Laci dari menu Pengeluaran **tidak** mengurangi "kas seharusnya" shift; catat kas
  keluar dari halaman Shift agar masuk hitungan laci. Shift pertama tanpa shift sebelumnya tidak membuat jurnal
  modal awal; isi saldo awal lewat Akuntansi › Jurnal manual (jurnal pembuka terhadap Modal Pemilik).
- Registrasi publik dan verifikasi email bawaan starter dinonaktifkan (staf dibuat owner).
- Env lokal memakai `POS_REQUIRE_OWNER_2FA=false` agar mudah dicoba; set `true` untuk staging/produksi.

Deploy: lihat [docs/deploy](docs/deploy/README.md).
