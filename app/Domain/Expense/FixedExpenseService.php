<?php

namespace App\Domain\Expense;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\Actions\RecordExpenseAction;
use App\Domain\Expense\Models\FixedExpense;
use App\Domain\Expense\Models\FixedExpenseBill;
use App\Domain\Notification\Notifier;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Makes the monthly bill of every fixed cost, and turns a paid bill into an ordinary expense. */
class FixedExpenseService
{
    public function __construct(private RecordExpenseAction $record, private Notifier $notifier) {}

    /** Creates the missing bills of one month for active fixed costs that run in that month. Returns how many were made. */
    public function generate(int $outletId, CarbonImmutable $month): int
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $made = 0;
        $costs = FixedExpense::query()->where('outlet_id', $outletId)->where('is_active', true)->whereDate('starts_on', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))->get();
        foreach ($costs as $cost) {
            $bill = FixedExpenseBill::query()->firstOrCreate(
                ['fixed_expense_id' => $cost->id, 'period' => $start->toDateString()],
                ['outlet_id' => $outletId, 'due_date' => $start->day(min($cost->due_day, $start->daysInMonth))->toDateString(), 'amount' => $cost->amount, 'status' => 'unpaid'],
            );
            $made += $bill->wasRecentlyCreated ? 1 : 0;
        }

        return $made;
    }

    public function pay(FixedExpenseBill $bill, User $user, string $date, int $amount, int $sourceAccountId, ?string $note = null, ?string $receiptPath = null): FixedExpenseBill
    {
        if (Account::query()->whereKey($sourceAccountId)->where('system_key', SystemAccount::CashDrawer->value)->exists()) {
            throw new AccountingException('Uang laci dicatat kasir di halaman Shift. Pilih rekening atau kas lain.');
        }

        return DB::transaction(function () use ($bill, $user, $date, $amount, $sourceAccountId, $note, $receiptPath) {
            $bill = FixedExpenseBill::query()->lockForUpdate()->with('fixedExpense')->findOrFail($bill->id);
            if ($bill->status !== 'unpaid') {
                throw new AccountingException('Tagihan ini sudah diselesaikan.');
            }
            $cost = $bill->fixedExpense;
            $label = $cost->name.' '.$bill->period->translatedFormat('M Y');
            $expense = $this->record->handle($user, $date, $cost->expense_category_id, $amount, $sourceAccountId, trim($label.($note ? " — {$note}" : '')), $receiptPath);
            $bill->update(['status' => 'paid', 'amount' => $amount, 'expense_id' => $expense->id, 'paid_on' => $date, 'settled_by' => $user->id]);
            Audit::log('fixed_expense.paid', $bill, ['name' => $cost->name, 'amount' => $amount], $user);
            $this->notifier->resolve($bill->outlet_id, "fixedbill:{$bill->id}");

            return $bill;
        });
    }

    public function skip(FixedExpenseBill $bill, User $user): void
    {
        if ($bill->status !== 'unpaid') {
            throw new AccountingException('Hanya tagihan yang belum dibayar yang bisa dilewati.');
        }
        $bill->update(['status' => 'skipped', 'settled_by' => $user->id]);
        Audit::log('fixed_expense.skipped', $bill, ['amount' => $bill->amount], $user);
        $this->notifier->resolve($bill->outlet_id, "fixedbill:{$bill->id}");
    }

    /** Notifies the people who manage fixed costs about bills due within $days (and the late ones). Returns how many. */
    public function remind(int $outletId, int $days): int
    {
        $today = CarbonImmutable::today();
        $bills = FixedExpenseBill::query()->where('outlet_id', $outletId)->where('status', 'unpaid')->whereDate('due_date', '<=', $today->addDays($days))->with('fixedExpense:id,name')->get();
        foreach ($bills as $bill) {
            $late = $bill->due_date->lt($today);
            $left = (int) $today->diffInDays($bill->due_date, false);
            $when = $late ? 'sudah lewat jatuh tempo' : ($left === 0 ? 'jatuh tempo hari ini' : "jatuh tempo {$left} hari lagi");
            $this->notifier->notify(
                $outletId, 'fixed_expense.due', "{$bill->fixedExpense->name} {$when}", 'Rp '.number_format($bill->amount, 0, ',', '.').' · '.$bill->due_date->translatedFormat('d M Y'),
                '/admin/fixed-expenses', $late ? 'danger' : 'warning', ['manage-fixed-expenses'], null, "fixedbill:{$bill->id}",
            );
        }

        return $bills->count();
    }
}
