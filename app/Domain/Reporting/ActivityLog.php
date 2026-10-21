<?php

namespace App\Domain\Reporting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * "Who did what, when": the audit trail turned into readable rows (actor, action, detail) with filters.
 */
class ActivityLog
{
    /** event prefix => group */
    private const GROUPS = [
        'pesanan' => ['order.', 'item.', 'items.', 'qr.', 'bill.', 'discount.', 'table.', 'tables.'],
        'pembayaran' => ['payment.', 'receipt.', 'journal.'],
        'shift' => ['shift.', 'cash.', 'day.', 'period.'],
        'stok' => ['stock.', 'inventory.', 'ingredient.', 'recipe.'],
        'pengeluaran' => ['expense.'],
        'aset' => ['asset.'],
        'menu' => ['product.', 'category.', 'printer.'],
        'pengaturan' => ['settings.', 'role.', 'user.', 'tax.', 'pin.'],
        'impor' => ['import.'],
    ];

    public const GROUP_LABELS = [
        'pesanan' => 'Pesanan & bill', 'pembayaran' => 'Pembayaran & jurnal', 'shift' => 'Shift & kas', 'stok' => 'Stok & resep', 'pengeluaran' => 'Pengeluaran',
        'aset' => 'Aset', 'menu' => 'Menu & printer', 'pengaturan' => 'Pengaturan & user', 'impor' => 'Impor Excel',
    ];

    private const EVENTS = [
        'order.placed' => 'Input pesanan', 'order.voided' => 'Batalkan order', 'order.closed_zero' => 'Tutup order Rp0', 'qr.submitted' => 'Pesanan dari QR meja',
        'qr.confirmed' => 'Konfirmasi pesanan QR', 'qr.rejected' => 'Tolak pesanan QR', 'item.voided' => 'Batalkan item', 'item.comp' => 'Komplimen / staff meal',
        'item.status' => 'Ubah status dapur', 'items.moved' => 'Pindahkan item', 'tables.merged' => 'Gabung meja', 'table.moved' => 'Pindah meja',
        'bill.requested' => 'Minta bill (pre-bill)', 'bill.split' => 'Pecah bill', 'bill.split_cancelled' => 'Satukan bill', 'bill.carved' => 'Bayar sebagian item',
        'discount.applied' => 'Beri diskon', 'discount.removed' => 'Hapus diskon', 'payment.received' => 'Terima pembayaran', 'payment.refunded' => 'Refund',
        'receipt.reprinted' => 'Cetak ulang struk', 'journal.manual' => 'Jurnal manual', 'journal.reversed' => 'Jurnal pembalik', 'shift.opened' => 'Buka shift',
        'shift.closed' => 'Tutup shift', 'cash.movement' => 'Kas masuk/keluar', 'day.closed' => 'Tutup hari', 'period.closed' => 'Tutup buku', 'stock.count_submitted' => 'Kirim hitungan stok',
        'stock.count_approved' => 'Setujui hitungan stok', 'stock.count_rejected' => 'Tolak hitungan stok', 'inventory.purchase' => 'Pembelian masuk stok',
        'inventory.purchase_submitted' => 'Kirim belanja bahan', 'inventory.purchase_rejected' => 'Tolak belanja bahan', 'inventory.counted' => 'Opname bahan',
        'inventory.waste' => 'Bahan terbuang', 'ingredient.created' => 'Tambah bahan', 'recipe.saved' => 'Ubah resep', 'recipe.modifier_saved' => 'Ubah bahan modifier',
        'expense.recorded' => 'Catat pengeluaran', 'asset.created' => 'Tambah aset', 'asset.updated' => 'Ubah aset', 'asset.deleted' => 'Hapus aset', 'asset.log' => 'Riwayat aset',
        'product.price_changed' => 'Ubah harga menu', 'product.availability' => 'Tandai habis / tersedia', 'printer.created' => 'Tambah printer', 'printer.updated' => 'Ubah printer',
        'printer.deleted' => 'Hapus printer', 'category.printers_changed' => 'Ubah printer kategori', 'settings.changed' => 'Ubah pengaturan', 'settings.outlet' => 'Ubah data outlet',
        'settings.payment_method' => 'Ubah metode bayar', 'settings.promotion' => 'Ubah promo', 'tax.changed' => 'Ubah pajak', 'user.created' => 'Tambah user',
        'user.role_changed' => 'Ubah peran user', 'user.password_reset' => 'Reset password', 'user.pin_set' => 'Atur PIN', 'role.created' => 'Buat peran', 'role.deleted' => 'Hapus peran',
        'role.permissions_changed' => 'Ubah izin peran', 'role.pin_login_changed' => 'Ubah login PIN peran', 'pin.failed' => 'PIN salah', 'import.ingredients' => 'Impor bahan',
        'import.purchase' => 'Impor pembelian', 'import.products' => 'Impor menu', 'import.recipes' => 'Impor resep', 'import.assets' => 'Impor aset',
    ];

    private const KEYS = [
        'order' => 'Order', 'where' => 'Di', 'items' => 'Item', 'item' => 'Item', 'status' => 'Status', 'bill' => 'Bill', 'method' => 'Metode', 'amount' => 'Nominal',
        'tendered' => 'Diterima', 'change' => 'Kembalian', 'reason' => 'Alasan', 'table' => 'Meja', 'customer' => 'Pelanggan', 'direction' => 'Arah', 'category' => 'Kategori',
        'note' => 'Catatan', 'expected' => 'Seharusnya', 'counted' => 'Dihitung', 'difference' => 'Selisih', 'deposit' => 'Setoran', 'total' => 'Total', 'name' => 'Nama',
        'lines' => 'Baris', 'qty' => 'Jumlah', 'from' => 'Dari', 'to' => 'Menjadi', 'ingredient' => 'Bahan', 'new' => 'Baru', 'updated' => 'Diperbarui', 'mode' => 'Cara',
    ];

    private const MONEY = ['amount', 'tendered', 'change', 'expected', 'counted', 'difference', 'deposit', 'total'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $from, string $to, ?int $userId, ?string $group, ?string $q, int $limit = 800): array
    {
        $query = Activity::query()->where('log_name', 'audit')
            ->where('created_at', '>=', CarbonImmutable::parse($from, 'Asia/Jakarta')->startOfDay())
            ->where('created_at', '<=', CarbonImmutable::parse($to, 'Asia/Jakarta')->endOfDay())
            ->with('causer')->latest('id');
        if ($userId) {
            $query->where('causer_type', User::class)->where('causer_id', $userId);
        }
        if ($group && isset(self::GROUPS[$group])) {
            $prefixes = self::GROUPS[$group];
            $query->where(function ($w) use ($prefixes) {
                foreach ($prefixes as $p) {
                    $w->orWhere('event', 'like', $p.'%');
                }
            });
        }

        $rows = $query->limit($limit)->get()->map(function (Activity $a) {
            $event = (string) $a->event;
            $causer = $a->causer;

            return [
                'id' => $a->id,
                'at' => $a->created_at?->setTimezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'actor' => $causer instanceof User ? $causer->name : ($event === 'qr.submitted' ? 'Pelanggan (QR)' : 'Sistem'),
                'event' => self::EVENTS[$event] ?? $event,
                'group' => $this->groupOf($event),
                'detail' => $this->detail($a->properties?->toArray() ?? []),
            ];
        });

        if ($q) {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['actor'].' '.$r['event'].' '.$r['detail']), $needle));
        }

        return $rows->values()->all();
    }

    private function groupOf(string $event): string
    {
        foreach (self::GROUPS as $group => $prefixes) {
            foreach ($prefixes as $p) {
                if (str_starts_with($event, $p)) {
                    return self::GROUP_LABELS[$group];
                }
            }
        }

        return 'Lainnya';
    }

    /** @param  array<string, mixed>  $props */
    private function detail(array $props): string
    {
        $parts = [];
        foreach ($props as $key => $value) {
            if (! is_scalar($value) || (is_bool($value) && ! $value) || $value === '') {
                continue;
            }
            $label = self::KEYS[$key] ?? null;
            if ($label === null) {
                continue;
            }
            $text = is_bool($value) ? 'ya' : (in_array($key, self::MONEY, true) && is_numeric($value) ? 'Rp '.number_format((int) $value, 0, ',', '.') : (string) $value);
            $parts[] = "{$label}: {$text}";
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return self::GROUP_LABELS;
    }
}
