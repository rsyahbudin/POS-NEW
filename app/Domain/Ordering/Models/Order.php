<?php

namespace App\Domain\Ordering\Models;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\OrderType;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pricing\Models\Discount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $outlet_id
 * @property string $number
 * @property OrderType $type
 * @property string|null $platform
 * @property int|null $table_id
 * @property OrderStatus $status
 * @property string|null $customer_name
 * @property int $subtotal
 * @property int $discount_total
 * @property int $charge_total
 * @property int $rounding
 * @property int $grand_total
 * @property int $paid_total
 * @property int $refunded_total
 * @property string|null $call_number
 * @property Carbon $business_date
 * @property int|null $opened_by
 * @property int|null $shift_id
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $bill_requested_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Table|null $table
 * @property-read User|null $opener
 */
class Order extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (Order $o) => $o->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'type' => OrderType::class,
            'business_date' => 'date:Y-m-d',
            'bill_requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Table, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return HasMany<OrderRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(OrderRound::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(OrderCharge::class);
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class)->orderBy('id');
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

    public function isSplit(): bool
    {
        return $this->bills()->whereNotNull('split_mode')->exists();
    }
}
