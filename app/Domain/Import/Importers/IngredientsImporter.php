<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Inventory\Units;
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
            'pack_name' => ['label' => 'Kemasan beli', 'aliases' => ['kemasan']],
            'pack_size' => ['label' => 'Isi kemasan', 'aliases' => ['isi']],
            'min_stock' => ['label' => 'Batas menipis', 'aliases' => ['batas stok', 'minimum', 'stok minimum']],
            'stock' => ['label' => 'Stok awal', 'aliases' => ['stok']],
            'unit_cost' => ['label' => 'Harga per satuan', 'aliases' => ['harga satuan', 'harga']],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Isi satu baris per bahan. Kolom bertanda * wajib.',
            '2. Satuan adalah satuan DASAR yang dipakai stok dan resep, dan hanya boleh g (gram), ml (mililiter), atau pcs (buah). Pilih dari daftar. Contoh: sirup = ml, gula = g, telur = pcs.',
            '3. Bahan yang sudah ada sudah terisi di sheet Data. Satuan dasar bahan yang sudah punya stok, riwayat, atau resep tidak bisa diubah.',
            '4. Kemasan beli (opsional): kemasan saat membeli, mis. "botol", dengan Isi kemasan dalam satuan dasar, mis. 750 (1 botol = 750 ml). Saat belanja atau opname orang boleh memilih "botol" dan sistem mengubahnya ke ml. Satu kemasan per baris; kemasan lain tambah di halaman Stok & Bahan.',
            '5. Untuk bahan BARU tulis di baris kosong paling bawah. Stok awal dan harga per satuan dasar hanya dipakai untuk bahan baru; stok bahan yang sudah ada diubah lewat Pembelian atau Stok opname.',
            '6. Angka boleh ditulis 1500, 1.500, atau 1,5. Jangan menulis "Rp".',
        ];
    }

    public function templateRows(int $outletId): array
    {
        $packs = IngredientUnit::query()->whereIn('ingredient_id', $this->ingredients($outletId)->pluck('id'))->get()->groupBy('ingredient_id');

        return $this->ingredients($outletId)->sortBy('name')->map(function (Ingredient $i) use ($packs) {
            $pack = $packs->get($i->id)?->first();

            return [$i->name, $i->unit, $pack->name ?? '', $pack->factor ?? '', $i->min_stock, '', ''];
        })->values()->all();
    }

    public function lists(int $outletId): array
    {
        return ['unit' => array_column(Units::suggestions($outletId), 'key')];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = $this->ingredients($ctx->outletId);
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $unitRaw = $c['unit'] ?? null;
            $unit = $unitRaw !== null && Units::isValidName($unitRaw) ? Units::normalize($unitRaw) : null;
            $packName = $c['pack_name'] ?? null;
            $packSize = ImportNumber::parse($c['pack_size'] ?? null);
            $current = $name === null ? null : $existing->get(mb_strtolower($name));
            $min = ImportNumber::parse($c['min_stock'] ?? null);
            $stock = ImportNumber::parse($c['stock'] ?? null);
            $cost = ImportNumber::parse($c['unit_cost'] ?? null);
            $key = mb_strtolower((string) $name);

            $error = match (true) {
                $name === null => 'Nama bahan kosong.',
                mb_strlen($name) > 80 => 'Nama bahan terlalu panjang.',
                $unitRaw === null => 'Satuan kosong. Isi g, ml, pcs, atau satuan lain (mis. ikat).',
                $unit === null => "Satuan \"{$unitRaw}\" tidak valid. Mulai dengan huruf, maksimal 12 karakter. kg dan liter bukan satuan dasar: pakai g atau ml.",
                $current !== null && $current->unit !== $unit => "Satuan dasar {$current->name} tidak bisa diubah dari {$current->unit} ke {$unit} lewat impor.",
                $packName !== null && (mb_strlen($packName) > 20 || in_array(mb_strtolower($packName), ['g', 'ml', 'pcs', 'kg', 'l', 'liter', 'gram', 'mililiter', 'buah'], true)) => 'Nama kemasan tidak boleh sama dengan satuan dasar dan maksimal 20 huruf.',
                $packName !== null && ($packSize === null || $packSize <= 0) => 'Isi kemasan harus angka lebih dari 0.',
                $packName === null && ($c['pack_size'] ?? null) !== null => 'Isi Kemasan beli (nama kemasan) bila Isi kemasan diisi.',
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
            $out[] = $this->result($row['line'], $isNew ? 'new' : 'update', $isNew ? null : 'Bahan sudah ada: batas menipis dan kemasan diperbarui.', $c, [
                'name' => $name, 'unit' => $unit, 'min_stock' => $min ?? 0.0, 'stock' => $stock ?? 0.0, 'unit_cost' => $cost ?? 0.0, 'is_new' => $isNew,
                'pack_name' => $packName, 'pack_size' => $packSize,
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
                $ing->update(['min_stock' => $rec['min_stock']]);
                $updated++;
            }
            if ($rec['pack_name'] !== null) {
                IngredientUnit::query()->updateOrCreate(['ingredient_id' => $ing->id, 'name' => $rec['pack_name']], ['factor' => $rec['pack_size']]);
            }
        }
        Audit::log('import.ingredients', null, ['new' => $new, 'updated' => $updated], $ctx->user);

        return "Impor selesai: {$new} bahan baru, {$updated} diperbarui.";
    }
}
