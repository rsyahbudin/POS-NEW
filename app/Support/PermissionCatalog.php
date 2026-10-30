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
                'discount-custom' => ['label' => 'Diskon manual (isi sendiri)', 'hint' => 'Boleh mengetik nilai diskon sendiri. Cabut dari peran kasir agar kasir hanya bisa memilih diskon yang sudah terdaftar.'],
                'discount-over-limit' => ['label' => 'Diskon di atas batas', 'hint' => 'Pemegang izin ini bisa menyetujui diskon lewat PIN 6 digit.'],
                'void' => ['label' => 'Batalkan item/order & komplimen', 'hint' => 'Membatalkan item atau order yang sudah masuk, dan menandai komplimen/staff meal. Peran tanpa izin ini butuh PIN dari pemegang izin.'],
                'refund' => ['label' => 'Refund', 'hint' => 'Refund pembayaran yang sudah lunas (jurnal pembalik).'],
                'cancel-order' => ['label' => 'Batalkan pesanan / item (belum dibayar)', 'hint' => 'Batalkan sendiri dengan alasan wajib (tercatat di laporan). Peran tanpa izin ini butuh PIN dari pemegang izin "Batalkan item/order & komplimen".'],
                'use-print-station' => ['label' => 'Pakai Stasiun Cetak', 'hint' => 'Membuka halaman Stasiun Cetak untuk mencetak antrean struk, bill, dan tiket.'],
                'update-kitchen-status' => ['label' => 'Update status dapur', 'hint' => 'Buka layar dapur dan majukan status item.'],
            ]],
            'shift' => ['label' => 'Shift & kas', 'items' => [
                'open-close-shift' => ['label' => 'Buka / tutup shift sendiri', 'hint' => 'Wajib punya shift terbuka untuk menerima pembayaran.'],
                'manage-shifts-any' => ['label' => 'Kelola shift orang lain & tutup hari', 'hint' => 'Tutup shift siapa pun, buka kembali shift, pindah/gabung meja.'],
                'view-own-shift-recap' => ['label' => 'Lihat rekap shift sendiri', 'hint' => 'Rekap shift kasir.'],
                'view-cash-ledger' => ['label' => 'Lihat laporan kas laci', 'hint' => 'Laporan per shift: modal, penjualan tunai, kas masuk/keluar, belanja tunai per kategori beserta foto nota, selisih, dan setoran. Bisa diunduh Excel/PDF.'],
                'input-expense' => ['label' => 'Input pengeluaran', 'hint' => 'Catat pengeluaran operasional.'],
            ]],
            'report' => ['label' => 'Laporan & akuntansi', 'items' => [
                'view-sales-reports' => ['label' => 'Laporan penjualan & dashboard', 'hint' => 'Dashboard, laporan penjualan, laporan diskon/pembatalan/refund.'],
                'view-kitchen-report' => ['label' => 'Laporan kinerja dapur', 'hint' => 'Berapa menit dapur menyelesaikan pesanan.'],
                'view-margin-report' => ['label' => 'Laporan margin & HPP', 'hint' => 'Biaya bahan, laba kotor, dan nilai stok. Data sensitif, batasi ke owner/manager.'],
                'view-activity-log' => ['label' => 'Lihat jejak aktivitas', 'hint' => 'Siapa melakukan apa dan kapan: pesanan, pembayaran, shift, stok, pengaturan.'],
                'view-accounting' => ['label' => 'Lihat laporan akuntansi', 'hint' => 'Laba rugi, neraca, arus kas, buku besar, jurnal (hanya baca).'],
                'manage-accounting' => ['label' => 'Kelola akuntansi', 'hint' => 'Jurnal manual, jurnal pembalik, tutup buku, ubah akun.'],
            ]],
            'admin' => ['label' => 'Pengaturan', 'items' => [
                'manage-menu' => ['label' => 'Kelola menu', 'hint' => 'Produk, kategori, modifier, stasiun dapur.'],
                'manage-inventory' => ['label' => 'Kelola stok, bahan & resep', 'hint' => 'Bahan baku, pembelian, opname, resep, dan pengaturan HPP.'],
                'manage-printers' => ['label' => 'Kelola printer', 'hint' => 'Daftar printer dan routing struk, bill, dan tiket per kategori/menu.'],
                'view-stock' => ['label' => 'Lihat daftar stok', 'hint' => 'Daftar bahan dan jumlah stok (tanpa harga/biaya).'],
                'count-stock' => ['label' => 'Stok opname', 'hint' => 'Menghitung stok bahan dan mengirim hasilnya. Persetujuan diatur di Pengaturan > Stok & HPP.'],
                'record-purchase' => ['label' => 'Catat belanja bahan', 'hint' => 'Karyawan yang berbelanja mencatat bahan yang dibeli (jumlah dan harga). Persetujuan diatur di Pengaturan > Stok & HPP.'],
                'view-assets' => ['label' => 'Lihat daftar aset & alat', 'hint' => 'Melihat daftar peralatan, kondisi, dan riwayat servis.'],
                'manage-assets' => ['label' => 'Kelola aset & alat', 'hint' => 'Tambah/ubah/hapus aset, catat servis dan perbaikan.'],
                'import-data' => ['label' => 'Impor dari Excel', 'hint' => 'Mengunggah template Excel untuk bahan, pembelian, resep, menu, dan aset. Tetap butuh izin kelola layar yang bersangkutan.'],
                'manage-suppliers' => ['label' => 'Kelola data pemasok', 'hint' => 'Tambah/ubah pemasok, lihat riwayat belanja dan perbandingan harga.'],
                'view-today-summary' => ['label' => 'Lihat ringkasan penjualan hari ini', 'hint' => 'Halaman Hari Ini dari menu akun: omzet, jumlah transaksi, penjualan per jam, menu terlaris. Untuk staf tanpa akses dashboard.'],
                'reopen-shift' => ['label' => 'Buka kembali shift yang tertutup', 'hint' => 'Membatalkan penutupan shift (salah pencet atau hitung ulang). Tanpa izin Kelola shift orang lain, hanya untuk shift yang ditutup sendiri dalam 30 menit.'],
                'handle-calls' => ['label' => 'Tangani panggilan meja', 'hint' => 'Melihat dan menandai selesai panggilan "Panggil waiter" dari pelanggan, dengan bunyi di layar kasir atau dapur.'],
                'mark-sold-out' => ['label' => 'Tandai menu habis', 'hint' => 'Menandai menu habis / tersedia lagi dari layar kasir. Menu habis ikut hilang dari QR pelanggan.'],
                'record-waste' => ['label' => 'Catat bahan / menu terbuang', 'hint' => 'Mencatat waste dengan alasan. Stok berkurang dan nilainya masuk laporan waste.'],
                'export-receipts' => ['label' => 'Unduh struk satu bulan (PDF)', 'hint' => 'Membuat satu PDF berisi semua struk di bulan dan tahun yang dipilih, termasuk bulan yang sudah lewat.'],
                'view-waste-report' => ['label' => 'Laporan waste', 'hint' => 'Nilai rupiah dan penyebab bahan terbuang.'],
                'manage-online-payments' => ['label' => 'Pembayaran QRIS online', 'hint' => 'Melihat daftar pembayaran QRIS dari HP pelanggan, mencatat ulang yang tertahan, dan menerima peringatannya.'],
                'manage-discount-presets' => ['label' => 'Kelola diskon & promo', 'hint' => 'Halaman Diskon & Promo: diskon bernama yang dipilih kasir (mis. Staff 10%) dan promo terjadwal (Happy Hour).'],
                'manage-tables' => ['label' => 'Kelola meja & QR', 'hint' => 'Area, meja, token QR, cetak QR.'],
                'manage-pricing' => ['label' => 'Pembayaran & pajak', 'hint' => 'Halaman Pembayaran & Pajak: metode bayar, pajak, dan service charge.'],
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
