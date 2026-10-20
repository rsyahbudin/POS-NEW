<?php

namespace App\Domain\Shift;

use App\Domain\Payment\Models\Payment;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\Shift;
use Illuminate\Support\Facades\DB;

/** End-of-day summary: revenue, payment methods, void, discounts, comps, cash variance. */
class ZReport
{
    public function __construct(private OutletContext $outlet) {}

    /** @return array<string, mixed> */
    public function build(string $date): array
    {
        $outletId = $this->outlet->id();

        $orders = DB::table('orders')->where('outlet_id', $outletId)->where('business_date', $date)
            ->whereIn('status', ['paid', 'refunded'])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(grand_total),0) as gross, COALESCE(SUM(discount_total),0) as discounts, COALESCE(SUM(refunded_total),0) as refunds')
            ->sole();

        $byMethod = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('orders.outlet_id', $outletId)->where('orders.business_date', $date)
            ->groupBy('payment_methods.name')->selectRaw('payment_methods.name as name, SUM(payments.amount) as total')
            ->pluck('total', 'name')->map(fn ($v) => (int) $v)->all();

        $voids = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)->where('orders.business_date', $date)->whereNotNull('order_items.voided_at')
            ->selectRaw('COUNT(*) as items, COALESCE(SUM(order_items.line_total),0) as value')->sole();

        $comps = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)->where('orders.business_date', $date)->whereNotNull('order_items.comp_type')
            ->selectRaw('COUNT(*) as items, COALESCE(SUM(order_items.line_total),0) as value')->sole();

        $shifts = Shift::query()->where('outlet_id', $outletId)->where('business_date', $date)->get();

        return [
            'date' => $date,
            'orders' => (int) $orders->orders,
            'gross' => (int) $orders->gross,
            'discounts' => (int) $orders->discounts,
            'refunds' => (int) $orders->refunds,
            'net' => (int) $orders->gross - (int) $orders->refunds,
            'by_method' => $byMethod,
            'voids' => ['items' => (int) $voids->items, 'value' => (int) $voids->value],
            'comps' => ['items' => (int) $comps->items, 'value' => (int) $comps->value],
            'cash_difference' => (int) $shifts->sum('difference'),
            'shifts' => $shifts->count(),
        ];
    }
}
