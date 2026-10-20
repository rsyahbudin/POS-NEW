<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Pricing\Models\Discount;
use App\Domain\Pricing\Models\Promotion;

/** Scheduled promos (e.g. Happy Hour): applied once when a round is confirmed in the window. */
class ApplyPromotionsAction
{
    public function handle(Order $order): void
    {
        $already = Discount::query()->where('order_id', $order->id)->where('type', 'promo')->whereNull('removed_at')->exists();
        if ($already) {
            return;
        }

        $promo = Promotion::query()->where('outlet_id', $order->outlet_id)->where('is_active', true)->get()
            ->first(fn (Promotion $p) => $p->isActiveAt(now()));
        if (! $promo) {
            return;
        }

        Discount::create([
            'order_id' => $order->id,
            'type' => 'promo',
            'value' => $promo->value,
            'reason' => "Promo: {$promo->name}",
            'promotion_id' => $promo->id,
        ]);
    }
}
