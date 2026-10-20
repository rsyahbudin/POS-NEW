<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class OpenShiftAction
{
    public function __construct(private PostJournalAction $journal, private OutletContext $outlet) {}

    /** Float the drawer should start with: what the previous shift left behind. */
    public function suggestedOpeningCash(): int
    {
        return (int) (Shift::query()->where('outlet_id', $this->outlet->id())->whereNotNull('closed_at')->latest('closed_at')->value('cash_left') ?? 0);
    }

    public function handle(User $user, int $openingCash, ?string $deviceLabel = null): Shift
    {
        if ($openingCash < 0) {
            throw new OrderException('Modal kembalian tidak boleh negatif.');
        }

        return DB::transaction(function () use ($user, $openingCash, $deviceLabel) {
            $outletId = $this->outlet->id();
            $date = BusinessDate::for();

            if (BusinessDay::query()->where('outlet_id', $outletId)->where('date', $date)->whereNotNull('closed_at')->exists()) {
                throw new OrderException('Hari bisnis ini sudah ditutup.');
            }

            $suggested = $this->suggestedOpeningCash();
            $hasPrevious = Shift::query()->where('outlet_id', $outletId)->whereNotNull('closed_at')->exists();

            try {
                $shift = Shift::create([
                    'outlet_id' => $outletId, 'user_id' => $user->id, 'device_label' => $deviceLabel,
                    'business_date' => $date, 'opening_cash' => $openingCash, 'opened_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new OrderException('Kamu sudah punya shift yang terbuka.');
            }

            // Counting a different float than the one left behind is a variance.
            $delta = $openingCash - $suggested;
            if ($delta !== 0 && $hasPrevious) {
                $cash = SystemAccount::CashDrawer->resolve($outletId)->id;
                $variance = SystemAccount::CashVariance->resolve($outletId)->id;
                $this->journal->handle($date, "Koreksi modal kembalian shift #{$shift->id}", $delta > 0
                    ? [['account_id' => $cash, 'debit' => $delta], ['account_id' => $variance, 'credit' => $delta]]
                    : [['account_id' => $variance, 'debit' => -$delta], ['account_id' => $cash, 'credit' => -$delta]],
                    $shift, $user->id);
            }

            Audit::log('shift.opened', $shift, ['opening_cash' => $openingCash, 'expected_opening' => $suggested], $user);

            return $shift;
        });
    }
}
