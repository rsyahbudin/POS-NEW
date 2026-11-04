<?php

namespace App\Domain\Ordering;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Exceptions\OrderException;

/**
 * Turns client item requests into trusted order-item rows. Prices, availability
 * and modifier rules are always re-validated here, never taken from the client.
 */
class ItemBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return list<array<string, mixed>>
     */
    public function build(array $requests, int $outletId): array
    {
        if ($requests === []) {
            throw new OrderException('Pesanan kosong.');
        }

        $products = Product::query()
            ->where('outlet_id', $outletId)
            ->with(['category', 'variants', 'modifierGroups.modifiers', 'bundleItems.product.category', 'bundleItems.product.variants', 'bundleItems.product.modifierGroups.modifiers', 'bundleItems.variant'])
            ->findMany(array_values(array_unique(array_map(fn (array $r) => (int) $r['product_id'], $requests))))
            ->keyBy('id');

        $rows = [];
        $demand = [];
        foreach ($requests as $req) {
            $product = $products->get((int) $req['product_id']);
            if (! $product) {
                throw new OrderException('Produk tidak ditemukan.');
            }
            if (! $product->is_active) {
                throw new OrderException("{$product->name} tidak dijual.");
            }
            if (! $product->is_available) {
                throw new OrderException("{$product->name} sedang habis.");
            }

            $qty = (int) $req['qty'];
            if ($qty < 1 || $qty > 99) {
                throw new OrderException("Jumlah {$product->name} tidak valid.");
            }

            $demand[$product->id] = ($demand[$product->id] ?? 0) + $qty;
            if ($product->stock_qty !== null && $demand[$product->id] > $product->stock_qty) {
                throw new OrderException("Stok {$product->name} tinggal {$product->stock_qty}.");
            }

            $bundle = $product->is_bundle ? $this->components($product, $qty, $demand, is_array($req['parts'] ?? null) ? $req['parts'] : []) : null;
            $components = $bundle['parts'] ?? null;

            $price = $product->base_price + ($bundle['extra'] ?? 0);
            $name = $product->name;
            if (! empty($req['variant_id']) && $components === null) {
                $variant = $product->variants->firstWhere('id', (int) $req['variant_id']);
                if (! $variant) {
                    throw new OrderException("Varian {$product->name} tidak valid.");
                }
                if (! $variant->is_available) {
                    throw new OrderException("{$product->name} ({$variant->name}) sedang habis.");
                }
                $price = $variant->price;
                $name .= " ({$variant->name})";
            } elseif ($components === null && $product->variants->isNotEmpty()) {
                throw new OrderException("Pilih varian untuk {$product->name}.");
            }

            $snapshot = $bundle['summary'] ?? [];
            $pickedIds = [];
            if ($components === null) {
                $chosen = $this->modifiers($product, is_array($req['modifier_ids'] ?? null) ? array_map('intval', $req['modifier_ids']) : []);
                $price += $chosen['extra'];
                $pickedIds = $chosen['ids'];
                $snapshot = $chosen['snapshot'];
            }

            if ($price < 0) {
                throw new OrderException("Harga {$product->name} tidak valid.");
            }

            $rows[] = [
                'product_id' => $product->id,
                // a bundle with no station of its own shows on the kitchen screen of its first menu item
                'station_id' => $product->effectiveStationId() ?? ($components[0]['station_id'] ?? null),
                'name_snapshot' => $name,
                'unit_price' => $price,
                'qty' => $qty,
                'variant_id' => ! empty($req['variant_id']) && $components === null ? (int) $req['variant_id'] : null,
                'modifier_ids' => $pickedIds,
                'modifiers_snapshot' => $snapshot,
                'bundle_snapshot' => $components,
                'note' => isset($req['note']) && is_string($req['note']) ? mb_substr($req['note'], 0, 200) : null,
                'line_total' => $price * $qty,
            ];
        }

        return $rows;
    }

    /**
     * The options of one menu item, checked against what it offers: each group's minimum and maximum, and that every option is in stock.
     *
     * @param  array<int, int>  $selected
     * @return array{ids: list<int>, snapshot: list<array{group: string, name: string, price_delta: int}>, extra: int}
     */
    private function modifiers(Product $product, array $selected): array
    {
        $ids = [];
        $snapshot = [];
        $extra = 0;
        foreach ($product->modifierGroups as $group) {
            $picked = $group->modifiers->whereIn('id', $selected);
            $count = $picked->count();
            $min = $group->is_required ? max(1, $group->min_select) : $group->min_select;
            if ($count < $min) {
                throw new OrderException("Pilih minimal {$min} untuk {$group->name} ({$product->name}).");
            }
            if ($count > $group->max_select) {
                throw new OrderException("Pilih maksimal {$group->max_select} untuk {$group->name} ({$product->name}).");
            }
            foreach ($picked as $m) {
                if (! $m->is_available) {
                    throw new OrderException("{$m->name} ({$product->name}) sedang habis.");
                }
                $extra += $m->price_delta;
                $ids[] = $m->id;
                $snapshot[] = ['group' => $group->name, 'name' => $m->name, 'price_delta' => $m->price_delta];
            }
        }

        if (array_diff($selected, $product->modifierGroups->flatMap->modifiers->pluck('id')->all()) !== []) {
            throw new OrderException("Modifier tidak valid untuk {$product->name}.");
        }

        return ['ids' => $ids, 'snapshot' => $snapshot, 'extra' => $extra];
    }

    /**
     * The menu items a bundle is made of, checked like any ordered item: sold, in stock, and enough portions left for everything in the order.
     * A bundle fixes the variant of a menu item or leaves it to the guest, and can let the guest pick that item's own options (sambal, sweetness).
     * Options that cost extra are added to the bundle price; the variant's own price is not used.
     *
     * @param  array<int, int>  $demand
     * @param  array<int, mixed>  $chosen  what the guest picked, one entry per menu item: product_id, variant_id, modifier_ids
     * @return array{parts: list<array<string, mixed>>, summary: list<array{group: string, name: string, price_delta: int}>, extra: int}
     */
    private function components(Product $bundle, int $qty, array &$demand, array $chosen): array
    {
        if ($bundle->bundleItems->isEmpty()) {
            throw new OrderException("Paket {$bundle->name} belum berisi menu.");
        }
        $picks = collect($chosen)->filter(fn ($c) => is_array($c))->keyBy(fn (array $c) => (int) ($c['product_id'] ?? 0));

        $parts = [];
        $summary = [];
        $extra = 0;
        foreach ($bundle->bundleItems as $line) {
            $part = $line->product;
            if ($part->trashed() || ! $part->is_active) {
                throw new OrderException("{$part->name} dalam {$bundle->name} tidak dijual.");
            }
            if (! $part->is_available) {
                throw new OrderException("{$part->name} dalam {$bundle->name} sedang habis.");
            }
            $demand[$part->id] = ($demand[$part->id] ?? 0) + $line->qty * $qty;
            if ($part->stock_qty !== null && $demand[$part->id] > $part->stock_qty) {
                throw new OrderException("Stok {$part->name} tinggal {$part->stock_qty}.");
            }

            $pick = $picks->get($part->id, []);
            $variant = $line->variant;
            if ($variant === null && $part->variants->isNotEmpty()) {
                $variant = $part->variants->firstWhere('id', (int) ($pick['variant_id'] ?? 0));
                if ($variant === null) {
                    throw new OrderException("Pilih varian untuk {$part->name} dalam {$bundle->name}.");
                }
            }
            if ($variant !== null && ! $variant->is_available) {
                throw new OrderException("{$part->name} ({$variant->name}) dalam {$bundle->name} sedang habis.");
            }

            $options = ['ids' => [], 'snapshot' => [], 'extra' => 0];
            if ($line->ask_modifiers) {
                $options = $this->modifiers($part, is_array($pick['modifier_ids'] ?? null) ? array_map('intval', $pick['modifier_ids']) : []);
            }
            $extra += $options['extra'];

            $label = $variant ? "{$part->name} ({$variant->name})" : $part->name;
            $names = array_column($options['snapshot'], 'name');
            $parts[] = [
                'product_id' => $part->id, 'variant_id' => $variant?->id, 'name' => $label, 'qty' => $line->qty, 'station_id' => $part->effectiveStationId(),
                'modifier_ids' => $options['ids'], 'modifiers' => $names,
            ];
            // what the guest chose for this item, shown on the receipt and reports like any option
            if ($variant !== null || $names !== []) {
                $summary[] = ['group' => $part->name, 'name' => implode(', ', array_filter([$variant?->name, ...$names])), 'price_delta' => 0];
            }
        }

        return ['parts' => $parts, 'summary' => $summary, 'extra' => $extra];
    }
}
