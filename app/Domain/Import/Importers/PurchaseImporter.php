<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;

/** One purchase (goods received) from a list of ingredient, quantity and price. New ingredients are created on the way. */
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
            ['key' => 'supplier', 'label' => 'Pemasok', 'type' => 'text'],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Isi Jumlah dan Harga per satuan untuk bahan yang dibeli. Baris yang Jumlahnya kosong dilewati.',
            '2. Semua bahan yang sudah terdaftar sudah ada di sheet Data. Pilih dari daftar atau ketik nama bahan yang sama persis.',
            '3. Bahan BARU: tulis namanya di baris kosong paling bawah dan isi Satuan (g, ml, pcs). Bahan akan dibuat otomatis.',
            '4. Isi Harga per satuan (harga 1 gram/ml/pcs) ATAU Total harga yang dibayar untuk baris itu. Cukup salah satu; kalau dua-duanya diisi, Harga per satuan dipakai.',
            '5. Tanggal dan pemasok diisi di layar impor, bukan di file.',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return $this->ingredients($outletId)->where('is_active', true)->sortBy('name')->map(fn (Ingredient $i) => [$i->name, $i->unit, '', '', ''])->values()->all();
    }

    public function lists(int $outletId): array
    {
        return ['name' => $this->ingredients($outletId)->pluck('name')->sort()->values()->all()];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = $this->ingredients($ctx->outletId);
        $canCreate = $ctx->user->can('manage-inventory');
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $qty = ImportNumber::parse($c['qty'] ?? null);
            $total = ImportNumber::parse($c['total'] ?? null);
            $cost = ImportNumber::parse($c['unit_cost'] ?? null) ?? ($total !== null && $qty !== null && $qty > 0 ? $total / $qty : null);
            $unit = $c['unit'] ?? null;
            $ing = $name === null ? null : $existing->get(mb_strtolower($name));

            if ($name !== null && ($c['qty'] ?? null) === null && ($c['unit_cost'] ?? null) === null && ($c['total'] ?? null) === null) {
                continue; // template row left empty
            }
            $error = match (true) {
                $name === null => 'Nama bahan kosong.',
                $qty === null || $qty <= 0 => 'Jumlah harus angka lebih dari 0.',
                $cost === null || $cost < 0 => 'Isi Harga per satuan atau Total harga (angka).',
                $ing === null && ! $canCreate => "Bahan \"{$name}\" belum terdaftar. Minta manager menambahkannya.",
                $ing === null && $unit === null => "Bahan \"{$name}\" belum terdaftar: isi Satuan agar dibuat otomatis.",
                $ing === null && mb_strlen((string) $unit) > 12 => 'Satuan terlalu panjang (maks 12 huruf).',
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $out[] = $this->result($row['line'], $ing === null ? 'new' : 'ok', $ing === null ? 'Bahan baru akan dibuat.' : null, $c, ['name' => $name, 'unit' => $unit, 'qty' => $qty, 'unit_cost' => $cost]);
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
                $ing = Ingredient::create(['outlet_id' => $ctx->outletId, 'name' => $rec['name'], 'unit' => $rec['unit']]);
                $existing->put(mb_strtolower($rec['name']), $ing);
                $created++;
            }
            $items[] = ['ingredient_id' => $ing->id, 'qty' => $rec['qty'], 'unit_cost' => $rec['unit_cost']];
        }
        $date = (string) ($ctx->extra['purchased_on'] ?? '') ?: BusinessDate::for();
        $purchase = app(InventoryService::class)->submitPurchase($ctx->outletId, [
            'supplier' => ($ctx->extra['supplier'] ?? null) ?: null, 'purchased_on' => $date, 'note' => 'Impor Excel', 'items' => $items,
        ], $ctx->user);
        Audit::log('import.purchase', $purchase, ['items' => count($items), 'new_ingredients' => $created], $ctx->user);

        if ($purchase->status === 'pending') {
            return 'Belanja dikirim ('.count($items).' bahan). Stok bertambah setelah disetujui manager.';
        }

        return 'Pembelian dicatat: '.count($items).' bahan'.($created > 0 ? " ({$created} bahan baru dibuat)" : '').', total Rp '.number_format($purchase->total, 0, ',', '.').'.';
    }
}
