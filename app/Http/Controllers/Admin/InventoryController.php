<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\StockCountService;
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
            'ingredients' => Ingredient::query()->where('outlet_id', $id)->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'stock' => $i->stock, 'avg_cost' => round($i->avg_cost, 2),
                'min_stock' => $i->min_stock, 'value' => (int) round(max(0, $i->stock) * $i->avg_cost), 'is_active' => $i->is_active,
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
            'unit' => 'required|string|max:12', 'min_stock' => 'nullable|numeric|min:0',
            'stock' => 'nullable|numeric|min:0', 'unit_cost' => 'nullable|numeric|min:0',
        ]);
        $ingredient = Ingredient::create([
            'outlet_id' => $outletId, 'name' => $data['name'], 'unit' => $data['unit'], 'min_stock' => $data['min_stock'] ?? 0,
            'avg_cost' => $data['unit_cost'] ?? 0,
        ]);
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
            'unit' => 'required|string|max:12', 'min_stock' => 'nullable|numeric|min:0', 'is_active' => 'boolean',
        ]);
        $ingredient->update(['name' => $data['name'], 'unit' => $data['unit'], 'min_stock' => $data['min_stock'] ?? 0, 'is_active' => (bool) ($data['is_active'] ?? true)]);

        return back()->with('success', 'Bahan disimpan.');
    }

    public function purchase(Request $request): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'supplier' => 'nullable|string|max:80', 'purchased_on' => 'required|date', 'note' => 'nullable|string|max:200',
            'items' => 'required|array|min:1|max:60',
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('outlet_id', $outletId)],
            'items.*.qty' => 'required|numeric|gt:0', 'items.*.unit_cost' => 'required|numeric|min:0',
        ]);
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
        $data = $request->validate(['qty' => 'required|numeric|gt:0', 'note' => 'required|string|max:200']);
        $this->inventory->waste($ingredient, (float) $data['qty'], $this->actor($request), $data['note']);

        return back()->with('success', 'Bahan terbuang dicatat.');
    }
}
