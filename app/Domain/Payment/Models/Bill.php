<?php

namespace App\Domain\Payment\Models;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\BillStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string|null $label
 * @property string|null $split_mode
 * @property bool $is_unallocated
 * @property int $subtotal
 * @property int $discount_total
 * @property int $charge_total
 * @property int $rounding
 * @property int $grand_total
 * @property int $paid_total
 * @property BillStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 */
class Bill extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (Bill $b) => $b->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['status' => BillStatus::class, 'is_unallocated' => 'boolean', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<BillItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    /** @return HasMany<BillCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(BillCharge::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function remaining(): int
    {
        return max(0, $this->grand_total - $this->paid_total);
    }
}
