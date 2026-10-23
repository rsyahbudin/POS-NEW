<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\BillService;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\BillCharge;
use App\Domain\Pricing\Models\Discount;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\PriceResult;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes order totals and charge snapshots on the server. Browser numbers
 * are display only. Only editable orders are recalculated, so paid orders keep
 * the rates they were paid with.
 */
class RecalculateOrderAction
{
    public function __construct(
        private PriceCalculator $calculator,
        private SettingsRepository $settings,
        private BillService $bills,
    ) {}

    public function handle(Order $order): ?PriceResult
    {
        return DB::transaction(function () use ($order) {
            if ($this->bills->isItemSplit($order)) {
                $this->bills->reconcileSplit($order);

                return null;
            }

            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->whereIn('status', ['new', 'preparing', 'ready'])
                ->whereNull('comp_type')
                ->get();

            $discounts = Discount::query()->where('order_id', $order->id)->whereNull('removed_at')->get();

            $subtotal = (int) $items->sum('line_total');
            $itemDiscountTotal = 0;
            $taken = []; // several discounts on one item add up but never exceed its price
            foreach ($discounts->whereNotNull('order_item_id') as $d) {
                $item = $items->firstWhere('id', $d->order_item_id);
                if (! $item) {
                    $d->update(['amount' => 0]);

                    continue;
                }
                $amount = $d->type === 'percent'
                    ? (int) round($item->line_total * $d->value / 10000)
                    : min((int) $d->value, $item->line_total);
                $amount = max(0, min($amount, $item->line_total - ($taken[$item->id] ?? 0)));
                $taken[$item->id] = ($taken[$item->id] ?? 0) + $amount;
                $d->update(['amount' => $amount]);
                $itemDiscountTotal += $amount;
            }

            $orderDiscounts = $discounts->whereNull('order_item_id')
                ->map(fn ($d) => ['type' => $d->type === 'promo' ? ($d->promotion?->type === 'percent' ? 'promo_percent' : 'promo_fixed') : $d->type, 'value' => (int) $d->value])
                ->values()->all();

            $rules = TaxRule::query()->where('outlet_id', $order->outlet_id)->where('is_active', true)->get()
                ->map(fn ($r) => [
                    'key' => $r->id, 'name' => $r->name, 'kind' => $r->kind->value, 'rate_bp' => $r->rate_bp,
                    'sequence' => $r->sequence, 'base' => $r->base, 'is_inclusive' => $r->is_inclusive,
                ])->all();

            $result = $this->calculator->calculate(
                $subtotal,
                $itemDiscountTotal,
                $orderDiscounts,
                $rules,
                (int) $this->settings->get('pricing.rounding', 0),
            );

            // Persist order-level discount amounts (proportional to calculator result for display).
            $afterItem = $subtotal - $result->itemDiscountTotal;
            foreach ($discounts->whereNull('order_item_id') as $d) {
                $amount = $d->type === 'fixed'
                    ? (int) $d->value
                    : (int) round($afterItem * $d->value / 10000);
                $d->update(['amount' => min($amount, $afterItem)]);
            }

            BillCharge::query()->whereIn('bill_id', Bill::query()->where('order_id', $order->id)->select('id'))->delete();
            $order->charges()->delete();
            foreach ($result->charges as $c) {
                $order->charges()->create([
                    'tax_rule_id' => $c['key'], 'name_snapshot' => $c['name'], 'kind' => $c['kind'],
                    'rate' => $c['rate_bp'], 'base' => $c['base'], 'amount' => $c['amount'], 'is_inclusive' => $c['is_inclusive'],
                ]);
            }

            $order->update([
                'subtotal' => $result->subtotal,
                'discount_total' => $result->discountTotal(),
                'charge_total' => $result->chargeTotal,
                'rounding' => $result->rounding,
                'grand_total' => $result->grandTotal,
            ]);

            $this->syncDefaultBill($order, $result);

            return $result;
        });
    }

    /** Orders without split keep one default bill mirroring the order. */
    private function syncDefaultBill(Order $order, PriceResult $result): void
    {
        $bills = Bill::query()->where('order_id', $order->id)->lockForUpdate()->get();
        $bill = $bills->first();
        if ($bills->count() > 1 || ($bill && $bill->split_mode !== null)) {
            return; // split bills are managed by SplitBillAction
        }

        $values = [
            'subtotal' => $result->subtotal,
            'discount_total' => $result->discountTotal(),
            'charge_total' => $result->chargeTotal,
            'rounding' => $result->rounding,
            'grand_total' => $result->grandTotal,
        ];
        if ($bill === null) {
            $bill = $order->bills()->create($values + ['label' => null, 'status' => BillStatus::Unpaid]);
        } else {
            $bill->update($values);
        }

        $bill->charges()->delete();
        foreach ($order->charges as $charge) {
            $bill->charges()->create(['order_charge_id' => $charge->id, 'amount' => $charge->amount]);
        }
    }
}
