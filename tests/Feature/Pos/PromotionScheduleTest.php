<?php

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
    $this->actingAs($owner)->post('/admin/promotions', ['name' => 'Weekend', 'type' => 'percent', 'value' => 1500, 'starts_at' => '10:00', 'ends_at' => '22:00', 'days' => [6, 7], 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'is_active' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/admin/promotions', ['name' => 'Salah', 'type' => 'percent', 'value' => 1000, 'starts_at' => '10:00', 'ends_at' => '22:00', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-01'])->assertSessionHasErrors('ends_on');

    $p = Promotion::query()->where('name', 'Weekend')->first();
    expect($p->days)->toBe([6, 7])->and($p->starts_on->toDateString())->toBe('2026-11-01');
});
