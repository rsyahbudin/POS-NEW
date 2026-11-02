<?php

use App\Domain\Payment\Actions\RefundPaymentAction;
use App\Domain\Reporting\SalesReports;
use App\Domain\Shift\ZReportSummary;
use App\Support\BusinessDate;

function paidDay(): array
{
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier);            // 2 x Americano = 44.000
    $payment = pay($order, $cashier);

    return [$order->fresh(['charges']), $payment, BusinessDate::for()];
}

it('does not count tax or service charge added on top as sales', function () {
    enableTax(false);
    [$order, , $d] = paidDay();
    $tax = (int) $order->charges->filter(fn ($c) => $c->kind === 'tax')->sum('amount');
    $service = (int) $order->charges->filter(fn ($c) => $c->kind === 'service')->sum('amount');
    $s = app(SalesReports::class)->summary($d, $d);

    expect($tax)->toBeGreaterThan(0)->and($service)->toBeGreaterThan(0);
    expect($s['gross_sales'])->toBe(44000)->and($s['net_sales'])->toBe(44000)                    // sales stay what the menu sold for
        ->and($s['tax'])->toBe($tax)->and($s['service'])->toBe($service)
        ->and($s['net'])->toBe($order->grand_total)                                               // total received includes them
        ->and($s['net_sales'] + $s['tax'] + $s['service'] + $s['rounding'])->toBe($s['net']);
    expect(app(SalesReports::class)->dashboard($d)['revenue'])->toBe(44000)->and(app(SalesReports::class)->dashboard($d)['total'])->toBe($order->grand_total);
});

it('takes tax and service charge that are inside the prices out of net sales', function () {
    enableTax(true);                                    // PB1 inclusive (service charge stays on top)
    [$order, , $d] = paidDay();
    $inclusive = (int) $order->charges->filter(fn ($c) => $c->is_inclusive)->sum('amount');
    $s = app(SalesReports::class)->summary($d, $d);

    expect($inclusive)->toBeGreaterThan(0);
    expect($s['gross_sales'])->toBe(44000)->and($s['net_sales'])->toBe(44000 - $inclusive)
        ->and($s['net_sales'] + $s['tax'] + $s['service'] + $s['rounding'])->toBe($s['net']);
    $row = app(SalesReports::class)->by('day', $d, $d)->first();
    expect($row->net_sales)->toBe(44000 - $inclusive);
});

it('lets a refund take its share out of net sales, tax and service together', function () {
    enableTax(false);
    [$order, $payment, $d] = paidDay();
    $half = intdiv($order->grand_total, 2);
    app(RefundPaymentAction::class)->handle($payment, userWithRole('manager'), $half, 'komplain', 'r1');
    $s = app(SalesReports::class)->summary($d, $d);

    expect($s['refunds'])->toBe($half)->and($s['net'])->toBe($order->grand_total - $half);
    expect(abs($s['net_sales'] - 22000))->toBeLessThanOrEqual(1);
    expect(abs(($s['net_sales'] + $s['tax'] + $s['service'] + $s['rounding']) - $s['net']))->toBeLessThanOrEqual(2);
});

it('measures growth against the period of the same length right before', function () {
    openShiftFor($cashier = userWithRole('cashier'), 0);
    pay(placeOrder($cashier), $cashier);
    $d = BusinessDate::for();
    $o = app(SalesReports::class)->overview($d, $d);

    expect($o['now']['net_sales'])->toBe(44000)->and($o['now']['items'])->toBe(2)->and($o['previous']['net_sales'])->toBe(0);
    expect($o['growth']['net_sales'])->toBeNull();                          // nothing before: no percentage to invent
    expect($o['previous_from'])->toBe(now()->parse($d)->subDay()->toDateString());

    $week = app(SalesReports::class)->overview(now()->parse($d)->subDays(6)->toDateString(), $d);
    expect($week['previous_to'])->toBe(now()->parse($d)->subDays(7)->toDateString());
});

it('keeps tax out of Net Sales in the Z-report and falls back to the total received for days closed before Net Sales existed', function () {
    $old = ZReportSummary::fromArray(['orders' => 2, 'gross' => 110000, 'net' => 110000]);
    expect($old->netSales)->toBe(110000)->and($old->tax)->toBe(0);

    $new = ZReportSummary::fromArray(['orders' => 2, 'gross' => 110000, 'net' => 110000, 'net_sales' => 100000, 'tax' => 10000, 'service' => 0]);
    expect($new->netSales)->toBe(100000)->and($new->net)->toBe(110000)->and($new->tax)->toBe(10000);
});
