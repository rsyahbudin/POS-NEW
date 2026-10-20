<?php

namespace App\Domain\Settings;

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
                'title' => 'Hari bisnis & harga',
                'fields' => [
                    ['key' => 'operations.business_day_start', 'type' => 'time', 'label' => 'Jam mulai hari bisnis', 'hint' => 'Transaksi sebelum jam ini masuk ke tanggal bisnis kemarin (mis. 04:00 agar operasional lewat tengah malam tetap satu hari).', 'default' => '04:00'],
                    ['key' => 'pricing.rounding', 'type' => 'select', 'label' => 'Pembulatan grand total', 'hint' => 'Selisih pembulatan dicatat di akun tersendiri.', 'default' => 0, 'options' => [['0', 'Tanpa pembulatan'], ['100', 'Ke Rp100 terdekat'], ['500', 'Ke Rp500 terdekat']]],
                    ['key' => 'shift.shared_for_managers', 'type' => 'bool', 'label' => 'Manager/owner boleh menerima pembayaran lewat shift kasir yang sedang terbuka', 'hint' => 'Aktif: owner/manager yang tidak punya shift sendiri memakai shift kasir yang terbuka (uang masuk tercatat di shift itu, penerima tetap nama yang login). Nonaktif: setiap orang wajib membuka shift sendiri.', 'default' => true],
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
                'title' => 'Anti-spam',
                'fields' => [
                    ['key' => 'qr.max_submits_per_window', 'type' => 'int', 'label' => 'Maksimal kiriman per meja', 'hint' => 'Jumlah pesanan yang boleh dikirim satu meja dalam rentang waktu di bawah.', 'default' => 5, 'min' => 1, 'max' => 50],
                    ['key' => 'qr.window_minutes', 'type' => 'int', 'label' => 'Rentang waktu anti-spam (menit)', 'hint' => 'Contoh: 5 kiriman per 10 menit.', 'default' => 10, 'min' => 1, 'max' => 120],
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
                'title' => 'PIN manager',
                'fields' => [
                    ['key' => 'security.pin_max_attempts', 'type' => 'int', 'label' => 'Salah PIN maksimal', 'hint' => 'Setelah sebanyak ini salah, kasir terkunci sementara.', 'default' => 5, 'min' => 1, 'max' => 20],
                    ['key' => 'security.pin_lock_minutes', 'type' => 'int', 'label' => 'Lama terkunci (menit)', 'hint' => 'Kasir tidak bisa memakai PIN manager selama ini.', 'default' => 15, 'min' => 1, 'max' => 1440],
                ],
            ]]],
            ['key' => 'notif', 'label' => 'Notifikasi', 'groups' => [[
                'title' => 'Telegram (Z-report tutup hari)',
                'fields' => [
                    ['key' => 'telegram.bot_token', 'type' => 'secret', 'label' => 'Bot token', 'hint' => 'Dari @BotFather. Tidak ditampilkan lagi setelah disimpan.', 'default' => null],
                    ['key' => 'telegram.chat_id', 'type' => 'text', 'label' => 'Chat ID owner', 'hint' => 'Tujuan pengiriman rekap harian.', 'default' => null, 'max' => 60],
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
