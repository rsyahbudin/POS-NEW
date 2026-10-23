<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Accounting\Actions\ReverseJournalAction;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a shift close (pressed by mistake, or a count to redo). The cash variance and deposit journals made at
 * close are reversed, the closing figures are cleared and the shift is open again for everyone.
 */
class ReopenShiftAction
{
    /** Someone without the manager permission may only undo their own close, and only soon after. */
    public const OWN_CLOSE_MINUTES = 30;

    public function __construct(private ReverseJournalAction $reverse) {}

    public function handle(Shift $shift, User $user, ?string $reason = null): Shift
    {
        return DB::transaction(function () use ($shift, $user, $reason) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->isOpen()) {
                throw new OrderException('Shift ini masih terbuka.');
            }
            if (! $user->can('manage-shifts-any') && ($shift->closed_by !== $user->id || $shift->closed_at === null || $shift->closed_at->lt(now()->subMinutes(self::OWN_CLOSE_MINUTES)))) {
                throw new OrderException('Hanya yang menutup shift, dalam '.self::OWN_CLOSE_MINUTES.' menit, atau manager yang bisa membuka kembali shift ini.');
            }
            if (Shift::query()->where('outlet_id', $shift->outlet_id)->where('id', '>', $shift->id)->exists()) {
                throw new OrderException('Sudah ada shift lain setelah shift ini, jadi tidak bisa dibuka kembali.');
            }
            if (BusinessDay::query()->where('outlet_id', $shift->outlet_id)->where('date', $shift->business_date->toDateString())->whereNotNull('closed_at')->exists()) {
                throw new OrderException('Hari bisnis shift ini sudah ditutup.');
            }

            $closing = [
                'difference' => $shift->difference, 'counted' => $shift->counted_cash, 'expected' => $shift->expected_cash,
                'deposit' => $shift->cash_deposited, 'closed_by' => $shift->closed_by,
            ];
            // only the journals made when it closed; the opening float correction stays
            JournalEntry::query()->where('source_type', $shift->getMorphClass())->where('source_id', $shift->id)
                ->whereNull('reverses')->whereNull('reversed_by')
                ->where(fn ($q) => $q->where('memo', 'like', 'Selisih kas shift #%')->orWhere('memo', 'like', 'Setoran kas shift #%'))->get()
                ->each(fn (JournalEntry $e) => $this->reverse->handle($e, "Pembalikan: {$e->memo}", $user->id, BusinessDate::for()));

            $shift->update([
                'closed_at' => null, 'closed_by' => null, 'close_key' => null, 'expected_cash' => null, 'counted_cash' => null,
                'cash_left' => null, 'cash_deposited' => null, 'deposit_account_id' => null, 'difference' => null, 'difference_reason' => null,
            ]);

            Audit::log('shift.reopened', $shift, ['reason' => $reason, 'was' => $closing], $user);
            Notifier::later(fn (Notifier $n) => $n->notify(
                $shift->outlet_id, 'shift.reopened', "Shift dibuka kembali oleh {$user->name}",
                $reason ? "Alasan: {$reason}" : 'Penutupan shift dibatalkan.', '/shift', 'info', ['manage-shifts-any'],
            ));

            return $shift;
        });
    }
}
