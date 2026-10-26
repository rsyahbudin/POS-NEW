<?php

namespace App\Domain\Settings;

use App\Support\Accent;

/**
 * Every adjustable setting, with its label, help text, type and default.
 * The settings page renders from this list and validation is derived from it,
 * so adding a setting here makes it editable in the UI with no other change.
 *
 * Types: bool | int | text | time | select | secret | list (comma-separated rupiah amounts)
 */
final class SettingsCatalog
{
    /** @return list<array{key: string, label: string, groups: list<array<string, mixed>>}> */
    public static function tabs(): array
    {
        return [
            ['key' => 'ops', 'label' => 'Operasional', 'groups' => [[
                'title' => 'Harga & tampilan',
                'fields' => [
                    ['key' => 'pricing.rounding', 'type' => 'select', 'label' => 'Pembulatan grand total', 'hint' => 'Selisih pembulatan dicatat di akun tersendiri.', 'default' => 0, 'options' => [['0', 'Tanpa pembulatan'], ['100', 'Ke Rp100 terdekat'], ['500', 'Ke Rp500 terdekat']]],
                    ['key' => 'ui.accent', 'type' => 'select', 'label' => 'Warna aksen aplikasi', 'hint' => 'Warna tombol, menu aktif, dan grafik di semua perangkat. Warna status (meja kosong/terisi, pesanan siap, peringatan) tidak ikut berubah supaya tetap jelas dibaca.', 'default' => 'blue', 'options' => Accent::options()],
                    ['key' => 'discount.cashier_limit_percent', 'type' => 'int', 'label' => 'Batas diskon kasir tanpa PIN (%)', 'hint' => 'Diskon di atas batas ini butuh PIN dari pemegang izin "Diskon di atas batas".', 'default' => 10, 'min' => 0, 'max' => 100],
                ],
            ]]],
            ['key' => 'qr', 'label' => 'Pesanan QR', 'groups' => [[
                'title' => 'Alur pesanan dari meja',
                'fields' => [
                    ['key' => 'qr.auto_confirm', 'type' => 'bool', 'label' => 'Langsung masuk dapur tanpa konfirmasi kasir', 'hint' => 'Aktif: pesanan QR otomatis diterima dan tiket dapur langsung muncul. Kasir tetap mendapat notifikasi. Jika stok tidak cukup, pesanan tetap menunggu konfirmasi kasir. Nonaktif: kasir harus menekan Konfirmasi dulu (lebih aman dari pesanan iseng).', 'default' => false],
                    ['key' => 'qr.confirm_alert_seconds', 'type' => 'int', 'label' => 'Alarm jika belum dikonfirmasi (detik)', 'hint' => 'Hanya berlaku bila konfirmasi kasir diwajibkan. Bunyi dan notifikasi diulang sampai ditangani.', 'default' => 180, 'min' => 30, 'max' => 3600],
                ],
            ], [
                'title' => 'Panggil waiter dari meja',
                'fields' => [
                    ['key' => 'call.enabled', 'type' => 'bool', 'label' => 'Tombol "Panggil waiter" di halaman QR', 'hint' => 'Aktif: pelanggan bisa memanggil staf dari HP-nya (butuh bantuan, minta bill, minta air). Meja yang memanggil ditandai di peta meja dan staf mendengar bunyi.', 'default' => false],
                    ['key' => 'call.to_pos', 'type' => 'bool', 'label' => 'Tampilkan panggilan di layar kasir dan waiter', 'hint' => 'Panggilan muncul di atas layar Kasir (semua yang punya izin Tangani panggilan meja) dengan bunyi.', 'default' => true],
                    ['key' => 'call.to_kitchen', 'type' => 'bool', 'label' => 'Tampilkan panggilan di layar dapur (KDS)', 'hint' => 'Berguna kalau dapur yang mengantar pesanan atau tidak ada waiter. Boleh aktif bersama layar kasir.', 'default' => false],
                    ['key' => 'alert.keep_ringing', 'type' => 'bool', 'label' => 'Bunyi berulang sampai ditangani', 'hint' => 'Aktif: bunyi terus berulang sampai pesanan QR dikonfirmasi, panggilan meja ditandai selesai, dan tiket baru di dapur mulai dibuat. Mati: bunyi hanya beberapa kali. Bunyi bisa dibisukan lewat tombol speaker di layar.', 'default' => true],
                    ['key' => 'alert.repeat_seconds', 'type' => 'int', 'label' => 'Jeda bunyi berulang (detik)', 'hint' => 'Selang waktu antar bunyi saat "Bunyi berulang sampai ditangani" aktif. Makin kecil makin sering berbunyi; 4 sampai 8 detik cukup mendesak.', 'default' => 5, 'min' => 2, 'max' => 60],
                ],
            ], [
                'title' => 'Anti-spam',
                'fields' => [
                    ['key' => 'qr.max_submits_per_window', 'type' => 'int', 'label' => 'Maksimal kiriman per meja', 'hint' => 'Jumlah pesanan yang boleh dikirim satu meja dalam rentang waktu di bawah.', 'default' => 5, 'min' => 1, 'max' => 50],
                    ['key' => 'qr.window_minutes', 'type' => 'int', 'label' => 'Rentang waktu anti-spam (menit)', 'hint' => 'Contoh: 5 kiriman per 10 menit.', 'default' => 10, 'min' => 1, 'max' => 120],
                ],
            ]]],
            ['key' => 'inventory', 'label' => 'Stok & HPP', 'groups' => [[
                'title' => 'Stok bahan dan harga pokok',
                'fields' => [
                    ['key' => 'inventory.enabled', 'type' => 'bool', 'label' => 'Pakai stok bahan, resep, dan HPP', 'hint' => 'Aktif: tiap penjualan memakai bahan sesuai resep, stok berkurang otomatis, dan laporan margin menghitung keuntungan per menu. Nonaktif: menu dan laporan stok disembunyikan dan penjualan tidak menyentuh stok bahan.', 'default' => false],
                    ['key' => 'inventory.block_when_empty', 'type' => 'bool', 'label' => 'Tolak pesanan jika bahan tidak cukup', 'hint' => 'Aktif: pesanan gagal bila stok bahan kurang dari resep. Nonaktif (disarankan di awal, saat stok belum rapi): pesanan tetap jalan dan stok bisa minus sebagai tanda perlu opname.', 'default' => false],
                    ['key' => 'inventory.purchase_needs_approval', 'type' => 'bool', 'label' => 'Belanja bahan yang dicatat staf perlu disetujui manager', 'hint' => 'Aktif (disarankan): stok dan harga rata-rata bahan baru berubah setelah manager menyetujui, supaya HPP tidak bisa diubah sendirian. Nonaktif: belanja staf langsung masuk stok. Manager dengan izin kelola stok selalu langsung.', 'default' => true],
                    ['key' => 'inventory.post_journals', 'type' => 'bool', 'label' => 'Catat stok ke jurnal akuntansi', 'hint' => 'Aktif: belanja bahan (disetujui), HPP penjualan, bahan terbuang, dan selisih opname otomatis masuk jurnal dan laporan keuangan (Persediaan Bahan, HPP, Beban Bahan Terbuang, Selisih Stok). Belanja dicatat keluar dari akun Kas Belanja Bahan, jadi jangan catat belanja yang sama lagi sebagai Pengeluaran. Berlaku mulai saat diaktifkan; kejadian sebelumnya tidak ikut.', 'default' => false],
                    ['key' => 'inventory.price_alert_percent', 'type' => 'int', 'label' => 'Peringatan kenaikan harga bahan (%)', 'hint' => 'Manager diberi tahu kalau harga beli sebuah bahan naik lebih dari persen ini dibanding biaya rata-ratanya. Isi 0 untuk mematikan.', 'default' => 15, 'min' => 0, 'max' => 500],
                    ['key' => 'inventory.count_needs_approval', 'type' => 'bool', 'label' => 'Hasil stok opname staf perlu disetujui manager', 'hint' => 'Aktif (disarankan): hitungan staf menunggu persetujuan sebelum stok berubah. Nonaktif: stok langsung disesuaikan begitu staf mengirim hitungan.', 'default' => true],
                    ['key' => 'inventory.blind_count', 'type' => 'bool', 'label' => 'Sembunyikan stok sistem saat staf menghitung', 'hint' => 'Aktif: staf hanya melihat kolom isian, bukan angka stok di sistem, sehingga hitungannya jujur. Manager tetap melihat angka sistem.', 'default' => true],
                ],
            ]]],
            ['key' => 'kitchen', 'label' => 'Dapur (KDS)', 'groups' => [[
                'title' => 'Aktifkan layar dapur',
                'fields' => [
                    ['key' => 'kitchen.enabled', 'type' => 'bool', 'label' => 'Pakai layar dapur (KDS)', 'hint' => 'Aktif: pesanan tampil sebagai tiket di layar dapur, staf dapur menandai Dibuat/Siap, dan laporan "Kinerja dapur" mencatat berapa menit tiap pesanan selesai. Nonaktif (cocok jika kasir sekaligus yang membuat dan mengantar): menu dapur, status dapur, dan laporan disembunyikan.', 'default' => false],
                ],
            ], [
                'title' => 'Warna tiket berdasarkan umur',
                'fields' => [
                    ['key' => 'kitchen.warn_minutes', 'type' => 'int', 'label' => 'Tiket menguning setelah (menit)', 'hint' => 'Peringatan awal untuk tiket yang lama.', 'default' => 10, 'min' => 1, 'max' => 240],
                    ['key' => 'kitchen.danger_minutes', 'type' => 'int', 'label' => 'Tiket memerah setelah (menit)', 'hint' => 'Tiket sangat terlambat.', 'default' => 20, 'min' => 1, 'max' => 240],
                ],
            ], [
                'title' => 'Berapa lama tiket tetap terlihat',
                'fields' => [
                    ['key' => 'kitchen.ready_visible_minutes', 'type' => 'int', 'label' => 'Item "Siap" tetap tampil (menit)', 'hint' => 'Setelah itu hilang dari layar dapur.', 'default' => 5, 'min' => 1, 'max' => 120],
                    ['key' => 'kitchen.void_visible_minutes', 'type' => 'int', 'label' => 'Item void tetap tampil dengan coretan (menit)', 'hint' => 'Agar dapur sempat tahu item dibatalkan.', 'default' => 10, 'min' => 1, 'max' => 120],
                ],
            ]]],
            ['key' => 'pos', 'label' => 'Kasir & struk', 'groups' => [[
                'title' => 'Layar kasir',
                'fields' => [
                    ['key' => 'pos.receipt_whatsapp', 'type' => 'bool', 'label' => 'Tombol bagikan struk lewat WhatsApp', 'hint' => 'Aktif: setelah bayar, layar struk punya tombol WhatsApp yang membuka WhatsApp di perangkat kasir dengan tautan struk sudah terisi; kasir memilih nomor pelanggan lalu mengirim sendiri. Tidak butuh gateway atau akun apa pun, dan terpisah dari pengiriman laporan ke owner.', 'default' => false],
                    ['key' => 'pos.quick_cash', 'type' => 'list', 'label' => 'Tombol cepat uang tunai', 'hint' => 'Nominal dipisah koma. Tombol "Uang pas" selalu ada. Contoh: 50000,100000,150000,200000', 'default' => '50000,100000,150000,200000'],
                    ['key' => 'pos.ready_alert_minutes', 'type' => 'int', 'label' => 'Item siap antar tampil di kasir (menit)', 'hint' => 'Daftar "siap diantar" di atas layar kasir.', 'default' => 15, 'min' => 1, 'max' => 180],
                ],
            ], [
                'title' => 'Isi struk (pratinjau di sebelah kanan)',
                'fields' => [
                    ['key' => 'receipt.header', 'type' => 'text', 'label' => 'Teks tambahan di bawah nama outlet', 'hint' => 'Mis. nomor WhatsApp, Instagram, atau jam buka. Nama, alamat, dan NPWP diatur di tab Outlet.', 'default' => '', 'max' => 120],
                    ['key' => 'receipt.show_npwp', 'type' => 'bool', 'label' => 'Tampilkan NPWP', 'hint' => 'Hanya tampil jika NPWP diisi di tab Outlet.', 'default' => true],
                    ['key' => 'receipt.show_cashier', 'type' => 'bool', 'label' => 'Tampilkan nama kasir', 'hint' => '', 'default' => true],
                    ['key' => 'receipt.show_table', 'type' => 'bool', 'label' => 'Tampilkan nomor meja', 'hint' => '', 'default' => true],
                    ['key' => 'receipt.show_call_number', 'type' => 'bool', 'label' => 'Tampilkan nomor panggil (takeaway)', 'hint' => '', 'default' => true],
                    ['key' => 'receipt.show_link', 'type' => 'bool', 'label' => 'Cetak link struk digital', 'hint' => 'Customer bisa membuka struk di HP lewat link ini.', 'default' => false],
                    ['key' => 'receipt.footer', 'type' => 'text', 'label' => 'Catatan kaki struk', 'hint' => 'Tampil di bagian bawah struk.', 'default' => 'Terima kasih atas kunjungan Anda', 'max' => 200],
                ],
            ]]],
            ['key' => 'security', 'label' => 'Keamanan', 'groups' => [[
                'title' => 'Login',
                'fields' => [
                    ['key' => 'security.require_owner_2fa', 'type' => 'bool', 'label' => 'Wajibkan 2FA untuk owner', 'hint' => 'Aktif: owner harus mengaktifkan verifikasi dua langkah (aplikasi authenticator) sebelum memakai aplikasi.', 'default' => false],
                ],
            ], [
                'title' => 'Pembatalan',
                'fields' => [
                    ['key' => 'security.cancel_needs_pin', 'type' => 'bool', 'label' => 'Pembatalan pesanan/item (belum dibayar) butuh PIN manager', 'hint' => 'Nonaktif (disarankan untuk kafe kecil): staf boleh membatalkan sendiri dengan alasan wajib; tercatat di laporan Diskon/Void/Refund beserta siapa pembatalnya. Refund setelah bayar tetap butuh PIN.', 'default' => false],
                ],
            ], [
                'title' => 'PIN manager',
                'fields' => [
                    ['key' => 'security.pin_max_attempts', 'type' => 'int', 'label' => 'Salah PIN maksimal', 'hint' => 'Setelah sebanyak ini salah, kasir terkunci sementara.', 'default' => 5, 'min' => 1, 'max' => 20],
                    ['key' => 'security.pin_lock_minutes', 'type' => 'int', 'label' => 'Lama terkunci (menit)', 'hint' => 'Kasir tidak bisa memakai PIN manager selama ini.', 'default' => 15, 'min' => 1, 'max' => 1440],
                ],
            ]]],
            ['key' => 'qris', 'label' => 'QRIS Online', 'groups' => [[
                'title' => 'Bayar dari HP pelanggan',
                'fields' => [
                    ['key' => 'qris.enabled', 'type' => 'bool', 'label' => 'Pelanggan bisa bayar QRIS dari halaman QR meja', 'hint' => 'Aktif: setelah pesanan dikonfirmasi, halaman QR meja menampilkan tombol Bayar QRIS. Pembayaran yang masuk otomatis tercatat lunas di shift yang sedang terbuka. Butuh akun payment gateway (lihat petunjuk di halaman Pembayaran Online).', 'default' => false],
                    ['key' => 'qris.provider', 'type' => 'select', 'label' => 'Gateway', 'hint' => 'Midtrans untuk pembayaran sungguhan. Simulasi untuk uji coba: tidak ada uang yang bergerak, manager menandai lunas sendiri.', 'default' => 'midtrans', 'options' => [['midtrans', 'Midtrans'], ['fake', 'Simulasi (uji coba)']]],
                    ['key' => 'qris.midtrans_server_key', 'type' => 'secret', 'label' => 'Midtrans Server Key', 'hint' => 'Dari dashboard Midtrans > Settings > Access Keys. Tidak ditampilkan lagi setelah disimpan. Pakai key Sandbox untuk uji coba.', 'default' => null],
                    ['key' => 'qris.production', 'type' => 'bool', 'label' => 'Mode produksi Midtrans', 'hint' => 'Nonaktif = Sandbox (uji coba, tanpa uang asli). Aktifkan hanya setelah akun Midtrans Anda diverifikasi dan Server Key yang dipakai adalah key Production.', 'default' => false],
                    ['key' => 'qris.expiry_minutes', 'type' => 'int', 'label' => 'QR berlaku (menit)', 'hint' => 'Setelah waktu ini kode QR kedaluwarsa dan pelanggan bisa meminta yang baru.', 'default' => 15, 'min' => 3, 'max' => 60],
                ],
            ]]],
            ['key' => 'notif', 'label' => 'Notifikasi', 'groups' => [[
                'title' => 'Apa yang dikirim ke HP / email',
                'fields' => [
                    ['key' => 'report.send_daily', 'type' => 'bool', 'label' => 'Laporan harian', 'hint' => 'Ringkasan omzet per jam, metode bayar, diskon, void, dan selisih kas, dikirim otomatis begitu shift terakhir ditutup. Kalau toko buka lagi dan angkanya berubah, laporan diperbarui dikirim lagi.', 'default' => true],
                    ['key' => 'report.send_shift', 'type' => 'bool', 'label' => 'Laporan tiap shift ditutup', 'hint' => 'Rincian penjualan dan selisih kas dikirim begitu kasir menutup shift.', 'default' => false],
                    ['key' => 'notify.forward_alerts', 'type' => 'bool', 'label' => 'Teruskan peringatan penting', 'hint' => 'Stok menipis, selisih kas, shift yang lupa ditutup, dan aset rusak ikut dikirim ke saluran di bawah (selain muncul di lonceng).', 'default' => false],
                ],
            ], [
                'title' => 'Telegram',
                'fields' => [
                    ['key' => 'telegram.bot_token', 'type' => 'secret', 'label' => 'Bot token', 'hint' => 'Dari @BotFather. Tidak ditampilkan lagi setelah disimpan.', 'default' => null],
                    ['key' => 'telegram.chat_id', 'type' => 'text', 'label' => 'Chat ID', 'hint' => 'Tujuan pengiriman (pribadi atau grup).', 'default' => null, 'max' => 60],
                ],
            ], [
                'title' => 'Email',
                'fields' => [
                    ['key' => 'notify.email_to', 'type' => 'text', 'label' => 'Alamat email penerima', 'hint' => 'Pisahkan dengan koma untuk lebih dari satu. Laporan harian dikirim sebagai email bergambar rapi. Pengiriman lewat Resend (isi MAIL_MAILER=resend, RESEND_API_KEY, dan MAIL_FROM_ADDRESS dengan domain yang sudah diverifikasi di file .env server) atau SMTP biasa; tanpa itu email hanya tercatat di log.', 'default' => null, 'max' => 300],
                ],
            ], [
                'title' => 'WhatsApp (lewat gateway)',
                'fields' => [
                    ['key' => 'whatsapp.token', 'type' => 'secret', 'label' => 'Token gateway', 'hint' => 'WhatsApp tidak punya jalur gratis resmi untuk pesan otomatis. Aplikasi memakai layanan gateway (fonnte.com): daftar, hubungkan nomor pengirim, lalu tempel token di sini. Biaya dan syarat mengikuti layanan itu.', 'default' => null],
                    ['key' => 'whatsapp.targets', 'type' => 'text', 'label' => 'Nomor penerima', 'hint' => 'Pisahkan dengan koma. Boleh ditulis 0812... atau 62812...', 'default' => null, 'max' => 200],
                ],
            ]]],
        ];
    }

    /** @return array<string, array<string, mixed>> field definitions keyed by setting key */
    public static function fields(): array
    {
        $out = [];
        foreach (self::tabs() as $tab) {
            foreach ($tab['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $out[$field['key']] = $field;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(fn (array $f) => $f['default'], self::fields());
    }

    /** @return array<string, list<string>> laravel validation rules per key */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::fields() as $key => $f) {
            $rules[$key] = match ($f['type']) {
                'bool' => ['nullable', 'boolean'],
                'int' => ['nullable', 'integer', 'min:'.($f['min'] ?? 0), 'max:'.($f['max'] ?? 100000)],
                'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'select' => ['nullable', 'in:'.implode(',', array_column($f['options'], 0))],
                'list' => ['nullable', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
                default => ['nullable', 'string', 'max:'.($f['max'] ?? 200)],
            };
        }

        return $rules;
    }

    /** Casts a submitted value to the type stored/read by the app. */
    public static function cast(string $key, mixed $value): mixed
    {
        $f = self::fields()[$key] ?? null;
        if ($f === null || $value === null || $value === '') {
            return $f['default'] ?? null;
        }

        return match ($f['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'select' => is_numeric($f['default']) ? (int) $value : (string) $value,
            'list' => implode(',', array_map('intval', array_map('trim', explode(',', (string) $value)))),
            default => (string) $value,
        };
    }
}
