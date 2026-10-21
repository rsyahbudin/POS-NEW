<?php

namespace App\Domain\Import\Importers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Support\Audit;
use Illuminate\Support\Collection;

/** Recipes: one row per ingredient of a menu item. Menu items listed in the file get their whole recipe replaced. */
class RecipesImporter extends Importer
{
    public function key(): string
    {
        return 'recipes';
    }

    public function title(): string
    {
        return 'Resep menu';
    }

    public function permission(): string
    {
        return 'manage-inventory';
    }

    public function columns(): array
    {
        return [
            'product' => ['label' => 'Menu', 'aliases' => ['nama menu', 'produk', 'nama'], 'required' => true],
            'variant' => ['label' => 'Varian', 'aliases' => ['ukuran']],
            'ingredient' => ['label' => 'Bahan', 'aliases' => ['nama bahan'], 'required' => true],
            'unit' => ['label' => 'Satuan', 'aliases' => ['unit']],
            'qty' => ['label' => 'Jumlah', 'aliases' => ['qty', 'takaran'], 'required' => true],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Satu baris = satu bahan untuk SATU porsi menu. Menu dengan beberapa bahan ditulis di beberapa baris.',
            '2. Menu yang sudah punya resep sudah terisi. Menu tanpa resep muncul satu baris dengan kolom Bahan dan Jumlah kosong: isi saja.',
            '3. Menu yang tercantum di file ini resepnya DIGANTI penuh oleh isi file. Menu yang tidak ada di file tidak berubah.',
            '4. Varian: isi nama varian (mis. Large) bila bahan itu hanya dipakai untuk varian tersebut. Kosong = semua varian.',
            '5. Bahan BARU: tulis namanya dan isi Satuan, bahan akan dibuat otomatis (stok 0).',
        ];
    }

    public function templateRows(int $outletId): array
    {
        $products = Product::query()->where('outlet_id', $outletId)->with('variants')->orderBy('category_id')->orderBy('sort')->get();
        $names = Ingredient::query()->where('outlet_id', $outletId)->get()->keyBy('id');
        $lines = RecipeLine::query()->whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');
        $rows = [];
        foreach ($products as $p) {
            $own = $lines->get($p->id, collect());
            if ($own->isEmpty()) {
                $rows[] = [$p->name, '', '', '', ''];

                continue;
            }
            foreach ($own as $l) {
                $rows[] = [$p->name, $l->variant_id ? ($p->variants->firstWhere('id', $l->variant_id)->name ?? '') : '', $names[$l->ingredient_id]->name ?? '', $names[$l->ingredient_id]->unit ?? '', $l->qty];
            }
        }

        return $rows;
    }

    public function lists(int $outletId): array
    {
        return [
            'product' => Product::query()->where('outlet_id', $outletId)->orderBy('name')->pluck('name')->all(),
            'ingredient' => $this->ingredients($outletId)->pluck('name')->sort()->values()->all(),
        ];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        /** @var Collection<string, Product> $products */
        $products = Product::query()->where('outlet_id', $ctx->outletId)->with('variants')->get()->keyBy(fn (Product $p) => mb_strtolower($p->name));
        $existing = $this->ingredients($ctx->outletId);
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['product'] ?? null;
            if ($name !== null && ($c['ingredient'] ?? null) === null && ($c['qty'] ?? null) === null) {
                continue; // menu without a recipe yet, left blank
            }
            $product = $name === null ? null : $products->get(mb_strtolower($name));
            $variantName = $c['variant'] ?? null;
            $variant = $variantName && $product ? $product->variants->first(fn ($v) => mb_strtolower($v->name) === mb_strtolower($variantName)) : null;
            $ingName = $c['ingredient'] ?? null;
            $ing = $ingName === null ? null : $existing->get(mb_strtolower($ingName));
            $qty = ImportNumber::parse($c['qty'] ?? null);
            $unit = $c['unit'] ?? null;

            $error = match (true) {
                $name === null => 'Nama menu kosong.',
                $product === null => "Menu \"{$name}\" tidak ditemukan.",
                $variantName !== null && $variant === null => "Varian \"{$variantName}\" tidak ada di menu {$name}.",
                $ingName === null => 'Nama bahan kosong.',
                $qty === null || $qty <= 0 => 'Jumlah harus angka lebih dari 0.',
                $ing === null && $unit === null => "Bahan \"{$ingName}\" belum terdaftar: isi Satuan agar dibuat otomatis.",
                $ing === null && mb_strlen((string) $unit) > 12 => 'Satuan terlalu panjang (maks 12 huruf).',
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $out[] = $this->result($row['line'], $ing === null ? 'new' : 'ok', $ing === null ? 'Bahan baru akan dibuat.' : null, $c, [
                'product_id' => $product->id, 'variant_id' => $variant?->id, 'ingredient' => $ingName, 'unit' => $unit, 'qty' => $qty,
            ]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $existing = $this->ingredients($ctx->outletId);
        $byProduct = [];
        $created = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $ing = $existing->get(mb_strtolower($rec['ingredient']));
            if ($ing === null) {
                $ing = Ingredient::create(['outlet_id' => $ctx->outletId, 'name' => $rec['ingredient'], 'unit' => $rec['unit']]);
                $existing->put(mb_strtolower($rec['ingredient']), $ing);
                $created++;
            }
            $byProduct[$rec['product_id']][] = ['variant_id' => $rec['variant_id'], 'ingredient_id' => $ing->id, 'qty' => $rec['qty']];
        }
        foreach ($byProduct as $productId => $lines) {
            RecipeLine::query()->where('product_id', $productId)->delete();
            foreach ($lines as $l) {
                RecipeLine::create(['product_id' => $productId] + $l);
            }
        }
        Audit::log('import.recipes', null, ['products' => count($byProduct), 'new_ingredients' => $created], $ctx->user);

        return 'Resep disimpan untuk '.count($byProduct).' menu'.($created > 0 ? " ({$created} bahan baru dibuat)" : '').'.';
    }
}
