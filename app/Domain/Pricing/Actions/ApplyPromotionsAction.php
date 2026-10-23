<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Pricing\Models\Discount;
use App\Domain\Pricing\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Scheduled promos (Happy Hour, "Kopi 20%"), applied when a round is confirmed inside the promo window.
 *
 * - A promo covers the whole order, some categories, or some menu items; a covered item gets its own discount line.
 * - Promos marked "boleh digabung" add up. A promo that is not combinable stands alone: if one is running it is the
 *   only promo used (the first one created).
 * - Each promo is applied once per order, or once per item; a promo the cashier removed stays removed, and items added
 *   in a later round inside the window get it too.
 */
class ApplyPromotionsAction
{
    public function handle(Order $order): void
    {
        $now = now();
        /** @var Collection<int, Promotion> $running */
        $running = Promotion::query()->where('outlet_id', $order->outlet_id)->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (Promotion $p) => $p->isActiveAt($now))->values();
        if ($running->isEmpty()) {
            return;
        }

        $alone = $running->first(fn (Promotion $p) => ! $p->combinable);
        $selected = $alone !== null ? collect([$alone]) : $running;

        $items = OrderItem::query()->where('order_id', $order->id)->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')->get();
        $categoryOf = Product::query()->whereIn('id', $items->pluck('product_id')->unique())->pluck('category_id', 'id');
        $given = Discount::query()->where('order_id', $order->id)->whereNotNull('promotion_id')->get(['promotion_id', 'order_item_id']);

        foreach ($selected as $promo) {
            if ($promo->scope === 'order') {
                if (! $given->contains(fn ($d) => $d->promotion_id === $promo->id && $d->order_item_id === null)) {
                    Discount::create([
                        'order_id' => $order->id, 'type' => 'promo', 'value' => $promo->value,
                        'reason' => "Promo: {$promo->name}", 'promotion_id' => $promo->id,
                    ]);
                }

                continue;
            }

            foreach ($items as $item) {
                if (! $promo->covers($item->product_id, (int) ($categoryOf[$item->product_id] ?? 0))) {
                    continue;
                }
                if ($given->contains(fn ($d) => $d->promotion_id === $promo->id && $d->order_item_id === $item->id)) {
                    continue;
                }
                // Item lines are computed from percent / fixed, so the promo's own type is stored.
                Discount::create([
                    'order_id' => $order->id, 'order_item_id' => $item->id, 'type' => $promo->type, 'value' => $promo->value,
                    'reason' => "Promo: {$promo->name}", 'promotion_id' => $promo->id,
                ]);
            }
        }
    }
}
