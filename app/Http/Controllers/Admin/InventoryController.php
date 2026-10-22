<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\Units;
use App\Domain\Inventory\WasteService;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Ingredient list, purchases (goods received), stock counts and waste. */
class InventoryController extends Controller
{
    public function __construct(private OutletContext $outlet, private InventoryService $inventory) {}

    public function index(SettingsRepository $settings): Response
    {
        $id = $this->outlet->id();
        $users = User::query()->pluck('name', 'id');

        return Inertia::render('admin/inventory', [
            'enabled' => $this->inventory->enabled(),
            'suppliers' => Supplier::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'wasteReasons' => WasteReason::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('sort')->orderBy('id')->get(['id', 'name'])->all(),
            'baseUnits' => collect(Units::BASE)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'ingredients' => Ingredient::query()->where('outlet_id', $id)->with('packs')->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'stock' => $i->stock, 'avg_cost' => round($i->avg_cost, 2),
                'min_stock' => $i->min_stock, 'value' => (int) round(max(0, $i->stock) * $i->avg_cost), 'is_active' => $i->is_active,
                'packs' => $i->packs->map(fn ($u) => ['name' => $u->name, 'factor' => $u->factor])->values()->all(), 'choices' => Units::choices($i),
                'low' => $i->is_active && $i->stock <= $i->min_stock,
            ])->all(),
            'purchases' => Purchase::query()->where('outlet_id', $id)->with('items')->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest('purchased_on')->latest('id')->limit(40)->get()->map(function (Purchase $p) use ($users) {
                $names = Ingredient::query()->whereIn('id', $p->items->pluck('ingredient_id'))->get()->keyBy('id');

                return [
                    'id' => $p->id, 'supplier' => $p->supplier, 'purchased_on' => $p->purchased_on->toDateString(), 'note' => $p->note, 'total' => $p->total,
                    'items_count' => $p->items->count(), 'user' => $users[$p->user_id] ?? null, 'status' => $p->status, 'review_note' => $p->review_note,
                    'items' => $p->items->map(fn ($i) => ['name' => $names[$i->ingredient_id]->name ?? '-', 'unit' => $names[$i->ingredient_id]->unit ?? '', 'qty' => $i->qty, 'unit_cost' => round($i->unit_cost, 2), 'total' => $i->total])->values()->all(),
                ];
            })->all(),
            'counts' => StockCount::query()->where('outlet_id', $id)->where('status', '!=', 'draft')->with('lines')->latest('id')->limit(30)->get()->map(function (StockCount $c) use ($users) {
                $costs = Ingredient::query()->whereIn('id', $c->lines->pluck('ingredient_id'))->get()->keyBy('id');

                return [
                    'id' => $c->id, 'status' => $c->status, 'user' => $users[$c->user_id] ?? null, 'at' => $c->submitted_at?->setTimezone('Asia/Jakarta')->format('d M H:i'),
                    'review_note' => $c->review_note, 'reviewer' => $users[$c->reviewed_by] ?? null,
                    'lines' => $c->lines->map(fn ($l) => [
                        'ingredient' => $costs[$l->ingredient_id]->name ?? '-', 'unit' => $costs[$l->ingredient_id]->unit ?? '', 'system' => $l->system_qty, 'counted' => $l->counted,
                        'diff' => round($l->counted - (float) $l->system_qty, 3), 'value' => (int) round(($l->counted - (float) $l->system_qty) * (float) ($costs[$l->ingredient_id]->avg_cost ?? 0)),
                    ])->values()->all(),
                ];
            })->all(),
            'movements' => StockMovement::query()->where('outlet_id', $id)->latest('id')->limit(100)->get()->map(fn (StockMovement $m) => [
                'id' => $m->id, 'ingredient_id' => $m->ingredient_id, 'type' => $m->type, 'qty' => $m->qty, 'unit_cost' => round($m->unit_cost, 2),
                'note' => $m->note, 'user' => $users[$m->user_id] ?? null, 'at' => $m->created_at?->setTimezone('Asia/Jakarta')->format('d M H:i'),
            ])->all(),
        ]);
    }

    public function storeIngredient(Request $request): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('ingredients', 'name')->where('outlet_id', $outletId)],
            'unit' => ['required', Rule::in(array_keys(Units::BASE))], 'min_stock' => 'nullable|numeric|min:0',
            'stock' => 'nullable|numeric|min:0', 'unit_cost' => 'nullable|numeric|min:0',
            'packs' => 'nullable|array|max:6', 'packs.*.name' => 'required|string|max:20', 'packs.*.factor' => 'required|numeric|gt:0',
        ]);
        $ingredient = Ingredient::create([
            'outlet_id' => $outletId, 'name' => $data['name'], 'unit' => $data['unit'], 'min_stock' => $data['min_stock'] ?? 0,
            'avg_cost' => $data['unit_cost'] ?? 0,
        ]);
        $this->syncPacks($ingredient, $data['packs'] ?? []);
        if (($data['stock'] ?? 0) > 0) {
            $this->inventory->count($ingredient, (float) $data['stock'], $this->actor($request), 'Stok awal');
        }
        Audit::log('ingredient.created', $ingredient, ['name' => $ingredient->name]);

        return back()->with('success', 'Bahan ditambahkan.');
    }

    public function updateIngredient(Request $request, Ingredient $ingredient): RedirectResponse
    {
        abort_unless($ingredient->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('ingredients', 'name')->where('outlet_id', $ingredient->outlet_id)->ignore($ingredient->id)],
            'unit' => ['required', Rule::in([...array_keys(Units::BASE), $ingredient->unit])], 'min_stock' => 'nullable|numeric|min:0', 'is_active' => 'boolean',
            'packs' => 'nullable|array|max:6', 'packs.*.name' => 'required|string|max:20', 'packs.*.factor' => 'required|numeric|gt:0',
        ]);
        if ($data['unit'] !== $ingredient->unit && ($ingredient->stock != 0 || $ingredient->movements()->exists() || RecipeLine::query()->where('ingredient_id', $ingredient->id)->exists())) {
            return back()->with('error', 'Satuan dasar tidak bisa diubah setelah bahan punya stok, riwayat, atau resep. Buat bahan baru dengan satuan yang benar.');
        }
        $ingredient->update(['name' => $data['name'], 'unit' => $data['unit'], 'min_stock' => $data['min_stock'] ?? 0, 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $this->syncPacks($ingredient, $data['packs'] ?? []);

        return back()->with('success', 'Bahan disimpan.');
    }

    /**
     * Packagings an ingredient is bought in ("botol" = 750 ml). A name may not repeat a base unit.
     *
     * @param  array<int, array{name: string, factor: float|int|string}>  $packs
     */
    private function syncPacks(Ingredient $ingredient, array $packs): void
    {
        $reserved = [...array_keys(Units::BASE), 'kg', 'l', 'liter', 'gram', 'mililiter', 'buah'];
        $seen = [];
        $rows = [];
        foreach ($packs as $pack) {
            $name = trim((string) $pack['name']);
            $key = mb_strtolower($name);
            if ($name === '' || in_array($key, $reserved, true)) {
                throw new OrderException("Nama kemasan \"{$name}\" tidak boleh sama dengan satuan dasar (g, ml, pcs, kg, l).");
            }
            if (isset($seen[$key])) {
                throw new OrderException("Kemasan \"{$name}\" ditulis dua kali.");
            }
            $seen[$key] = true;
            $rows[] = ['name' => $name, 'factor' => (float) $pack['factor']];
        }
        IngredientUnit::query()->where('ingredient_id', $ingredient->id)->delete();
        foreach ($rows as $row) {
            IngredientUnit::create(['ingredient_id' => $ingredient->id] + $row);
        }
    }

    public function purchase(Request $request): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'supplier' => 'nullable|string|max:80', 'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('outlet_id', $outletId)], 'purchased_on' => 'required|date', 'note' => 'nullable|string|max:200',
            'items' => 'required|array|min:1|max:60',
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('outlet_id', $outletId)],
            'items.*.qty' => 'required|numeric|gt:0', 'items.*.unit' => 'nullable|string|max:20',
            'items.*.total' => 'nullable|numeric|min:0', 'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);
        $data['items'] = $this->inventory->normalizePurchaseItems($outletId, $data['items']);
        $this->inventory->receive($outletId, $data, $this->actor($request));

        return back()->with('success', 'Pembelian dicatat, stok bertambah.');
    }

    public function count(Request $request, Ingredient $ingredient): RedirectResponse
    {
        abort_unless($ingredient->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['counted' => 'required|numeric|min:0', 'note' => 'nullable|string|max:200']);
        $diff = $this->inventory->count($ingredient, (float) $data['counted'], $this->actor($request), $data['note'] ?? null);

        return back()->with('success', abs($diff) < 0.0005 ? 'Stok sudah sesuai.' : 'Stok disesuaikan ('.($diff > 0 ? '+' : '').round($diff, 3).' '.$ingredient->unit.').');
    }

    public function approvePurchase(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_unless($purchase->outlet_id === $this->outlet->id(), 404);
        $this->inventory->approvePurchase($purchase, $this->actor($request));

        return back()->with('success', 'Pembelian disetujui, stok bertambah.');
    }

    public function rejectPurchase(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_unless($purchase->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $this->inventory->rejectPurchase($purchase, $this->actor($request), $data['reason']);

        return back()->with('success', 'Pembelian ditolak.');
    }

    public function approveCount(Request $request, StockCount $count, StockCountService $counts): RedirectResponse
    {
        abort_unless($count->outlet_id === $this->outlet->id(), 404);
        $counts->approve($count, $this->actor($request));

        return back()->with('success', 'Hitungan disetujui, stok disesuaikan.');
    }

    public function rejectCount(Request $request, StockCount $count, StockCountService $counts): RedirectResponse
    {
        abort_unless($count->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $counts->reject($count, $this->actor($request), $data['reason']);

        return back()->with('success', 'Hitungan ditolak.');
    }

    public function waste(Request $request, Ingredient $ingredient): RedirectResponse
    {
        abort_unless($ingredient->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['qty' => 'required|numeric|gt:0', 'unit' => 'nullable|string|max:20', 'reason_id' => 'required|integer', 'note' => 'nullable|string|max:200']);
        app(WasteService::class)->ingredient($ingredient, (float) $data['qty'], $data['unit'] ?? null, (int) $data['reason_id'], $data['note'] ?? null, $this->actor($request));

        return back()->with('success', 'Bahan terbuang dicatat.');
    }
}
