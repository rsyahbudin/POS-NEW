<?php

namespace App\Support;

/**
 * Human-readable description of every permission, grouped for the role editor.
 * Permissions are checked by name in code; this catalog only labels them.
 */
final class PermissionCatalog
{
    /** @return array<string, array{label: string, items: array<string, array{label: string, hint: string}>}> */
    public static function groups(): array
    {
        return [
            'order' => ['label' => 'Pesanan & pembayaran', 'items' => [
                'create-order' => ['label' => 'Buat / ubah order', 'hint' => 'Input pesanan, tambah ronde, pindah meja, tandai habis.'],
                'receive-payment' => ['label' => 'Terima pembayaran', 'hint' => 'Bayar, split bill, konfirmasi/tolak pesanan QR, cetak ulang struk.'],
                'discount-within-limit' => ['label' => 'Diskon sampai batas kasir', 'hint' => 'Batas persen diatur di Pengaturan. Di atasnya butuh PIN orang yang punya izin "Diskon di atas batas".'],
                'discount-over-limit' => ['label' => 'Diskon di atas batas', 'hint' => 'Pemegang izin ini bisa menyetujui diskon lewat PIN 6 digit.'],
                'void' => ['label' => 'Void & komplimen', 'hint' => 'Void item/order dan tandai komplimen/staff meal. Peran tanpa izin ini butuh PIN dari pemegang izin.'],
                'refund' => ['label' => 'Refund', 'hint' => 'Refund pembayaran yang sudah lunas (jurnal pembalik).'],
                'update-kitchen-status' => ['label' => 'Update status dapur', 'hint' => 'Buka layar dapur dan majukan status item.'],
            ]],
            'shift' => ['label' => 'Shift & kas', 'items' => [
                'open-close-shift' => ['label' => 'Buka / tutup shift sendiri', 'hint' => 'Wajib punya shift terbuka untuk menerima pembayaran.'],
                'manage-shifts-any' => ['label' => 'Kelola shift orang lain & tutup hari', 'hint' => 'Tutup shift siapa pun, tutup hari (Z-report), pindah/gabung meja.'],
                'view-own-shift-recap' => ['label' => 'Lihat rekap shift sendiri', 'hint' => 'Rekap shift kasir.'],
                'input-expense' => ['label' => 'Input pengeluaran', 'hint' => 'Catat pengeluaran operasional.'],
            ]],
            'report' => ['label' => 'Laporan & akuntansi', 'items' => [
                'view-sales-reports' => ['label' => 'Laporan penjualan & dashboard', 'hint' => 'Dashboard, laporan penjualan, laporan diskon/void/refund.'],
                'view-accounting' => ['label' => 'Lihat laporan akuntansi', 'hint' => 'Laba rugi, neraca, arus kas, buku besar, jurnal (hanya baca).'],
                'manage-accounting' => ['label' => 'Kelola akuntansi', 'hint' => 'Jurnal manual, jurnal pembalik, tutup buku, ubah akun.'],
            ]],
            'admin' => ['label' => 'Pengaturan', 'items' => [
                'manage-menu' => ['label' => 'Kelola menu', 'hint' => 'Produk, kategori, modifier, stasiun dapur.'],
                'manage-tables' => ['label' => 'Kelola meja & QR', 'hint' => 'Area, meja, token QR, cetak QR.'],
                'manage-pricing' => ['label' => 'Harga & pajak', 'hint' => 'Cadangan untuk kontrol harga/pajak terpisah.'],
                'manage-settings' => ['label' => 'Pengaturan outlet', 'hint' => 'Profil outlet, metode bayar, pajak, promo, notifikasi.'],
                'manage-users' => ['label' => 'Kelola user', 'hint' => 'Buat user, nonaktifkan, reset password, set PIN.'],
                'manage-roles' => ['label' => 'Kelola peran & akses', 'hint' => 'Mengatur izin tiap peran (halaman ini). Sebaiknya hanya owner.'],
            ]],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(array_merge(...array_column(self::groups(), 'items')));
    }

    /** Roles shipped with the app: cannot be deleted; owner cannot be edited. */
    public const BUILT_IN = ['owner' => 'Owner', 'manager' => 'Manager / Supervisor', 'cashier' => 'Kasir', 'waiter' => 'Waiter', 'kitchen' => 'Dapur / Bar'];
}
