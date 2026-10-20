<?php

use App\Domain\Ordering\Actions\MarkCompAction;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Enums\CompType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Payment\Actions\CloseZeroOrderAction;
use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pricing\Models\TaxRule;
use Illuminate\Support\Str;

beforeEach(fn () => openShiftFor(userWithRole('cashier'), 0));

it('refuses payment without an open shift', function () {
    $order = placeOrder(userWithRole('manager'));
    expect(fn () => pay($order, userWithRole('manager')))->toThrow(OrderException::class, 'shift');
});

it('records cash with change and posts a balanced journal in the same transaction', function () {
    $order = placeOrder();
    $payment = pay($order, amount: 50000);

    expect($payment->amount)->toBe(44000)->and($payment->tendered)->toBe(50000)->and($payment->change)->toBe(6000)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)->and($order->fresh()->paid_total)->toBe(44000);

    assertJournalsBalanced();
    expect(accountBalance('cash_drawer'))->toBe(44000)->and(accountBalance('sales_revenue'))->toBe(-44000);
});

it('credits the receiving bank account for QRIS / transfer', function () {
    $order = placeOrder();
    $payment = pay($order, type: 'qris');

    expect($payment->account_id)->toBe(method('qris')->account_id);
    $bank = DB::table('journal_lines')->where('account_id', method('qris')->account_id)->sum('debit');
    expect((int) $bank)->toBe(44000);
});

it('is idempotent: same key twice creates one payment and one journal', function () {
    $order = placeOrder();
    $bill = $order->bills()->first();
    $key = (string) Str::uuid();
    $cashier = userWithRole('cashier');

    $a = app(RecordPaymentAction::class)->handle($bill, $cashier, method('cash')->id, 44000, $key);
    $b = app(RecordPaymentAction::class)->handle($bill, $cashier, method('cash')->id, 44000, $key);

    expect($b->id)->toBe($a->id)->and(Payment::count())->toBe(1)->and(DB::table('journal_entries')->count())->toBe(1);
});

it('rejects a second payment on an already paid bill (two cashiers, same order)', function () {
    $order = placeOrder();
    pay($order);
    expect(fn () => pay($order, userWithRole('cashier'), key: 'another-key'))->toThrow(OrderException::class);
    expect(Payment::count())->toBe(1);
});

it('supports split payment: part cash, part QRIS, then marks paid', function () {
    $order = placeOrder();
    pay($order, amount: 14000);
    expect($order->fresh()->status)->toBe(OrderStatus::PartiallyPaid)->and($order->fresh()->table->status->value)->toBe('awaiting_payment');
    expect(DB::table('journal_entries')->count())->toBe(0); // journal when the bill is fully paid

    pay($order, type: 'qris', amount: 30000);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    assertJournalsBalanced();
    expect(accountBalance('cash_drawer'))->toBe(14000);
});

it('rejects non-cash overpayment and unknown methods', function () {
    $order = placeOrder();
    expect(fn () => pay($order, type: 'qris', amount: 50000))->toThrow(OrderException::class, 'melebihi');
});

it('blocks payment while a QR round is still pending', function () {
    $order = placeOrder();
    $table = $order->table;
    app(SubmitQrOrderAction::class)->handle($table->qr_token, ['customer_name' => 'Dina', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1]]]);

    expect(fn () => pay($order))->toThrow(OrderException::class, 'QR');
});

it('journals tax-exclusive service charge, PB1, discount and rounding so that debit = credit', function () {
    enableTax();
    setting('pricing.rounding', 100);
    $order = placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 3]]); // 114.000
    expect($order->subtotal)->toBe(114000)
        ->and($order->charges->sum('amount'))->toBe(5700 + 11970)   // service 5%, PB1 10% of 119.700
        ->and($order->grand_total)->toBe(131700)                    // raw 131.670 -> 131.700
        ->and($order->rounding)->toBe(30);

    pay($order);
    assertJournalsBalanced();
    expect(accountBalance('tax_payable'))->toBe(-11970)
        ->and(accountBalance('service_revenue'))->toBe(-5700)
        ->and(accountBalance('rounding_variance'))->toBe(-30);
});

it('handles tax-inclusive prices: tax is reported, revenue is net of tax', function () {
    enableTax(inclusive: true);
    TaxRule::query()->where('name', 'Service Charge')->update(['is_active' => false]);
    $order = placeOrder(items: [['product_id' => product('Nasi Goreng')->id, 'qty' => 1]]); // 38.000 incl PB1 10%

    expect($order->grand_total)->toBe(38000)->and($order->charge_total)->toBe(0);
    pay($order);
    assertJournalsBalanced();
    expect(accountBalance('tax_payable'))->toBe(-3455)      // 38.000 * 1000/11000
        ->and(accountBalance('sales_revenue'))->toBe(-(38000 - 3455));
});

it('keeps the tax snapshot on a paid order when rates change later', function () {
    enableTax();
    $order = placeOrder();
    pay($order);
    TaxRule::query()->update(['rate_bp' => 9999]);

    expect($order->fresh()->charges->sum('amount'))->toBe($order->charges->sum('amount'))->and($order->fresh()->grand_total)->toBe($order->grand_total);
});

it('closes an all-complimentary order without payment or journal', function () {
    $order = placeOrder();
    app(MarkCompAction::class)->handle($order->items->first(), userWithRole('manager'), CompType::StaffMeal, 'makan staf');
    $order = $order->fresh();

    expect($order->grand_total)->toBe(0);
    app(CloseZeroOrderAction::class)->handle($order, userWithRole('cashier'));
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and(DB::table('journal_entries')->count())->toBe(0);
});
