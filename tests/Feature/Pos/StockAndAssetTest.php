<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\StockCountService;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\Models\Outlet;

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
