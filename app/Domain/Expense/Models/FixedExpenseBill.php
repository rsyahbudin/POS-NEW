<?php

namespace App\Domain\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int $fixed_expense_id
 * @property Carbon $period
 * @property Carbon $due_date
 * @property int $amount
 * @property string $status unpaid, paid or skipped
 * @property int|null $expense_id
 * @property Carbon|null $paid_on
 * @property int|null $settled_by
 * @property-read FixedExpense $fixedExpense
 */
class FixedExpenseBill extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'date', 'due_date' => 'date', 'paid_on' => 'date'];
    }

    /** @return BelongsTo<FixedExpense, $this> */
    public function fixedExpense(): BelongsTo
    {
        return $this->belongsTo(FixedExpense::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
