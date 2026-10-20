<?php

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\Enums\RoundSource;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property RoundSource $source
 * @property RoundStatus $status
 * @property string|null $customer_name
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $reject_reason
 * @property int|null $created_by
 * @property Carbon|null $last_alerted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 */
class OrderRound extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RoundStatus::class,
            'source' => RoundSource::class,
            'confirmed_at' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
