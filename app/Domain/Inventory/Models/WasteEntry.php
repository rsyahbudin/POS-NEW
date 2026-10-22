<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int|null $user_id
 * @property string $kind
 * @property int|null $ingredient_id
 * @property int|null $product_id
 * @property string $label
 * @property float $qty
 * @property string $unit
 * @property string $reason
 * @property string|null $note
 * @property int $cost
 * @property Carbon|null $created_at
 */
class WasteEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'float'];
    }
}
