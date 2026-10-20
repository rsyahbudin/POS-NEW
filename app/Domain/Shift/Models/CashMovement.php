<?php

namespace App\Domain\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shift_id
 * @property string $direction
 * @property int $amount
 * @property string $reason
 * @property int $account_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CashMovement extends Model
{
    protected $guarded = [];
}
