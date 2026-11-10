<?php

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseItem;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Reporting\PurchaseReports;
use App\Domain\Settings\Models\Outlet;

function reportIngredient(string $name, string $unit = 'g'): Ingredient
{
    return Ingredient::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => $name, 'unit' => $unit, 'stock' => 0, 'avg_cost' => 0, 'min_stock' => 0]);
}

/** @param  array<int, array{0: Ingredient, 1: float, 2: float}>  $lines  ingredient, qty in base unit, unit cost */
function reportPurchase(string $date, string $status, ?Supplier $supplier, array $lines, ?string $by = 'manager'): Purchase
{
    $p = Purchase::create(['outlet_id' => (int) Outlet::query()->value('id'), 'supplier_id' => $supplier?->id, 'purchased_on' => $date, 'status' => $status, 'total' => 0, 'user_id' => $by ? userWithRole($by)->id : null, 'note' => 'untuk akhir pekan']);
    $total = 0;
    foreach ($lines as [$ing, $qty, $cost]) {
        $t = (int) round($qty * $cost);
        PurchaseItem::create(['purchase_id' => $p->id, 'ingredient_id' => $ing->id, 'qty' => $qty, 'unit_cost' => $cost, 'total' => $t]);
        $total += $t;
    }
    $p->update(['total' => $total]);

    return $p;
}

it('totals what was bought per ingredient with packages, average and cheapest to dearest price', function () {
    $chicken = reportIngredient('Ayam');
    IngredientUnit::create(['ingredient_id' => $chicken->id, 'name' => 'ekor', 'factor' => 700]);
    $sugar = reportIngredient('Gula');
    $pasar = Supplier::create(['outlet_id' => $chicken->outlet_id, 'name' => 'Pasar Pagi', 'is_active' => true]);
    reportPurchase('2026-11-02', 'approved', $pasar, [[$chicken, 1400, 50], [$sugar, 500, 14]]);
    reportPurchase('2026-11-04', 'approved', null, [[$chicken, 700, 60]]);
    reportPurchase('2026-11-05', 'pending', $pasar, [[$sugar, 1000, 14]]);       // not approved: stays out of the default report

    $r = app(PurchaseReports::class);
    $by = collect($r->by('ingredient', '2026-11-01', '2026-11-30'))->keyBy('label');
    expect($by->keys()->all())->toBe(['Ayam', 'Gula'])                              // dearest first
        ->and($by['Ayam']->qty_label)->toBe('3 ekor (2.100 g)')->and($by['Ayam']->total)->toBe(112000)->and($by['Ayam']->notes)->toBe(2)
        ->and($by['Ayam']->low)->toBe(50.0)->and($by['Ayam']->high)->toBe(60.0)->and($by['Ayam']->average)->toBe(53.33)
        ->and($by['Gula']->qty_label)->toBe('500 g');

    expect($r->summary('2026-11-01', '2026-11-30'))->toMatchArray(['total' => 119000, 'notes' => 2, 'ingredients' => 2, 'average' => 59500, 'top_ingredient' => 'Ayam', 'top_supplier' => 'Pasar Pagi'])
        ->and($r->summary('2026-11-01', '2026-11-30', 'all')['notes'])->toBe(3)
        ->and($r->summary('2026-11-01', '2026-11-30', 'pending')['total'])->toBe(14000)
        ->and($r->summary('2026-11-01', '2026-11-30', 'approved', $pasar->id)['total'])->toBe(77000);

    $entries = $r->entries('2026-11-01', '2026-11-30');
    expect($entries)->toHaveCount(3)->and($entries[0]['date'])->toBe('2026-11-04')->and($entries[0]['ingredient'])->toBe('Ayam')->and($entries[0]['qty_label'])->toBe('1 ekor (700 g)')
        ->and($entries[0]['user'])->toBe(userWithRole('manager')->name);
    expect(collect($r->by('supplier', '2026-11-01', '2026-11-30'))->pluck('label')->all())->toContain('Pasar Pagi', 'Tanpa pemasok');
    expect(collect($r->by('day', '2026-11-01', '2026-11-30'))->pluck('label')->all())->toBe(['2026-11-02', '2026-11-04']);
});

it('shows the report and its Excel to people with the permission only', function () {
    $chicken = reportIngredient('Ayam');
    reportPurchase('2026-11-02', 'approved', null, [[$chicken, 700, 60]]);

    $this->actingAs(userWithRole('manager'))->get('/admin/reports/purchases?from=2026-11-01&to=2026-11-30')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/reports/purchases')->where('summary.total', 42000)->has('rows', 1)->has('entries', 1)->where('filters.status', 'approved'));
    $this->actingAs(userWithRole('manager'))->getJson('/admin/reports/purchases?dimension=nope')->assertStatus(422);
    $this->actingAs(userWithRole('manager'))->get('/admin/reports/purchases/export?format=xlsx&from=2026-11-01&to=2026-11-30')->assertOk();
    $this->actingAs(userWithRole('cashier'))->get('/admin/reports/purchases')->assertForbidden();
});
