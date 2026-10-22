<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseItem;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Who ingredients are bought from: contact data, purchase history, and last prices to compare. */
class SupplierController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(): Response
    {
        $id = $this->outlet->id();
        $stats = Purchase::query()->where('outlet_id', $id)->where('status', 'approved')->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as purchases, SUM(total) as total, MAX(purchased_on) as last_on, SUM(CASE WHEN purchased_on >= ? THEN total ELSE 0 END) as total_30', [now()->subDays(30)->toDateString()])
            ->groupBy('supplier_id')->get()->keyBy('supplier_id');
        $preferred = Ingredient::query()->where('outlet_id', $id)->whereNotNull('preferred_supplier_id')->selectRaw('preferred_supplier_id, COUNT(*) as n')->groupBy('preferred_supplier_id')->pluck('n', 'preferred_supplier_id');

        return Inertia::render('admin/suppliers', [
            'suppliers' => Supplier::query()->where('outlet_id', $id)->orderBy('name')->get()->map(fn (Supplier $s) => [
                'id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'address' => $s->address, 'note' => $s->note, 'is_active' => $s->is_active,
                'purchases' => (int) ($stats[$s->id]->purchases ?? 0), 'total' => (int) ($stats[$s->id]->total ?? 0), 'total_30' => (int) ($stats[$s->id]->total_30 ?? 0),
                'last_on' => $stats[$s->id]->last_on ?? null, 'ingredients' => (int) ($preferred[$s->id] ?? 0),
            ])->all(),
            'compare' => $this->compare($id),
        ]);
    }

    /**
     * Last purchase price of every ingredient per supplier, cheapest first (per base unit).
     *
     * @return array<int, array<string, mixed>>
     */
    private function compare(int $outletId): array
    {
        $rows = PurchaseItem::query()->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->join('ingredients', 'ingredients.id', '=', 'purchase_items.ingredient_id')
            ->where('purchases.outlet_id', $outletId)->where('purchases.status', 'approved')
            ->orderByDesc('purchases.purchased_on')->orderByDesc('purchases.id')->limit(3000)->toBase()
            ->get(['ingredients.id as ingredient_id', 'ingredients.name as ingredient', 'ingredients.unit', 'suppliers.id as supplier_id', 'suppliers.name as supplier', 'purchase_items.unit_cost', 'purchases.purchased_on']);
        $out = [];
        foreach ($rows->map(fn ($r) => (array) $r)->groupBy('ingredient_id') as $items) {
            $offers = [];
            foreach ($items->groupBy('supplier_id') as $mine) {
                $latest = $mine->first();
                $offers[] = ['supplier' => (string) data_get($latest, 'supplier'), 'cost' => round((float) data_get($latest, 'unit_cost'), 2), 'on' => (string) data_get($latest, 'purchased_on')];
            }
            usort($offers, fn ($a, $b) => $a['cost'] <=> $b['cost']);
            $first = $items->first();
            $out[] = ['ingredient' => (string) data_get($first, 'ingredient'), 'unit' => (string) data_get($first, 'unit'), 'offers' => $offers];
        }
        usort($out, fn ($a, $b) => strcmp($a['ingredient'], $b['ingredient']));

        return $out;
    }

    public function show(Supplier $supplier): JsonResponse
    {
        abort_unless($supplier->outlet_id === $this->outlet->id(), 404);
        $purchases = Purchase::query()->where('supplier_id', $supplier->id)->with('items')->latest('purchased_on')->latest('id')->limit(30)->get();
        $names = Ingredient::query()->whereIn('id', $purchases->flatMap->items->pluck('ingredient_id'))->get()->keyBy('id');

        return response()->json([
            'purchases' => $purchases->map(fn (Purchase $p) => [
                'id' => $p->id, 'on' => $p->purchased_on->toDateString(), 'total' => $p->total, 'status' => $p->status,
                'items' => $p->items->map(fn ($i) => ['name' => $names[$i->ingredient_id]->name ?? '-', 'unit' => $names[$i->ingredient_id]->unit ?? '', 'qty' => $i->qty, 'unit_cost' => round($i->unit_cost, 2)])->values()->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->data($request) + ['outlet_id' => $this->outlet->id()]);
        Audit::log('supplier.created', $supplier, ['name' => $supplier->name]);

        return back()->with('success', 'Pemasok ditambahkan.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        abort_unless($supplier->outlet_id === $this->outlet->id(), 404);
        $supplier->update($this->data($request, $supplier));
        Audit::log('supplier.updated', $supplier, ['name' => $supplier->name]);

        return back()->with('success', 'Pemasok disimpan.');
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('suppliers', 'name')->where('outlet_id', $this->outlet->id())->ignore($supplier?->id)],
            'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:200', 'note' => 'nullable|string|max:300', 'is_active' => 'boolean',
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }
}
