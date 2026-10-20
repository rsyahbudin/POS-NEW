<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Payment\Actions\CarveBillAction;
use App\Domain\Payment\Actions\SplitBillAction;
use App\Domain\Payment\Models\Bill;
use App\Domain\Pricing\Actions\ApplyDiscountAction;

beforeEach(fn () => openShiftFor(userWithRole('cashier'), 0));

function carve($order, array $qty, ?string $label = null)
{
    return app(CarveBillAction::class)->handle($order->fresh(), $qty, userWithRole('cashier'), $label);
}

function twoItemOrder()
{
    return placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]); // 44.000 + 38.000
}

it('pays only the selected items and leaves the rest payable as "Sisa"', function () {
    $order = twoItemOrder();
    [$americano, $nasi] = $order->items->sortBy('id')->pluck('id')->all();

    $bill = carve($order, [$americano => 1]);
    expect($bill->subtotal)->toBe(22000)->and($bill->grand_total)->toBe(22000);

    $rest = $order->bills()->where('label', 'Sisa')->first();
    expect($rest->grand_total)->toBe(60000)->and($order->fresh()->grand_total)->toBe(82000);

    pay($bill);
    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyPaid)->and($order->fresh()->table->status->value)->toBe('awaiting_payment');
    pay($rest->fresh());
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    assertJournalsBalanced();
    expect(DB::table('journal_entries')->count())->toBe(2)->and(accountBalance('sales_revenue'))->toBe(-82000);
});

it('prices the selection with service charge, tax and rounding, and the parts add up to the order', function () {
    enableTax();
    setting('pricing.rounding', 100);
    $order = twoItemOrder();
    [$americano, $nasi] = $order->items->sortBy('id')->pluck('id')->all();

    $preview = app(CarveBillAction::class)->preview($order->fresh(), [$nasi => 1]);
    $bill = carve($order, [$nasi => 1]);
    expect($bill->grand_total)->toBe($preview->grandTotal)->and($bill->charge_total)->toBeGreaterThan(0);

    $rest = $order->bills()->where('label', 'Sisa')->first();
    expect($order->fresh()->grand_total)->toBe($bill->grand_total + $rest->grand_total);

    pay($bill);
    pay($rest->fresh());
    assertJournalsBalanced();
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('can carve repeatedly: each guest pays their own items, the last pays the rest', function () {
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 3], ['product_id' => product('Nasi Goreng')->id, 'qty' => 2]]);
    [$a, $n] = $order->items->sortBy('id')->pluck('id')->all();

    $first = carve($order, [$a => 1, $n => 1], 'Andi');
    pay($first);
    $second = carve($order, [$a => 1], 'Budi');
    expect($second->label)->toBe('Budi')->and($second->grand_total)->toBe(22000);
    pay($second);

    $rest = $order->bills()->where('status', 'unpaid')->first();
    expect($rest->grand_total)->toBe(22000 + 38000);
    pay($rest);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and($order->bills()->count())->toBe(3)
        ->and($order->fresh()->grand_total)->toBe(66000 + 76000);
    assertJournalsBalanced();
});

it('never lets paid items be selected again, nor more than exists', function () {
    $order = twoItemOrder();
    [$a, $n] = $order->items->sortBy('id')->pluck('id')->all();

    expect(fn () => carve($order, [$a => 3]))->toThrow(OrderException::class, 'tersisa');
    expect(fn () => carve($order, []))->toThrow(OrderException::class, 'minimal');

    pay(carve($order, [$a => 2]));
    expect(fn () => carve($order, [$a => 1]))->toThrow(OrderException::class, 'tersisa 0');
});

it('returns the existing bill when everything is selected', function () {
    $order = twoItemOrder();
    [$a, $n] = $order->items->sortBy('id')->pluck('id')->all();

    $bill = carve($order, [$a => 2, $n => 1]);
    expect($bill->grand_total)->toBe(82000)->and(Bill::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses equal/amount splits and partially paid single bills', function () {
    $order = twoItemOrder();
    [$a] = $order->items->sortBy('id')->pluck('id')->all();

    pay($order, amount: 10000);
    expect(fn () => carve($order, [$a => 1]))->toThrow(OrderException::class, 'dibayar sebagian');

    $other = placeOrder(tableId: Table::query()->skip(1)->value('id'));
    app(SplitBillAction::class)->handle($other, 'equal', ['count' => 2], userWithRole('cashier'));
    expect(fn () => carve($other, [$other->items->first()->id => 1]))->toThrow(OrderException::class, 'bagi rata');
});

it('shares a fixed order discount between the carved bills', function () {
    $order = twoItemOrder();
    app(ApplyDiscountAction::class)->handle($order, userWithRole('manager'), 'fixed', 8200, 'promo');
    [$a, $n] = $order->items->sortBy('id')->pluck('id')->all();

    $bill = carve($order->fresh(), [$a => 2]);   // 44.000 of 82.000 net
    $rest = $order->bills()->where('label', 'Sisa')->first();

    expect($bill->discount_total + $rest->discount_total)->toBe(8200)
        ->and($order->fresh()->grand_total)->toBe(82000 - 8200)
        ->and($bill->grand_total + $rest->grand_total)->toBe(73800);
});

it('works over HTTP: preview shows the total, carve returns the bill to pay', function () {
    $cashier = userWithRole('cashier');
    $order = twoItemOrder();
    [$a, $n] = $order->items->sortBy('id')->pluck('id')->all();
    $body = ['items' => [['order_item_id' => $n, 'qty' => 1]]];

    $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/carve/preview", $body)->assertOk()->assertJsonPath('grand_total', 38000);
    $res = $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/carve", $body + ['label' => 'Rani'])->assertOk();
    expect(collect($res->json('order.bills'))->firstWhere('id', $res->json('bill_id'))['label'])->toBe('Rani');

    $this->actingAs(userWithRole('waiter'))->postJson("/pos/orders/{$order->id}/carve", $body)->assertForbidden();
    $this->actingAs($cashier)->postJson("/pos/orders/{$order->id}/carve", ['items' => [['order_item_id' => $a, 'qty' => 9]]])->assertUnprocessable();
});
