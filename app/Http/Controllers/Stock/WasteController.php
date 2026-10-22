<?php

namespace App\Http\Controllers\Stock;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\WasteEntry;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Inventory\Units;
use App\Domain\Inventory\WasteService;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Recording waste: staff with the permission pick what was lost and why. */
class WasteController extends Controller
{
    public function __construct(private OutletContext $outlet, private WasteService $waste) {}

    public function index(Request $request): Response
    {
        $id = $this->outlet->id();
        $user = $this->actor($request);

        return Inertia::render('stock/waste', [
            'reasons' => WasteReason::query()->where('outlet_id', $id)->orderBy('sort')->orderBy('id')->get(['id', 'name', 'is_active'])->all(),
            'ingredients' => Ingredient::query()->where('outlet_id', $id)->where('is_active', true)->with('packs')->orderBy('name')->get()->map(fn (Ingredient $i) => [
                'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'choices' => Units::choices($i),
            ])->all(),
            'products' => Product::query()->where('outlet_id', $id)->with('variants')->orderBy('name')->get()->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->all(),
            ])->all(),
            'recent' => WasteEntry::query()->where('outlet_id', $id)->where('user_id', $user->id)->latest('id')->limit(15)->get()->map(fn (WasteEntry $e) => [
                'id' => $e->id, 'at' => $e->created_at?->setTimezone('Asia/Jakarta')->format('d M H:i'), 'label' => $e->label, 'qty' => $e->qty, 'unit' => $e->unit, 'reason' => $e->reason, 'cost' => $e->cost,
            ])->all(),
            'canManage' => $user->can('manage-inventory'),
        ]);
    }

    public function ingredient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('outlet_id', $this->outlet->id())],
            'qty' => 'required|numeric|gt:0', 'unit' => 'nullable|string|max:20', 'reason_id' => 'required|integer', 'note' => 'nullable|string|max:200',
        ]);
        $entry = $this->waste->ingredient(Ingredient::query()->findOrFail((int) $data['ingredient_id']), (float) $data['qty'], $data['unit'] ?? null, (int) $data['reason_id'], $data['note'] ?? null, $this->actor($request));

        return back()->with('success', "Tercatat: {$entry->label} terbuang (Rp ".number_format($entry->cost, 0, ',', '.').').');
    }

    public function product(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('outlet_id', $this->outlet->id())],
            'variant_id' => 'nullable|integer', 'portions' => 'required|integer|min:1|max:999', 'reason_id' => 'required|integer', 'note' => 'nullable|string|max:200',
        ]);
        $entry = $this->waste->product(Product::query()->findOrFail((int) $data['product_id']), $data['variant_id'] ?? null, (int) $data['portions'], (int) $data['reason_id'], $data['note'] ?? null, $this->actor($request));

        return back()->with('success', "Tercatat: {$entry->label} {$data['portions']} porsi terbuang (Rp ".number_format($entry->cost, 0, ',', '.').'). Bahan sesuai resep sudah dikurangi.');
    }

    /** Managers edit the list of reasons (add, rename, switch off). */
    public function saveReason(Request $request, ?WasteReason $reason = null): RedirectResponse
    {
        abort_unless($this->actor($request)->can('manage-inventory'), 403);
        $id = $this->outlet->id();
        abort_if($reason !== null && $reason->outlet_id !== $id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:40', Rule::unique('waste_reasons', 'name')->where('outlet_id', $id)->ignore($reason?->id)], 'is_active' => 'boolean']);
        if ($reason) {
            $reason->update(['name' => $data['name'], 'is_active' => (bool) ($data['is_active'] ?? true)]);
        } else {
            $reason = WasteReason::create(['outlet_id' => $id, 'name' => $data['name'], 'sort' => (int) WasteReason::query()->where('outlet_id', $id)->max('sort') + 1]);
        }
        Audit::log('settings.changed', $reason, ['name' => 'Alasan waste: '.$reason->name]);

        return back()->with('success', 'Alasan disimpan.');
    }
}
