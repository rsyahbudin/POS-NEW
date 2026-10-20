<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Shift\Models\CashMovement;
use App\Domain\Shift\Models\Shift;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/** Non-sales cash in/out during a shift (e.g. buying ice). Journals against the chosen counter account. */
class AddCashMovementAction
{
    public function __construct(private PostJournalAction $journal) {}

    public function handle(Shift $shift, User $user, string $direction, int $amount, string $reason, int $accountId): CashMovement
    {
        if (! in_array($direction, ['in', 'out'], true) || $amount <= 0 || trim($reason) === '') {
            throw new OrderException('Arah, nominal, dan alasan wajib diisi.');
        }

        return DB::transaction(function () use ($shift, $user, $direction, $amount, $reason, $accountId) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if (! $shift->isOpen()) {
                throw new OrderException('Shift sudah ditutup.');
            }

            $move = $shift->movements()->create([
                'direction' => $direction, 'amount' => $amount, 'reason' => $reason, 'account_id' => $accountId, 'created_by' => $user->id,
            ]);

            $cash = SystemAccount::CashDrawer->resolve($shift->outlet_id)->id;
            $lines = $direction === 'out'
                ? [['account_id' => $accountId, 'debit' => $amount], ['account_id' => $cash, 'credit' => $amount]]
                : [['account_id' => $cash, 'debit' => $amount], ['account_id' => $accountId, 'credit' => $amount]];
            $this->journal->handle(BusinessDate::for(), "Kas {$direction}: {$reason}", $lines, $move, $user->id, false, $shift->outlet_id);

            return $move;
        });
    }
}
