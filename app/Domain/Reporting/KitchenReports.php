<?php

namespace App\Domain\Reporting;

use App\Domain\Settings\OutletContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * How long the kitchen takes: from the moment a round is confirmed until the
 * item is marked "Siap" (waiting = confirmed -> started, making = started -> ready).
 * Only items that were actually advanced on the KDS are measured.
 */
class KitchenReports
{
    public const DIMENSIONS = ['day', 'hour', 'product', 'station'];

    public function __construct(private OutletContext $outlet) {}

    private function base(string $from, string $to): Builder
    {
        return DB::table('order_items')
            ->join('order_rounds', 'order_rounds.id', '=', 'order_items.order_round_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $this->outlet->id())
            ->whereBetween('orders.business_date', [$from, $to])
            ->whereNotNull('order_items.ready_at')->whereNotNull('order_rounds.confirmed_at')
            ->where('order_items.status', '!=', 'voided');
    }

    private const TOTAL = 'EXTRACT(EPOCH FROM (order_items.ready_at - order_rounds.confirmed_at))/60.0';

    /** @return array{items: int, avg_minutes: float, p90_minutes: float, avg_wait: float, avg_make: float, slow: int} */
    public function summary(string $from, string $to, int $slowAfterMinutes): array
    {
        $r = $this->base($from, $to)->selectRaw('
            COUNT(*) as items,
            COALESCE(AVG('.self::TOTAL.'),0) as avg_total,
            COALESCE(percentile_cont(0.9) WITHIN GROUP (ORDER BY '.self::TOTAL.'),0) as p90,
            COALESCE(AVG(EXTRACT(EPOCH FROM (order_items.preparing_at - order_rounds.confirmed_at))/60.0),0) as avg_wait,
            COALESCE(AVG(EXTRACT(EPOCH FROM (order_items.ready_at - order_items.preparing_at))/60.0),0) as avg_make,
            COUNT(*) FILTER (WHERE '.self::TOTAL.' > ?) as slow', [$slowAfterMinutes])->sole();

        return [
            'items' => (int) $r->items, 'avg_minutes' => round((float) $r->avg_total, 1), 'p90_minutes' => round((float) $r->p90, 1),
            'avg_wait' => round((float) $r->avg_wait, 1), 'avg_make' => round((float) $r->avg_make, 1), 'slow' => (int) $r->slow,
        ];
    }

    /** @return Collection<int, stdClass> rows: label, items, avg_minutes, p90_minutes, max_minutes */
    public function by(string $dimension, string $from, string $to): Collection
    {
        $q = $this->base($from, $to);
        $q = match ($dimension) {
            'day' => $q->selectRaw("to_char(orders.business_date,'YYYY-MM-DD') as label")->groupByRaw('1')->orderByRaw('1'),
            'hour' => $q->selectRaw("to_char(order_rounds.confirmed_at,'HH24') || ':00' as label")->groupByRaw('1')->orderByRaw('1'),
            'product' => $q->selectRaw('order_items.name_snapshot as label')->groupByRaw('1')->orderByRaw('3 desc'),
            'station' => $q->leftJoin('stations', 'stations.id', '=', 'order_items.station_id')->selectRaw("COALESCE(stations.name,'-') as label")->groupByRaw('1')->orderByRaw('3 desc'),
            default => throw new InvalidArgumentException('Dimensi tidak dikenal.'),
        };

        return $q->selectRaw('COUNT(*) as items, AVG('.self::TOTAL.') as avg_minutes, percentile_cont(0.9) WITHIN GROUP (ORDER BY '.self::TOTAL.') as p90_minutes, MAX('.self::TOTAL.') as max_minutes')
            ->get()->map(function (stdClass $r): stdClass {
                $row = new stdClass;
                $row->label = (string) $r->label;
                $row->items = (int) $r->items;
                $row->avg_minutes = round((float) $r->avg_minutes, 1);
                $row->p90_minutes = round((float) $r->p90_minutes, 1);
                $row->max_minutes = round((float) $r->max_minutes, 1);

                return $row;
            });
    }
}
