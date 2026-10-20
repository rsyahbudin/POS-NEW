<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

class CloseShiftAction
{
    public function __construct(private PostJournalAction $journal, private ShiftCash $cash) {}

    /**
     * @param  int  $countedCash  physical cash the cashier counted in the drawer
     * @param  int|null  $depositAccountId  bank account receiving the deposit; null = handed to owner (recorded against Modal Pemilik)
     */
    public function handle(
        Shift $shift,
        User $user,
        int $countedCash,
        int $cashLeft,
        ?int $depositAccountId = null,
        ?string $differenceReason = null,
        ?string $closeKey = null,
    ): Shift {
        return DB::transaction(function () use ($shift, $user, $countedCash, $cashLeft, $depositAccountId, $differenceReason, $closeKey) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if (! $shift->isOpen()) {
                if ($closeKey !== null && $shift->close_key === $closeKey) {
                    return $shift; // idempotent retry
                }
                throw new OrderException('Shift sudah ditutup.');
            }
            if ($shift->user_id !== $user->id && ! $user->can('manage-shifts-any')) {
                throw new OrderException('Hanya pemilik shift atau manager yang bisa menutup shift ini.');
            }

            if ($countedCash < 0) {
                throw new OrderException('Kas fisik tidak boleh negatif.');
            }
            $counted = $countedCash;

            $expected = $this->cash->summarize($shift)['expected'];
            $difference = $counted - $expected;
            if ($difference !== 0 && trim((string) $differenceReason) === '') {
                throw new OrderException('Selisih kas harus diberi alasan.');
            }
            if ($cashLeft < 0 || $cashLeft > $counted) {
                throw new OrderException('Uang yang ditinggal di laci tidak boleh melebihi kas fisik.');
            }
            $deposit = $counted - $cashLeft;

            $shift->update([
                'expected_cash' => $expected, 'counted_cash' => $counted,
                'cash_left' => $cashLeft, 'cash_deposited' => $deposit, 'deposit_account_id' => $depositAccountId,
                'difference' => $difference, 'difference_reason' => $differenceReason,
                'closed_at' => now(), 'close_key' => $closeKey,
            ]);

            $date = BusinessDate::for();
            $cash = SystemAccount::CashDrawer->resolve($shift->outlet_id)->id;
            if ($difference !== 0) {
                $variance = SystemAccount::CashVariance->resolve($shift->outlet_id)->id;
                $this->journal->handle($date, "Selisih kas shift #{$shift->id}: {$differenceReason}", $difference < 0
                    ? [['account_id' => $variance, 'debit' => -$difference], ['account_id' => $cash, 'credit' => -$difference]]
                    : [['account_id' => $cash, 'debit' => $difference], ['account_id' => $variance, 'credit' => $difference]],
                    $shift, $user->id, false, $shift->outlet_id);
            }
            if ($deposit > 0) {
                $target = $depositAccountId ?? SystemAccount::OwnerEquity->resolve($shift->outlet_id)->id;
                $this->journal->handle($date, "Setoran kas shift #{$shift->id}", [
                    ['account_id' => $target, 'debit' => $deposit], ['account_id' => $cash, 'credit' => $deposit],
                ], $shift, $user->id, false, $shift->outlet_id);
            }

            Audit::log('shift.closed', $shift, ['expected' => $expected, 'counted' => $counted, 'difference' => $difference, 'deposit' => $deposit], $user);

            return $shift;
        });
    }
}
