<?php

use App\Domain\Ordering\Models\Order;
use App\Domain\Shift\Actions\CloseShiftAction;
use Spatie\Permission\PermissionRegistrar;

it('shows every staff role all the transactions of the shift, whoever made them', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    $mine = placeOrder($cashier, type: 'takeaway');
    pay($mine, $cashier);
    $waiters = placeOrder(userWithRole('waiter'), type: 'takeaway');
    pay($waiters, userWithRole('manager'));                                  // taken by the manager
    $open = placeOrder(userWithRole('waiter'), type: 'takeaway');            // still open, opened by the waiter

    foreach (['cashier', 'waiter', 'manager', 'owner'] as $role) {
        $this->actingAs(userWithRole($role))->getJson('/pos/history')->assertOk()
            ->assertJsonPath('shift.opened_by', $cashier->name)->assertJsonCount(3, 'orders')
            ->assertJsonPath('summary.paid', 2)->assertJsonPath('summary.total', 88000)->assertJsonPath('summary.open', 1);
    }

    $json = $this->actingAs(userWithRole('waiter'))->getJson('/pos/history')->json('orders');
    $paidByManager = collect($json)->firstWhere('id', $waiters->id);
    expect($paidByManager['paid_by'])->toBe([userWithRole('manager')->name])->and($paidByManager['by'])->toBe(userWithRole('waiter')->name);
    expect($shift->id)->toBeInt();
});

it('keeps old shifts out of the list and is closed to people who cannot take orders', function () {
    $cashier = userWithRole('cashier');
    $first = openShiftFor($cashier, 0);
    $old = placeOrder($cashier, type: 'takeaway');
    pay($old, $cashier);
    app(CloseShiftAction::class)->handle($first, $cashier, 44000, 0, null, null, 'k');
    openShiftFor($cashier, 0);
    $new = placeOrder($cashier, type: 'takeaway');
    pay($new, $cashier);

    $this->actingAs($cashier)->getJson('/pos/history')->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $new->id);
    $this->actingAs(userWithRole('kitchen'))->getJson('/pos/history')->assertForbidden();
    expect(Order::query()->count())->toBe(2);
});

it('lists the shift transactions on the shift page for every staff role that may open it', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $order = placeOrder($cashier, type: 'takeaway');
    pay($order, userWithRole('manager'));

    foreach (['waiter', 'cashier', 'manager'] as $role) {
        $this->actingAs(userWithRole($role))->get('/shift')->assertInertia(
            fn ($p) => $p->has('transactions', 1)->where('transactions.0.number', $order->number)->where('transactions.0.paid_by', userWithRole('manager')->name),
        );
    }
});

it('gives cashier, waiter and kitchen a Today page with the day sales, and respects the permission', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    pay(placeOrder($cashier, type: 'takeaway'), $cashier);

    foreach (['cashier' => '/pos', 'waiter' => '/pos', 'kitchen' => '/kds'] as $role => $back) {
        $this->actingAs(userWithRole($role))->get('/today')->assertOk()->assertInertia(
            fn ($p) => $p->component('pos/today')->where('metrics.orders', 1)->where('metrics.revenue', 44000)->where('back', $back),
        );
    }

    $waiter = userWithRole('waiter');
    $waiter->roles->firstOrFail()->revokePermissionTo('view-today-summary');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($waiter->fresh())->get('/today')->assertForbidden();
});
