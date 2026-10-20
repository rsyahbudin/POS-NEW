<?php

namespace App\Domain\Payment;

use App\Domain\Accounting\SystemAccount;
use App\Domain\Payment\Models\Bill;

/** Builds journal lines for a fully paid bill (see PRD 7.4, "Order lunas"). */
class BillJournal
{
    /** @return list<array{account_id: int, debit?: int, credit?: int}> */
    public function lines(Bill $bill): array
    {
        $outletId = $bill->order->outlet_id;
        $lines = [];

        $byAccount = [];
        foreach ($bill->payments as $p) {
            $byAccount[$p->account_id] = ($byAccount[$p->account_id] ?? 0) + $p->amount;
        }
        foreach ($byAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount];
        }

        if ($bill->discount_total > 0) {
            $lines[] = ['account_id' => SystemAccount::SalesDiscount->resolve($outletId)->id, 'debit' => $bill->discount_total];
        }

        $inclusive = 0;
        $service = 0;
        $tax = 0;
        foreach ($bill->charges()->with('orderCharge')->get() as $bc) {
            $oc = $bc->orderCharge;
            if ($oc->is_inclusive) {
                $inclusive += $bc->amount;
            }
            if ($oc->kind === 'service') {
                $service += $bc->amount;
            } else {
                $tax += $bc->amount;
            }
        }

        $lines[] = ['account_id' => SystemAccount::SalesRevenue->resolve($outletId)->id, 'credit' => $bill->subtotal - $inclusive];
        if ($service > 0) {
            $lines[] = ['account_id' => SystemAccount::ServiceRevenue->resolve($outletId)->id, 'credit' => $service];
        }
        if ($tax > 0) {
            $lines[] = ['account_id' => SystemAccount::TaxPayable->resolve($outletId)->id, 'credit' => $tax];
        }
        if ($bill->rounding !== 0) {
            $acc = SystemAccount::RoundingVariance->resolve($outletId)->id;
            $lines[] = $bill->rounding > 0
                ? ['account_id' => $acc, 'credit' => $bill->rounding]
                : ['account_id' => $acc, 'debit' => -$bill->rounding];
        }

        return $lines;
    }
}
