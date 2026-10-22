<?php

namespace App\Domain\Payment\Models;

use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dynamic QRIS charge for a bill, created at a payment gateway. It turns into a normal `payments` row
 * once the gateway confirms the money arrived and an open shift can book it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $outlet_id
 * @property int $order_id
 * @property int $bill_id
 * @property string $provider
 * @property string|null $provider_ref
 * @property int $amount
 * @property string $status
 * @property string|null $qr_string
 * @property Carbon|null $expires_at
 * @property Carbon|null $paid_at
 * @property int|null $payment_id
 * @property Carbon|null $applied_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property-read Order $order
 * @property-read Bill $bill
 */
class OnlinePayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    /** The reference sent to the gateway as the order id. */
    public function gatewayOrderId(): string
    {
        return 'pos-'.$this->public_id;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
