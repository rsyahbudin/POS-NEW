<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Units;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function syrup(float $stock = 0, float $cost = 0): Ingredient
{
    $i = Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'Sirup vanila', 'unit' => 'ml', 'stock' => $stock, 'avg_cost' => $cost]);
    IngredientUnit::create(['ingredient_id' => $i->id, 'name' => 'botol', 'factor' => 750]);

    return $i;
}

function shopperUser(): User
{
    Role::findByName('cashier')->givePermissionTo('record-purchase');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return userWithRole('cashier');
}

it('offers only the units that make sense for an ingredient and converts them to the base unit', function () {
    $syrup = syrup();
    $flour = Ingredient::create(['outlet_id' => $syrup->outlet_id, 'name' => 'Tepung', 'unit' => 'g']);

    expect(collect(Units::choices($syrup))->pluck('name')->all())->toBe(['ml', 'l', 'botol']);
    expect(collect(Units::choices($flour))->pluck('name')->all())->toBe(['g', 'kg']);
    expect(Units::toBase($syrup, 'botol', 2))->toBe(1500.0)->and(Units::toBase($syrup, 'L', 1.5))->toBe(1500.0)->and(Units::toBase($flour, 'kg', 2))->toBe(2000.0)->and(Units::toBase($flour, null, 7))->toBe(7.0);
    expect(fn () => Units::toBase($syrup, 'dus', 1))->toThrow(OrderException::class, 'Pilihan: ml, l (1000 ml), botol (750 ml)');
    expect(fn () => Units::toBase($flour, 'botol', 1))->toThrow(OrderException::class);
    expect(Units::base('Gram'))->toBe('g')->and(Units::base('buah'))->toBe('pcs')->and(Units::base('kg'))->toBeNull()->and(Units::base('botol'))->toBeNull();
});

it('records "2 botol" as 1.500 ml with the cost per ml, whether staff enter a total or a price per botol', function () {
    $syrup = syrup();
    $staff = shopperUser();

    $this->actingAs($staff)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $syrup->id, 'qty' => 2, 'unit' => 'botol', 'total' => 60000]]])->assertSessionHasNoErrors();
    $purchase = Purchase::query()->first();
    $line = $purchase->items()->first();
    expect($line->qty)->toBe(1500.0)->and($line->unit_cost)->toBe(40.0)->and($purchase->total)->toBe(60000);

    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/purchases/{$purchase->id}/approve")->assertSessionHasNoErrors();
    expect($syrup->fresh()->stock)->toBe(1500.0)->and($syrup->fresh()->avg_cost)->toBe(40.0);

    // a manager entering a price per botol: 30.000 / botol = 40 / ml
    $this->actingAs(userWithRole('manager'))->post('/admin/inventory/purchases', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $syrup->id, 'qty' => 1, 'unit' => 'botol', 'unit_cost' => 30000]]])->assertSessionHasNoErrors();
    expect($syrup->fresh()->stock)->toBe(2250.0)->and($syrup->fresh()->avg_cost)->toBe(40.0);
});

it('refuses a unit that is not defined for the ingredient, with the allowed choices in the message', function () {
    $syrup = syrup();
    $res = $this->actingAs(shopperUser())->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $syrup->id, 'qty' => 1, 'unit' => 'dus', 'total' => 1000]]]);
    $res->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak dikenal') && str_contains($m, 'botol (750 ml)'));
    expect(Purchase::query()->count())->toBe(0);

    $this->actingAs(shopperUser())->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $syrup->id, 'qty' => 1]]])->assertSessionHas('error'); // no price
});

it('lets staff count in packaging: 3 botol is saved as 2.250 ml', function () {
    $syrup = syrup(3000);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->post('/stock/count/save', ['lines' => [['ingredient_id' => $syrup->id, 'counted' => 3, 'unit' => 'botol']]])->assertSessionHasNoErrors();
    $draft = StockCount::query()->where('user_id', $cashier->id)->first();
    expect($draft->lines()->value('counted'))->toBe(2250.0);
    $this->actingAs($cashier)->get('/stock/count')->assertInertia(fn ($p) => $p->where('ingredients.0.choices.2.name', 'botol')->where('ingredients.0.counted', 2250));
});

it('only allows g, ml or pcs as the base unit and guards changing it', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/admin/inventory/ingredients', ['name' => 'Sirup', 'unit' => '9 botol'])->assertSessionHasErrors('unit');
    $this->actingAs($manager)->post('/admin/inventory/ingredients', ['name' => 'Daun bawang', 'unit' => ' Ikat '])->assertSessionHasNoErrors();
    expect(Ingredient::query()->where('name', 'Daun bawang')->value('unit'))->toBe('ikat');
    $this->actingAs($manager)->get('/admin/inventory')->assertInertia(fn ($p) => $p->where('baseUnits.3.key', 'ikat'));
    $this->actingAs($manager)->post('/admin/inventory/ingredients', ['name' => 'Sirup', 'unit' => 'ml', 'packs' => [['name' => 'botol', 'factor' => 750], ['name' => 'dus', 'factor' => 9000]]])->assertSessionHasNoErrors();
    $sirup = Ingredient::query()->where('name', 'Sirup')->first();
    expect(IngredientUnit::query()->where('ingredient_id', $sirup->id)->count())->toBe(2);

    $this->actingAs($manager)->put("/admin/inventory/ingredients/{$sirup->id}", ['name' => 'Sirup', 'unit' => 'ml', 'packs' => [['name' => 'ML', 'factor' => 1]]])->assertSessionHas('error');
    $this->actingAs($manager)->put("/admin/inventory/ingredients/{$sirup->id}", ['name' => 'Sirup', 'unit' => 'ml', 'packs' => [['name' => 'botol', 'factor' => 700]]])->assertSessionHasNoErrors();
    expect(IngredientUnit::query()->where('ingredient_id', $sirup->id)->pluck('factor', 'name')->all())->toBe(['botol' => 700.0]);

    $this->actingAs($manager)->put("/admin/inventory/ingredients/{$sirup->id}", ['name' => 'Sirup', 'unit' => 'g'])->assertSessionHasNoErrors(); // nothing recorded yet: allowed
    expect($sirup->fresh()->unit)->toBe('g');
    app(InventoryService::class)->count($sirup, 10, $manager);
    $this->actingAs($manager)->put("/admin/inventory/ingredients/{$sirup->id}", ['name' => 'Sirup', 'unit' => 'ml'])->assertSessionHas('error');
    expect($sirup->fresh()->unit)->toBe('g');
});

it('applies the same unit rules in Excel imports', function () {
    $syrup = syrup();
    $manager = userWithRole('manager');

    $file = sheetFile([
        ['Bahan *', 'Satuan', 'Jumlah *', 'Harga per satuan', 'Total harga'],
        ['Sirup vanila', 'botol', '2', '30.000', ''],   // 1.500 ml, 40/ml
        ['Sirup vanila', 'dus', '1', '1000', ''],        // unit not defined
        ['Sirup baru', 'botol', '1', '1000', ''],        // new ingredient cannot start in a packaging
        ['Cuka', 'ml', '1000', '', '5.000'],             // new, base unit, total price
    ]);
    $preview = previewOf($this, 'purchase', $file, $manager)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['ok' => 1, 'new' => 1, 'error' => 2]);
    $errors = collect($preview['rows'])->where('status', 'error')->pluck('message')->implode(' | ');
    expect($errors)->toContain('Satuan "dus" tidak dikenal')->and($errors)->toContain('tidak dikenal untuk bahan baru');

    $this->actingAs($manager)->postJson('/import/purchase/apply', ['rows' => $preview['payload']])->assertOk();
    expect($syrup->fresh()->stock)->toBe(1500.0)->and($syrup->fresh()->avg_cost)->toBe(40.0);
    expect(Ingredient::query()->where('name', 'Cuka')->first())->toMatchArray(['unit' => 'ml', 'stock' => 1000.0, 'avg_cost' => 5.0]);

    $count = sheetFile([['Bahan *', 'Satuan', 'Hitungan *'], ['Sirup vanila', 'botol', '3'], ['Sirup vanila', 'ml', '1']]);
    $cpreview = previewOf($this, 'count', $count, $manager)->assertOk()->json();
    expect($cpreview['summary'])->toMatchArray(['ok' => 1, 'error' => 1]);
    $this->actingAs($manager)->postJson('/import/count/apply', ['rows' => $cpreview['payload']])->assertOk();
    expect(StockCount::query()->where('user_id', $manager->id)->first()->lines()->value('counted'))->toBe(2250.0);
});

it('converts recipe quantities to the base unit', function () {
    $syrup = syrup();
    $manager = userWithRole('manager');
    $file = sheetFile([['Menu *', 'Varian', 'Bahan *', 'Satuan', 'Jumlah *'], ['Americano', '', 'Sirup vanila', 'ml', '15'], ['Caffe Latte', '', 'Sirup vanila', 'l', '0,03']]);
    $preview = previewOf($this, 'recipes', $file, $manager)->assertOk()->json();
    expect($preview['summary']['error'])->toBe(0);
    $this->actingAs($manager)->postJson('/import/recipes/apply', ['rows' => $preview['payload']])->assertOk();
    expect(RecipeLine::query()->where('ingredient_id', $syrup->id)->pluck('qty')->sort()->values()->all())->toBe([15.0, 30.0]);
});

it('shows each ingredient\'s allowed units on the admin and staff screens', function () {
    syrup();
    $this->actingAs(userWithRole('manager'))->get('/admin/inventory')->assertInertia(fn ($p) => $p->has('baseUnits', 3)->where('ingredients.0.packs.0.name', 'botol')->where('ingredients.0.choices.2.factor', 750));
    $this->actingAs(shopperUser())->get('/stock/purchase')->assertInertia(fn ($p) => $p->where('ingredients.0.choices.2.name', 'botol'));
});
