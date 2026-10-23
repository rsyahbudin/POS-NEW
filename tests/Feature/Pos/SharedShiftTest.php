<?php

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Payment\Models\Payment;

it('lets a cashier work inside the shift the owner opened, with the cashier as the one who took the money', function () {
    $owner = userWithRole('owner');
    $shift = openShiftFor($owner, 100000);
    $cashier = userWithRole('cashier');
    $order = placeOrder($cashier, type: 'takeaway');

    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('shift.id', $shift->id)->where('shift.mine', false)->where('shift.owner', $owner->name));

    $payment = pay($order, $cashier);
    expect($payment->shift_id)->toBe($shift->id)->and($payment->received_by)->toBe($cashier->id);
    expect(Payment::query()->where('shift_id', $shift->id)->count())->toBe(1);
});

it('keeps payments blocked for everyone without a shift once sharing is switched off', function () {
    setting('shift.shared_for_managers', false);
    openShiftFor(userWithRole('owner'), 0);
    $cashier = userWithRole('cashier');
    $order = placeOrder($cashier, type: 'takeaway');

    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('shift', null));
    expect(fn () => pay($order, $cashier))->toThrow(OrderException::class, 'shift');
});

it('never asks a waiter to open a shift, since waiters take no payments', function () {
    $this->actingAs(userWithRole('waiter'))->get('/pos')->assertInertia(fn ($p) => $p->where('can.pay', false)->where('can.open_shift', false));
});
