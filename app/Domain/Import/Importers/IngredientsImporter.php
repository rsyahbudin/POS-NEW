<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Support\Audit;

/** Ingredient master list: add new ones (with opening stock and price) and update unit / minimum of existing ones. */
class IngredientsImporter extends Importer
{
    public function key(): string
    {
        return 'ingredients';
    }

    public function title(): string
    {
        return 'Daftar bahan';
    }

    public function permission(): string
    {
        return 'manage-inventory';
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Bahan', 'aliases' => ['nama bahan', 'nama'], 'required' => true],
            'unit' => ['label' => 'Satuan', 'aliases' => ['unit'], 'required' => true],
            'min_stock' => ['label' => 'Batas menipis', 'aliases' => ['batas stok', 'minimum', 'stok minimum']],
            'stock' => ['label' => 'Stok awal', 'aliases' => ['stok']],
            'unit_cost' => ['label' => 'Harga per satuan', 'aliases' => ['harga satuan', 'harga']],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Isi satu baris per bahan. Kolom bertanda * wajib.',
            '2. Bahan yang sudah ada di sistem sudah terisi di sheet Data. Ubah Satuan atau Batas menipis kalau perlu.',
            '3. Untuk bahan BARU, tulis di baris kosong paling bawah: nama, satuan (g, ml, pcs), batas menipis, stok awal, dan harga per satuan.',
            '4. Stok awal dan harga hanya dipakai untuk bahan baru. Stok bahan yang sudah ada diubah lewat Pembelian atau Stok opname.',
            '5. Angka boleh ditulis 1500, 1.500, atau 1,5. Jangan menulis "Rp".',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return $this->ingredients($outletId)->sortBy('name')->map(fn (Ingredient $i) => [$i->name, $i->unit, $i->min_stock, '', ''])->values()->all();
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = $this->ingredients($ctx->outletId);
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $unit = $c['unit'] ?? null;
            $min = ImportNumber::parse($c['min_stock'] ?? null);
            $stock = ImportNumber::parse($c['stock'] ?? null);
            $cost = ImportNumber::parse($c['unit_cost'] ?? null);
            $key = mb_strtolower((string) $name);

            $error = match (true) {
                $name === null => 'Nama bahan kosong.',
                mb_strlen($name) > 80 => 'Nama bahan terlalu panjang.',
                $unit === null => 'Satuan kosong.',
                mb_strlen($unit) > 12 => 'Satuan terlalu panjang (maks 12 huruf).',
                isset($seen[$key]) => "Bahan \"{$name}\" muncul dua kali di file.",
                ($c['min_stock'] ?? null) !== null && ($min === null || $min < 0) => 'Batas menipis harus angka.',
                ($c['stock'] ?? null) !== null && ($stock === null || $stock < 0) => 'Stok awal harus angka.',
                ($c['unit_cost'] ?? null) !== null && ($cost === null || $cost < 0) => 'Harga harus angka.',
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $seen[$key] = true;
            $isNew = ! $existing->has($key);
            $out[] = $this->result($row['line'], $isNew ? 'new' : 'update', $isNew ? null : 'Bahan sudah ada: satuan dan batas menipis diperbarui.', $c, [
                'name' => $name, 'unit' => $unit, 'min_stock' => $min ?? 0.0, 'stock' => $stock ?? 0.0, 'unit_cost' => $cost ?? 0.0, 'is_new' => $isNew,
            ]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $existing = $this->ingredients($ctx->outletId);
        $inventory = app(InventoryService::class);
        $new = 0;
        $updated = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $ing = $existing->get(mb_strtolower($rec['name']));
            if ($ing === null) {
                $ing = Ingredient::create(['outlet_id' => $ctx->outletId, 'name' => $rec['name'], 'unit' => $rec['unit'], 'min_stock' => $rec['min_stock'], 'avg_cost' => $rec['unit_cost']]);
                if ($rec['stock'] > 0) {
                    $inventory->count($ing, $rec['stock'], $ctx->user, 'Stok awal (impor Excel)');
                }
                $new++;
            } else {
                $ing->update(['unit' => $rec['unit'], 'min_stock' => $rec['min_stock']]);
                $updated++;
            }
        }
        Audit::log('import.ingredients', null, ['new' => $new, 'updated' => $updated], $ctx->user);

        return "Impor selesai: {$new} bahan baru, {$updated} diperbarui.";
    }
}
