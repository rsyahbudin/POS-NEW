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
 *
 * The money words, so that tax never inflates the sales figure:
 *  - Gross sales (subtotal): what the menu items sold for before any discount.
 *  - Net sales: gross minus discount and minus the tax and service charge that are INSIDE the prices (inclusive); tax and
 *    service charge added on top never count as sales. A refund takes its share out of it.
 *  - Tax and service charge: what was collected for the state and for the staff, shown apart.
 *  - Total received: what the guests paid after refunds (net sales + tax + service charge + rounding).
 */
class SalesReports
{
    public const DIMENSIONS = ['day', 'hour', 'product', 'variant', 'modifier', 'category', 'method', 'cashier', 'receiver', 'type'];

    /** Dimensions counted per order, which can show the full money breakdown (menu, discount, tax, refund). */
    public const ORDER_DIMENSIONS = ['day', 'hour', 'cashier', 'type'];

    private const TYPE_LABELS = ['dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'online' => 'Online / Ojol'];

    public function __construct(private OutletContext $outlet) {}

    /** Tax and service charge that are already inside the prices of an order. */
    private const INCLUSIVE = '(COALESCE((SELECT SUM(oc.amount) FROM order_charges oc WHERE oc.order_id = orders.id AND oc.is_inclusive), 0))';

    /** What is left of an order after refunds, as a share (1 = nothing refunded). */
    private const KEEP = '(1 - COALESCE(orders.refunded_total::numeric / NULLIF(orders.grand_total, 0), 0))';

    /**
     * @param  'tax'|'service'  $kind
     * @return literal-string
     */
    private static function chargeOf(string $kind): string
    {
        return "(COALESCE((SELECT SUM(oc.amount) FROM order_charges oc WHERE oc.order_id = orders.id AND oc.kind = '{$kind}'), 0))";
    }

    /**
     * Net sales of the orders in a group: menu minus discount minus inclusive tax and service, after the refunded share.
     *
     * @return literal-string
     */
    private static function netSalesExpr(): string
    {
        return 'SUM(ROUND((orders.subtotal - orders.discount_total - '.self::INCLUSIVE.') * '.self::KEEP.'))';
    }

    /** @return literal-string */
    private static function netSalesSql(): string
    {
        return self::netSalesExpr().' net_sales';
    }

    private function paidOrders(string $from, string $to): Builder
    {
        return DB::table('orders')->where('orders.outlet_id', $this->outlet->id())
            ->whereIn('orders.status', ['paid', 'refunded'])->whereBetween('orders.business_date', [$from, $to]);
    }

    /**
     * The headline numbers of one business day against the same weekday a week earlier. `revenue` is net sales (no tax, no
     * service charge); `total` is what guests paid.
     *
     * @return array{revenue: int, total: int, orders: int, average: int, last_week_revenue: int, change_percent: float|null}
     */
    public function dashboard(string $date): array
    {
        $today = $this->summary($date, $date);
        $lastWeek = $this->summary(CarbonImmutable::parse($date)->subDays(7)->toDateString(), CarbonImmutable::parse($date)->subDays(7)->toDateString());

        return [
            'revenue' => $today['net_sales'], 'total' => $today['net'], 'orders' => $today['orders'],
            'average' => $today['orders'] > 0 ? intdiv($today['net_sales'], $today['orders']) : 0,
            'last_week_revenue' => $lastWeek['net_sales'],
            'change_percent' => $lastWeek['net_sales'] > 0 ? round(($today['net_sales'] - $lastWeek['net_sales']) / $lastWeek['net_sales'] * 100, 1) : null,
        ];
    }

    /**
     * A period against the period of the same length right before it, with growth in percent for the numbers an owner watches.
     *
     * @return array{now: array<string, int>, previous: array<string, int>, growth: array<string, float|null>, previous_from: string, previous_to: string}
     */
    public function overview(string $from, string $to): array
    {
        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $previousTo = CarbonImmutable::parse($from)->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $now = $this->summary($from, $to);
        $previous = $this->summary($previousFrom->toDateString(), $previousTo->toDateString());
        $growth = [];
        foreach (['net_sales', 'gross_sales', 'orders', 'average', 'items', 'net'] as $key) {
            $growth[$key] = $previous[$key] > 0 ? round(($now[$key] - $previous[$key]) / $previous[$key] * 100, 1) : null;
        }

        return ['now' => $now, 'previous' => $previous, 'growth' => $growth, 'previous_from' => $previousFrom->toDateString(), 'previous_to' => $previousTo->toDateString()];
    }

    /**
     * `net` is what guests paid after refunds (total received); `net_sales` is the sales figure without tax and service charge.
     *
     * @return array{orders: int, subtotal: int, gross_sales: int, discounts: int, charges: int, tax: int, service: int, rounding: int, gross: int, refunds: int, net: int, net_sales: int, items: int, average: int}
     */
    public function summary(string $from, string $to): array
    {
        $r = $this->paidOrders($from, $to)->selectRaw(
            'COUNT(*) orders, COALESCE(SUM(grand_total),0) gross, COALESCE(SUM(refunded_total),0) refunds, COALESCE(SUM(discount_total),0) discounts, COALESCE(SUM(subtotal),0) subtotal, '
            .'COALESCE(SUM(charge_total),0) charges, COALESCE(SUM(rounding),0) rounding, COALESCE('.self::netSalesExpr().',0) net_sales, '
            .'COALESCE(SUM(ROUND('.self::chargeOf('tax').' * '.self::KEEP.')),0) tax, COALESCE(SUM(ROUND('.self::chargeOf('service').' * '.self::KEEP.')),0) service, '
            ."COALESCE(SUM((SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi WHERE oi.order_id = orders.id AND oi.status IN ('new','preparing','ready') AND oi.voided_at IS NULL)),0) items"
        )->sole();
        $orders = (int) $r->orders;

        return [
            'orders' => $orders, 'gross' => (int) $r->gross, 'refunds' => (int) $r->refunds, 'net' => (int) $r->gross - (int) $r->refunds, 'discounts' => (int) $r->discounts,
            'subtotal' => (int) $r->subtotal, 'gross_sales' => (int) $r->subtotal, 'charges' => (int) $r->charges, 'tax' => (int) $r->tax, 'service' => (int) $r->service, 'rounding' => (int) $r->rounding,
            'net_sales' => (int) $r->net_sales, 'items' => (int) $r->items,
            'average' => $orders > 0 ? intdiv((int) $r->net_sales, $orders) : 0,
        ];
    }

    /** @return Collection<int, stdClass> rows with `label`, `display`, `orders`, `total`, and for order dimensions `subtotal`, `discount`, `charges`, `rounding`, `refunds`, `average` */
    public function by(string $dimension, string $from, string $to, ?int $cashierId = null): Collection
    {
        $q = match ($dimension) {
            'day' => $this->paidOrders($from, $to)->selectRaw("to_char(orders.business_date,'YYYY-MM-DD') label, COUNT(*) orders, SUM(grand_total - refunded_total) total, SUM(orders.subtotal) subtotal, SUM(orders.discount_total) discount, SUM(orders.charge_total) charges, SUM(orders.rounding) rounding, SUM(orders.refunded_total) refunds, ".self::netSalesSql())->groupByRaw('1')->orderByRaw('1'),
            'hour' => $this->paidOrders($from, $to)->selectRaw("to_char(orders.closed_at,'HH24') || ':00' label, COUNT(*) orders, SUM(grand_total - refunded_total) total, SUM(orders.subtotal) subtotal, SUM(orders.discount_total) discount, SUM(orders.charge_total) charges, SUM(orders.rounding) rounding, SUM(orders.refunded_total) refunds, ".self::netSalesSql())->groupByRaw('1')->orderByRaw('1'),
            'type' => $this->paidOrders($from, $to)->selectRaw('orders.type label, COUNT(*) orders, SUM(grand_total - refunded_total) total, SUM(orders.subtotal) subtotal, SUM(orders.discount_total) discount, SUM(orders.charge_total) charges, SUM(orders.rounding) rounding, SUM(orders.refunded_total) refunds, '.self::netSalesSql())->groupByRaw('1')->orderByRaw('3 desc'),
            'cashier' => $this->paidOrders($from, $to)->join('users', 'users.id', '=', 'orders.opened_by')->selectRaw('users.name label, COUNT(*) orders, SUM(grand_total - refunded_total) total, SUM(orders.subtotal) subtotal, SUM(orders.discount_total) discount, SUM(orders.charge_total) charges, SUM(orders.rounding) rounding, SUM(orders.refunded_total) refunds, '.self::netSalesSql())->groupByRaw('1')->orderByRaw('3 desc'),
            'product' => $this->itemQuery($from, $to)->selectRaw('order_items.name_snapshot label, SUM(order_items.qty) orders, SUM(order_items.line_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            // sold variants, one row per menu item and variant ("Americano (Large)"); items without a variant are left out
            'variant' => $this->itemQuery($from, $to)->whereNotNull('order_items.variant_id')->selectRaw('order_items.name_snapshot label, SUM(order_items.qty) orders, SUM(order_items.line_total) total')->groupByRaw('1')->orderByRaw('2 desc, 3 desc'),
            // chosen options (sugar level, topping...) and the picks inside bundles: how often, and the extra money they brought in
            'modifier' => $this->itemQuery($from, $to)->whereRaw("jsonb_typeof(order_items.modifiers_snapshot) = 'array'")
                ->crossJoin(DB::raw('lateral jsonb_array_elements(order_items.modifiers_snapshot) as m(opt)'))
                ->selectRaw("CASE WHEN COALESCE(m.opt->>'group', '') = '' THEN m.opt->>'name' ELSE (m.opt->>'group') || ': ' || (m.opt->>'name') END label, SUM(order_items.qty) orders, SUM(order_items.qty * COALESCE((m.opt->>'price_delta')::int, 0)) total")
                ->groupByRaw('1')->orderByRaw('2 desc, 3 desc'),
            'category' => $this->itemQuery($from, $to)->join('products', 'products.id', '=', 'order_items.product_id')->join('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw('categories.name label, SUM(order_items.qty) orders, SUM(order_items.line_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'method' => DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
                ->where('orders.outlet_id', $this->outlet->id())->whereBetween('orders.business_date', [$from, $to])
                ->selectRaw('payment_methods.name label, COUNT(*) orders, SUM(payments.amount - payments.refunded_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            'receiver' => DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->join('users', 'users.id', '=', 'payments.received_by')
                ->where('orders.outlet_id', $this->outlet->id())->whereBetween('orders.business_date', [$from, $to])
                ->selectRaw('users.name label, COUNT(DISTINCT payments.order_id) orders, SUM(payments.amount - payments.refunded_total) total')->groupByRaw('1')->orderByRaw('3 desc'),
            default => throw new \InvalidArgumentException('Dimensi laporan tidak dikenal.'),
        };

        return $q->get()->map(function (stdClass $r) use ($dimension): stdClass {
            $row = new stdClass;
            $row->label = (string) $r->label;
            $row->orders = (int) $r->orders;
            $row->total = (int) $r->total;
            $row->subtotal = (int) ($r->subtotal ?? 0);
            $row->discount = (int) ($r->discount ?? 0);
            $row->charges = (int) ($r->charges ?? 0);
            $row->rounding = (int) ($r->rounding ?? 0);
            $row->refunds = (int) ($r->refunds ?? 0);
            $row->net_sales = (int) ($r->net_sales ?? 0);
            $row->average = $row->orders > 0 ? intdiv($row->net_sales ?: $row->total, $row->orders) : 0;
            $row->display = $this->display($dimension, $row->label);

            return $row;
        });
    }

    /** Human label for a group: dates and order types are shown the way people read them. */
    private function display(string $dimension, string $label): string
    {
        return match ($dimension) {
            'day' => CarbonImmutable::parse($label)->translatedFormat('D, d M Y'),
            'type' => self::TYPE_LABELS[$label] ?? $label,
            default => $label,
        };
    }

    /**
     * One row per paid or refunded order: when, who, how it was paid and the full money breakdown.
     *
     * @return Collection<int, stdClass>
     */
    public function transactions(string $from, string $to): Collection
    {
        $methods = "(SELECT string_agg(DISTINCT pm.name, ', ') FROM payments p JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.order_id = orders.id)";
        $items = "(SELECT string_agg(oi.qty || 'x ' || oi.name_snapshot, '; ' ORDER BY oi.id) FROM order_items oi WHERE oi.order_id = orders.id AND oi.status IN ('new','preparing','ready'))";
        $receivers = "(SELECT string_agg(DISTINCT ru.name, ', ') FROM payments p JOIN users ru ON ru.id = p.received_by WHERE p.order_id = orders.id)";

        return $this->paidOrders($from, $to)
            ->leftJoin('users as opener', 'opener.id', '=', 'orders.opened_by')->leftJoin('tables', 'tables.id', '=', 'orders.table_id')
            ->selectRaw("orders.number, orders.business_date, orders.closed_at, orders.type, orders.status, tables.name as table_name, orders.customer_name, opener.name as recorder, {$receivers} as receiver, {$methods} as methods, {$items} as items, orders.subtotal, orders.discount_total, orders.charge_total, orders.rounding, orders.grand_total, orders.refunded_total")
            ->orderBy('orders.business_date')->orderBy('orders.closed_at')->orderBy('orders.id')->get();
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
            ->select('orders.number', 'orders.business_date as date', 'discounts.created_at as at', 'discounts.type', 'discounts.value', 'discounts.amount', 'discounts.reason', 'cu.name as actor', 'au.name as approver')->get();

        $voids = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('users as vu', 'vu.id', '=', 'order_items.voided_by')->leftJoin('users as au', 'au.id', '=', 'order_items.void_approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])->whereNotNull('order_items.voided_at')
            ->select('orders.number', 'orders.business_date as date', 'order_items.voided_at as at', 'order_items.name_snapshot as item', 'order_items.qty', 'order_items.line_total as amount', 'order_items.void_reason as reason', 'vu.name as actor', 'au.name as approver')->get();

        $refunds = DB::table('refunds')->join('orders', 'orders.id', '=', 'refunds.order_id')->leftJoin('users as au', 'au.id', '=', 'refunds.approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])
            ->select('orders.number', 'orders.business_date as date', 'refunds.created_at as at', 'refunds.amount', 'refunds.reason', 'au.name as approver')->get();

        $comps = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->leftJoin('users as au', 'au.id', '=', 'order_items.comp_approved_by')
            ->where('orders.outlet_id', $oid)->whereBetween('orders.business_date', [$from, $to])->whereNotNull('order_items.comp_type')
            ->select('orders.number', 'orders.business_date as date', 'order_items.name_snapshot as item', 'order_items.qty', 'order_items.line_total as amount', 'order_items.comp_type as type', 'order_items.comp_reason as reason', 'au.name as approver')->get();

        return compact('discounts', 'voids', 'refunds', 'comps');
    }
}
