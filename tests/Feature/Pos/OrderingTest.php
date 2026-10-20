<?php

use App\Domain\Floor\Enums\TableStatus;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\MergeOrdersAction;
use App\Domain\Ordering\Actions\MoveTableAction;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderItem;

it('creates a server-priced order with an ORD-YYMMDD-NNNN number', function () {
    $order = placeOrder();

    expect($order->number)->toMatch('/^ORD-\d{6}-0001$/')
        ->and($order->subtotal)->toBe(44000)
        ->and($order->grand_total)->toBe(44000)
        ->and($order->items)->toHaveCount(1)
        ->and(placeOrder(tableId: Table::query()->skip(1)->value('id'))->number)->toEndWith('-0002');
});

it('adds a second round to the same table order instead of opening a new one', function () {
    $first = placeOrder();
    $second = placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);

    expect($second->id)->toBe($first->id)
        ->and($second->rounds()->count())->toBe(2)
        ->and($second->subtotal)->toBe(44000 + 38000);
});

it('marks the table occupied and frees it after payment', function () {
    $order = placeOrder();
    expect($order->table->fresh()->status)->toBe(TableStatus::Occupied);

    openShiftFor(userWithRole('cashier'));
    pay($order);
    expect($order->table->fresh()->status)->toBe(TableStatus::Empty);
});

it('snapshots name, price and modifiers so later price changes do not touch old orders', function () {
    $latte = product('Caffe Latte');
    $variant = $latte->variants->firstWhere('name', 'Iced');
    $sugar = $latte->modifierGroups->firstWhere('name', 'Sugar Level')->modifiers->first();
    $extra = $latte->modifierGroups->firstWhere('name', 'Tambahan')->modifiers->firstWhere('name', 'Extra Shot');

    $order = placeOrder(items: [['product_id' => $latte->id, 'variant_id' => $variant->id, 'modifier_ids' => [$sugar->id, $extra->id], 'qty' => 1]]);
    $item = $order->items->first();
    expect($item->unit_price)->toBe(30000 + 5000)->and($item->name_snapshot)->toBe('Caffe Latte (Iced)')
        ->and($item->modifiers_snapshot)->toHaveCount(2);

    $variant->update(['price' => 99000]);
    $latte->update(['name' => 'Renamed']);
    expect($item->fresh()->unit_price)->toBe(35000)->and($item->fresh()->name_snapshot)->toBe('Caffe Latte (Iced)');
});

it('rejects orders that break modifier group rules on the server', function () {
    $latte = product('Caffe Latte');
    $variant = $latte->variants->first();
    $extra = $latte->modifierGroups->firstWhere('name', 'Tambahan')->modifiers;

    // required "Sugar Level" missing
    expect(fn () => placeOrder(items: [['product_id' => $latte->id, 'variant_id' => $variant->id, 'qty' => 1]]))
        ->toThrow(OrderException::class, 'minimal');

    // max 2 extras
    $sugar = $latte->modifierGroups->firstWhere('name', 'Sugar Level')->modifiers->first();
    $three = [$sugar->id, ...$extra->pluck('id')->all(), 9999];
    expect(fn () => placeOrder(items: [['product_id' => $latte->id, 'variant_id' => $variant->id, 'modifier_ids' => $three, 'qty' => 1]]))
        ->toThrow(OrderException::class);

    // variant required
    expect(fn () => placeOrder(items: [['product_id' => $latte->id, 'modifier_ids' => [$sugar->id], 'qty' => 1]]))
        ->toThrow(OrderException::class, 'varian');
});

it('rejects sold-out products and out-of-stock quantities', function () {
    product('Americano')->update(['is_available' => false]);
    expect(fn () => placeOrder())->toThrow(OrderException::class, 'habis');

    $croissant = product('Croissant');
    expect(fn () => placeOrder(items: [['product_id' => $croissant->id, 'qty' => 21]]))->toThrow(OrderException::class, 'Stok');
});

it('requires a table for dine-in and a platform for online orders', function () {
    expect(fn () => app(PlaceOrderAction::class)->handle(userWithRole('cashier'), ['type' => 'dine_in', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]]))
        ->toThrow(OrderException::class, 'meja');
    expect(fn () => placeOrder(type: 'online'))->toThrow(OrderException::class, 'platform');
    expect(placeOrder(type: 'online', extra: ['platform' => 'GoFood'])->platform)->toBe('GoFood');
});

it('gives takeaway orders a daily call number', function () {
    expect(placeOrder(type: 'takeaway')->call_number)->toBe('A-001')
        ->and(placeOrder(type: 'takeaway')->call_number)->toBe('A-002');
});

it('decrements portion stock, sells out at zero, and restores on void', function () {
    $croissant = product('Croissant');
    $order = placeOrder(items: [['product_id' => $croissant->id, 'qty' => 20]]);
    expect($croissant->fresh()->stock_qty)->toBe(0)->and($croissant->fresh()->is_available)->toBeFalse();

    app(VoidItemAction::class)->handle($order->items->first(), userWithRole('manager'), 'salah input');
    expect($croissant->fresh()->stock_qty)->toBe(20)->and($croissant->fresh()->is_available)->toBeTrue();
});

it('locks an order against new items after it is paid', function () {
    $order = placeOrder();
    openShiftFor(userWithRole('cashier'));
    pay($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    expect(fn () => placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1]], extra: ['order_id' => $order->id]))
        ->toThrow(OrderException::class, 'dikunci');
});

it('moves a table order and merges two orders', function () {
    $tables = Table::query()->orderBy('id')->take(3)->get();
    $a = placeOrder(tableId: $tables[0]->id);
    $b = placeOrder(tableId: $tables[1]->id, items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);

    app(MoveTableAction::class)->handle($a, $tables[2]->id, userWithRole('cashier'));
    expect($tables[0]->fresh()->status)->toBe(TableStatus::Empty)->and($tables[2]->fresh()->status)->toBe(TableStatus::Occupied);

    app(MergeOrdersAction::class)->handle($b, $a->fresh(), userWithRole('cashier'));
    expect($a->fresh()->subtotal)->toBe(44000 + 38000)->and($b->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($tables[1]->fresh()->status)->toBe(TableStatus::Empty);
});

it('never hard-deletes: voiding keeps the item row with reason and approver', function () {
    $order = placeOrder();
    $item = $order->items->first();
    app(VoidItemAction::class)->handle($item, userWithRole('manager'), 'pelanggan batal');

    $item = OrderItem::query()->find($item->id);
    expect($item->status->value)->toBe('voided')->and($item->void_reason)->toBe('pelanggan batal')->and($item->void_approved_by)->not->toBeNull();
    expect($order->fresh()->subtotal)->toBe(0);
});
