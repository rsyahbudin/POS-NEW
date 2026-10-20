<?php

namespace App\Domain\Payment;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderCharge;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\BillCharge;
use App\Domain\Payment\Models\BillItem;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\PriceResult;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money;
use LogicException;

/**
 * Bill-level math for split bills. Item bills are priced by running the same
 * PriceCalculator over the bill's own items, so rounding is per bill.
 */
class BillService
{
    public function __construct(private PriceCalculator $calculator, private SettingsRepository $settings) {}

    /**
     * @param  array<int, int>  $itemQty  order_item_id => qty on this bill
     */
    public function subsetTotals(Order $order, array $itemQty, int $fixedOrderDiscount = 0): PriceResult
    {
        $items = OrderItem::query()->whereIn('id', array_keys($itemQty))->get()->keyBy('id');
        $itemDiscounts = $order->discounts()->whereNull('removed_at')->whereNotNull('order_item_id')->get()->groupBy('order_item_id');

        $subtotal = 0;
        $itemDiscount = 0;
        foreach ($itemQty as $id => $qty) {
            $item = $items->get($id) ?? throw new LogicException("Item {$id} tidak ditemukan.");
            $subtotal += $this->carve($item->line_total, $item->qty, $qty);
            foreach ($itemDiscounts->get($id, []) as $d) {
                $itemDiscount += $this->carve($d->amount, $item->qty, $qty);
            }
        }

        $orderDiscounts = [];
        foreach ($order->discounts()->whereNull('removed_at')->whereNull('order_item_id')->get() as $d) {
            if ($d->type === 'percent') {
                $orderDiscounts[] = ['type' => 'percent', 'value' => (int) $d->value];
            } elseif ($d->type === 'promo') {
                $orderDiscounts[] = ['type' => $d->promotion?->type === 'percent' ? 'promo_percent' : 'promo_fixed', 'value' => (int) $d->value];
            }
        }
        if ($fixedOrderDiscount > 0) {
            $orderDiscounts[] = ['type' => 'fixed', 'value' => $fixedOrderDiscount];
        }

        $rules = TaxRule::query()->where('outlet_id', $order->outlet_id)->where('is_active', true)->get()
            ->map(fn ($r) => [
                'key' => $r->id, 'name' => $r->name, 'kind' => $r->kind->value, 'rate_bp' => $r->rate_bp,
                'sequence' => $r->sequence, 'base' => $r->base, 'is_inclusive' => $r->is_inclusive,
            ])->all();

        return $this->calculator->calculate($subtotal, $itemDiscount, $orderDiscounts, $rules, (int) $this->settings->get('pricing.rounding', 0));
    }

    /** Fixed (rupiah) order discounts, to be shared by net value across item bills. */
    public function fixedOrderDiscountTotal(Order $order): int
    {
        return (int) $order->discounts()->whereNull('removed_at')->whereNull('order_item_id')->where('type', 'fixed')->sum('amount');
    }

    /**
     * @param  array<int, int>|null  $itemQty  null for bills without item rows (equal / amount split)
     */
    public function writeBill(Bill $bill, PriceResult $result, ?array $itemQty): void
    {
        $bill->update([
            'subtotal' => $result->subtotal,
            'discount_total' => $result->discountTotal(),
            'charge_total' => $result->chargeTotal,
            'rounding' => $result->rounding,
            'grand_total' => $result->grandTotal,
        ]);

        if ($itemQty !== null) {
            $bill->items()->delete();
            $items = OrderItem::query()->whereIn('id', array_keys($itemQty))->get()->keyBy('id');
            foreach ($itemQty as $id => $qty) {
                $item = $items->get($id) ?? throw new LogicException("Item {$id} tidak ditemukan.");
                $bill->items()->create(['order_item_id' => $id, 'qty' => $qty, 'line_total' => $this->carve($item->line_total, $item->qty, $qty)]);
            }
        }

        $bill->charges()->delete();
        foreach ($result->charges as $c) {
            $orderCharge = $this->orderChargeFor($bill->order, $c);
            $bill->charges()->create(['order_charge_id' => $orderCharge->id, 'amount' => $c['amount']]);
        }
    }

    public function clearBills(Order $order): void
    {
        $ids = Bill::query()->where('order_id', $order->id)->pluck('id');
        BillItem::query()->whereIn('bill_id', $ids)->delete();
        BillCharge::query()->whereIn('bill_id', $ids)->delete();
        Bill::query()->whereIn('id', $ids)->delete();
    }

    public function isItemSplit(Order $order): bool
    {
        return Bill::query()->where('order_id', $order->id)->where('split_mode', 'item')->exists();
    }

    /**
     * Item-mode split after new items arrive: leftover qty goes to the
     * "unallocated" bill, which must be allocated before the order can be paid.
     */
    public function reconcileSplit(Order $order): void
    {
        $items = OrderItem::query()->where('order_id', $order->id)
            ->whereIn('status', ['new', 'preparing', 'ready'])->whereNull('comp_type')->get();

        $bills = Bill::query()->where('order_id', $order->id)->get();
        $unallocated = $bills->firstWhere('is_unallocated', true);
        $allocated = BillItem::query()
            ->whereIn('bill_id', $bills->where('is_unallocated', false)->pluck('id'))
            ->selectRaw('order_item_id, SUM(qty) as qty')->groupBy('order_item_id')->pluck('qty', 'order_item_id');

        $leftover = [];
        foreach ($items as $item) {
            $rest = $item->qty - (int) ($allocated[$item->id] ?? 0);
            if ($rest > 0) {
                $leftover[$item->id] = $rest;
            }
        }

        if ($leftover === []) {
            if ($unallocated && $unallocated->paid_total === 0) {
                BillItem::query()->where('bill_id', $unallocated->id)->delete();
                BillCharge::query()->where('bill_id', $unallocated->id)->delete();
                $unallocated->delete();
            }
        } else {
            $unallocated ??= $order->bills()->create(['label' => 'Belum dialokasikan', 'split_mode' => 'item', 'is_unallocated' => true, 'status' => BillStatus::Unpaid]);
            $this->writeBill($unallocated, $this->subsetTotals($order, $leftover), $leftover);
        }

        $this->syncOrderFromBills($order);
    }

    /** Order totals and charge snapshots follow the sum of its live bills. */
    public function syncOrderFromBills(Order $order): void
    {
        $bills = Bill::query()->where('order_id', $order->id)->where('status', '!=', BillStatus::Void->value)->get();
        $order->update([
            'subtotal' => $bills->sum('subtotal'),
            'discount_total' => $bills->sum('discount_total'),
            'charge_total' => $bills->sum('charge_total'),
            'rounding' => $bills->sum('rounding'),
            'grand_total' => $bills->sum('grand_total'),
        ]);

        $amounts = [];
        foreach ($bills as $bill) {
            foreach ($bill->charges as $bc) {
                $amounts[$bc->order_charge_id] = ($amounts[$bc->order_charge_id] ?? 0) + $bc->amount;
            }
        }
        foreach ($order->charges as $oc) {
            $oc->update(['amount' => $amounts[$oc->id] ?? 0]);
        }
    }

    /** @param  array{key: int|string|null, name: string, kind: string, rate_bp: int, base: int, amount: int, is_inclusive: bool}  $c */
    private function orderChargeFor(Order $order, array $c): OrderCharge
    {
        return OrderCharge::query()->firstOrCreate(
            ['order_id' => $order->id, 'tax_rule_id' => $c['key']],
            ['name_snapshot' => $c['name'], 'kind' => $c['kind'], 'rate' => $c['rate_bp'], 'base' => 0, 'amount' => 0, 'is_inclusive' => $c['is_inclusive']],
        );
    }

    /** Share of `$amount` that belongs to `$qty` of `$total` units (remainder rupiah stay with the larger part). */
    private function carve(int $amount, int $total, int $qty): int
    {
        return $qty >= $total ? $amount : Money::of($amount)->allocate([$qty, $total - $qty])[0];
    }
}
