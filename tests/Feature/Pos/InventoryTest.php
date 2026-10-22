<?php

use App\Domain\Catalog\Models\Modifier;
use App\Domain\Floor\Models\Table;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\ModifierIngredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Ordering\Actions\VoidOrderAction;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\Models\Outlet;
use App\Support\BusinessDate;

beforeEach(fn () => setting('inventory.enabled', true));

function ingredient(string $name, string $unit, float $stock = 0, float $cost = 0): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => $unit, 'stock' => $stock, 'avg_cost' => $cost]);
}

function recipe(string $productName, array $lines, ?int $variantId = null): void
{
    foreach ($lines as $ingredientId => $qty) {
        RecipeLine::create(['product_id' => product($productName)->id, 'variant_id' => $variantId, 'ingredient_id' => $ingredientId, 'qty' => $qty]);
    }
}

it('averages the cost of purchases and raises stock', function () {
    $beans = ingredient('Biji kopi', 'g');
    $svc = app(InventoryService::class);
    $user = userWithRole('manager');
    $outlet = (int) Outlet::query()->value('id');

    $svc->receive($outlet, ['supplier' => 'Toko A', 'purchased_on' => '2026-10-20', 'items' => [['ingredient_id' => $beans->id, 'qty' => 1000, 'unit_cost' => 50]]], $user);
    $svc->receive($outlet, ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $beans->id, 'qty' => 1000, 'unit_cost' => 70]]], $user);

    $beans->refresh();
    expect($beans->stock)->toBe(2000.0)->and($beans->avg_cost)->toBe(60.0);
    expect(StockMovement::query()->where('type', 'purchase')->count())->toBe(2);
});

it('uses the recipe on sale, freezes the cost on the line, and puts it back on void', function () {
    $beans = ingredient('Biji kopi', 'g', 1000, 200);
    $water = ingredient('Air', 'ml', 5000, 0.5);
    recipe('Americano', [$beans->id => 18, $water->id => 200]);

    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2]]);
    $item = $order->items->first()->fresh();

    expect($beans->fresh()->stock)->toBe(964.0)->and($water->fresh()->stock)->toBe(4600.0);
    expect($item->cogs)->toBe(7400)->and($item->inventory_consumed)->toBeTrue();

    // price change later does not touch the frozen cost
    $beans->update(['avg_cost' => 999]);
    expect($item->fresh()->cogs)->toBe(7400);

    app(VoidItemAction::class)->handle($item, userWithRole('cashier'), 'salah input');
    expect($beans->fresh()->stock)->toBe(1000.0)->and($water->fresh()->stock)->toBe(5000.0)->and($item->fresh()->cogs)->toBe(0);
    expect(StockMovement::query()->where('type', 'void_return')->count())->toBe(2);
});

it('puts stock back when a whole order is voided', function () {
    $beans = ingredient('Biji kopi', 'g', 1000, 200);
    recipe('Americano', [$beans->id => 18]);
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 3]]);
    expect($beans->fresh()->stock)->toBe(946.0);

    app(VoidOrderAction::class)->handle($order, userWithRole('cashier'), 'pelanggan batal');
    expect($beans->fresh()->stock)->toBe(1000.0);
});

it('adds variant-specific and modifier ingredients to a portion', function () {
    $milk = ingredient('Susu', 'ml', 10000, 20);
    $shot = ingredient('Biji kopi', 'g', 1000, 200);
    $svc = app(InventoryService::class);
    $americano = product('Americano');

    recipe('Americano', [$shot->id => 18]);
    $variantId = $americano->variants()->first()?->id;
    $modifier = Modifier::query()->first();
    if ($variantId) {
        recipe('Americano', [$milk->id => 50], $variantId);
    }
    if ($modifier) {
        ModifierIngredient::create(['modifier_id' => $modifier->id, 'ingredient_id' => $shot->id, 'qty' => 9]);
    }

    $base = $svc->portion($americano->id, null, []);
    expect($base[$shot->id])->toBe(18.0);
    if ($variantId) {
        expect($svc->portion($americano->id, $variantId, [])[$milk->id])->toBe(50.0);
    }
    if ($modifier) {
        expect($svc->portion($americano->id, null, [$modifier->id])[$shot->id])->toBe(27.0);
    }
    expect($svc->portionCost($americano->id))->toBe(3600.0);
});

it('does nothing while stock tracking is off, and refuses when the empty-stock rule is on', function () {
    $beans = ingredient('Biji kopi', 'g', 10, 200);
    recipe('Americano', [$beans->id => 18]);

    setting('inventory.enabled', false);
    placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1]]);
    expect($beans->fresh()->stock)->toBe(10.0)->and(StockMovement::query()->count())->toBe(0);

    setting('inventory.enabled', true);
    setting('inventory.block_when_empty', true);
    expect(fn () => placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1]], tableId: Table::query()->skip(1)->value('id')))
        ->toThrow(OrderException::class, 'Biji kopi tidak cukup');
    expect($beans->fresh()->stock)->toBe(10.0);

    setting('inventory.block_when_empty', false);
    placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1]], tableId: Table::query()->skip(2)->value('id'));
    expect($beans->fresh()->stock)->toBe(-8.0); // goes negative = needs a count
});

it('records stock counts and waste as movements', function () {
    $beans = ingredient('Biji kopi', 'g', 500, 200);
    $svc = app(InventoryService::class);
    $user = userWithRole('manager');

    expect($svc->count($beans, 450, $user, 'opname Senin'))->toBe(-50.0);
    expect($beans->fresh()->stock)->toBe(450.0);
    $svc->waste($beans, 20, $user, 'tumpah');
    expect($beans->fresh()->stock)->toBe(430.0);
    expect(StockMovement::query()->pluck('type')->sort()->values()->all())->toBe(['opname', 'waste']);
});

it('manages ingredients, purchases, counts and waste from the admin pages, for permitted roles only', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/admin/inventory/ingredients', ['name' => 'Susu', 'unit' => 'ml', 'min_stock' => 500, 'stock' => 2000, 'unit_cost' => 20])->assertSessionHasNoErrors();
    $milk = Ingredient::query()->where('name', 'Susu')->first();
    expect($milk->stock)->toBe(2000.0);

    $this->actingAs($manager)->post('/admin/inventory/ingredients', ['name' => 'Susu', 'unit' => 'ml'])->assertSessionHasErrors('name');

    $this->actingAs($manager)->post('/admin/inventory/purchases', ['purchased_on' => '2026-10-20', 'supplier' => 'Toko', 'items' => [['ingredient_id' => $milk->id, 'qty' => 1000, 'unit_cost' => 30]]])->assertSessionHasNoErrors();
    expect($milk->fresh()->stock)->toBe(3000.0)->and(round($milk->fresh()->avg_cost, 2))->toBe(23.33);

    $this->actingAs($manager)->post("/admin/inventory/ingredients/{$milk->id}/count", ['counted' => 2900])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post("/admin/inventory/ingredients/{$milk->id}/waste", ['qty' => 100, 'reason_id' => WasteReason::query()->value('id'), 'note' => 'basi'])->assertSessionHasNoErrors();
    expect($milk->fresh()->stock)->toBe(2800.0);
    $this->actingAs($manager)->post("/admin/inventory/ingredients/{$milk->id}/waste", ['qty' => 1])->assertSessionHasErrors('reason_id');

    $this->actingAs($manager)->get('/admin/inventory')->assertInertia(fn ($p) => $p->component('admin/inventory')->has('ingredients', 1)->has('movements', 4)->where('enabled', true));
    $this->actingAs(userWithRole('cashier'))->get('/admin/inventory')->assertForbidden();
});

it('saves recipes and shows cost and margin per menu item', function () {
    $beans = ingredient('Biji kopi', 'g', 1000, 200);
    $owner = userWithRole('owner');
    $americano = product('Americano');

    $this->actingAs($owner)->post("/admin/recipes/products/{$americano->id}", ['lines' => [['ingredient_id' => $beans->id, 'qty' => 18]]])->assertSessionHasNoErrors();
    expect(RecipeLine::query()->where('product_id', $americano->id)->count())->toBe(1);
    $this->actingAs($owner)->get('/admin/recipes')->assertInertia(fn ($p) => $p->component('admin/recipes')->where('products', fn ($products) => collect($products)->firstWhere('id', $americano->id)['cost'] === 3600));

    $modifier = Modifier::query()->first();
    if ($modifier) {
        $this->actingAs($owner)->post("/admin/recipes/modifiers/{$modifier->id}", ['lines' => [['ingredient_id' => $beans->id, 'qty' => 9]]])->assertSessionHasNoErrors();
        expect(ModifierIngredient::query()->where('modifier_id', $modifier->id)->count())->toBe(1);
    }

    $this->actingAs($owner)->post("/admin/recipes/products/{$americano->id}", ['lines' => [['ingredient_id' => 999999, 'qty' => 1]]])->assertSessionHasErrors('lines.0.ingredient_id');
    $this->actingAs($owner)->post("/admin/recipes/products/{$americano->id}", ['lines' => []])->assertSessionHasNoErrors();
    expect(RecipeLine::query()->count())->toBe(0);
});

it('reports sales, cost and margin per product, category and day, with Excel and PDF', function () {
    $beans = ingredient('Biji kopi', 'g', 10000, 200);
    recipe('Americano', [$beans->id => 18]);
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2]])); // 44.000 revenue, 7.200 cost
    pay(placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]], tableId: Table::query()->skip(1)->value('id'))); // no recipe

    $d = BusinessDate::for();
    $manager = userWithRole('manager');
    $this->actingAs($manager)->get("/admin/reports/margin?from={$d}&to={$d}&dimension=product")->assertOk()
        ->assertInertia(fn ($p) => $p->where('summary.cogs', 7200)->where('summary.revenue', 44000 + 38000)->where('summary.profit', 82000 - 7200)->where('summary.uncosted_qty', 1));
    foreach (['category', 'day'] as $dim) {
        $this->actingAs($manager)->get("/admin/reports/margin?from={$d}&to={$d}&dimension={$dim}")->assertOk();
    }
    $this->actingAs($manager)->get("/admin/reports/margin/export?from={$d}&to={$d}&format=xlsx")->assertOk();
    $this->actingAs($manager)->get("/admin/reports/margin/export?from={$d}&to={$d}&format=pdf")->assertOk();
    $this->actingAs(userWithRole('cashier'))->get('/admin/reports/margin')->assertForbidden();
});
