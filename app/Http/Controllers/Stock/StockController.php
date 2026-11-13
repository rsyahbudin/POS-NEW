<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseItem;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockScope;
use App\Domain\Inventory\Units;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Actions\AddCashMovementAction;
use App\Domain\Shift\ShiftResolver;
use App\Http\Controllers\Controller;
use App\Support\ImageOptimizer;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** What staff see of the stock: a read-only list and the stock count form. Costs stay with managers. */
class StockController extends Controller
{
    public function __construct(private OutletContext $outlet, private StockCountService $counts, private InventoryService $inventory, private SettingsRepository $settings, private StockScope $scope) {}

    /** Ingredients this person handles: everything, or just those of their station when stock is held per station. */
    /** @return Builder<Ingredient> */
    private function ingredients(Request $request): Builder
    {
        $user = $this->actor($request);
        $ids = $this->scope->ingredientIds($user, $this->outlet->id());

        return Ingredient::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    /** @return array{by_station: bool, stations: list<string>} what the stock pages tell staff about their share */
    private function share(Request $request): array
    {
        $user = $this->actor($request);

        return ['by_station' => $this->scope->ingredientIds($user, $this->outlet->id()) !== null, 'stations' => $this->scope->stationNames($user)];
    }

    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $canSeeCost = $user->can('manage-inventory');
        // when each ingredient was last counted (a submitted count that included it)
        $lastCount = DB::table('stock_count_lines')->join('stock_counts', 'stock_counts.id', '=', 'stock_count_lines.stock_count_id')
            ->where('stock_counts.outlet_id', $this->outlet->id())->whereIn('stock_counts.status', ['submitted', 'approved'])
            ->groupBy('stock_count_lines.ingredient_id')->selectRaw('stock_count_lines.ingredient_id as id, max(stock_counts.submitted_at) as at')->pluck('at', 'id')
            ->map(fn ($at) => Carbon::parse((string) $at)->setTimezone('Asia/Jakarta')->toDateString())->all();

        return Inertia::render('stock/index', [
            'share' => $this->share($request),
            'ingredients' => $this->ingredients($request)->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'stock' => $i->stock, 'min_stock' => $i->min_stock, 'low' => $i->stock <= $i->min_stock,
                'value' => $canSeeCost ? (int) round(max($i->stock, 0) * $i->avg_cost) : null,
                'counted_at' => $lastCount[$i->id] ?? null,
            ])->all(),
            'canCount' => $user->can('count-stock'),
            'canBuy' => $user->can('record-purchase'),
            'showValue' => $canSeeCost,
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
            'share' => $this->share($request),
            'ingredients' => $this->ingredients($request)->with('packs')->orderBy('name')->get()->map(fn (Ingredient $i) => [
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
        $ingredients = $this->ingredients($request)->with('packs')->orderBy('name')->get();
        $ids = $ingredients->pluck('id')->all();

        // what each ingredient last cost per base unit, from approved purchases
        $latest = PurchaseItem::query()->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.outlet_id', $id)->where('purchases.status', 'approved')->whereIn('purchase_items.ingredient_id', $ids)
            ->groupBy('purchase_items.ingredient_id')->selectRaw('max(purchase_items.id) as item_id')->pluck('item_id');
        $lastPrices = PurchaseItem::query()->whereIn('id', $latest)->pluck('unit_cost', 'ingredient_id')->map(fn ($c) => (float) $c)->all();

        $last = Purchase::query()->where('outlet_id', $id)->where('user_id', $user->id)->where('status', '!=', 'rejected')->with('items')->latest('id')->first();
        $repeat = $last === null ? null : [
            'supplier_id' => $last->supplier_id, 'on' => $last->purchased_on->toDateString(),
            // quantities are kept in the base unit, so repeat them in it; prices are typed again
            'items' => $last->items->filter(fn ($i) => in_array($i->ingredient_id, $ids, true))->map(fn ($i) => [
                'ingredient_id' => $i->ingredient_id, 'qty' => $i->qty, 'unit' => $ingredients->firstWhere('id', $i->ingredient_id)?->unit,
            ])->values()->all(),
        ];

        return Inertia::render('stock/purchase', [
            'suppliers' => Supplier::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'needsApproval' => ! $user->can('manage-inventory') && (bool) $this->settings->get('inventory.purchase_needs_approval', true),
            'share' => $this->share($request),
            'canCreateIngredient' => $user->can('manage-inventory'),
            'canPayFromDrawer' => $user->can('pay-purchase-from-drawer') && app(ShiftResolver::class)->current() !== null,
            'priceAlertPercent' => (int) $this->settings->get('inventory.price_alert_percent', 15),
            'lastPrices' => $lastPrices,
            'lastPurchase' => $repeat,
            'lowIds' => $request->boolean('menipis') ? $ingredients->filter(fn (Ingredient $i) => $i->stock <= $i->min_stock)->pluck('id')->values()->all() : [],
            'ingredients' => $ingredients->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'choices' => Units::choices($i),
                'stock' => $i->stock, 'min_stock' => $i->min_stock,
            ])->values()->all(),
            'history' => Purchase::query()->where('outlet_id', $id)->where('user_id', $user->id)->with('items')->latest('id')->limit(15)->get()->map(fn (Purchase $p) => [
                'id' => $p->id, 'purchased_on' => $p->purchased_on->toDateString(), 'supplier' => $p->supplier, 'total' => $p->total, 'status' => $p->status,
                'items' => $p->items->count(), 'review_note' => $p->review_note, 'paid_from' => $p->paid_from,
                'receipt' => $p->receipt_path ? MediaUrl::public($p->receipt_path) : null,
            ])->all(),
        ]);
    }

    public function submitPurchase(Request $request, AddCashMovementAction $cash, ImageOptimizer $images): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'supplier' => 'nullable|string|max:80', 'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('outlet_id', $outletId)], 'purchased_on' => 'required|date', 'note' => 'nullable|string|max:200',
            'paid_from' => 'nullable|in:drawer,own', 'receipt' => 'nullable|image|max:8192',
            'items' => 'required|array|min:1|max:60',
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('outlet_id', $outletId)],
            'items.*.qty' => 'required|numeric|gt:0', 'items.*.unit' => 'nullable|string|max:20',
            'items.*.total' => 'nullable|numeric|min:0', 'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);
        $allowed = $this->scope->ingredientIds($this->actor($request), $outletId);
        if ($allowed !== null) {
            foreach ((array) $data['items'] as $item) {
                if (! in_array((int) $item['ingredient_id'], $allowed, true)) {
                    return back()->withErrors(['items' => 'Ada bahan yang bukan milik station Anda.']);
                }
            }
        }
        $user = $this->actor($request);
        $drawer = ($data['paid_from'] ?? null) === 'drawer';
        if ($drawer && ! $user->can('pay-purchase-from-drawer')) {
            return back()->withErrors(['paid_from' => 'Akunmu belum boleh membayar belanja dari laci kas.']);
        }
        $shift = $drawer ? app(ShiftResolver::class)->current() : null;
        if ($drawer && $shift === null) {
            return back()->withErrors(['paid_from' => 'Belum ada shift terbuka, jadi belum bisa bayar dari laci kas.']);
        }
        $data['items'] = $this->inventory->normalizePurchaseItems($outletId, $data['items']);
        $data['receipt_path'] = $request->hasFile('receipt') ? $images->store($request->file('receipt'), 'receipts') : null;
        unset($data['receipt']);

        // the purchase and the money leaving the drawer are saved together or not at all
        $purchase = DB::transaction(function () use ($outletId, $data, $user, $shift, $cash) {
            $purchase = $this->inventory->submitPurchase($outletId, $data, $user);
            if ($shift !== null) {
                $category = ExpenseCategory::query()->where('outlet_id', $outletId)->where('allow_drawer', true)->where('name', 'Bahan Baku')->first();
                if ($category === null) {
                    throw new OrderException('Kategori Bahan Baku belum boleh dibayar dari laci kas. Minta manager mengaktifkannya.');
                }
                $move = $cash->handle($shift, $user, 'out', (int) $purchase->total, 'Belanja bahan'.($purchase->supplier ? " {$purchase->supplier}" : ''), null, $category->id, $purchase->receipt_path);
                $purchase->update(['cash_movement_id' => $move->id]);
            }

            return $purchase;
        });

        return redirect()->route('stock.purchase')->with('success', $purchase->status === 'approved' ? 'Belanja dicatat dan stok bertambah.' : 'Belanja dikirim. Stok bertambah setelah disetujui manager.');
    }

    public function save(Request $request): RedirectResponse
    {
        $draft = $this->persist($request);

        // autosave while typing stays quiet; only the button says "saved"
        return $request->boolean('auto') ? back() : back()->with('success', 'Draf hitungan disimpan.')->with('draft', $draft->id);
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
        $data = $request->validate(['lines' => 'present|array', 'lines.*.ingredient_id' => 'required|integer', 'lines.*.counted' => 'nullable|numeric|min:0', 'lines.*.unit' => 'nullable|string|max:20', 'note' => 'nullable|string|max:200', 'auto' => 'nullable|boolean']);
        $draft = $this->counts->draftFor($this->actor($request), $this->outlet->id());

        $allowed = $this->scope->ingredientIds($this->actor($request), $this->outlet->id());
        $ingredients = Ingredient::query()->where('outlet_id', $this->outlet->id())->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->with('packs')->get()->keyBy('id');
        $counted = [];
        foreach ($data['lines'] as $line) {
            $ingredient = $ingredients->get((int) $line['ingredient_id']);
            if ($ingredient === null && $allowed !== null) {
                continue;
            }
            $qty = $line['counted'] ?? null;
            // quantities may be entered in a packaging; the count is kept in the base unit
            $counted[(int) $line['ingredient_id']] = $ingredient !== null && $qty !== null ? Units::toBase($ingredient, $line['unit'] ?? null, (float) $qty) : null;
        }

        return $this->counts->save($draft, $counted, $data['note'] ?? null);
    }
}
