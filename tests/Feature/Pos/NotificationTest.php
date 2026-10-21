<?php

use App\Domain\Asset\Models\Asset;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\StockCountService;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Notification\Notifier;
use App\Domain\Settings\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function lowItem(float $stock = 600, float $min = 500): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'Gula', 'unit' => 'g', 'stock' => $stock, 'min_stock' => $min, 'avg_cost' => 10]);
}

function titles($test, $user): array
{
    return collect($test->actingAs($user)->getJson('/notifications')->assertOk()->json('items'))->pluck('title')->all();
}

it('alerts the roles that can buy or check stock when an ingredient runs low, and clears it after restocking', function () {
    $sugar = lowItem(600, 500);
    expect(AppNotification::query()->count())->toBe(0);

    app(InventoryService::class)->waste($sugar, 200, userWithRole('manager'), 'tumpah'); // 400 <= 500
    expect(titles($this, userWithRole('manager')))->toContain('Gula hampir habis');
    expect(titles($this, userWithRole('cashier')))->toContain('Gula hampir habis'); // view-stock

    app(InventoryService::class)->waste($sugar->fresh(), 50, userWithRole('manager'), 'lagi'); // still low: no second alert
    expect(AppNotification::query()->where('kind', 'stock.low')->count())->toBe(1);

    app(InventoryService::class)->waste($sugar->fresh(), 400, userWithRole('manager'), 'habis'); // -50 => out
    expect(AppNotification::query()->where('kind', 'stock.low')->count())->toBe(1);

    app(InventoryService::class)->receive((int) $sugar->outlet_id, ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $sugar->id, 'qty' => 5000, 'unit_cost' => 10]]], userWithRole('manager'));
    expect(titles($this, userWithRole('manager')))->not->toContain('Gula hampir habis');
});

it('only shows an alert to roles that hold one of its permissions', function () {
    $role = Role::findOrCreate('tanpa-stok', 'web')->syncPermissions(['create-order']);
    $user = User::factory()->create(['is_active' => true, 'outlet_id' => userWithRole('owner')->outlet_id]);
    $user->assignRole($role);

    app(InventoryService::class)->waste(lowItem(600, 500), 200, userWithRole('manager'), 'x');
    expect(titles($this, $user))->toBe([]);
    expect(titles($this, userWithRole('owner')))->toContain('Gula hampir habis');
});

it('tells managers about a stock count waiting for approval and the submitter about the result', function () {
    $sugar = lowItem(1000, 0);
    $cashier = userWithRole('cashier');
    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 900]]])->assertSessionHasNoErrors();
    $count = StockCount::query()->first();

    expect(titles($this, userWithRole('manager')))->toContain('Hitungan stok menunggu persetujuan');
    expect(titles($this, $cashier))->not->toContain('Hitungan stok menunggu persetujuan');

    app(StockCountService::class)->approve($count, userWithRole('manager'));
    expect(titles($this, userWithRole('manager')))->not->toContain('Hitungan stok menunggu persetujuan'); // cleared
    expect(titles($this, $cashier))->toContain('Hitungan stok Anda disetujui');

    $this->actingAs($cashier)->post('/stock/count/submit', ['lines' => [['ingredient_id' => $sugar->id, 'counted' => 10]]])->assertSessionHasNoErrors();
    $second = StockCount::query()->where('status', 'submitted')->first();
    app(StockCountService::class)->reject($second, userWithRole('manager'), 'hitung ulang');
    expect(titles($this, $cashier))->toContain('Hitungan stok Anda ditolak');
});

it('tells managers about shopping waiting for approval and the shopper about the result', function () {
    $sugar = lowItem(1000, 0);
    Role::findByName('cashier')->givePermissionTo('record-purchase');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $cashier = userWithRole('cashier');
    $this->actingAs($cashier)->post('/stock/purchase', ['purchased_on' => '2026-10-21', 'items' => [['ingredient_id' => $sugar->id, 'qty' => 10, 'total' => 1000]]])->assertSessionHasNoErrors();
    $purchase = Purchase::query()->first();

    expect(titles($this, userWithRole('manager')))->toContain('Belanja bahan menunggu persetujuan');
    app(InventoryService::class)->approvePurchase($purchase, userWithRole('manager'));
    expect(titles($this, userWithRole('manager')))->not->toContain('Belanja bahan menunggu persetujuan');
    expect(titles($this, $cashier))->toContain('Belanja Anda disetujui');
});

it('keeps read state per person: mark one, mark all, and cannot read what one cannot see', function () {
    lowItem(100, 500);
    app(InventoryService::class)->waste(Ingredient::query()->first(), 1, userWithRole('manager'), 'x');
    $manager = userWithRole('manager');
    $owner = userWithRole('owner');

    $feed = $this->actingAs($manager)->getJson('/notifications')->json();
    expect($feed['unread'])->toBe(1)->and($feed['items'][0]['read'])->toBeFalse();
    $id = $feed['items'][0]['id'];

    $this->actingAs($manager)->postJson("/notifications/{$id}/read")->assertOk();
    expect($this->actingAs($manager)->getJson('/notifications')->json('unread'))->toBe(0);
    expect($this->actingAs($owner)->getJson('/notifications')->json('unread'))->toBe(1); // owner has not read it

    $own = app(Notifier::class)->notify((int) $manager->outlet_id, 'x', 'Hanya untuk saya', null, null, 'info', null, $manager->id);
    expect(titles($this, $owner))->not->toContain('Hanya untuk saya');
    $this->actingAs($owner)->postJson("/notifications/{$own->id}/read")->assertOk(); // silently ignored
    expect(DB::table('notification_reads')->where('user_id', $owner->id)->where('notification_id', $own->id)->exists())->toBeFalse();

    $this->actingAs($owner)->postJson('/notifications/read-all')->assertOk();
    expect($this->actingAs($owner)->getJson('/notifications')->json('unread'))->toBe(0);
});

it('alerts equipment that needs service or is broken and clears when fixed', function () {
    $asset = Asset::create(['outlet_id' => (int) Outlet::query()->value('id'), 'code' => 'AST-1', 'name' => 'Grinder', 'quantity' => 1, 'condition' => 'good']);
    expect(AppNotification::query()->count())->toBe(0);

    $asset->update(['condition' => 'broken']);
    expect(titles($this, userWithRole('manager')))->toContain('Grinder rusak');
    expect(titles($this, userWithRole('cashier')))->not->toContain('Grinder rusak'); // cashier may look, not manage

    $asset->update(['condition' => 'good']);
    expect(titles($this, userWithRole('manager')))->not->toContain('Grinder rusak');
});

it('tells managers about a cash difference when a shift is closed', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 100000);
    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 95000, 'cash_left' => 0, 'close_key' => 'k1', 'difference_reason' => 'salah kembalian'])->assertSessionHasNoErrors();

    expect(collect($this->actingAs(userWithRole('manager'))->getJson('/notifications')->json('items'))->pluck('title')->first(fn ($t) => str_contains($t, 'Selisih kas shift')))->toContain('KURANG Rp 5.000');
});

it('needs a signed-in user', function () {
    auth()->logout();
    $this->getJson('/notifications')->assertUnauthorized();
});
