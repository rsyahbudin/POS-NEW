<?php

namespace App\Domain\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property int $account_id
 * @property bool $allow_drawer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExpenseCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allow_drawer' => 'boolean'];
    }
}
