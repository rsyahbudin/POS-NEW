<?php

namespace App\Domain\Import\Importers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Support\Audit;

/**
 * Menu items: add new ones (and new categories) or update price, stock and details of existing ones.
 * Photos, variants, modifiers and kitchen station stay as they are (edit them in the Menu page).
 */
class ProductsImporter extends Importer
{
    public function key(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return 'Daftar menu';
    }

    public function permission(): string
    {
        return 'manage-menu';
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Menu', 'aliases' => ['nama menu', 'produk', 'nama'], 'required' => true],
            'category' => ['label' => 'Kategori', 'aliases' => [], 'required' => true],
            'price' => ['label' => 'Harga', 'aliases' => ['harga dasar', 'harga jual'], 'required' => true],
            'sku' => ['label' => 'SKU', 'aliases' => ['kode']],
            'stock' => ['label' => 'Stok porsi', 'aliases' => ['stok']],
            'description' => ['label' => 'Deskripsi', 'aliases' => ['keterangan']],
            'available' => ['label' => 'Tersedia', 'aliases' => ['aktif', 'ada']],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Menu yang sudah ada sudah terisi. Ubah Harga, SKU, Stok porsi, Deskripsi, atau Tersedia lalu unggah lagi untuk memperbarui banyak menu sekaligus.',
            '2. Menu BARU: tulis di baris kosong paling bawah. Kategori yang belum ada akan dibuat otomatis.',
            '3. Menu dikenali dari SKU (kalau diisi), jika tidak dari nama menu.',
            '4. Stok porsi kosong = tidak terbatas untuk menu baru, dan tidak diubah untuk menu yang sudah ada. Tulis angka 0 untuk menandai habis.',
            '5. Tersedia: isi "ya" atau "tidak". Kosong = tidak diubah (menu baru otomatis tersedia).',
            '6. Foto, varian, modifier, dan stasiun dapur tidak diubah lewat file ini; atur di halaman Menu.',
        ];
    }

    public function templateRows(int $outletId): array
    {
        $categories = Category::query()->where('outlet_id', $outletId)->pluck('name', 'id');

        return Product::query()->where('outlet_id', $outletId)->orderBy('category_id')->orderBy('sort')->get()->map(fn (Product $p) => [
            $p->name, $categories[$p->category_id] ?? '', $p->base_price, $p->sku ?? '', $p->stock_qty ?? '', $p->description ?? '', $p->is_available ? 'ya' : 'tidak',
        ])->all();
    }

    public function lists(int $outletId): array
    {
        return [
            'category' => Category::query()->where('outlet_id', $outletId)->orderBy('sort')->pluck('name')->all(),
            'available' => ['ya', 'tidak'],
        ];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $products = Product::query()->where('outlet_id', $ctx->outletId)->get();
        $byName = $products->keyBy(fn (Product $p) => mb_strtolower($p->name));
        $bySku = $products->filter(fn (Product $p) => $p->sku !== null)->keyBy(fn (Product $p) => mb_strtolower((string) $p->sku));
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $category = $c['category'] ?? null;
            $price = ImportNumber::money($c['price'] ?? null);
            $sku = $c['sku'] ?? null;
            $stockRaw = $c['stock'] ?? null;
            $stock = ImportNumber::money($stockRaw);
            $avail = $this->bool($c['available'] ?? null);
            $found = ($sku !== null ? $bySku->get(mb_strtolower($sku)) : null) ?? ($name !== null ? $byName->get(mb_strtolower($name)) : null);
            $skuOwner = $sku !== null ? $bySku->get(mb_strtolower($sku)) : null;
            $key = $found ? 'p'.$found->id : 'n'.mb_strtolower((string) $name);

            $error = match (true) {
                $name === null => 'Nama menu kosong.',
                mb_strlen($name) > 80 => 'Nama menu terlalu panjang.',
                $category === null => 'Kategori kosong.',
                $price === null || $price < 0 => 'Harga harus angka 0 atau lebih.',
                $stockRaw !== null && ($stock === null || $stock < 0) => 'Stok porsi harus angka 0 atau lebih.',
                ($c['available'] ?? null) !== null && $avail === null => 'Tersedia diisi "ya" atau "tidak".',
                $sku !== null && mb_strlen($sku) > 40 => 'SKU terlalu panjang.',
                $skuOwner !== null && $found !== null && $skuOwner->id !== $found->id => "SKU \"{$sku}\" dipakai menu lain.",
                isset($seen[$key]) => "Menu \"{$name}\" muncul dua kali di file.",
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $seen[$key] = true;
            $out[] = $this->result($row['line'], $found ? 'update' : 'new', null, $c, [
                'id' => $found?->id, 'name' => $name, 'category' => $category, 'price' => $price, 'sku' => $sku,
                'stock_set' => $stockRaw !== null, 'stock' => $stock, 'description' => $c['description'] ?? null, 'available' => $avail,
            ]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $categories = Category::query()->where('outlet_id', $ctx->outletId)->get()->keyBy(fn (Category $c) => mb_strtolower($c->name));
        $new = 0;
        $updated = 0;
        $newCategories = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $cat = $categories->get(mb_strtolower($rec['category']));
            if ($cat === null) {
                $cat = Category::create(['outlet_id' => $ctx->outletId, 'name' => $rec['category'], 'sort' => $categories->count()]);
                $categories->put(mb_strtolower($rec['category']), $cat);
                $newCategories++;
            }
            $fields = ['name' => $rec['name'], 'category_id' => $cat->id, 'base_price' => $rec['price'], 'sku' => $rec['sku'], 'description' => $rec['description']];
            if ($rec['id'] === null) {
                Product::create($fields + ['outlet_id' => $ctx->outletId, 'stock_qty' => $rec['stock'], 'is_available' => $rec['available'] ?? true]);
                $new++;

                continue;
            }
            $product = Product::query()->findOrFail((int) $rec['id']);
            if ($rec['stock_set']) {
                $fields['stock_qty'] = $rec['stock'];
            }
            if ($rec['available'] !== null) {
                $fields['is_available'] = $rec['available'];
            }
            if ($product->base_price !== $rec['price']) {
                Audit::log('product.price_changed', $product, ['from' => $product->base_price, 'to' => $rec['price'], 'via' => 'import'], $ctx->user);
            }
            $product->update($fields);
            $updated++;
        }
        Audit::log('import.products', null, ['new' => $new, 'updated' => $updated], $ctx->user);

        return "Impor selesai: {$new} menu baru, {$updated} diperbarui".($newCategories > 0 ? ", {$newCategories} kategori baru" : '').'.';
    }

    private function bool(?string $v): ?bool
    {
        if ($v === null) {
            return null;
        }

        return match (mb_strtolower($v)) {
            'ya', 'y', 'yes', 'true', '1', 'ada', 'tersedia' => true,
            'tidak', 't', 'no', 'false', '0', 'habis' => false,
            default => null,
        };
    }
}
