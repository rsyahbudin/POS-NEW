<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Models\WasteEntry;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Inventory\WasteService;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\Models\Outlet;
use App\Support\BusinessDate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function supplierOf(string $name = 'Toko Maju'): Supplier
{
    return Supplier::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'phone' => '0812']);
}

function stuff(string $name, string $unit, float $stock, float $cost): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => $unit, 'stock' => $stock, 'avg_cost' => $cost]);
}

function reasonId(string $name = 'Basi / busuk'): int
{
    return (int) WasteReason::query()->where('name', $name)->value('id');
}

function grant(string $role, string $permission): void
{
    Role::findByName($role)->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ---------------------------------------------------------------- suppliers

it('keeps supplier data and shows spending and ingredients per supplier', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/admin/suppliers', ['name' => 'Toko Maju', 'phone' => '0812-111', 'address' => 'Pasar Baru', 'note' => 'bayar tunai'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/admin/suppliers', ['name' => 'Toko Maju'])->assertSessionHasErrors('name');
    $s = Supplier::query()->first();
    $milk = stuff('Susu', 'ml', 0, 0);
    $this->actingAs($manager)->post('/admin/inventory/purchases', ['purchased_on' => now()->toDateString(), 'supplier_id' => $s->id, 'items' => [['ingredient_id' => $milk->id, 'qty' => 1000, 'total' => 20000]]])->assertSessionHasNoErrors();

    $purchase = Purchase::query()->first();
    expect($purchase->supplier_id)->toBe($s->id)->and($purchase->supplier)->toBe('Toko Maju');
    $this->actingAs($manager)->get('/admin/suppliers')->assertInertia(fn ($p) => $p->component('admin/suppliers')->where('suppliers.0.purchases', 1)->where('suppliers.0.total', 20000)->where('suppliers.0.total_30', 20000));
    $this->actingAs($manager)->getJson("/admin/suppliers/{$s->id}")->assertOk()->assertJsonPath('purchases.0.items.0.name', 'Susu')->assertJsonPath('purchases.0.items.0.unit_cost', 20);
    $this->actingAs($manager)->put("/admin/suppliers/{$s->id}", ['name' => 'Toko Maju Jaya', 'is_active' => false])->assertSessionHasNoErrors();
    expect($s->fresh())->toMatchArray(['name' => 'Toko Maju Jaya', 'is_active' => false]);
    $this->actingAs(userWithRole('cashier'))->get('/admin/suppliers')->assertForbidden();
});

it('lets shopping staff pick a supplier, and rejects one that does not exist', function () {
    $s = supplierOf();
    $milk = stuff('Susu', 'ml', 0, 0);
    grant('cashier', 'record-purchase');
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->get('/stock/purchase')->assertInertia(fn ($p) => $p->where('suppliers.0.name', 'Toko Maju'));
    $this->actingAs($cashier)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'supplier_id' => $s->id, 'items' => [['ingredient_id' => $milk->id, 'qty' => 10, 'total' => 1000]]])->assertSessionHasNoErrors();
    expect(Purchase::query()->first()->supplier)->toBe('Toko Maju');
    $this->actingAs($cashier)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'supplier_id' => 99999, 'items' => [['ingredient_id' => $milk->id, 'qty' => 10, 'total' => 1000]]])->assertSessionHasErrors('supplier_id');
});

it('compares the latest price of an ingredient between suppliers', function () {
    $a = supplierOf('Toko A');
    $b = supplierOf('Toko B');
    $milk = stuff('Susu', 'ml', 0, 0);
    $svc = app(InventoryService::class);
    $manager = userWithRole('manager');
    $svc->receive((int) $milk->outlet_id, ['purchased_on' => '2026-10-01', 'supplier_id' => $a->id, 'items' => [['ingredient_id' => $milk->id, 'qty' => 1000, 'unit_cost' => 25]]], $manager);
    $svc->receive((int) $milk->outlet_id, ['purchased_on' => '2026-10-10', 'supplier_id' => $b->id, 'items' => [['ingredient_id' => $milk->id, 'qty' => 1000, 'unit_cost' => 20]]], $manager);
    $svc->receive((int) $milk->outlet_id, ['purchased_on' => '2026-10-15', 'supplier_id' => $a->id, 'items' => [['ingredient_id' => $milk->id, 'qty' => 1000, 'unit_cost' => 22]]], $manager);

    $this->actingAs($manager)->get('/admin/suppliers')->assertInertia(fn ($p) => $p->where('compare.0.ingredient', 'Susu')->where('compare.0.offers.0.supplier', 'Toko B')->where('compare.0.offers.0.cost', 20)->where('compare.0.offers.1.supplier', 'Toko A')->where('compare.0.offers.1.cost', 22));
});

it('warns managers when a purchase price jumps past the limit', function () {
    $sugar = stuff('Gula', 'g', 1000, 10);
    $manager = userWithRole('manager');
    $svc = app(InventoryService::class);

    $svc->receive((int) $sugar->outlet_id, ['purchased_on' => '2026-10-21', 'supplier' => 'Toko A', 'items' => [['ingredient_id' => $sugar->id, 'qty' => 100, 'unit_cost' => 11]]], $manager); // +10%
    expect(AppNotification::query()->where('kind', 'price.rise')->count())->toBe(0);
    $svc->receive((int) $sugar->outlet_id, ['purchased_on' => '2026-10-21', 'supplier' => 'Toko A', 'items' => [['ingredient_id' => $sugar->id, 'qty' => 100, 'unit_cost' => 40]]], $manager);
    $alert = AppNotification::query()->where('kind', 'price.rise')->first();
    expect($alert->title)->toContain('Harga Gula naik')->and($alert->body)->toContain('Toko A');

    setting('inventory.price_alert_percent', 0);
    $svc->receive((int) $sugar->outlet_id, ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $sugar->id, 'qty' => 100, 'unit_cost' => 500]]], $manager);
    expect(AppNotification::query()->where('kind', 'price.rise')->count())->toBe(1);
});

// ---------------------------------------------------------------- waste

it('records ingredient waste with a reason, the cost at that moment, and the person', function () {
    $syrup = stuff('Sirup', 'ml', 3000, 40);
    IngredientUnit::create(['ingredient_id' => $syrup->id, 'name' => 'botol', 'factor' => 750]);
    grant('cashier', 'record-waste');
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->post('/stock/waste/ingredient', ['ingredient_id' => $syrup->id, 'qty' => 1, 'unit' => 'botol', 'reason_id' => reasonId('Tumpah'), 'note' => 'jatuh di bar'])->assertSessionHasNoErrors();
    $entry = WasteEntry::query()->first();
    expect($syrup->fresh()->stock)->toBe(2250.0)->and($entry)->toMatchArray(['kind' => 'ingredient', 'label' => 'Sirup', 'reason' => 'Tumpah', 'cost' => 30000, 'user_id' => $cashier->id])->and($entry->qty)->toBe(750.0);
    expect(StockMovement::query()->where('waste_entry_id', $entry->id)->first())->toMatchArray(['type' => 'waste', 'user_id' => $cashier->id]);

    $syrup->update(['avg_cost' => 999]); // a later price change does not rewrite the loss
    expect($entry->fresh()->cost)->toBe(30000);
    expect(collect($this->actingAs(userWithRole('manager'))->getJson('/notifications')->json('items'))->pluck('title')->all())->toContain("{$cashier->name} membuang Sirup");
});

it('records finished menu waste through the recipe and refuses a menu without one', function () {
    $beans = stuff('Biji kopi', 'g', 1000, 200);
    $milk = stuff('Susu', 'ml', 5000, 20);
    $americano = product('Americano');
    RecipeLine::create(['product_id' => $americano->id, 'ingredient_id' => $beans->id, 'qty' => 18]);
    RecipeLine::create(['product_id' => $americano->id, 'ingredient_id' => $milk->id, 'qty' => 100]);
    $manager = userWithRole('manager');

    $this->actingAs($manager)->post('/stock/waste/product', ['product_id' => $americano->id, 'portions' => 2, 'reason_id' => reasonId('Salah buat')])->assertSessionHasNoErrors();
    $entry = WasteEntry::query()->first();
    expect($entry)->toMatchArray(['kind' => 'product', 'unit' => 'porsi', 'cost' => 2 * (18 * 200 + 100 * 20)])->and($entry->qty)->toBe(2.0);
    expect($beans->fresh()->stock)->toBe(964.0)->and($milk->fresh()->stock)->toBe(4800.0);

    $this->actingAs($manager)->post('/stock/waste/product', ['product_id' => product('Croissant')->id, 'portions' => 1, 'reason_id' => reasonId()])->assertSessionHas('error', fn ($m) => str_contains($m, 'belum punya resep'));
    expect(WasteEntry::query()->count())->toBe(1);
});

it('needs a valid reason and the right permission, and managers keep the reason list', function () {
    $syrup = stuff('Sirup', 'ml', 1000, 40);
    $this->actingAs(userWithRole('waiter'))->get('/stock/waste')->assertForbidden();
    $manager = userWithRole('manager');
    $this->actingAs($manager)->post('/stock/waste/ingredient', ['ingredient_id' => $syrup->id, 'qty' => 5, 'reason_id' => 99999])->assertSessionHas('error');
    expect(WasteEntry::query()->count())->toBe(0)->and($syrup->fresh()->stock)->toBe(1000.0);

    $this->actingAs($manager)->post('/stock/waste/reasons', ['name' => 'Dibawa pulang'])->assertSessionHasNoErrors();
    $id = (int) WasteReason::query()->where('name', 'Dibawa pulang')->value('id');
    $this->actingAs($manager)->put("/stock/waste/reasons/{$id}", ['name' => 'Dibawa pulang', 'is_active' => false])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/stock/waste/ingredient', ['ingredient_id' => $syrup->id, 'qty' => 5, 'reason_id' => $id])->assertSessionHas('error'); // switched off
    $this->actingAs($manager)->get('/stock/waste')->assertInertia(fn ($p) => $p->component('stock/waste')->has('reasons', 7)->has('ingredients', 1)->where('canManage', true));
    $this->actingAs($manager)->post('/stock/waste/reasons', ['name' => 'Tumpah'])->assertSessionHasErrors('name');
});

it('adds up waste per item, reason, person and day with Excel and PDF', function () {
    $manager = userWithRole('manager');
    $owner = userWithRole('owner');
    $a = stuff('Susu', 'ml', 10000, 20);
    $b = stuff('Gula', 'g', 10000, 10);
    $svc = app(WasteService::class);
    $svc->ingredient($a, 500, null, reasonId('Basi / busuk'), null, $manager);   // 10.000
    $svc->ingredient($a, 250, null, reasonId('Tumpah'), null, $owner);          // 5.000
    $svc->ingredient($b, 100, null, reasonId('Tumpah'), 'jatuh', $manager);     // 1.000
    $d = BusinessDate::for();

    $props = $this->actingAs($manager)->get("/admin/reports/waste?from={$d}&to={$d}&dimension=item")->assertOk()->inertiaProps();
    expect($props['summary'])->toMatchArray(['cost' => 16000, 'entries' => 3, 'top_item' => 'Susu', 'top_reason' => 'Basi / busuk']);
    expect(collect($props['rows'])->pluck('cost', 'label')->all())->toBe(['Susu' => 15000, 'Gula' => 1000]);
    expect(collect($this->actingAs($manager)->get("/admin/reports/waste?from={$d}&to={$d}&dimension=reason")->inertiaProps('rows'))->pluck('cost', 'label')->all())->toBe(['Basi / busuk' => 10000, 'Tumpah' => 6000]);
    expect(collect($this->actingAs($manager)->get("/admin/reports/waste?from={$d}&to={$d}&dimension=person")->inertiaProps('rows'))->pluck('cost', 'label')->all())->toBe([$manager->name => 11000, $owner->name => 5000]);
    $this->actingAs($manager)->get("/admin/reports/waste?from={$d}&to={$d}&dimension=day")->assertOk();
    $this->actingAs($manager)->get("/admin/reports/waste/export?from={$d}&to={$d}&format=xlsx")->assertOk();
    $this->actingAs($manager)->get("/admin/reports/waste/export?from={$d}&to={$d}&format=pdf")->assertOk();
    $this->actingAs(userWithRole('cashier'))->get('/admin/reports/waste')->assertForbidden();
});
