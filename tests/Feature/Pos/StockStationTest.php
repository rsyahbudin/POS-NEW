<?php

use App\Domain\Catalog\Models\Station;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function stationIngredient(string $name, Station ...$stations): Ingredient
{
    $i = Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => 'g', 'stock' => 100, 'avg_cost' => 10, 'min_stock' => 10]);
    $i->stations()->sync(collect($stations)->pluck('id')->all());

    return $i;
}

function stationShopper(): User
{
    Role::findByName('cashier', 'web')->givePermissionTo('record-purchase');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return userWithRole('cashier');
}

function stationFor(string $name): Station
{
    return Station::query()->where('name', $name)->first() ?? Station::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'is_active' => true]);
}

it('lets staff count and buy only the ingredients of their own station, and managers everything', function () {
    setting('stock.by_station', true);
    $bar = stationFor('Bar');
    $kitchen = stationFor('Dapur');
    $milk = stationIngredient('Susu', $bar);
    $rice = stationIngredient('Beras', $kitchen);
    $sugar = stationIngredient('Gula', $bar, $kitchen);

    $barista = stationShopper();
    $barista->stations()->sync([$bar->id]);

    $this->actingAs($barista)->get('/stock/count')->assertInertia(fn ($p) => $p->where('share.by_station', true)->where('share.stations', ['Bar'])
        ->where('ingredients', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Gula', 'Susu']));
    $this->actingAs($barista)->get('/stock')->assertInertia(fn ($p) => $p->has('ingredients', 2));
    $this->actingAs(userWithRole('manager'))->get('/stock/count')->assertInertia(fn ($p) => $p->where('share.by_station', false)->has('ingredients', 3));

    // a count line for somebody else's ingredient is ignored; a purchase of one is refused
    $this->actingAs($barista)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $milk->id, 'counted' => 50], ['ingredient_id' => $rice->id, 'counted' => 1]]])->assertSessionHasNoErrors();
    expect($milk->fresh()->stock)->toBe(50.0)->and($rice->fresh()->stock)->toBe(100.0);
    $this->actingAs($barista)->post('/stock/purchase', ['purchased_on' => now()->toDateString(), 'items' => [['ingredient_id' => $rice->id, 'qty' => 5, 'total' => 1000]]])->assertSessionHasErrors('items');
    $this->actingAs($barista)->post('/stock/purchase', ['purchased_on' => now()->toDateString(), 'items' => [['ingredient_id' => $sugar->id, 'qty' => 5, 'total' => 1000]]])->assertSessionHasNoErrors();
});

it('shows everything when stock per station is off, and nothing to a person without a station when it is on', function () {
    stationIngredient('Susu', stationFor('Bar'));
    $staff = userWithRole('cashier');

    $this->actingAs($staff)->get('/stock/count')->assertInertia(fn ($p) => $p->has('ingredients', 1));
    setting('stock.by_station', true);
    $this->actingAs($staff)->get('/stock/count')->assertInertia(fn ($p) => $p->has('ingredients', 0));
});

it('saves the stations of an ingredient and of a user', function () {
    $bar = stationFor('Bar');
    $owner = userWithRole('owner');
    $sugar = stationIngredient('Gula');

    $this->actingAs($owner)->put("/admin/inventory/ingredients/{$sugar->id}", ['name' => 'Gula', 'unit' => 'g', 'min_stock' => 10, 'station_ids' => [$bar->id]])->assertSessionHasNoErrors();
    expect($sugar->fresh()->stations->pluck('id')->all())->toBe([$bar->id]);

    $staff = userWithRole('cashier');
    $this->actingAs($owner)->put("/admin/users/{$staff->id}", ['name' => $staff->name, 'email' => $staff->email, 'role' => 'cashier', 'station_ids' => [$bar->id]])->assertSessionHasNoErrors();
    expect($staff->fresh()->stations->pluck('id')->all())->toBe([$bar->id]);
});

it('reminds a station and the managers when its count is late, and stops once the count is in', function () {
    setting('stock.by_station', true);
    setting('stock.opname_frequency', 'daily');
    setting('stock.opname_deadline', '00:00');
    $bar = stationFor('Bar');
    $milk = stationIngredient('Susu', $bar);
    $barista = userWithRole('cashier');
    $barista->stations()->sync([$bar->id]);
    $open = fn () => AppNotification::query()->where('kind', 'stock.count_late')->whereNull('resolved_at')->count();

    $this->artisan('stock:remind-opname')->assertSuccessful();
    expect($open())->toBe(2);                                                    // one for the barista, one for the managers

    $this->actingAs($barista)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $milk->id, 'counted' => 80]]])->assertSessionHasNoErrors();
    $this->artisan('stock:remind-opname')->assertSuccessful();
    expect($open())->toBe(0);
});
