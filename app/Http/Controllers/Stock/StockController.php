<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\Units;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** What staff see of the stock: a read-only list and the stock count form. Costs stay with managers. */
class StockController extends Controller
{
    public function __construct(private OutletContext $outlet, private StockCountService $counts, private InventoryService $inventory, private SettingsRepository $settings) {}

    public function index(Request $request): Response
    {
        $user = $this->actor($request);

        return Inertia::render('stock/index', [
            'ingredients' => Ingredient::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'stock' => $i->stock, 'min_stock' => $i->min_stock, 'low' => $i->stock <= $i->min_stock,
            ])->all(),
            'canCount' => $user->can('count-stock'),
        ]);
    }

    public function count(Request $request): Response
    {
        $user = $this->actor($request);
        $draft = $this->counts->draftFor($user, $this->outlet->id());
        $saved = $draft->lines()->pluck('counted', 'ingredient_id');
        $showSystem = ! $this->counts->blind() || $user->can('manage-inventory');

        return Inertia::render('stock/count', [
            'draft' => ['id' => $draft->id, 'note' => $draft->note],
            'blind' => ! $showSystem,
            'needsApproval' => $this->counts->needsApproval(),
            'ingredients' => Ingredient::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->with('packs')->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'choices' => Units::choices($i), 'counted' => $saved->get($i->id),
                'system' => $showSystem ? $i->stock : null,
            ])->all(),
            'history' => StockCount::query()->where('user_id', $user->id)->where('status', '!=', 'draft')->latest('id')->limit(8)->get()->map(fn (StockCount $c) => [
                'id' => $c->id, 'status' => $c->status, 'at' => $c->submitted_at?->setTimezone('Asia/Jakarta')->format('d M H:i'), 'lines' => $c->lines()->count(), 'review_note' => $c->review_note,
            ])->all(),
        ]);
    }

    /** Shopping staff record what they bought; stock and costs move only once a manager approves (unless the rule is off). */
    public function purchase(Request $request): Response
    {
        $user = $this->actor($request);
        $id = $this->outlet->id();

        return Inertia::render('stock/purchase', [
            'suppliers' => Supplier::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'needsApproval' => ! $user->can('manage-inventory') && (bool) $this->settings->get('inventory.purchase_needs_approval', true),
            'ingredients' => Ingredient::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'choices' => Units::choices($i),
            ])->all(),
            'history' => Purchase::query()->where('outlet_id', $id)->where('user_id', $user->id)->with('items')->latest('id')->limit(15)->get()->map(fn (Purchase $p) => [
                'id' => $p->id, 'purchased_on' => $p->purchased_on->toDateString(), 'supplier' => $p->supplier, 'total' => $p->total, 'status' => $p->status,
                'items' => $p->items->count(), 'review_note' => $p->review_note,
            ])->all(),
        ]);
    }

    public function submitPurchase(Request $request): RedirectResponse
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
        $purchase = $this->inventory->submitPurchase($outletId, $data, $this->actor($request));

        return redirect()->route('stock.purchase')->with('success', $purchase->status === 'approved' ? 'Belanja dicatat dan stok bertambah.' : 'Belanja dikirim. Stok bertambah setelah disetujui manager.');
    }

    public function save(Request $request): RedirectResponse
    {
        $draft = $this->persist($request);

        return back()->with('success', 'Draf hitungan disimpan.')->with('draft', $draft->id);
    }

    public function submit(Request $request): RedirectResponse
    {
        $user = $this->actor($request);
        $draft = $this->persist($request);
        $count = $this->counts->submit($draft, $user);

        return redirect()->route('stock.count')->with('success', $count->status === 'approved' ? 'Hitungan dikirim dan stok disesuaikan.' : 'Hitungan dikirim. Menunggu persetujuan manager.');
    }

    private function persist(Request $request): StockCount
    {
        $data = $request->validate(['lines' => 'present|array', 'lines.*.ingredient_id' => 'required|integer', 'lines.*.counted' => 'nullable|numeric|min:0', 'lines.*.unit' => 'nullable|string|max:20', 'note' => 'nullable|string|max:200']);
        $draft = $this->counts->draftFor($this->actor($request), $this->outlet->id());

        $ingredients = Ingredient::query()->where('outlet_id', $this->outlet->id())->with('packs')->get()->keyBy('id');
        $counted = [];
        foreach ($data['lines'] as $line) {
            $ingredient = $ingredients->get((int) $line['ingredient_id']);
            $qty = $line['counted'] ?? null;
            // quantities may be entered in a packaging; the count is kept in the base unit
            $counted[(int) $line['ingredient_id']] = $ingredient !== null && $qty !== null ? Units::toBase($ingredient, $line['unit'] ?? null, (float) $qty) : null;
        }

        return $this->counts->save($draft, $counted, $data['note'] ?? null);
    }
}
