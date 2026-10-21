<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every change of an ingredient's stock is a row here (stock is the sum).
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $ingredient_id
 * @property string $type
 * @property float $qty
 * @property float $unit_cost
 * @property int|null $user_id
 * @property int|null $order_item_id
 * @property int|null $purchase_id
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_cost' => 'float'];
    }
}
