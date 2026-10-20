<?php

namespace App\Domain\Payment\Models;

use App\Domain\Ordering\Models\OrderCharge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bill_id
 * @property int $order_charge_id
 * @property int $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrderCharge $orderCharge
 */
class BillCharge extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<OrderCharge, $this> */
    public function orderCharge(): BelongsTo
    {
        return $this->belongsTo(OrderCharge::class);
    }
}
