<?php

namespace App\Domain\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring monthly cost (rent, salary, electricity, internet). One bill is made per month; paying a bill records
 * an ordinary expense, so reports and the ledger need no special case.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property int $expense_category_id
 * @property int $amount
 * @property int $due_day
 * @property int|null $source_account_id
 * @property string|null $note
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_active
 * @property-read ExpenseCategory $category
 */
class FixedExpense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return HasMany<FixedExpenseBill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(FixedExpenseBill::class);
    }
}
