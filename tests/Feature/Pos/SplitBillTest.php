<?php

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Payment\Actions\AllocateItemsAction;
use App\Domain\Payment\Actions\CancelSplitAction;
use App\Domain\Payment\Actions\SplitBillAction;
use App\Domain\Payment\Models\Bill;

beforeEach(fn () => openShiftFor(userWithRole('cashier'), 0));

function split($order, string $mode, array $spec)
{
    return app(SplitBillAction::class)->handle($order, $mode, $spec, userWithRole('cashier'));
}

it('splits equally and puts the leftover rupiah on the last bill', function () {
    $order = placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1], ['product_id' => product('Americano')->id, 'qty' => 1]]); // 60.000... use odd
    $order = placeOrder(userWithRole('cashier'), [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]], tableId: Table::query()->skip(2)->value('id')); // 38.000
    $bills = split($order, 'equal', ['count' => 3]);

    expect(collect($bills)->pluck('grand_total')->all())->toBe([12666, 12666, 12668])
        ->and($order->fresh()->grand_total)->toBe(38000);
});

it('rejects a free-amount split that does not add up to the grand total', function () {
    $order = placeOrder();
    expect(fn () => split($order, 'amount', ['parts' => [['amount' => 10000], ['amount' => 10000]]]))->toThrow(OrderException::class, 'grand total');
});

it('splits by free amount and pays each bill with a different method', function () {
    $order = placeOrder();
    $bills = split($order, 'amount', ['parts' => [['label' => 'Andi', 'amount' => 30000], ['label' => 'Budi', 'amount' => 14000]]]);

    pay($bills[0], type: 'qris');
    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyPaid)->and($order->fresh()->table->status->value)->toBe('awaiting_payment');
    pay($bills[1], type: 'cash');

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    expect(DB::table('journal_entries')->count())->toBe(2); // one per paid bill
    assertJournalsBalanced();
    expect(accountBalance('sales_revenue'))->toBe(-44000);
});

it('splits per item with qty split across bills and prices each bill', function () {
    enableTax();
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]); // 44.000 + 38.000
    [$id1, $id2] = $order->items->pluck('id')->all();

    $bills = split($order, 'item', ['parts' => [
        ['label' => 'Tamu 1', 'items' => [['order_item_id' => $id1, 'qty' => 1], ['order_item_id' => $id2, 'qty' => 1]]],
        ['label' => 'Tamu 2', 'items' => [['order_item_id' => $id1, 'qty' => 1]]],
    ]]);

    expect($bills[0]->subtotal)->toBe(22000 + 38000)->and($bills[1]->subtotal)->toBe(22000);
    expect($order->fresh()->grand_total)->toBe($bills[0]->grand_total + $bills[1]->grand_total);

    pay($bills[0]);
    pay($bills[1]);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    assertJournalsBalanced();
    expect(accountBalance('sales_revenue'))->toBe(-(60000 + 22000));
});

it('requires every qty to be allocated in per-item mode', function () {
    $order = placeOrder();
    $id = $order->items->first()->id;
    expect(fn () => split($order, 'item', ['parts' => [['items' => [['order_item_id' => $id, 'qty' => 1]]], ['items' => [['order_item_id' => $id, 'qty' => 0]]]]]))
        ->toThrow(OrderException::class);
});

it('allows cancelling a split until a bill is paid, then locks it', function () {
    $order = placeOrder();
    $bills = split($order, 'equal', ['count' => 2]);
    app(CancelSplitAction::class)->handle($order);
    expect(Bill::query()->where('order_id', $order->id)->count())->toBe(1);

    $bills = split($order->fresh(), 'equal', ['count' => 2]);
    pay($bills[0]);
    expect(fn () => split($order->fresh(), 'equal', ['count' => 3]))->toThrow(OrderException::class);
});

it('puts items added after a per-item split into an unallocated bill that must be allocated before paying', function () {
    $order = placeOrder();
    $id = $order->items->first()->id;
    $bills = split($order, 'item', ['parts' => [
        ['items' => [['order_item_id' => $id, 'qty' => 1]]],
        ['items' => [['order_item_id' => $id, 'qty' => 1]]],
    ]]);
    pay($bills[0]);

    $order = placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]], extra: ['order_id' => $order->id]);
    $unallocated = $order->bills()->where('is_unallocated', true)->first();
    expect($unallocated)->not->toBeNull()->and($unallocated->subtotal)->toBe(38000);
    expect(fn () => pay($bills[1]))->toThrow(OrderException::class, 'Alokasikan');

    $newItem = $order->items()->where('name_snapshot', 'Nasi Goreng')->first();
    app(AllocateItemsAction::class)->handle($bills[1]->fresh(), [$newItem->id => 1]);

    expect($order->bills()->where('is_unallocated', true)->exists())->toBeFalse()
        ->and($bills[1]->fresh()->subtotal)->toBe(22000 + 38000);
    pay($bills[1]->fresh());
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    assertJournalsBalanced();
});

it('counts a split order once in sales (parent order, not bills)', function () {
    $order = placeOrder();
    $bills = split($order, 'equal', ['count' => 2]);
    pay($bills[0]);
    pay($bills[1]);

    expect(DB::table('orders')->where('status', 'paid')->count())->toBe(1)->and((int) DB::table('orders')->sum('grand_total'))->toBe(44000);
});
