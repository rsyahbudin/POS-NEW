<?php

namespace App\Domain\Pricing;

/**
 * Output of PriceCalculator. All amounts in whole rupiah.
 */
final readonly class PriceResult
{
    /**
     * @param  list<array{key: int|string|null, name: string, kind: string, rate_bp: int, base: int, amount: int, is_inclusive: bool}>  $charges
     */
    public function __construct(
        public int $subtotal,
        public int $itemDiscountTotal,
        public int $orderDiscountTotal,
        public array $charges,
        public int $chargeTotal,
        public int $inclusiveTotal,
        public int $rawTotal,
        public int $rounding,
        public int $grandTotal,
    ) {}

    public function discountTotal(): int
    {
        return $this->itemDiscountTotal + $this->orderDiscountTotal;
    }

    public function netSubtotal(): int
    {
        return $this->subtotal - $this->discountTotal();
    }
}
