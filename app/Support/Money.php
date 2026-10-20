<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Rupiah value object. Integer only, never float.
 */
final readonly class Money implements \JsonSerializable, \Stringable
{
    private function __construct(public int $amount) {}

    public static function of(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function sub(self $other): self
    {
        return new self($this->amount - $other->amount);
    }

    public function times(int $qty): self
    {
        return new self($this->amount * $qty);
    }

    /**
     * Percentage in basis points (1000 = 10%), rounded half away from zero.
     */
    public function percent(int $basisPoints): self
    {
        return new self(self::divRound($this->amount * $basisPoints, 10000));
    }

    /**
     * Portion of an amount inclusive of a tax: amount * bp / (10000 + bp).
     */
    public function inclusivePortion(int $basisPoints): self
    {
        return new self(self::divRound($this->amount * $basisPoints, 10000 + $basisPoints));
    }

    /**
     * Round to nearest multiple (half away from zero). Step 0 or 1 = no rounding.
     */
    public function roundTo(int $step): self
    {
        if ($step <= 1) {
            return $this;
        }

        return new self(self::divRound($this->amount, $step) * $step);
    }

    /**
     * Split into parts proportional to weights, remainder spread by largest remainder.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int> same keys as weights
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);
        if ($weights === [] || $total <= 0) {
            throw new InvalidArgumentException('Weights must sum to a positive number.');
        }

        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $share = intdiv($this->amount * $weight, $total);
            $result[$key] = $share;
            $remainders[$key] = ($this->amount * $weight) % $total;
            $allocated += $share;
        }

        $left = $this->amount - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left === 0) {
                break;
            }
            $step = $left > 0 ? 1 : -1;
            $result[$key] += $step;
            $left -= $step;
        }

        return $result;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function format(): string
    {
        return self::formatRupiah($this->amount);
    }

    public static function formatRupiah(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';

        return $sign.'Rp'.number_format(abs($amount), 0, ',', '.');
    }

    public function jsonSerialize(): int
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private static function divRound(int $numerator, int $denominator): int
    {
        $abs = intdiv(abs($numerator) * 2 + $denominator, $denominator * 2);

        return $numerator < 0 ? -$abs : $abs;
    }
}
