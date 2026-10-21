<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\StockCountService;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function stockItem(string $name, float $stock, float $cost = 100): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => 'g', 'stock' => $stock, 'avg_cost' => $cost, 'min_stock' => 10]);
}

// ---------------------------------------------------------------- staff stock list and counting

it('shows staff the stock list without costs, and keeps the admin stock pages for managers', function () {
    stockItem('Gula', 5);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->get('/stock')->assertOk()->assertInertia(fn ($p) => $p->component('stock/index')->has('ingredients', 1)->missing('ingredients.0.avg_cost')->where('ingredients.0.low', true)->where('canCount', true));
    $this->actingAs($cashier)->get('/admin/inventory')->assertForbidden();
});

it('hides the system quantity from counting staff, but not from managers', function () {
    stockItem('Gula', 500);
    $this->actingAs(userWithRole('cashier'))->get('/stock/count')->assertInertia(fn ($p) => $p->where('blind', true)->where('ingredients.0.system', null));
    $this->actingAs(userWithRole('manager'))->get('/stock/count')->assertInertia(fn ($p) => $p->where('blind', false)->where('ingredients.0.system', 500));

    setting('inventory.blind_count', false);
    $this->actingAs(userWithRole('cashier'))->get('/stock/count')->assertInertia(fn ($p) => $p->where('ingredients.0.system', 500));
});

it('applies a staff count as a difference only after a manager approves it', function () {
    $sugar = stockItem('Gula', 100);
    $coffee = stockItem('Kopi', 50);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->post('/stock/count/save', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 90], ['ingredient_id' => $coffee->id, 'counted' => null]]])->assertSessionHasNoErrors();
    $this->actingAs($cashier)->get('/stock/count')->assertInertia(fn ($p) => $p->where('ingredients.0.counted', fn ($v) => $v === null || (float) $v === 90.0));

    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 90]], 'note' => 'Senin pagi'])->assertSessionHasNoErrors();
    $count = StockCount::query()->first();
    expect($count->status)->toBe('submitted')->and($sugar->fresh()->stock)->toBe(100.0);

    $sugar->update(['stock' => 95]); // a sale happens before the manager looks
    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/counts/{$count->id}/approve")->assertSessionHasNoErrors();

    expect($count->fresh()->status)->toBe('approved')->and($sugar->fresh()->stock)->toBe(85.0)->and($coffee->fresh()->stock)->toBe(50.0);
    expect(StockMovement::query()->where('type', 'opname')->where('ingredient_id', $sugar->id)->value('qty'))->toBe(-10.0);
});

it('adjusts stock at once when approval is switched off, and not at all when rejected', function () {
    $sugar = stockItem('Gula', 100);
    $cashier = userWithRole('cashier');
    setting('inventory.count_needs_approval', false);

    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 80]]])->assertSessionHasNoErrors();
    expect($sugar->fresh()->stock)->toBe(80.0)->and(StockCount::query()->first()->status)->toBe('approved');

    setting('inventory.count_needs_approval', true);
    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 10]]])->assertSessionHasNoErrors();
    $pending = StockCount::query()->where('status', 'submitted')->first();
    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/counts/{$pending->id}/reject", ['reason' => 'hitung ulang'])->assertSessionHasNoErrors();
    expect($pending->fresh()->status)->toBe('rejected')->and($sugar->fresh()->stock)->toBe(80.0);
    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/counts/{$pending->id}/approve")->assertSessionHas('error');
});

it('refuses an empty count and a second submit of the same draft', function () {
    $sugar = stockItem('Gula', 100);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => null]]])->assertSessionHas('error');
    $draft = app(StockCountService::class)->draftFor($cashier, (int) $sugar->outlet_id);
    app(StockCountService::class)->save($draft, [$sugar->id => 90]);
    app(StockCountService::class)->submit($draft, $cashier);
    expect(fn () => app(StockCountService::class)->save($draft, [$sugar->id => 1]))->toThrow(OrderException::class);
});

it('lists staff counts for the manager to review', function () {
    $sugar = stockItem('Gula', 100);
    $this->actingAs(userWithRole('waiter'))->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 90]]])->assertSessionHasNoErrors();

    $this->actingAs(userWithRole('manager'))->get('/admin/inventory')->assertInertia(fn ($p) => $p->has('counts', 1)->where('counts.0.status', 'submitted')->where('counts.0.lines.0.diff', -10)->where('counts.0.lines.0.value', -1000));
});

// ---------------------------------------------------------------- assets

it('keeps an equipment register: add, change, log service, and see value and attention counts', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/assets', ['name' => 'Mesin espresso', 'category' => 'Mesin kopi', 'quantity' => 1, 'purchase_price' => 15000000, 'condition' => 'good', 'location' => 'Bar'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/assets', ['name' => 'Grinder', 'quantity' => 2, 'purchase_price' => 2000000, 'condition' => 'good'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/assets', ['name' => 'Kulkas lama', 'quantity' => 1, 'purchase_price' => 3000000, 'condition' => 'retired'])->assertSessionHasNoErrors();
    $machine = Asset::query()->where('name', 'Mesin espresso')->first();
    expect($machine->code)->toBe('AST-0001');

    $this->actingAs($manager)->post("/assets/{$machine->id}/logs", ['type' => 'repair', 'happened_on' => '2026-10-20', 'cost' => 350000, 'note' => 'Ganti seal', 'condition' => 'needs_service'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post("/assets/{$machine->id}/logs", ['type' => 'move', 'happened_on' => '2026-10-21', 'location' => 'Gudang'])->assertSessionHasNoErrors();
    expect($machine->fresh()->condition)->toBe('needs_service')->and($machine->fresh()->location)->toBe('Gudang')->and($machine->logs()->count())->toBe(2);

    $this->actingAs($manager)->get('/assets')->assertInertia(fn ($p) => $p->component('assets/index')->where('summary.items', 3)->where('summary.value', 15000000 + 4000000)->where('summary.attention', 1)->where('can.manage', true)->has('assets', 3));

    $this->actingAs($manager)->put("/assets/{$machine->id}", ['name' => 'Mesin espresso 2 group', 'quantity' => 1, 'condition' => 'good'])->assertSessionHasNoErrors();
    expect($machine->fresh()->name)->toBe('Mesin espresso 2 group');
    $this->actingAs($manager)->get('/assets/export?format=xlsx')->assertOk();
    $this->actingAs($manager)->get('/assets/export?format=pdf')->assertOk();
    $this->actingAs($manager)->delete("/assets/{$machine->id}")->assertSessionHasNoErrors();
    expect(Asset::query()->count())->toBe(2);
});

it('lets staff look at the equipment list but not change it', function () {
    $asset = Asset::create(['outlet_id' => (int) Outlet::query()->value('id'), 'code' => 'AST-0001', 'name' => 'Blender', 'quantity' => 1, 'condition' => 'good']);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->get('/assets')->assertOk()->assertInertia(fn ($p) => $p->where('can.manage', false));
    $this->actingAs($cashier)->post('/assets', ['name' => 'X', 'quantity' => 1, 'condition' => 'good'])->assertForbidden();
    $this->actingAs($cashier)->delete("/assets/{$asset->id}")->assertForbidden();
    $this->actingAs($cashier)->post("/assets/{$asset->id}/logs", ['type' => 'note', 'happened_on' => '2026-10-20'])->assertForbidden();
});

it('rejects a duplicate asset code and an unknown condition', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/assets', ['name' => 'A', 'code' => 'K-1', 'quantity' => 1, 'condition' => 'good'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/assets', ['name' => 'B', 'code' => 'K-1', 'quantity' => 1, 'condition' => 'good'])->assertSessionHasErrors('code');
    $this->actingAs($manager)->post('/assets', ['name' => 'C', 'quantity' => 1, 'condition' => 'weird'])->assertSessionHasErrors('condition');
});

// ---------------------------------------------------------------- staff shopping

function shopper(): User
{
    $role = Role::findByName('cashier');
    $role->givePermissionTo('record-purchase');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return userWithRole('cashier');
}

it('lets a shopping employee record purchases, but stock and cost move only after a manager approves', function () {
    $beans = stockItem('Biji kopi', 1000, 50);
    $shopper = shopper();
    $payload = ['purchased_on' => '2026-10-21', 'supplier' => 'Pasar', 'items' => [['ingredient_id' => $beans->id, 'qty' => 1000, 'unit_cost' => 70]]];

    $this->actingAs($shopper)->post('/stock/purchase', $payload)->assertSessionHasNoErrors();
    $purchase = Purchase::query()->first();
    expect($purchase->status)->toBe('pending')->and($purchase->total)->toBe(70000)->and($beans->fresh()->stock)->toBe(1000.0)->and($beans->fresh()->avg_cost)->toBe(50.0);
    expect(StockMovement::query()->where('type', 'purchase')->count())->toBe(0);

    $this->actingAs($shopper)->post("/admin/inventory/purchases/{$purchase->id}/approve")->assertForbidden();
    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/purchases/{$purchase->id}/approve")->assertSessionHasNoErrors();
    expect($purchase->fresh()->status)->toBe('approved')->and($beans->fresh()->stock)->toBe(2000.0)->and($beans->fresh()->avg_cost)->toBe(60.0);
    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/purchases/{$purchase->id}/approve")->assertSessionHas('error'); // not twice
});

it('keeps stock untouched when a manager rejects the shopping', function () {
    $beans = stockItem('Biji kopi', 1000, 50);
    $shopper = shopper();
    $this->actingAs($shopper)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $beans->id, 'qty' => 5, 'unit_cost' => 99999]]])->assertSessionHasNoErrors();
    $purchase = Purchase::query()->first();

    $this->actingAs(userWithRole('manager'))->post("/admin/inventory/purchases/{$purchase->id}/reject", ['reason' => 'harga tidak wajar'])->assertSessionHasNoErrors();
    expect($purchase->fresh()->status)->toBe('rejected')->and($beans->fresh()->stock)->toBe(1000.0)->and($beans->fresh()->avg_cost)->toBe(50.0);
});

it('applies staff shopping at once when approval is switched off, and managers always apply directly', function () {
    $beans = stockItem('Biji kopi', 0, 0);
    setting('inventory.purchase_needs_approval', false);
    $this->actingAs(shopper())->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $beans->id, 'qty' => 10, 'unit_cost' => 5]]])->assertSessionHasNoErrors();
    expect($beans->fresh()->stock)->toBe(10.0);

    setting('inventory.purchase_needs_approval', true);
    $this->actingAs(userWithRole('manager'))->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $beans->id, 'qty' => 10, 'unit_cost' => 5]]])->assertSessionHasNoErrors();
    expect($beans->fresh()->stock)->toBe(20.0);
});

it('does not let anyone approve their own entry, except the owner', function () {
    $beans = stockItem('Biji kopi', 100, 10);
    $user = shopper();
    $user->givePermissionTo('manage-inventory'); // can review, but this one is their own

    $purchase = app(InventoryService::class)->submitPurchase((int) $beans->outlet_id, ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $beans->id, 'qty' => 5, 'unit_cost' => 10]]], userWithRole('waiter'));
    $purchase->update(['user_id' => $user->id]);
    expect(fn () => app(InventoryService::class)->approvePurchase($purchase, $user))->toThrow(OrderException::class, 'orang lain');
    app(InventoryService::class)->approvePurchase($purchase, userWithRole('owner')); // owner may

    $count = StockCount::create(['outlet_id' => $beans->outlet_id, 'user_id' => $user->id, 'status' => 'submitted']);
    $count->lines()->create(['ingredient_id' => $beans->id, 'counted' => 90, 'system_qty' => 100]);
    expect(fn () => app(StockCountService::class)->approve($count, $user))->toThrow(OrderException::class, 'orang lain');
    expect(fn () => app(StockCountService::class)->reject($count, $user, 'x'))->toThrow(OrderException::class);
});

it('keeps costs and the shopping screen away from staff without the permission', function () {
    $waiter = userWithRole('waiter');
    $this->actingAs($waiter)->get('/stock/purchase')->assertForbidden();
    $this->actingAs($waiter)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => []])->assertForbidden();
    $this->actingAs(shopper())->get('/stock/purchase')->assertOk()->assertInertia(fn ($p) => $p->component('stock/purchase')->where('needsApproval', true)->missing('ingredients.0.avg_cost'));
    $this->actingAs(shopper())->get('/admin/inventory')->assertForbidden();
});

it('lets shopping staff import a purchase list but not create ingredients through it', function () {
    stockItem('Gula', 10, 5);
    $shopper = shopper();
    $file = sheetFile([['Bahan *', 'Satuan', 'Jumlah *', 'Harga per satuan', 'Total harga'], ['Gula', 'g', '1.000', '', '15.000'], ['Teh', 'g', '10', '2', '']]);

    $preview = previewOf($this, 'purchase', $file, $shopper)->assertOk()->json();
    expect($preview['summary'])->toMatchArray(['ok' => 1, 'error' => 1]);
    $this->actingAs($shopper)->postJson('/import/purchase/apply', ['rows' => $preview['payload'], 'extra' => ['purchased_on' => '2026-10-21']])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'disetujui manager'));
    $purchase = Purchase::query()->first();
    expect($purchase->status)->toBe('pending')->and($purchase->total)->toBe(15000)->and(Ingredient::query()->where('name', 'Teh')->exists())->toBeFalse();
    $this->actingAs(userWithRole('waiter'))->get('/import/purchase/template')->assertForbidden();
});
