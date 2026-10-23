<?php

use App\Domain\Ordering\Models\Order;
use App\Domain\Pricing\Actions\ApplyDiscountAction;
use App\Domain\Pricing\Models\Discount;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Settings\Models\Outlet;
use Carbon\CarbonImmutable;

function promo(array $over = []): Promotion
{
    return Promotion::create($over + ['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'Happy Hour', 'type' => 'percent', 'value' => 1000, 'starts_at' => '15:00', 'ends_at' => '17:00', 'is_active' => true])->fresh();
}

it('runs only inside its hours', function () {
    $p = promo();
    expect($p->isActiveAt(CarbonImmutable::parse('2026-11-04 15:00:00')))->toBeTrue()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-04 16:30:00')))->toBeTrue()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-04 14:59:00')))->toBeFalse()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-04 17:30:00')))->toBeFalse();
});

it('runs only on the chosen weekdays', function () {
    $p = promo(['days' => [1, 2, 3, 4, 5]]); // Mon-Fri
    expect($p->isActiveAt(CarbonImmutable::parse('2026-11-04 16:00:00')))->toBeTrue()  // Wednesday
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-07 16:00:00')))->toBeFalse(); // Saturday
});

it('runs only between its start and end dates, inclusive', function () {
    $p = promo(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-12']);
    expect($p->isActiveAt(CarbonImmutable::parse('2026-11-09 16:00:00')))->toBeFalse()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-10 16:00:00')))->toBeTrue()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-12 16:00:00')))->toBeTrue()
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-13 16:00:00')))->toBeFalse();
});

it('supports hours that cross midnight, counting the after-midnight part for the day it started', function () {
    $p = promo(['starts_at' => '22:00', 'ends_at' => '02:00', 'days' => [5]]); // Friday night
    expect($p->isActiveAt(CarbonImmutable::parse('2026-11-06 23:00:00')))->toBeTrue()   // Friday 23:00
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-07 01:00:00')))->toBeTrue()  // Saturday 01:00, still Friday's promo
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-07 23:00:00')))->toBeFalse() // Saturday night
        ->and($p->isActiveAt(CarbonImmutable::parse('2026-11-06 12:00:00')))->toBeFalse();
});

it('saves a promo with days and dates from the settings page, in whole percent', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/promotions', ['name' => 'Weekend', 'type' => 'percent', 'value' => 1500, 'starts_at' => '10:00', 'ends_at' => '22:00', 'days' => [6, 7], 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'is_active' => true, 'scope' => 'order'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/admin/promotions', ['name' => 'Salah', 'type' => 'percent', 'value' => 1000, 'starts_at' => '10:00', 'ends_at' => '22:00', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-01', 'scope' => 'order'])->assertSessionHasErrors('ends_on');

    $p = Promotion::query()->where('name', 'Weekend')->first();
    expect($p->days)->toBe([6, 7])->and($p->starts_on->toDateString())->toBe('2026-11-01');
});

// ------------------------------------------------------------ scope and stacking

function allDayPromo(string $name, int $valueBp, array $over = []): Promotion
{
    return promo(['name' => $name, 'value' => $valueBp, 'starts_at' => '00:00', 'ends_at' => '23:59'] + $over);
}

function coffeeAndCake(string $type = 'dine_in'): Order
{
    return placeOrder(type: $type, items: [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Croissant')->id, 'qty' => 1]]); // 44.000 + 25.000
}

it('discounts only the categories a promo is set for', function () {
    $kopi = product('Americano')->category_id;
    allDayPromo('Kopi 20%', 2000, ['scope' => 'category', 'category_ids' => [$kopi]]);

    $order = coffeeAndCake();
    expect($order->fresh()->discount_total)->toBe(8800)->and($order->fresh()->grand_total)->toBe(69000 - 8800);
});

it('discounts only the chosen menu items', function () {
    allDayPromo('Croissant 10%', 1000, ['scope' => 'product', 'product_ids' => [product('Croissant')->id]]);

    $order = coffeeAndCake();
    expect($order->fresh()->discount_total)->toBe(2500);
});

it('adds up promos marked combinable, but never takes more than the price of an item', function () {
    $kopi = product('Americano')->category_id;
    allDayPromo('Kopi 10%', 1000, ['scope' => 'category', 'category_ids' => [$kopi], 'combinable' => true]);
    allDayPromo('Senin 5%', 500, ['scope' => 'category', 'category_ids' => [$kopi], 'combinable' => true]);
    expect(coffeeAndCake()->fresh()->discount_total)->toBe(6600); // 15% of 44.000

    Promotion::query()->update(['is_active' => false]);
    allDayPromo('Gila 60%', 6000, ['scope' => 'product', 'product_ids' => [product('Americano')->id], 'combinable' => true]);
    allDayPromo('Gila lagi 60%', 6000, ['scope' => 'product', 'product_ids' => [product('Americano')->id], 'combinable' => true]);
    expect(coffeeAndCake('takeaway')->fresh()->discount_total)->toBe(44000); // capped at the item price
});

it('lets a promo that is not combinable stand alone', function () {
    allDayPromo('Happy Hour 20%', 2000);                        // exclusive (default)
    allDayPromo('Member 10%', 1000, ['combinable' => true]);

    $order = coffeeAndCake();
    expect($order->fresh()->discount_total)->toBe(13800)        // 20% of 69.000 only
        ->and(Discount::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('does not bring a removed promo back on the next round, but gives it to new items', function () {
    $kopi = product('Americano')->category_id;
    allDayPromo('Kopi 20%', 2000, ['scope' => 'category', 'category_ids' => [$kopi]]);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = coffeeAndCake();
    $first = Discount::query()->where('order_id', $order->id)->firstOrFail();

    app(ApplyDiscountAction::class)->remove($first, $cashier);
    placeOrder($cashier, items: [['product_id' => product('Americano')->id, 'qty' => 1]], tableId: $order->table_id);

    $live = Discount::query()->where('order_id', $order->id)->whereNull('removed_at')->get();
    expect($live)->toHaveCount(1)->and($order->fresh()->discount_total)->toBe(4400); // only the new coffee (22.000 x 20%)
});

it('saves a category promo from the settings page and rejects one with nothing selected', function () {
    $owner = userWithRole('owner');
    $kopi = product('Americano')->category_id;
    $body = ['name' => 'Kopi 20%', 'type' => 'percent', 'value' => 2000, 'starts_at' => '10:00', 'ends_at' => '22:00', 'scope' => 'category', 'combinable' => true];

    $this->actingAs($owner)->post('/admin/promotions', $body + ['category_ids' => []])->assertSessionHasErrors('scope');
    $this->actingAs($owner)->post('/admin/promotions', $body + ['category_ids' => [$kopi]])->assertSessionHasNoErrors();

    $p = Promotion::query()->where('name', 'Kopi 20%')->first();
    expect($p->scope)->toBe('category')->and($p->category_ids)->toBe([$kopi])->and($p->combinable)->toBeTrue();
});
