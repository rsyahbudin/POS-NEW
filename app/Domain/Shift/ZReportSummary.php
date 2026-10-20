<?php

namespace App\Domain\Shift;

/** Typed view over the Z-report JSON stored on a closed business day. */
final readonly class ZReportSummary
{
    /**
     * @param  array<string, int>  $byMethod
     */
    public function __construct(
        public int $orders,
        public int $gross,
        public int $discounts,
        public int $refunds,
        public int $net,
        public array $byMethod,
        public int $voidItems,
        public int $voidValue,
        public int $compItems,
        public int $compValue,
        public int $cashDifference,
    ) {}

    /** @param  array<mixed>|null  $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $voids = is_array($data['voids'] ?? null) ? $data['voids'] : [];
        $comps = is_array($data['comps'] ?? null) ? $data['comps'] : [];
        $methods = [];
        foreach (is_array($data['by_method'] ?? null) ? $data['by_method'] : [] as $name => $total) {
            $methods[(string) $name] = $int($total);
        }

        return new self(
            $int($data['orders'] ?? 0), $int($data['gross'] ?? 0), $int($data['discounts'] ?? 0), $int($data['refunds'] ?? 0), $int($data['net'] ?? 0),
            $methods, $int($voids['items'] ?? 0), $int($voids['value'] ?? 0), $int($comps['items'] ?? 0), $int($comps['value'] ?? 0), $int($data['cash_difference'] ?? 0),
        );
    }
}
