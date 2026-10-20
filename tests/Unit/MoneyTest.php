<?php

use App\Support\Money;

it('formats rupiah without decimals', function () {
    expect(Money::of(12500)->format())->toBe('Rp12.500')
        ->and(Money::of(-1500)->format())->toBe('-Rp1.500')
        ->and(Money::of(0)->format())->toBe('Rp0');
});

it('computes basis point percentages with half-up rounding', function () {
    expect(Money::of(10000)->percent(1000)->amount)->toBe(1000)
        ->and(Money::of(1005)->percent(1000)->amount)->toBe(101) // 100.5 -> 101
        ->and(Money::of(1004)->percent(1000)->amount)->toBe(100);
});

it('extracts the inclusive portion of a gross price', function () {
    // 11.000 gross incl 10% -> tax 1.000
    expect(Money::of(11000)->inclusivePortion(1000)->amount)->toBe(1000);
});

it('rounds to the nearest step', function () {
    expect(Money::of(10049)->roundTo(100)->amount)->toBe(10000)
        ->and(Money::of(10050)->roundTo(100)->amount)->toBe(10100)
        ->and(Money::of(10249)->roundTo(500)->amount)->toBe(10000)
        ->and(Money::of(10250)->roundTo(500)->amount)->toBe(10500)
        ->and(Money::of(10049)->roundTo(0)->amount)->toBe(10049);
});

it('allocates without losing a single rupiah', function () {
    foreach ([[100, [1, 1, 1]], [101, [1, 2, 3]], [7, [5, 5]], [1, [1, 1, 1]]] as [$amount, $weights]) {
        $parts = Money::of($amount)->allocate($weights);
        expect(array_sum($parts))->toBe($amount);
    }
});
