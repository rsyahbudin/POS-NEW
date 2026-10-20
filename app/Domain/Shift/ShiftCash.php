<?php

namespace App\Domain\Shift;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Shift\Models\Shift;

/** Expected drawer cash: opening + cash sales + cash in - cash out - cash refunds. */
class ShiftCash
{
    /** @return array{opening: int, cash_sales: int, cash_in: int, cash_out: int, cash_refunds: int, expected: int} */
    public function summarize(Shift $shift): array
    {
        $cashSales = (int) Payment::query()->where('shift_id', $shift->id)
            ->whereHas('method', fn ($q) => $q->where('type', 'cash'))->sum('amount');
        $cashIn = (int) $shift->movements()->where('direction', 'in')->sum('amount');
        $cashOut = (int) $shift->movements()->where('direction', 'out')->sum('amount');
        $refunds = (int) Refund::query()->where('shift_id', $shift->id)->sum('amount');

        return [
            'opening' => $shift->opening_cash,
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'cash_refunds' => $refunds,
            'expected' => $shift->opening_cash + $cashSales + $cashIn - $cashOut - $refunds,
        ];
    }
}
