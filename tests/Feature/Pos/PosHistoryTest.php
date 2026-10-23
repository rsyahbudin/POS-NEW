<?php

use App\Domain\Ordering\Models\Order;

it("shows a cashier today's bills they opened or took payment for, and managers the whole outlet", function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $mine = placeOrder($cashier, type: 'takeaway');
    pay($mine, $cashier);                                                 // opened and paid by the cashier
    $waiters = placeOrder(userWithRole('waiter'), type: 'takeaway');
    pay($waiters, $cashier);                                              // opened by a waiter, paid by the cashier
    $others = placeOrder(userWithRole('manager'), type: 'takeaway');      // nothing to do with the cashier

    $this->actingAs($cashier)->getJson('/pos/history')->assertOk()
        ->assertJsonPath('scope', 'mine')->assertJsonCount(2, 'orders')
        ->assertJsonPath('summary.paid', 2)->assertJsonPath('summary.total', 88000)->assertJsonPath('summary.open', 0);

    $this->actingAs(userWithRole('manager'))->getJson('/pos/history')->assertOk()
        ->assertJsonPath('scope', 'all')->assertJsonCount(3, 'orders')->assertJsonPath('summary.open', 1)
        ->assertJsonPath('orders.0.id', $others->id)->assertJsonPath('orders.2.bills.0.status', 'paid');
});

it('only lists the current business day and is closed to people who cannot take orders', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $old = placeOrder($cashier, type: 'takeaway');
    Order::query()->whereKey($old->id)->update(['business_date' => now()->subDays(2)->toDateString()]);

    $this->actingAs($cashier)->getJson('/pos/history')->assertOk()->assertJsonCount(0, 'orders');
    $this->actingAs(userWithRole('kitchen'))->getJson('/pos/history')->assertForbidden();
});
