<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Notification\Jobs\SendDailyReportJob;
use App\Domain\Notification\Notifier;
use App\Domain\Notification\ReportDelivery;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\SettingsRepository;
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
                'closed_at' => now(), 'closed_by' => $user->id, 'close_key' => $closeKey,
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

            Notifier::later(function (Notifier $n) use ($shift) {
                $n->resolve($shift->outlet_id, "shift.open:{$shift->id}");
                if ((bool) app(SettingsRepository::class)->get('report.send_shift', false)) {
                    $delivery = app(ReportDelivery::class);
                    $delivery->deliver('Laporan shift', $delivery->shiftText($shift->refresh()));
                }
            });
            // Nobody is left behind the counter: the day's report does not have to wait for the date to change.
            if ((bool) app(SettingsRepository::class)->get('report.on_last_shift', true)
                && ! Shift::query()->where('outlet_id', $shift->outlet_id)->whereNull('closed_at')->exists()) {
                SendDailyReportJob::dispatch($shift->business_date->toDateString())->afterCommit();
            }
            if ($difference !== 0) {
                Notifier::later(fn (Notifier $n) => $n->notify(
                    $shift->outlet_id, 'shift.difference', 'Selisih kas shift '.($difference < 0 ? 'KURANG' : 'LEBIH').' Rp '.number_format(abs($difference), 0, ',', '.'),
                    "{$user->name}: ".(string) $differenceReason, '/admin/day-close', 'warning', ['manage-shifts-any'],
                ));
            }
            Audit::log('shift.closed', $shift, ['expected' => $expected, 'counted' => $counted, 'difference' => $difference, 'deposit' => $deposit], $user);

            return $shift;
        });
    }
}
