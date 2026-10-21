<?php

namespace App\Domain\Reporting;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Settings\OutletContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Profit per menu item: sales minus the ingredient cost (HPP) frozen on each order line.
 * Only paid orders count; comps and staff meals count as cost with no revenue. Order-level
 * discounts are not allocated to lines, so revenue here is before such discounts.
 */
class MarginReports
{
    public const DIMENSIONS = ['product', 'category', 'day'];

    public function __construct(private OutletContext $outlet) {}

    private function base(string $from, string $to): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $this->outlet->id())->where('orders.status', 'paid')
            ->whereBetween('orders.business_date', [$from, $to])
            ->where('order_items.status', '!=', 'voided');
    }

    private const REVENUE = 'SUM(CASE WHEN order_items.comp_type IS NULL THEN order_items.line_total ELSE 0 END)';

    /** @return array{revenue: int, cogs: int, profit: int, margin_pct: float, uncosted_qty: int} */
    public function summary(string $from, string $to): array
    {
        $r = $this->base($from, $to)->selectRaw(self::REVENUE.' as revenue, COALESCE(SUM(order_items.cogs),0) as cogs, COALESCE(SUM(CASE WHEN order_items.inventory_consumed THEN 0 ELSE order_items.qty END),0) as uncosted')->sole();
        $revenue = (int) $r->revenue;
        $cogs = (int) $r->cogs;

        return [
            'revenue' => $revenue, 'cogs' => $cogs, 'profit' => $revenue - $cogs,
            'margin_pct' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 1) : 0.0, 'uncosted_qty' => (int) $r->uncosted,
        ];
    }

    /** @return Collection<int, stdClass> rows: label, qty, revenue, cogs, profit, margin_pct, uncosted_qty */
    public function by(string $dimension, string $from, string $to): Collection
    {
        $q = $this->base($from, $to);
        $q = match ($dimension) {
            'product' => $q->selectRaw('order_items.name_snapshot as label')->groupByRaw('1'),
            'category' => $q->join('products', 'products.id', '=', 'order_items.product_id')->join('categories', 'categories.id', '=', 'products.category_id')->selectRaw('categories.name as label')->groupByRaw('1'),
            'day' => $q->selectRaw("to_char(orders.business_date,'YYYY-MM-DD') as label")->groupByRaw('1')->orderByRaw('1'),
            default => throw new InvalidArgumentException('Dimensi tidak dikenal.'),
        };
        if ($dimension !== 'day') {
            $q->orderByRaw(self::REVENUE.' desc');
        }

        return $q->selectRaw('SUM(order_items.qty) as qty, '.self::REVENUE.' as revenue, COALESCE(SUM(order_items.cogs),0) as cogs, COALESCE(SUM(CASE WHEN order_items.inventory_consumed THEN 0 ELSE order_items.qty END),0) as uncosted')
            ->get()->map(function (stdClass $r): stdClass {
                $row = new stdClass;
                $row->label = (string) $r->label;
                $row->qty = (int) $r->qty;
                $row->revenue = (int) $r->revenue;
                $row->cogs = (int) $r->cogs;
                $row->profit = $row->revenue - $row->cogs;
                $row->margin_pct = $row->revenue > 0 ? round($row->profit / $row->revenue * 100, 1) : 0.0;
                $row->uncosted_qty = (int) $r->uncosted;

                return $row;
            });
    }

    /** @return Collection<int, stdClass> rows: name, unit, stock, avg_cost, value, min_stock, low */
    public function stock(): Collection
    {
        return Ingredient::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('name')->get()->map(function (Ingredient $i): stdClass {
            $row = new stdClass;
            $row->name = $i->name;
            $row->unit = $i->unit;
            $row->stock = $i->stock;
            $row->avg_cost = round($i->avg_cost, 2);
            $row->value = (int) round(max(0, $i->stock) * $i->avg_cost);
            $row->min_stock = $i->min_stock;
            $row->low = $i->stock <= $i->min_stock;

            return $row;
        });
    }
}
