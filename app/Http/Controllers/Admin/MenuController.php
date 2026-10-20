<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Station;
use App\Domain\Ordering\Events\ProductAvailabilityChanged;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ImageOptimizer;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(): Response
    {
        $id = $this->outlet->id();

        return Inertia::render('admin/menu', [
            'categories' => Category::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name', 'sort']),
            'stations' => Station::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name', 'is_active']),
            'products' => Product::query()->where('outlet_id', $id)->with(['variants', 'modifierGroups:id'])->orderBy('category_id')->orderBy('sort')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'description' => $p->description, 'category_id' => $p->category_id, 'station_id' => $p->station_id,
                'base_price' => $p->base_price, 'is_available' => $p->is_available, 'stock_qty' => $p->stock_qty, 'sort' => $p->sort,
                'image' => $p->image_path ? \Storage::url($p->image_path) : null,
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price])->all(),
                'modifier_group_ids' => $p->modifierGroups->pluck('id')->all(),
            ]),
            'modifierGroups' => ModifierGroup::query()->where('outlet_id', $id)->with('modifiers')->get()->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select, 'is_required' => $g->is_required,
                'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => $m->price_delta])->all(),
            ]),
        ]);
    }

    public function saveCategory(Request $request, ?Category $category = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'sort' => 'nullable|integer|min:0']);
        $category ? $category->update($data) : Category::create($data + ['outlet_id' => $this->outlet->id()]);

        return back()->with('success', 'Kategori disimpan.');
    }

    public function deleteCategory(Category $category): RedirectResponse
    {
        if ($category->products()->withTrashed()->exists()) {
            return back()->with('error', 'Kategori masih punya produk.');
        }
        $category->delete();

        return back()->with('success', 'Kategori dihapus.');
    }

    public function saveStation(Request $request, ?Station $station = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'is_active' => 'boolean']);
        $station ? $station->update($data) : Station::create($data + ['outlet_id' => $this->outlet->id()]);

        return back()->with('success', 'Stasiun disimpan.');
    }

    public function saveProduct(Request $request, ImageOptimizer $images, ?Product $product = null): RedirectResponse
    {
        $outletId = $this->outlet->id();
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'category_id' => ['required', Rule::exists('categories', 'id')->where('outlet_id', $outletId)],
            'station_id' => ['nullable', Rule::exists('stations', 'id')->where('outlet_id', $outletId)],
            'sku' => ['nullable', 'string', 'max:40', Rule::unique('products', 'sku')->where('outlet_id', $outletId)->ignore($product?->id)],
            'description' => 'nullable|string|max:500',
            'base_price' => 'required|integer|min:0',
            'stock_qty' => 'nullable|integer|min:0',
            'is_available' => 'boolean',
            'sort' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:8192',
            'variants' => 'nullable|array', 'variants.*.name' => 'required|string|max:40', 'variants.*.price' => 'required|integer|min:0',
            'modifier_group_ids' => 'nullable|array', 'modifier_group_ids.*' => 'integer',
        ]);

        DB::transaction(function () use ($request, $images, $product, $data, $outletId) {
            $fields = Arr::except($data, ['image', 'variants', 'modifier_group_ids']);
            if ($request->hasFile('image')) {
                $fields['image_path'] = $images->store($request->file('image'));
            }

            $oldPrice = $product?->base_price;
            $product = $product ? tap($product)->update($fields) : Product::create($fields + ['outlet_id' => $outletId]);
            if ($oldPrice !== null && $oldPrice !== $product->base_price) {
                Audit::log('product.price_changed', $product, ['from' => $oldPrice, 'to' => $product->base_price]);
            }

            $product->variants()->delete();
            foreach ($data['variants'] ?? [] as $i => $v) {
                $product->variants()->create($v + ['sort' => $i]);
            }
            $product->modifierGroups()->sync($data['modifier_group_ids'] ?? []);
        });

        return back()->with('success', 'Produk disimpan.');
    }

    public function deleteProduct(Product $product): RedirectResponse
    {
        $product->delete(); // soft delete: old orders keep their snapshots

        return back()->with('success', 'Produk dihapus.');
    }

    /** "Habis" toggle: visible on QR menu and cashier screen immediately via broadcast. */
    public function availability(Product $product, Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('manage-menu') || $this->actor($request)->can('create-order'), 403);
        $data = $request->validate(['is_available' => 'required|boolean']);
        $product->update(['is_available' => $data['is_available']]);
        Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, $product->is_available));
        Audit::log('product.availability', $product, ['is_available' => $product->is_available]);

        return response()->json(['is_available' => $product->is_available]);
    }

    public function saveModifierGroup(Request $request, ?ModifierGroup $group = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'min_select' => 'required|integer|min:0|max:20', 'max_select' => 'required|integer|min:1|max:20|gte:min_select',
            'is_required' => 'boolean', 'modifiers' => 'required|array|min:1', 'modifiers.*.name' => 'required|string|max:60', 'modifiers.*.price_delta' => 'required|integer',
        ]);

        DB::transaction(function () use ($group, $data) {
            $fields = Arr::except($data, ['modifiers']);
            $group = $group ? tap($group)->update($fields) : ModifierGroup::create($fields + ['outlet_id' => $this->outlet->id()]);
            // Old orders keep their own snapshot, so rebuilding the options is safe.
            Modifier::query()->where('modifier_group_id', $group->id)->delete();
            foreach ($data['modifiers'] as $i => $m) {
                $group->modifiers()->create($m + ['sort' => $i]);
            }
        });

        return back()->with('success', 'Grup modifier disimpan.');
    }

    public function deleteModifierGroup(ModifierGroup $group): RedirectResponse
    {
        DB::table('modifier_group_product')->where('modifier_group_id', $group->id)->delete();
        $group->modifiers()->delete();
        $group->delete();

        return back()->with('success', 'Grup modifier dihapus.');
    }
}
