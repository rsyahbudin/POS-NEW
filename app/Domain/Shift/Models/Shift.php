<?php

namespace App\Domain\Shift\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property string|null $device_label
 * @property Carbon $business_date
 * @property int $opening_cash
 * @property int|null $expected_cash
 * @property int|null $counted_cash
 * @property array<mixed>|null $denominations
 * @property int|null $cash_left
 * @property int|null $cash_deposited
 * @property int|null $deposit_account_id
 * @property int|null $difference
 * @property string|null $difference_reason
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $close_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Shift extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'denominations' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'business_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
