<?php

namespace App\Domain\Reporting;

use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Settings\OutletContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * What was bought, from whom, how much of it, at what price, and who wrote it down. Only approved purchases moved stock and
 * costs, so that is what counts by default; pending and rejected ones can be looked at on their own.
 */
class PurchaseReports
{
    public const DIMENSIONS = ['ingredient', 'supplier', 'day', 'person'];

    public const STATUSES = ['approved', 'pending', 'rejected', 'all'];

    public function __construct(private OutletContext $outlet) {}

    private function base(string $from, string $to, string $status, ?int $supplierId): Builder
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('ingredients', 'ingredients.id', '=', 'purchase_items.ingredient_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.user_id')
            ->where('purchases.outlet_id', $this->outlet->id())
            ->whereBetween('purchases.purchased_on', [$from, $to])
            ->when($status !== 'all', fn ($q) => $q->where('purchases.status', $status))
            ->when($supplierId !== null, fn ($q) => $q->where('purchases.supplier_id', $supplierId));
    }

    /** @return array{total: int, notes: int, ingredients: int, average: int, top_ingredient: string|null, top_supplier: string|null} */
    public function summary(string $from, string $to, string $status = 'approved', ?int $supplierId = null): array
    {
        $r = $this->base($from, $to, $status, $supplierId)
            ->selectRaw('COALESCE(SUM(purchase_items.total),0) AS total, COUNT(DISTINCT purchases.id) AS notes, COUNT(DISTINCT purchase_items.ingredient_id) AS ingredients')->sole();
        $topIngredient = $this->base($from, $to, $status, $supplierId)->selectRaw('ingredients.name AS name, SUM(purchase_items.total) AS t')->groupByRaw('ingredients.name')->orderByDesc('t')->value('name');
        $topSupplier = $this->base($from, $to, $status, $supplierId)->selectRaw("COALESCE(suppliers.name, purchases.supplier, 'Tanpa pemasok') AS name, SUM(purchase_items.total) AS t")->groupByRaw('1')->orderByDesc('t')->value('name');
        $notes = (int) $r->notes;

        return [
            'total' => (int) $r->total, 'notes' => $notes, 'ingredients' => (int) $r->ingredients, 'average' => $notes > 0 ? intdiv((int) $r->total, $notes) : 0,
            'top_ingredient' => $topIngredient === null ? null : (string) $topIngredient,
            'top_supplier' => $topSupplier === null ? null : (string) $topSupplier,
        ];
    }

    /**
     * Rows: label, notes, qty and unit (per ingredient only), total, average price per unit, lowest and highest price, share_pct.
     *
     * @return list<stdClass>
     */
    public function by(string $dimension, string $from, string $to, string $status = 'approved', ?int $supplierId = null): array
    {
        $q = $this->base($from, $to, $status, $supplierId);
        $q = match ($dimension) {
            'ingredient' => $q->selectRaw('ingredients.name AS label, ingredients.unit AS unit, ingredients.id AS ingredient_id, SUM(purchase_items.qty) AS qty, MIN(purchase_items.unit_cost) AS low, MAX(purchase_items.unit_cost) AS high')->groupByRaw('ingredients.id, ingredients.name, ingredients.unit'),
            'supplier' => $q->selectRaw("COALESCE(suppliers.name, purchases.supplier, 'Tanpa pemasok') AS label")->groupByRaw('1'),
            'person' => $q->selectRaw("COALESCE(users.name, '-') AS label")->groupByRaw('1'),
            'day' => $q->selectRaw("to_char(purchases.purchased_on,'YYYY-MM-DD') AS label")->groupByRaw('1')->orderByRaw('1'),
            default => throw new InvalidArgumentException('Dimensi tidak dikenal.'),
        };
        if ($dimension !== 'day') {
            $q->orderByRaw('SUM(purchase_items.total) desc');
        }
        $rows = $q->selectRaw('COUNT(DISTINCT purchases.id) AS notes, COALESCE(SUM(purchase_items.total),0) AS total')->get();
        $sum = max(1, (int) $rows->sum('total'));
        $packs = $dimension === 'ingredient' ? $this->packMap($rows->pluck('ingredient_id')) : [];

        return array_values($rows->map(function (stdClass $r) use ($dimension, $sum, $packs): stdClass {
            $row = new stdClass;
            $row->label = (string) $r->label;
            $row->notes = (int) $r->notes;
            $row->total = (int) $r->total;
            $row->share_pct = round($row->total / $sum * 100, 1);
            if ($dimension === 'ingredient') {
                $qty = (float) $r->qty;
                $row->qty = $qty;
                $row->unit = (string) $r->unit;
                $row->qty_label = self::qtyLabel($qty, (string) $r->unit, $packs[(int) $r->ingredient_id] ?? []);
                $row->average = $qty > 0 ? round($row->total / $qty, 2) : 0.0;
                $row->low = round((float) $r->low, 2);
                $row->high = round((float) $r->high, 2);
            }

            return $row;
        })->all());
    }

    /**
     * One row per purchased line, newest first, with who bought it and in which note.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $from, string $to, string $status = 'approved', ?int $supplierId = null, int $limit = 600): array
    {
        $rows = $this->base($from, $to, $status, $supplierId)->orderByDesc('purchases.purchased_on')->orderByDesc('purchases.id')->orderBy('purchase_items.id')->limit($limit)
            ->get(['purchases.id AS purchase_id', 'purchases.purchased_on', 'purchases.status', 'purchases.note', 'purchases.total AS note_total', 'purchase_items.qty', 'purchase_items.unit_cost', 'purchase_items.total',
                'ingredients.id AS ingredient_id', 'ingredients.name AS ingredient', 'ingredients.unit', 'users.name AS user', DB::raw("COALESCE(suppliers.name, purchases.supplier, 'Tanpa pemasok') AS supplier")]);
        $packs = $this->packMap($rows->pluck('ingredient_id')->unique());

        return array_values($rows->map(fn ($e) => [
            'purchase_id' => (int) $e->purchase_id, 'date' => (string) $e->purchased_on, 'status' => (string) $e->status, 'note' => $e->note, 'supplier' => (string) $e->supplier, 'user' => $e->user,
            'ingredient' => (string) $e->ingredient, 'qty' => (float) $e->qty, 'unit' => (string) $e->unit, 'qty_label' => self::qtyLabel((float) $e->qty, (string) $e->unit, $packs[(int) $e->ingredient_id] ?? []),
            'unit_cost' => round((float) $e->unit_cost, 2), 'total' => (int) $e->total,
        ])->all());
    }

    /**
     * The packages each ingredient is bought in, biggest first.
     *
     * @param  iterable<mixed>  $ids
     * @return array<int, list<array{name: string, factor: float}>>
     */
    private function packMap(iterable $ids): array
    {
        $map = [];
        foreach (IngredientUnit::query()->whereIn('ingredient_id', $ids)->orderByDesc('factor')->get() as $u) {
            $map[(int) $u->ingredient_id][] = ['name' => (string) $u->name, 'factor' => (float) $u->factor];
        }

        return $map;
    }

    /**
     * "2 ekor (1.400 g)" when the amount is whole packages, else "350 g".
     *
     * @param  list<array{name: string, factor: float}>  $packs  biggest first
     */
    public static function qtyLabel(float $qty, string $unit, array $packs): string
    {
        $fmt = fn (float $n) => number_format($n, 3, ',', '.') === '' ? '0' : rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
        foreach ($packs as $p) {
            if ($p['factor'] > 1 && $qty >= $p['factor']) {
                $n = $qty / $p['factor'];
                if (abs($n - round($n)) < 0.0005) {
                    return $fmt(round($n)).' '.$p['name'].' ('.$fmt($qty).' '.$unit.')';
                }
            }
        }

        return $fmt($qty).' '.$unit;
    }
}
