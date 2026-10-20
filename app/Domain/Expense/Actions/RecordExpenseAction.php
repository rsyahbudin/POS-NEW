<?php

namespace App\Domain\Expense\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Settings\OutletContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordExpenseAction
{
    public function __construct(private PostJournalAction $journal, private OutletContext $outlet) {}

    public function handle(User $user, string $date, int $categoryId, int $amount, int $sourceAccountId, ?string $note = null, ?string $receiptPath = null): Expense
    {
        if ($amount <= 0) {
            throw new AccountingException('Nominal pengeluaran harus lebih dari 0.');
        }

        return DB::transaction(function () use ($user, $date, $categoryId, $amount, $sourceAccountId, $note, $receiptPath) {
            $outletId = $this->outlet->id();
            $category = ExpenseCategory::query()->where('outlet_id', $outletId)->findOrFail($categoryId);
            $source = Account::query()->where('outlet_id', $outletId)->where('type', AccountType::Asset->value)->findOrFail($sourceAccountId);

            $expense = Expense::create([
                'outlet_id' => $outletId, 'date' => $date, 'category_id' => $category->id, 'amount' => $amount,
                'source_account_id' => $source->id, 'note' => $note, 'receipt_path' => $receiptPath, 'created_by' => $user->id,
            ]);

            $this->journal->handle($date, "Pengeluaran: {$category->name}".($note ? " — {$note}" : ''), [
                ['account_id' => $category->account_id, 'debit' => $amount],
                ['account_id' => $source->id, 'credit' => $amount],
            ], $expense, $user->id, false, $outletId);

            return $expense;
        });
    }
}
