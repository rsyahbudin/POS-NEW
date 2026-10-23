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
            ->with(['variants', 'modifierGroups.modifiers'])
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

            $price = $product->base_price;
            $name = $product->name;
            if (! empty($req['variant_id'])) {
                $variant = $product->variants->firstWhere('id', (int) $req['variant_id']);
                if (! $variant) {
                    throw new OrderException("Varian {$product->name} tidak valid.");
                }
                if (! $variant->is_available) {
                    throw new OrderException("{$product->name} ({$variant->name}) sedang habis.");
                }
                $price = $variant->price;
                $name .= " ({$variant->name})";
            } elseif ($product->variants->isNotEmpty()) {
                throw new OrderException("Pilih varian untuk {$product->name}.");
            }

            $selected = is_array($req['modifier_ids'] ?? null) ? array_map('intval', $req['modifier_ids']) : [];
            $snapshot = [];
            $pickedIds = [];
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
                    $price += $m->price_delta;
                    $pickedIds[] = $m->id;
                    $snapshot[] = ['group' => $group->name, 'name' => $m->name, 'price_delta' => $m->price_delta];
                }
            }

            $knownIds = $product->modifierGroups->flatMap->modifiers->pluck('id')->all();
            if (array_diff($selected, $knownIds) !== []) {
                throw new OrderException("Modifier tidak valid untuk {$product->name}.");
            }

            if ($price < 0) {
                throw new OrderException("Harga {$product->name} tidak valid.");
            }

            $rows[] = [
                'product_id' => $product->id,
                'station_id' => $product->station_id,
                'name_snapshot' => $name,
                'unit_price' => $price,
                'qty' => $qty,
                'variant_id' => ! empty($req['variant_id']) ? (int) $req['variant_id'] : null,
                'modifier_ids' => $pickedIds,
                'modifiers_snapshot' => $snapshot,
                'note' => isset($req['note']) && is_string($req['note']) ? mb_substr($req['note'], 0, 200) : null,
                'line_total' => $price * $qty,
            ];
        }

        return $rows;
    }
}
