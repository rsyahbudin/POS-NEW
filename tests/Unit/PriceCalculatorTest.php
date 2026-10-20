<?php

use App\Domain\Pricing\PriceCalculator;

function rule(string $name, string $kind, int $bp, int $seq, string $base = 'after_discount', bool $inclusive = false): array
{
    return ['key' => $seq, 'name' => $name, 'kind' => $kind, 'rate_bp' => $bp, 'sequence' => $seq, 'base' => $base, 'is_inclusive' => $inclusive];
}

it('returns the subtotal when there are no charges (go-live default)', function () {
    $r = (new PriceCalculator)->calculate(100000);
    expect($r->grandTotal)->toBe(100000)->and($r->charges)->toBe([])->and($r->rounding)->toBe(0);
});

it('applies service charge then PB1 on the cumulative base', function () {
    $r = (new PriceCalculator)->calculate(100000, 0, [], [
        rule('Service', 'service', 500, 1),
        rule('PB1', 'tax', 1000, 2, 'cumulative'),
    ]);
    // service 5.000, PB1 on 105.000 = 10.500
    expect($r->charges[0]['amount'])->toBe(5000)
        ->and($r->charges[1]['base'])->toBe(105000)
        ->and($r->charges[1]['amount'])->toBe(10500)
        ->and($r->grandTotal)->toBe(115500);
});

it('computes tax on the net subtotal after discount', function () {
    $r = (new PriceCalculator)->calculate(100000, 0, [['type' => 'percent', 'value' => 1000]], [rule('PB1', 'tax', 1000, 1)]);
    expect($r->orderDiscountTotal)->toBe(10000)->and($r->charges[0]['base'])->toBe(90000)->and($r->grandTotal)->toBe(99000);
});

it('does not add inclusive tax to the total but reports it', function () {
    $r = (new PriceCalculator)->calculate(110000, 0, [], [rule('PB1', 'tax', 1000, 1, 'after_discount', true)]);
    expect($r->grandTotal)->toBe(110000)->and($r->charges[0]['amount'])->toBe(10000)->and($r->chargeTotal)->toBe(0)->and($r->inclusiveTotal)->toBe(10000);
});

it('applies item discounts before order discounts and caps total discount at the subtotal', function () {
    $r = (new PriceCalculator)->calculate(100000, 20000, [['type' => 'percent', 'value' => 1000]]);
    expect($r->discountTotal())->toBe(28000)->and($r->grandTotal)->toBe(72000);

    $capped = (new PriceCalculator)->calculate(50000, 0, [['type' => 'fixed', 'value' => 999999]]);
    expect($capped->grandTotal)->toBe(0);
});

it('rounds the grand total and records the difference (up and down)', function () {
    $calc = new PriceCalculator;
    $down = $calc->calculate(10049, 0, [], [], 100);
    $up = $calc->calculate(10050, 0, [], [], 100);
    $five = $calc->calculate(10250, 0, [], [], 500);
    expect($down->grandTotal)->toBe(10000)->and($down->rounding)->toBe(-49)
        ->and($up->grandTotal)->toBe(10100)->and($up->rounding)->toBe(50)
        ->and($five->grandTotal)->toBe(10500)->and($five->rounding)->toBe(250);
});

it('always reconciles: raw + rounding = grand across combinations', function () {
    $calc = new PriceCalculator;
    foreach ([12345, 99999, 1, 0, 250000] as $sub) {
        foreach ([0, 100, 500] as $step) {
            foreach ([true, false] as $inclusive) {
                $r = $calc->calculate($sub, 0, [['type' => 'percent', 'value' => 750]], [rule('S', 'service', 500, 1), rule('T', 'tax', 1100, 2, 'cumulative', $inclusive)], $step);
                expect($r->rawTotal + $r->rounding)->toBe($r->grandTotal);
            }
        }
    }
});
