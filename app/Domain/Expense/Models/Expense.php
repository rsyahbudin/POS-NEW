<?php

namespace App\Domain\Expense\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property Carbon $date
 * @property int $category_id
 * @property int $amount
 * @property int $source_account_id
 * @property string|null $note
 * @property string|null $receipt_path
 * @property int|null $cash_movement_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ExpenseCategory $category
 */
class Expense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
