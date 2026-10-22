<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string|null $supplier
 * @property Carbon $purchased_on
 * @property string|null $note
 * @property int $total
 * @property int|null $user_id
 * @property int|null $supplier_id
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Purchase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
