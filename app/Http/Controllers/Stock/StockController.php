<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\StockCountService;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What staff see of the stock: a read-only list and the stock count form. Costs stay with managers. */
class StockController extends Controller
{
    public function __construct(private OutletContext $outlet, private StockCountService $counts) {}

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
            'ingredients' => Ingredient::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'counted' => $saved->get($i->id),
                'system' => $showSystem ? $i->stock : null,
            ])->all(),
            'history' => StockCount::query()->where('user_id', $user->id)->where('status', '!=', 'draft')->latest('id')->limit(8)->get()->map(fn (StockCount $c) => [
                'id' => $c->id, 'status' => $c->status, 'at' => $c->submitted_at?->setTimezone('Asia/Jakarta')->format('d M H:i'), 'lines' => $c->lines()->count(), 'review_note' => $c->review_note,
            ])->all(),
        ]);
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
        $data = $request->validate(['lines' => 'present|array', 'lines.*.ingredient_id' => 'required|integer', 'lines.*.counted' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:200']);
        $draft = $this->counts->draftFor($this->actor($request), $this->outlet->id());

        $counted = [];
        foreach ($data['lines'] as $line) {
            $counted[(int) $line['ingredient_id']] = $line['counted'] ?? null;
        }

        return $this->counts->save($draft, $counted, $data['note'] ?? null);
    }
}
