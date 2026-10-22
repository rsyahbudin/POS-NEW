<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Inventory\WasteService;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Settings\Models\Outlet;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => setting('inventory.enabled', true));

function jItem(string $name, string $unit, float $stock = 0, float $cost = 0): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => $unit, 'stock' => $stock, 'avg_cost' => $cost]);
}

/** Debit minus credit of a system account. */
function bal(string $key): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.system_key', $key)->selectRaw('coalesce(sum(debit - credit), 0) as b')->value('b');
}

it('posts nothing while the journal setting is off', function () {
    $beans = jItem('Biji', 'g', 1000, 100);
    app(InventoryService::class)->receive((int) Outlet::query()->value('id'), ['purchased_on' => now()->toDateString(), 'items' => [['ingredient_id' => $beans->id, 'qty' => 100, 'unit_cost' => 50]]], userWithRole('manager'));
    expect(DB::table('journal_entries')->count())->toBe(0);
});

it('journals purchase, sale, void, waste and count differences and keeps the books balanced', function () {
    setting('inventory.post_journals', true);
    $manager = userWithRole('manager');
    $outlet = (int) Outlet::query()->value('id');
    $beans = jItem('Biji', 'g', 0, 0);
    $svc = app(InventoryService::class);

    $svc->receive($outlet, ['purchased_on' => now()->toDateString(), 'items' => [['ingredient_id' => $beans->id, 'qty' => 1000, 'unit_cost' => 50]]], $manager);
    expect(bal('inventory_asset'))->toBe(50000)->and(bal('purchase_payment'))->toBe(-50000);

    RecipeLine::create(['product_id' => product('Americano')->id, 'ingredient_id' => $beans->id, 'qty' => 20]);
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2]]);
    expect(bal('cogs'))->toBe(2000)->and(bal('inventory_asset'))->toBe(48000);

    app(VoidItemAction::class)->handle($order->items->first()->fresh(), userWithRole('cashier'), 'salah');
    expect(bal('cogs'))->toBe(0)->and(bal('inventory_asset'))->toBe(50000);

    app(WasteService::class)->ingredient($beans->fresh(), 100, null, (int) WasteReason::query()->value('id'), null, $manager);
    expect(bal('waste_loss'))->toBe(5000)->and(bal('inventory_asset'))->toBe(45000);

    $svc->count($beans->fresh(), 850, $manager); // 900 in system -> 50 missing
    expect(bal('stock_variance'))->toBe(2500)->and(bal('inventory_asset'))->toBe(42500);

    // inventory account equals stock value
    $beans->refresh();
    expect((int) round($beans->stock * $beans->avg_cost))->toBe(bal('inventory_asset'));
    expect((int) DB::table('journal_lines')->sum('debit'))->toBe((int) DB::table('journal_lines')->sum('credit'));
});
