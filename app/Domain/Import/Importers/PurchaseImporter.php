<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Units;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;

/**
 * One purchase from a list of ingredient, quantity, unit and price. The unit must be one that is defined for the
 * ingredient (its base unit, kg / liter, or a packaging such as "botol"); quantities are converted to the base unit.
 */
class PurchaseImporter extends Importer
{
    public function key(): string
    {
        return 'purchase';
    }

    /** Managers (with import permission) and shopping staff. */
    public function allows(User $user): bool
    {
        return ($user->can('manage-inventory') && $user->can('import-data')) || $user->can('record-purchase');
    }

    public function title(): string
    {
        return 'Pembelian stok';
    }

    public function permission(): string
    {
        return 'manage-inventory';
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Bahan', 'aliases' => ['nama bahan', 'nama', 'item'], 'required' => true],
            'unit' => ['label' => 'Satuan', 'aliases' => ['unit']],
            'qty' => ['label' => 'Jumlah', 'aliases' => ['qty', 'kuantitas'], 'required' => true],
            'unit_cost' => ['label' => 'Harga per satuan', 'aliases' => ['harga satuan', 'harga']],
            'total' => ['label' => 'Total harga', 'aliases' => ['total', 'jumlah harga', 'bayar']],
        ];
    }

    public function extraFields(): array
    {
        return [
            ['key' => 'purchased_on', 'label' => 'Tanggal pembelian', 'type' => 'date', 'default' => now()->toDateString()],
            ['key' => 'supplier', 'label' => 'Pemasok (nama)', 'type' => 'text'],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Isi Jumlah dan harga untuk bahan yang dibeli. Baris yang Jumlahnya kosong dilewati.',
            '2. Satuan = satuan dari angka Jumlah. Pilih dari daftar: satuan dasar bahan (g, ml, pcs), kg / liter, atau kemasan yang sudah ditentukan (mis. botol, dus). Satuan di luar daftar ditolak.',
            '3. Contoh: sirup disimpan dalam ml. Beli 2 botol (1 botol = 750 ml): Satuan "botol", Jumlah 2. Sistem mengubahnya jadi 1.500 ml.',
            '4. Isi Harga per satuan (harga 1 satuan yang Anda tulis, mis. per botol) ATAU Total harga yang dibayar untuk baris itu. Salah satu saja; kalau dua-duanya diisi, Harga per satuan dipakai.',
            '5. Bahan BARU hanya bisa ditambahkan manager: tulis nama dan satuan dasar (g, ml, atau pcs). Kemasan diatur di halaman Stok & Bahan.',
            '6. Tanggal dan pemasok diisi di layar impor, bukan di file.',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return $this->ingredients($outletId)->where('is_active', true)->sortBy('name')->map(fn (Ingredient $i) => [$i->name, $i->unit, '', '', ''])->values()->all();
    }

    public function lists(int $outletId): array
    {
        $units = [];
        foreach ($this->ingredients($outletId)->where('is_active', true) as $i) {
            foreach (Units::choices($i) as $choice) {
                $units[$choice['name']] = true;
            }
        }

        return ['name' => $this->ingredients($outletId)->pluck('name')->sort()->values()->all(), 'unit' => array_keys($units)];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = $this->ingredients($ctx->outletId);
        $canCreate = $ctx->user->can('manage-inventory');
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            if ($name !== null && ($c['qty'] ?? null) === null && ($c['unit_cost'] ?? null) === null && ($c['total'] ?? null) === null) {
                continue; // template row left empty
            }
            $qty = ImportNumber::parse($c['qty'] ?? null);
            $perUnit = ImportNumber::parse($c['unit_cost'] ?? null);
            $total = ImportNumber::parse($c['total'] ?? null);
            $unit = $c['unit'] ?? null;
            $ing = $name === null ? null : $existing->get(mb_strtolower($name));
            $baseUnit = $ing === null ? Units::base($unit) : $ing->unit;
            $baseQty = null;
            $unitError = null;
            if ($ing !== null && $qty !== null && $qty > 0) {
                [$baseQty, $unitError] = $this->toBase($ing, $unit, $qty);
            } elseif ($ing === null && $qty !== null) {
                $baseQty = $qty;
            }
            $price = $perUnit !== null ? $perUnit : $total;

            $error = match (true) {
                $name === null => 'Nama bahan kosong.',
                $qty === null || $qty <= 0 => 'Jumlah harus angka lebih dari 0.',
                $price === null || $price < 0 => 'Isi Harga per satuan atau Total harga (angka).',
                $ing === null && ! $canCreate => "Bahan \"{$name}\" belum terdaftar. Minta manager menambahkannya.",
                $ing === null && $unit === null => "Bahan \"{$name}\" belum terdaftar: isi Satuan dasar (g, ml, atau pcs).",
                $ing === null && $baseUnit === null => "Satuan \"{$unit}\" tidak dikenal untuk bahan baru. Pakai g, ml, atau pcs.",
                $unitError !== null => $unitError,
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $baseQty ??= $qty;
            $factor = $qty > 0 ? $baseQty / $qty : 1.0;
            $out[] = $this->result($row['line'], $ing === null ? 'new' : 'ok', $ing === null ? 'Bahan baru akan dibuat.' : ($factor !== 1.0 ? "= {$baseQty} {$ing->unit}" : null), $c, [
                'name' => $name, 'base_unit' => $baseUnit, 'qty' => $baseQty,
                'unit_cost' => $perUnit !== null ? $perUnit / $factor : (float) $total / $baseQty,
            ]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $existing = $this->ingredients($ctx->outletId);
        $items = [];
        $created = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $ing = $existing->get(mb_strtolower($rec['name']));
            if ($ing === null) {
                $ing = Ingredient::create(['outlet_id' => $ctx->outletId, 'name' => $rec['name'], 'unit' => $rec['base_unit']]);
                $existing->put(mb_strtolower($rec['name']), $ing);
                $created++;
            }
            $items[] = ['ingredient_id' => $ing->id, 'qty' => $rec['qty'], 'unit_cost' => $rec['unit_cost']];
        }
        $date = (string) ($ctx->extra['purchased_on'] ?? '') ?: BusinessDate::for();
        $supplierName = trim((string) ($ctx->extra['supplier'] ?? ''));
        $supplier = $supplierName === '' ? null : Supplier::query()->where('outlet_id', $ctx->outletId)->whereRaw('lower(name) = ?', [mb_strtolower($supplierName)])->first();
        if ($supplier === null && $supplierName !== '' && $ctx->user->can('manage-suppliers')) {
            $supplier = Supplier::create(['outlet_id' => $ctx->outletId, 'name' => $supplierName]);
        }
        $purchase = app(InventoryService::class)->submitPurchase($ctx->outletId, [
            'supplier' => $supplierName !== '' ? $supplierName : null, 'supplier_id' => $supplier?->id, 'purchased_on' => $date, 'note' => 'Impor Excel', 'items' => $items,
        ], $ctx->user);
        Audit::log('import.purchase', $purchase, ['items' => count($items), 'new_ingredients' => $created], $ctx->user);

        if ($purchase->status === 'pending') {
            return 'Belanja dikirim ('.count($items).' bahan). Stok bertambah setelah disetujui manager.';
        }

        return 'Pembelian dicatat: '.count($items).' bahan'.($created > 0 ? " ({$created} bahan baru dibuat)" : '').', total Rp '.number_format($purchase->total, 0, ',', '.').'.';
    }
}
