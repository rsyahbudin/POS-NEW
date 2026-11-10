<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\ModifierIngredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** What each menu item (and each modifier) uses, with the resulting cost and margin. */
class RecipeController extends Controller
{
    public function __construct(private OutletContext $outlet, private InventoryService $inventory, private SettingsRepository $settings) {}

    public function index(): Response
    {
        $id = $this->outlet->id();
        $costs = Ingredient::query()->where('outlet_id', $id)->pluck('avg_cost', 'id');
        $lines = RecipeLine::query()->whereIn('product_id', Product::query()->where('outlet_id', $id)->pluck('id'))->get()->groupBy('product_id');
        $categories = Category::query()->where('outlet_id', $id)->pluck('name', 'id');

        return Inertia::render('admin/recipes', [
            'enabled' => $this->inventory->enabled(),
            'targetFoodCost' => max(10, min(90, (int) $this->settings->get('pricing.target_food_cost', 35))),
            'canSetPrice' => $this->canSetPrice(),
            'ingredients' => Ingredient::query()->where('outlet_id', $id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'avg_cost'])->all(),
            'products' => Product::query()->where('outlet_id', $id)->with('variants')->orderBy('category_id')->orderBy('sort')->get()->map(function (Product $p) use ($lines, $costs, $categories) {
                $own = $lines->get($p->id, collect());
                $cost = fn (?int $variantId) => (int) round($own->filter(fn ($l) => $l->variant_id === null || $l->variant_id === $variantId)->sum(fn ($l) => $l->qty * (float) ($costs[$l->ingredient_id] ?? 0)));

                return [
                    'id' => $p->id, 'name' => $p->name, 'category' => $categories[$p->category_id] ?? '', 'price' => $p->base_price, 'is_active' => $p->is_active,
                    'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price, 'cost' => $cost($v->id)])->all(),
                    'lines' => $own->map(fn ($l) => ['variant_id' => $l->variant_id, 'ingredient_id' => $l->ingredient_id, 'qty' => $l->qty])->values()->all(),
                    'cost' => $cost(null),
                ];
            })->all(),
            'modifiers' => ModifierGroup::query()->where('outlet_id', $id)->with('modifiers')->get()->flatMap(fn (ModifierGroup $g) => $g->modifiers->map(fn (Modifier $m) => [
                'id' => $m->id, 'name' => $m->name, 'group' => $g->name,
                'lines' => ModifierIngredient::query()->where('modifier_id', $m->id)->get()->map(fn ($l) => ['ingredient_id' => $l->ingredient_id, 'qty' => $l->qty])->all(),
            ]))->values()->all(),
        ]);
    }

    private function canSetPrice(): bool
    {
        return (bool) auth()->user()?->canAny(['manage-menu', 'manage-pricing']);
    }

    /**
     * Takes the suggested price as the selling price of the menu item or of one of its variants. A menu saved without a price
     * (not sold yet) starts being sold with its first price.
     */
    public function applyPrice(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->outlet_id === $this->outlet->id() && $this->canSetPrice(), 403);
        $data = $request->validate(['price' => 'required|integer|min:1|max:100000000', 'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)]]);
        $price = (int) $data['price'];

        $waiting = ! $product->is_active && $product->base_price === 0 && ! $product->variants()->where('price', '>', 0)->exists();
        if (! empty($data['variant_id'])) {
            $variant = $product->variants()->findOrFail((int) $data['variant_id']);
            $old = $variant->price;
            $variant->update(['price' => $price]);
        } else {
            $old = $product->base_price;
            $product->update(['base_price' => $price]);
        }
        Audit::log('product.price_changed', $product, ['from' => $old, 'to' => $price, 'variant' => $data['variant_id'] ?? null, 'via' => 'recipe']);
        if ($waiting) {
            $product->update(['is_active' => true]);
        }

        return back()->with('success', $waiting ? "Harga jual diatur. {$product->name} mulai dijual." : 'Harga jual diubah.');
    }

    public function saveProduct(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->outlet_id === $this->outlet->id(), 404);
        $data = $this->validateLines($request, true);

        DB::transaction(function () use ($product, $data) {
            RecipeLine::query()->where('product_id', $product->id)->delete();
            foreach ($data['lines'] as $l) {
                RecipeLine::create(['product_id' => $product->id, 'variant_id' => $l['variant_id'] ?? null, 'ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty']]);
            }
        });
        Audit::log('recipe.saved', $product, ['product' => $product->name, 'lines' => count($data['lines'])]);

        return back()->with('success', 'Resep disimpan.');
    }

    public function saveModifier(Request $request, Modifier $modifier): RedirectResponse
    {
        $data = $this->validateLines($request, false);

        DB::transaction(function () use ($modifier, $data) {
            ModifierIngredient::query()->where('modifier_id', $modifier->id)->delete();
            foreach ($data['lines'] as $l) {
                ModifierIngredient::create(['modifier_id' => $modifier->id, 'ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty']]);
            }
        });
        Audit::log('recipe.modifier_saved', $modifier, ['modifier' => $modifier->name, 'lines' => count($data['lines'])]);

        return back()->with('success', 'Bahan tambahan disimpan.');
    }

    /** @return array{lines: list<array{ingredient_id: int, qty: float, variant_id?: int|null}>} */
    private function validateLines(Request $request, bool $withVariant): array
    {
        $rules = [
            'lines' => 'present|array|max:40',
            'lines.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('outlet_id', $this->outlet->id())],
            'lines.*.qty' => 'required|numeric|gt:0',
        ];
        if ($withVariant) {
            $rules['lines.*.variant_id'] = ['nullable', 'integer', Rule::exists('product_variants', 'id')];
        }

        /** @var array{lines: list<array{ingredient_id: int, qty: float, variant_id?: int|null}>} */
        return $request->validate($rules);
    }
}
