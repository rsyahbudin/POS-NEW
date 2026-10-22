<?php

namespace App\Domain\Reporting;

use App\Domain\Settings\OutletContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/** What was thrown away, why, who recorded it, and what it cost. */
class WasteReports
{
    public const DIMENSIONS = ['item', 'reason', 'person', 'day'];

    public function __construct(private OutletContext $outlet) {}

    private function base(string $from, string $to): Builder
    {
        return DB::table('waste_entries')->where('waste_entries.outlet_id', $this->outlet->id())
            ->where('waste_entries.created_at', '>=', $from.' 00:00:00')->where('waste_entries.created_at', '<=', $to.' 23:59:59');
    }

    /** @return array{cost: int, entries: int, top_item: string|null, top_reason: string|null} */
    public function summary(string $from, string $to): array
    {
        $r = $this->base($from, $to)->selectRaw('COALESCE(SUM(cost),0) as cost, COUNT(*) as entries')->sole();
        $topItem = $this->base($from, $to)->selectRaw('label as name, SUM(cost) as c')->groupBy('label')->orderByDesc('c')->value('name');
        $topReason = $this->base($from, $to)->selectRaw('reason as name, SUM(cost) as c')->groupBy('reason')->orderByDesc('c')->value('name');

        return ['cost' => (int) $r->cost, 'entries' => (int) $r->entries, 'top_item' => $topItem === null ? null : (string) $topItem, 'top_reason' => $topReason === null ? null : (string) $topReason];
    }

    /**
     * Rows: label, entries, cost, share_pct.
     *
     * @return array<int, stdClass>
     */
    public function by(string $dimension, string $from, string $to): array
    {
        $q = $this->base($from, $to);
        $q = match ($dimension) {
            'item' => $q->selectRaw('waste_entries.label as label')->groupByRaw('1'),
            'reason' => $q->selectRaw('waste_entries.reason as label')->groupByRaw('1'),
            'person' => $q->leftJoin('users', 'users.id', '=', 'waste_entries.user_id')->selectRaw("COALESCE(users.name,'-') as label")->groupByRaw('1'),
            'day' => $q->selectRaw("to_char(waste_entries.created_at,'YYYY-MM-DD') as label")->groupByRaw('1')->orderByRaw('1'),
            default => throw new InvalidArgumentException('Dimensi tidak dikenal.'),
        };
        if ($dimension !== 'day') {
            $q->orderByRaw('SUM(waste_entries.cost) desc');
        }
        $rows = $q->selectRaw('COUNT(*) as entries, COALESCE(SUM(waste_entries.cost),0) as cost')->get();
        $total = max(1, (int) $rows->sum('cost'));

        return $rows->map(function (stdClass $r) use ($total): stdClass {
            $row = new stdClass;
            $row->label = (string) $r->label;
            $row->entries = (int) $r->entries;
            $row->cost = (int) $r->cost;
            $row->share_pct = round($row->cost / $total * 100, 1);

            return $row;
        })->all();
    }

    /**
     * Newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entries(string $from, string $to, int $limit = 300): array
    {
        return $this->base($from, $to)->leftJoin('users', 'users.id', '=', 'waste_entries.user_id')->orderByDesc('waste_entries.id')->limit($limit)
            ->get(['waste_entries.id', 'waste_entries.created_at', 'waste_entries.label', 'waste_entries.qty', 'waste_entries.unit', 'waste_entries.reason', 'waste_entries.note', 'waste_entries.cost', 'users.name as user'])
            ->map(fn ($e) => [
                'id' => $e->id, 'at' => substr((string) $e->created_at, 0, 16), 'label' => $e->label, 'qty' => (float) $e->qty, 'unit' => $e->unit,
                'reason' => $e->reason, 'note' => $e->note, 'cost' => (int) $e->cost, 'user' => $e->user,
            ])->all();
    }
}
