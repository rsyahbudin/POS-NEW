<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Station;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Ordering\Events\ProductAvailabilityChanged;
use App\Domain\Printing\Models\Printer;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\ImageOptimizer;
use App\Support\MediaUrl;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function __construct(
        private OutletContext $outlet,
        private InventoryService $inventory,
        private SettingsRepository $settings,
    ) {}

    public function index(): Response
    {
        $id = $this->outlet->id();
        $user = request()->user();
        $inventoryEnabled = $this->inventory->enabled();
        $canRecipe = (bool) $user?->can('manage-inventory');

        return Inertia::render('admin/menu', [
            'categories' => Category::query()->where('outlet_id', $id)->with('printers:id')->orderBy('sort')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'sort' => $c->sort, 'station_id' => $c->station_id, 'printer_ids' => $c->printers->pluck('id')->all()]),
            'printers' => Printer::query()->where('outlet_id', $id)->forRole('ticket')->orderBy('id')->get(['id', 'name']),
            'stations' => Station::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name', 'is_active']),
            'products' => Product::query()->where('outlet_id', $id)->with(array_filter([
                'variants', 'modifierGroups:id', 'printers:id', 'bundleItems',
                $canRecipe ? 'recipeLines' : null,
            ]))->orderBy('category_id')->orderBy('sort')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'description' => $p->description, 'category_id' => $p->category_id, 'station_id' => $p->station_id,
                'base_price' => $p->base_price, 'is_available' => $p->is_available, 'is_active' => $p->is_active, 'is_favorite' => $p->is_favorite, 'stock_qty' => $p->stock_qty, 'sort' => $p->sort,
                'image' => MediaUrl::public($p->image_path),
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price])->all(),
                'modifier_group_ids' => $p->modifierGroups->pluck('id')->all(),
                'printer_ids' => $p->printers->pluck('id')->all(),
                'is_bundle' => $p->is_bundle,
                'components' => $p->bundleItems->map(fn ($b) => ['product_id' => $b->product_id, 'variant_id' => $b->variant_id, 'ask_modifiers' => $b->ask_modifiers, 'qty' => $b->qty])->all(),
                'recipe_lines' => $canRecipe
                    ? $p->recipeLines->map(fn (RecipeLine $l) => ['variant_id' => $l->variant_id, 'ingredient_id' => $l->ingredient_id, 'qty' => $l->qty])->values()->all()
                    : [],
            ]),
            'canBundles' => (bool) $user?->can('manage-bundles'),
            'modifierGroups' => ModifierGroup::query()->where('outlet_id', $id)->with('modifiers')->get()->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select, 'is_required' => $g->is_required,
                'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => $m->price_delta, 'is_default' => $m->is_default])->all(),
            ]),
            // Wizard "Siapkan menu": recipe + suggested price steps only when stock is on and the user can edit recipes.
            'inventoryEnabled' => $inventoryEnabled,
            'canRecipe' => $canRecipe,
            'canSetPrice' => (bool) $user?->canAny(['manage-menu', 'manage-pricing']),
            'targetFoodCost' => max(10, min(90, (int) $this->settings->get('pricing.target_food_cost', 35))),
            'ingredients' => $inventoryEnabled && $canRecipe
                ? Ingredient::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'avg_cost'])->all()
                : [],
        ]);
    }

    public function saveCategory(Request $request, ?Category $category = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'sort' => 'nullable|integer|min:0', 'station_id' => ['nullable', Rule::exists('stations', 'id')->where('outlet_id', $this->outlet->id())]]);
        $category ? $category->update($data) : Category::create($data + ['outlet_id' => $this->outlet->id()]);

        return back()->with('success', 'Kategori disimpan.');
    }

    /** Default ticket printers for every item of the category (an item can override them). */
    public function saveCategoryPrinters(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['printer_ids' => 'nullable|array', 'printer_ids.*' => ['integer', Printer::existsFor($this->outlet->id(), 'ticket')]]);
        $category->printers()->sync($data['printer_ids'] ?? []);
        Audit::log('category.printers_changed', $category, ['category' => $category->name, 'printers' => $data['printer_ids'] ?? []]);

        return back()->with('success', 'Printer kategori disimpan.');
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
            'is_favorite' => 'boolean',
            'sort' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:8192',
            'variants' => 'nullable|array', 'variants.*.name' => 'required|string|max:40', 'variants.*.price' => 'required|integer|min:0',
            'modifier_group_ids' => 'nullable|array', 'modifier_group_ids.*' => 'integer',
            'printer_ids' => 'nullable|array', 'printer_ids.*' => ['integer', Printer::existsFor($outletId, 'ticket')],
            'is_bundle' => 'boolean',
            'components' => 'nullable|array|max:30',
            'components.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('outlet_id', $outletId)],
            'components.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'components.*.ask_modifiers' => 'boolean',
            'components.*.qty' => 'required|integer|min:1|max:50',
        ]);

        // Bundles are their own permission: without it the bundle fields are left as they are.
        $canBundle = (bool) $request->user()?->can('manage-bundles');
        $bundle = $canBundle ? (bool) ($data['is_bundle'] ?? false) : (bool) $product?->is_bundle;
        $parts = $canBundle && $bundle ? $this->bundleParts($data['components'] ?? [], $product) : null;

        // a new menu item without a price is not sold yet: fill the recipe first, then take the suggested price (Resep & HPP)
        $isEdit = $product !== null;
        $priced = (int) $data['base_price'] > 0 || $bundle || array_sum(array_map(fn (array $v): int => (int) $v['price'], $data['variants'] ?? [])) > 0;

        $saved = DB::transaction(function () use ($request, $images, $product, $data, $outletId, $canBundle, $bundle, $parts, $priced) {
            $fields = Arr::except($data, ['image', 'variants', 'modifier_group_ids', 'printer_ids', 'is_bundle', 'components']);
            if ($canBundle) {
                $fields['is_bundle'] = $bundle;
            }
            if ($request->hasFile('image')) {
                $fields['image_path'] = $images->store($request->file('image'));
            }

            $oldPrice = $product?->base_price;
            $product = $product ? tap($product)->update($fields) : Product::create($fields + ['outlet_id' => $outletId, 'is_active' => $priced]);
            if ($oldPrice !== null && $oldPrice !== $product->base_price) {
                Audit::log('product.price_changed', $product, ['from' => $oldPrice, 'to' => $product->base_price]);
            }

            $product->variants()->delete();
            foreach ($bundle ? [] : ($data['variants'] ?? []) as $i => $v) {
                $product->variants()->create($v + ['sort' => $i]);
            }
            $product->modifierGroups()->sync($bundle ? [] : ($data['modifier_group_ids'] ?? []));
            $product->printers()->sync($data['printer_ids'] ?? []);
            if ($canBundle) {
                $product->bundleItems()->delete();
                foreach ($parts ?? [] as $i => $part) {
                    $product->bundleItems()->create($part + ['sort' => $i]);
                }
            }

            return $product;
        });

        $message = $priced || $isEdit
            ? 'Produk disimpan.'
            : 'Produk disimpan. Belum dijual karena belum ada harga: isi resepnya, lalu pakai harga yang disarankan di Resep & HPP.';

        return back()->with('success', $message)->with('product_id', $saved->id);
    }

    /**
     * The menu items a bundle is made of. An item can be in a bundle once and cannot itself be a bundle.
     * An item with variants either has the variant the bundle includes fixed (Es Teh Manis, Iced) or leaves it to the guest (no variant set).
     * The guest can also be given the item's own options (sambal, sweetness) to pick.
     *
     * @param  array<int, array{product_id: int, variant_id?: int|null, ask_modifiers?: bool, qty: int}>  $components
     * @return list<array{product_id: int, variant_id: int|null, ask_modifiers: bool, qty: int}>
     */
    private function bundleParts(array $components, ?Product $bundle): array
    {
        if ($components === []) {
            throw ValidationException::withMessages(['components' => 'Paket harus berisi minimal satu menu.']);
        }
        $ids = array_map(fn (array $c): int => (int) $c['product_id'], $components);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['components' => 'Satu menu hanya boleh sekali dalam paket; naikkan jumlahnya.']);
        }
        if ($bundle !== null && in_array($bundle->id, $ids, true)) {
            throw ValidationException::withMessages(['components' => 'Paket tidak boleh berisi dirinya sendiri.']);
        }
        $products = Product::query()->whereIn('id', $ids)->with('variants')->get()->keyBy('id');
        $parts = [];
        foreach ($components as $c) {
            $p = $products->get((int) $c['product_id']);
            if ($p === null) {
                continue;
            }
            if ($p->is_bundle) {
                throw ValidationException::withMessages(['components' => "{$p->name} sendiri sebuah paket; paket tidak bisa berisi paket."]);
            }
            $variantId = ! empty($c['variant_id']) ? (int) $c['variant_id'] : null;
            if ($variantId !== null && ! $p->variants->contains('id', $variantId)) {
                throw ValidationException::withMessages(['components' => "Varian bukan milik {$p->name}."]);
            }
            $parts[] = [
                'product_id' => $p->id, 'variant_id' => $variantId, 'qty' => (int) $c['qty'],
                'ask_modifiers' => ! empty($c['ask_modifiers']) && $p->modifierGroups()->exists(),
            ];
        }

        return $parts;
    }

    /** Hide a product from the POS and the QR menu without deleting it; switching it back on restores it as it was. */
    public function setActive(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->outlet_id === $this->outlet->id(), 404);
        $active = (bool) $request->validate(['is_active' => 'required|boolean'])['is_active'];
        $product->update(['is_active' => $active]);
        Realtime::dispatch(new ProductAvailabilityChanged($product->outlet_id, $product->id, $active && $product->is_available));
        Audit::log($active ? 'product.activated' : 'product.deactivated', $product, ['name' => $product->name]);

        return back()->with('success', $active ? "{$product->name} diaktifkan." : "{$product->name} dinonaktifkan.");
    }

    /** Mark a menu item as an outlet favorite so it shows first on the QR menu and kasir. */
    public function setFavorite(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->outlet_id === $this->outlet->id(), 404);
        $favorite = (bool) $request->validate(['is_favorite' => 'required|boolean'])['is_favorite'];
        $product->update(['is_favorite' => $favorite]);
        Audit::log($favorite ? 'product.favorited' : 'product.unfavorited', $product, ['name' => $product->name]);

        return back()->with('success', $favorite ? "{$product->name} jadi favorit." : "{$product->name} bukan favorit lagi.");
    }

    public function deleteProduct(Product $product): RedirectResponse
    {
        $product->delete(); // soft delete: old orders keep their snapshots

        return back()->with('success', 'Produk dihapus.');
    }

    /** "Habis" toggle: visible on QR menu and cashier screen immediately via broadcast. */
    public function availability(Product $product, Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('manage-menu') || $this->actor($request)->can('mark-sold-out'), 403);
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
            'is_required' => 'boolean', 'modifiers' => 'required|array|min:1',
            'modifiers.*.name' => 'required|string|max:60', 'modifiers.*.price_delta' => 'required|integer',
            'modifiers.*.is_default' => 'boolean',
        ]);

        DB::transaction(function () use ($group, $data) {
            $fields = Arr::except($data, ['modifiers']);
            $group = $group ? tap($group)->update($fields) : ModifierGroup::create($fields + ['outlet_id' => $this->outlet->id()]);
            // Old orders keep their own snapshot, so rebuilding the options is safe.
            Modifier::query()->where('modifier_group_id', $group->id)->delete();
            $defaultsLeft = (int) $data['max_select'];
            foreach ($data['modifiers'] as $i => $m) {
                $default = ! empty($m['is_default']) && $defaultsLeft > 0;
                if ($default) {
                    $defaultsLeft--;
                }
                $group->modifiers()->create([
                    'name' => $m['name'],
                    'price_delta' => $m['price_delta'],
                    'is_default' => $default,
                    'sort' => $i,
                ]);
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
