<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $order_item_id
 * @property string $type
 * @property int $value
 * @property int $amount
 * @property string $reason
 * @property int|null $promotion_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Discount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
