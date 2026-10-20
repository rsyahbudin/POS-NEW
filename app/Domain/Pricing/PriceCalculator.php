<?php

namespace App\Domain\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * The single place where totals are computed.
 *
 * Order: Subtotal -> Discount -> charges by sequence (service, tax) -> rounding.
 * A charge's base is either the net subtotal ("after_discount") or the net
 * subtotal plus exclusive charges before it ("cumulative"). Inclusive charges
 * are already inside menu prices: they are reported but never added to the total.
 */
class PriceCalculator
{
    /**
     * @param  array<int, array{key?: int|string|null, name: string, kind: string, rate_bp: int, sequence: int, base: string, is_inclusive: bool}>  $rules  active rules only
     * @param  array<int, array{type: string, value: int}>  $orderDiscounts  percent value in basis points; fixed in rupiah
     */
    public function calculate(
        int $subtotal,
        int $itemDiscountTotal = 0,
        array $orderDiscounts = [],
        array $rules = [],
        int $roundingStep = 0,
    ): PriceResult {
        if ($subtotal < 0 || $itemDiscountTotal < 0) {
            throw new InvalidArgumentException('Subtotal dan diskon tidak boleh negatif.');
        }

        $itemDiscountTotal = min($itemDiscountTotal, $subtotal);
        $afterItem = Money::of($subtotal - $itemDiscountTotal);

        $orderDiscountTotal = 0;
        foreach ($orderDiscounts as $d) {
            $amount = match ($d['type']) {
                'percent', 'promo_percent' => $afterItem->percent((int) $d['value'])->amount,
                'fixed', 'promo_fixed' => (int) $d['value'],
                default => throw new InvalidArgumentException("Tipe diskon tidak dikenal: {$d['type']}"),
            };
            $orderDiscountTotal += max(0, $amount);
        }
        $orderDiscountTotal = min($orderDiscountTotal, $afterItem->amount);

        $net = $afterItem->sub(Money::of($orderDiscountTotal));

        usort($rules, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        $charges = [];
        $exclusive = Money::zero();
        $inclusiveTotal = 0;
        foreach ($rules as $rule) {
            $base = $rule['base'] === 'cumulative' ? $net->add($exclusive) : $net;
            if ($rule['is_inclusive']) {
                $amount = $base->inclusivePortion($rule['rate_bp']);
                $inclusiveTotal += $amount->amount;
            } else {
                $amount = $base->percent($rule['rate_bp']);
                $exclusive = $exclusive->add($amount);
            }
            $charges[] = [
                'key' => $rule['key'] ?? null,
                'name' => $rule['name'],
                'kind' => $rule['kind'],
                'rate_bp' => $rule['rate_bp'],
                'base' => $base->amount,
                'amount' => $amount->amount,
                'is_inclusive' => $rule['is_inclusive'],
            ];
        }

        $raw = $net->add($exclusive);
        $grand = $raw->roundTo($roundingStep);

        return new PriceResult(
            subtotal: $subtotal,
            itemDiscountTotal: $itemDiscountTotal,
            orderDiscountTotal: $orderDiscountTotal,
            charges: $charges,
            chargeTotal: $exclusive->amount,
            inclusiveTotal: $inclusiveTotal,
            rawTotal: $raw->amount,
            rounding: $grand->amount - $raw->amount,
            grandTotal: $grand->amount,
        );
    }
}
