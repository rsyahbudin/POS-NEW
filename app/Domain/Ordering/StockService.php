<?php

namespace App\Domain\Ordering;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\InventoryService;
use App\Domain\Ordering\Events\ProductAvailabilityChanged;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Realtime;

/** Portion stock: decremented on confirmation, restored on void. Null stock = unlimited. */
class StockService
{
    public function __construct(private InventoryService $inventory) {}

    public function reserve(OrderItem $item): void
    {
        $this->inventory->consume($item);

        if ($item->stock_reserved) {
            return;
        }
        if ($item->bundle_snapshot) {
            $this->reserveParts($item);
        }
        $product = Product::query()->lockForUpdate()->find($item->product_id);
        if (! $product || $product->stock_qty === null) {
            // Nothing to count on the bundle itself, but its menu items were counted, so remember that.
            if ($item->bundle_snapshot) {
                $item->update(['stock_reserved' => true]);
            }

            return;
        }
        if ($product->stock_qty < $item->qty) {
            throw new OrderException("Stok {$product->name} tinggal {$product->stock_qty}.");
        }

        $remaining = $product->stock_qty - $item->qty;
        $product->update(['stock_qty' => $remaining, 'is_available' => $remaining > 0 ? $product->is_available : false]);
        $item->update(['stock_reserved' => true]);

        if ($remaining === 0) {
            Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, false));
        }
    }

    public function release(OrderItem $item): void
    {
        $this->inventory->restore($item);

        if (! $item->stock_reserved) {
            return;
        }
        if ($item->bundle_snapshot) {
            $this->releaseParts($item);
        }
        $product = Product::query()->lockForUpdate()->find($item->product_id);
        if ($product && $product->stock_qty !== null) {
            $wasZero = $product->stock_qty === 0;
            $product->update(['stock_qty' => $product->stock_qty + $item->qty, 'is_available' => $wasZero ? true : $product->is_available]);
            if ($wasZero) {
                Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, true));
            }
        }
        $item->update(['stock_reserved' => false]);
    }

    /** A bundle uses up the portions of every menu item inside it. */
    private function reserveParts(OrderItem $item): void
    {
        foreach ($item->bundle_snapshot ?? [] as $part) {
            $product = Product::withTrashed()->lockForUpdate()->find($part['product_id']);
            if (! $product || $product->stock_qty === null) {
                continue;
            }
            $need = $part['qty'] * $item->qty;
            if ($product->stock_qty < $need) {
                throw new OrderException("Stok {$product->name} tinggal {$product->stock_qty}.");
            }
            $remaining = $product->stock_qty - $need;
            $product->update(['stock_qty' => $remaining, 'is_available' => $remaining > 0 ? $product->is_available : false]);
            if ($remaining === 0) {
                Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, false));
            }
        }
    }

    private function releaseParts(OrderItem $item): void
    {
        foreach ($item->bundle_snapshot ?? [] as $part) {
            $product = Product::withTrashed()->lockForUpdate()->find($part['product_id']);
            if (! $product || $product->stock_qty === null) {
                continue;
            }
            $wasZero = $product->stock_qty === 0;
            $product->update(['stock_qty' => $product->stock_qty + $part['qty'] * $item->qty, 'is_available' => $wasZero ? true : $product->is_available]);
            if ($wasZero) {
                Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, true));
            }
        }
    }
}
