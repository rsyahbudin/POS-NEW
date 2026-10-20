<?php

namespace App\Domain\Reporting;

/** Cash / bank account movement over a period. */
final readonly class CashFlowRow
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public int $opening,
        public int $inflow,
        public int $outflow,
        public int $closing,
    ) {}
}
