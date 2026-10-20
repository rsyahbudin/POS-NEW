<?php

namespace App\Domain\Reporting;

use App\Domain\Settings\OutletContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sales are counted per parent order (split bills never inflate the count).
 * Net sales = grand total of paid/refunded orders minus refunds.
 */
class SalesReports
{
    public const DIMENSIONS = ['day', 'hour', 'product', 'category', 'method', 'cashier', 'type'];

    public function __construct(private OutletContext $outlet) {}

    private function paidOrders(string $from, string $to): Builder
    {
        return DB::table('orders')->where('orders.outlet_id', $this->outlet->id())
            ->whereIn('orders.status', ['paid', 'refunded'])->whereBetween('orders.business_date', [$from, $to]);
    }

    /** @return array{revenue: int, orders: int, average: int, last_week_revenue: int, change_percent: float|null} */
    public function dashboard(string $date): array
    {
        $today = $this->summary($date, $date);
        $lastWeek = $this->summary(CarbonImmutable::parse($date)->subDays(7)->toDateString(), CarbonImmutable::parse($date)->subDays(7)->toDateString());

        return [
            'revenue' => $today['net'], 'orders' => $today['orders'],
            'average' => $today['orders'] > 0 ? intdiv($today['net'], $today['orders']) : 0,
            'last_week_revenue' => $lastWeek['net'],
            'change_percent' => $lastWeek['net'] > 0 ? round(($today['net'] - $lastWeek['net']) / $lastWeek['net'] * 100, 1) : null,
        ];
    }

    /** @return array{orders: int, gross: int, refunds: int, net: int, discounts: int} */
    public function summary(string $from, string $to): array
    {
        $r = $this->paidOrders($from, $to)->selectRaw('COUNT(*) orders, COALESCE(SUM(grand_total),0) gross, COALESCE(SUM(refunded_total),0) refunds, COALESCE(SUM(discount_total),0) discounts')->sole();

        return ['orders' => (int) $r->orders, 'gross' => (int) $r->gross, 'refunds' => (int) $r->refunds, 'net' => (int) $r->gross - (int) $r->refunds, 'discounts' => (int) $r->discounts];
    }

    /** @return Collection<int, stdClass> rows with `label`, `orders`, `total` */
    public function by(string $dimension, string $from, string $to, ?int $cashierId = null): Collection
    {
        $q = match ($dimension) {
            'day' => $this->paidOrders($from, $to)->selectRaw("to_char(orders.business_date,'YYYY-MM-DD') label, COUNT(*) orders, SUM(grand_total - refunded_total) total")->groupByRaw('1')->orderByRaw('1'),
            'hour' => $this->paidOrders($from, $to)->selectRaw("to_char(orders.closed_at,'HH24') || ':00' label, COUNT(*) orders, SUM(grand_total - refunded_total) total")->groupByRaw('1')->orderByRaw('1'),
            'type' => $this->paidOrders($from, $to)->selectRaw('orders.type label, COUNT(*) orders, SUM(grand_total - refunded_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'cashier' => $this->paidOrders($from, $to)->join('users', 'users.id', '=', 'orders.opened_by')->selectRaw('users.name label, COUNT(*) orders, SUM(grand_total - refunded_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'product' => $this->itemQuery($from, $to)->selectRaw('order_items.name_snapshot label, SUM(order_items.qty) orders, SUM(order_items.line_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'category' => $this->itemQuery($from, $to)->join('products', 'products.id', '=', 'order_items.product_id')->join('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw('categories.name label, SUM(order_items.qty) orders, SUM(order_items.line_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'method' => DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->where('orders.outlet_id', $this->outlet->id())->whereBetween('orders.business_date', [$from, $to])
                ->selectRaw('payment_methods.name label, COUNT(*) orders, SUM(payments.amount - payments.refunded_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            default => throw new \InvalidArgumentException('Dimensi laporan tidak dikenal.'),
        };

        return $q->get()->map(function (stdClass $r): stdClass {
            $row = new stdClass;
            $row->label = (string) $r->label;
            $row->orders = (int) $r->orders;
            $row->total = (int) $r->total;

            return $row;
        });
    }

    private function itemQuery(string $from, string $to): Builder
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $this->outlet->id())->whereIn('orders.status', ['paid', 'refunded'])
            ->whereBetween('orders.business_date', [$from, $to])->whereIn('order_items.status', ['new', 'preparing', 'ready'])->whereNull('order_items.comp_type');
    }

    /**
     * Discounts, voids, refunds and comps with who did it and who approved.
     *
     * @return array{discounts: Collection<int, stdClass>, voids: Collection<int, stdClass>, refunds: Collection<int, stdClass>, comps: Collection<int, stdClass>}
     */
    public function adjustments(string $from, string $to): array
    {
        $oid = $this->outlet->id();
        $discounts = DB::table('discounts')->join('orders', 'orders.id', '=', 'discounts.order_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'discounts.created_by')->leftJoin('users as au', 'au.id', '=', 'discounts.approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])->whereNull('discounts.removed_at')
            ->select('orders.number', 'discounts.type', 'discounts.value', 'discounts.amount', 'discounts.reason', 'cu.name as actor', 'au.name as approver')->get();

        $voids = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('users as vu', 'vu.id', '=', 'order_items.voided_by')->leftJoin('users as au', 'au.id', '=', 'order_items.void_approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])->whereNotNull('order_items.voided_at')
            ->select('orders.number', 'order_items.name_snapshot as item', 'order_items.qty', 'order_items.line_total as amount', 'order_items.void_reason as reason', 'vu.name as actor', 'au.name as approver')->get();

        $refunds = DB::table('refunds')->join('orders', 'orders.id', '=', 'refunds.order_id')->leftJoin('users as au', 'au.id', '=', 'refunds.approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])
            ->select('orders.number', 'refunds.amount', 'refunds.reason', 'au.name as approver')->get();

        $comps = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->leftJoin('users as au', 'au.id', '=', 'order_items.comp_approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])->whereNotNull('order_items.comp_type')
            ->select('orders.number', 'order_items.name_snapshot as item', 'order_items.qty', 'order_items.line_total as amount', 'order_items.comp_type as type', 'order_items.comp_reason as reason', 'au.name as approver')->get();

        return compact('discounts', 'voids', 'refunds', 'comps');
    }
}
