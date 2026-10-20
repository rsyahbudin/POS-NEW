<?php

use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Ordering\Actions\VoidOrderAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Pricing\Actions\ApplyDiscountAction;
use App\Support\ApprovalGate;
use Spatie\Activitylog\Models\Activity;

it('lets a cashier void only with a valid manager PIN when the setting requires it', function () {
    setting('security.cancel_needs_pin', true);
    $order = placeOrder();
    $item = $order->items->first();
    $cashier = userWithRole('cashier');

    expect(fn () => app(VoidItemAction::class)->handle($item, $cashier, 'salah'))->toThrow(OrderException::class, 'PIN');
    expect(fn () => app(VoidItemAction::class)->handle($item, $cashier, 'salah', '999999'))->toThrow(OrderException::class, 'salah');

    app(VoidItemAction::class)->handle($item, $cashier, 'pelanggan batal', '222222'); // manager PIN
    $item = $item->fresh();
    expect($item->voided_by)->toBe($cashier->id)->and($item->void_approved_by)->toBe(userWithRole('manager')->id);
    expect(Activity::query()->where('event', 'item.voided')->exists())->toBeTrue();
});

it('locks the cashier out for 15 minutes after 5 wrong PINs', function () {
    $order = placeOrder();
    $cashier = userWithRole('cashier');
    foreach (range(1, 5) as $_) {
        try {
            app(ApprovalGate::class)->resolve($cashier, 'void', '000000');
        } catch (OrderException) {
        }
    }

    expect(fn () => app(ApprovalGate::class)->resolve($cashier->fresh(), 'void', '222222'))->toThrow(OrderException::class, 'Terlalu banyak');

    $this->travel(16)->minutes();
    expect(app(ApprovalGate::class)->resolve($cashier->fresh(), 'void', '222222')->id)->toBe(userWithRole('manager')->id);
});

it('does not accept a cashier PIN as a manager approval', function () {
    setting('security.cancel_needs_pin', true);
    $order = placeOrder();
    expect(fn () => app(VoidItemAction::class)->handle($order->items->first(), userWithRole('waiter'), 'x', '333333'))->toThrow(OrderException::class, 'salah');
});

it('applies a discount within the cashier limit without a PIN, above it only with PIN', function () {
    $order = placeOrder(); // 44.000
    $cashier = userWithRole('cashier');
    $discount = app(ApplyDiscountAction::class);

    $d = $discount->handle($order, $cashier, 'percent', 1000, 'member'); // 10% = limit
    expect($d->amount)->toBe(4400)->and($order->fresh()->grand_total)->toBe(39600);

    expect(fn () => $discount->handle($order, $cashier, 'percent', 2000, 'bonus'))->toThrow(OrderException::class, 'PIN');

    $d2 = $discount->handle($order, $cashier, 'percent', 2000, 'bonus', pin: '222222');
    expect($d2->approved_by)->toBe(userWithRole('manager')->id)->and($order->fresh()->discount_total)->toBe(4400 + 8800);
});

it('requires a reason and caps fixed discounts', function () {
    $order = placeOrder();
    expect(fn () => app(ApplyDiscountAction::class)->handle($order, userWithRole('manager'), 'fixed', 1000, ''))->toThrow(OrderException::class, 'Alasan');

    app(ApplyDiscountAction::class)->handle($order, userWithRole('owner'), 'fixed', 999999, 'promo besar');
    expect($order->fresh()->grand_total)->toBe(0);
});

it('applies an item-level discount only to that item', function () {
    $order = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]);
    app(ApplyDiscountAction::class)->handle($order, userWithRole('manager'), 'percent', 5000, 'komplain', $order->items->first());

    expect($order->fresh()->discount_total)->toBe(22000)->and($order->fresh()->grand_total)->toBe(82000 - 22000);
});

it('voids a whole unpaid order but never a paid one', function () {
    $order = placeOrder();
    app(VoidOrderAction::class)->handle($order, userWithRole('manager'), 'tamu pergi');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and($order->table->fresh()->status->value)->toBe('empty')
        ->and(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue();

    openShiftFor(userWithRole('cashier'));
    $paid = placeOrder();
    pay($paid);
    expect(fn () => app(VoidOrderAction::class)->handle($paid, userWithRole('manager'), 'x'))->toThrow(OrderException::class);
});
