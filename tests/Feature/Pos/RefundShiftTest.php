<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Payment\Actions\RefundPaymentAction;
use App\Domain\Shift\Actions\AddCashMovementAction;
use App\Domain\Shift\Actions\CloseShiftAction;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Domain\Shift\ShiftCash;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Http;

it('lets only manager or owner refund, with a reversing journal and the original order untouched', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    $manager = userWithRole('manager');

    enableTax();
    $order = placeOrder();
    $payment = pay($order);
    $before = $order->fresh()->only(['subtotal', 'grand_total', 'paid_total']);

    expect(fn () => app(RefundPaymentAction::class)->handle($payment, $cashier, 1000, 'x', 'k0'))->toThrow(OrderException::class, 'manager');

    $refund = app(RefundPaymentAction::class)->handle($payment, $manager, 20000, 'salah pesan', 'k1');
    assertJournalsBalanced();
    expect($order->fresh()->only(['subtotal', 'grand_total', 'paid_total']))->toBe($before)
        ->and($order->fresh()->refunded_total)->toBe(20000)->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    expect(accountBalance('cash_drawer'))->toBe($payment->amount - 20000);

    app(RefundPaymentAction::class)->handle($payment->fresh(), $manager, $payment->amount - 20000, 'sisa', 'k2');
    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)->and(accountBalance('cash_drawer'))->toBe(0)
        ->and(accountBalance('sales_revenue'))->toBe(0)->and(accountBalance('tax_payable'))->toBe(0);
    assertJournalsBalanced();
});

it('is idempotent on refunds and rejects over-refunds', function () {
    $manager = userWithRole('manager');
    openShiftFor(userWithRole('cashier'), 0);
    $payment = pay(placeOrder());

    $a = app(RefundPaymentAction::class)->handle($payment, $manager, 1000, 'r', 'same');
    $b = app(RefundPaymentAction::class)->handle($payment, $manager, 1000, 'r', 'same');
    expect($b->id)->toBe($a->id);
    expect(fn () => app(RefundPaymentAction::class)->handle($payment->fresh(), $manager, 999999, 'r', 'big'))->toThrow(OrderException::class, 'melebihi');
});

it('pre-fills the next shift with the cash left behind and journals a variance when corrected', function () {
    $cashier = userWithRole('cashier');
    $open = app(OpenShiftAction::class);
    $shift = $open->handle($cashier, 200000);
    app(CloseShiftAction::class)->handle($shift, $cashier, 200000, 150000, null);

    expect($open->suggestedOpeningCash())->toBe(150000);
    $next = $open->handle($cashier, 140000);
    expect(accountBalance('cash_variance'))->toBe(10000)->and($next->opening_cash)->toBe(140000);
    assertJournalsBalanced();
});

it('computes expected cash and journals the difference, deposit and leaves the float', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 100000);
    pay(placeOrder()); // 44.000 cash
    pay(placeOrder(tableId: Table::query()->skip(1)->value('id')), type: 'qris'); // non-cash
    app(AddCashMovementAction::class)->handle($shift, $cashier, 'out', 5000, 'beli es batu', Account::query()->where('code', '5-5000')->value('id'));
    app(AddCashMovementAction::class)->handle($shift, $cashier, 'in', 10000, 'tambah modal', SystemAccount::OwnerEquity->resolve()->id);

    $summary = app(ShiftCash::class)->summarize($shift->fresh());
    expect($summary['expected'])->toBe(100000 + 44000 + 10000 - 5000);

    // difference requires a reason
    expect(fn () => app(CloseShiftAction::class)->handle($shift, $cashier, 150000, 50000))->toThrow(OrderException::class, 'alasan');

    $closed = app(CloseShiftAction::class)->handle($shift, $cashier, 155000, 100000, null, 'uang receh hilang');
    // counted = 100.000 + 40.000 + 10.000 + 5.000 = 155.000 ; expected 149.000 ; difference +6.000
    expect($closed->counted_cash)->toBe(155000)->and($closed->difference)->toBe(6000)->and($closed->cash_deposited)->toBe(55000)->and($closed->cash_left)->toBe(100000);

    assertJournalsBalanced();
    // book cash = opening(never journaled here) + movements: 44.000 sale - 5.000 + 10.000 + variance 6.000 - deposit 55.000
    expect(accountBalance('cash_drawer'))->toBe(44000 - 5000 + 10000 + 6000 - 55000);
});

it('closes a shift only once, and is idempotent with the same key', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    $close = fn ($key) => app(CloseShiftAction::class)->handle($shift, $cashier, 0, 0, null, null, $key);

    $a = $close('close-1');
    $b = $close('close-1');
    expect($b->id)->toBe($a->id);
    expect(fn () => $close('other'))->toThrow(OrderException::class, 'sudah ditutup');
});

it('holds the Kas Laci invariant per shift: opening + cash sales + in - out - cash refunds', function () {
    $cashier = userWithRole('cashier');
    $manager = userWithRole('manager');
    $shift = openShiftFor($cashier, 75000);

    $p1 = pay(placeOrder());
    pay(placeOrder(tableId: Table::query()->skip(1)->value('id')), amount: 50000);
    app(RefundPaymentAction::class)->handle($p1, $manager, 10000, 'komplain', 'r1');
    app(AddCashMovementAction::class)->handle($shift, $cashier, 'out', 3000, 'parkir', Account::query()->where('code', '5-5000')->value('id'));

    $s = app(ShiftCash::class)->summarize($shift->fresh());
    expect($s['expected'])->toBe($s['opening'] + 44000 + 44000 - 10000 - 3000)->toBe(75000 + 88000 - 10000 - 3000); // the manager's cash refund leaves the same drawer
});

it('lets a new shift open after the previous one closed, even on the same day', function () {
    $manager = userWithRole('manager');
    $shift = openShiftFor($manager, 0);
    expect(fn () => openShiftFor(userWithRole('cashier')))->toThrow(OrderException::class, 'sudah dibuka');

    app(CloseShiftAction::class)->handle($shift, $manager, 0, 0);
    expect(openShiftFor(userWithRole('cashier'))->business_date->toDateString())->toBe(BusinessDate::for());
});

it('sends the daily report to Telegram when the last shift closes', function () {
    Http::fake();
    setting('telegram.bot_token', 'T0KEN');
    setting('telegram.chat_id', '123');
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    app(CloseShiftAction::class)->handle($shift, $cashier, 44000, 0);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'botT0KEN/sendMessage') && str_contains($r['text'], 'Rp44.000'));
});
