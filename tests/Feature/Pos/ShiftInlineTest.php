<?php

use App\Domain\Shift\Models\Shift;

it('lets the cashier open the shift from the POS and stay on the POS', function () {
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->from('/pos')->post('/shift/open', ['opening_cash' => 150000])->assertRedirect('/pos');

    $shift = Shift::query()->where('user_id', $cashier->id)->whereNull('closed_at')->first();
    expect($shift)->not->toBeNull()->and($shift->opening_cash)->toBe(150000);
    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('shift.mine', true));
});

it('tells the POS who may open a shift, so waiters are not asked to', function () {
    $this->actingAs(userWithRole('cashier'))->get('/pos')->assertInertia(fn ($p) => $p->where('can.open_shift', true)->where('shift', null));
    $this->actingAs(userWithRole('waiter'))->get('/pos')->assertInertia(fn ($p) => $p->where('can.open_shift', false));
});

it('keeps the shift page for managers in the menu and for the cashier via the POS', function () {
    $this->actingAs(userWithRole('cashier'))->get('/shift')->assertOk();
    $this->actingAs(userWithRole('manager'))->get('/shift')->assertOk();
});
