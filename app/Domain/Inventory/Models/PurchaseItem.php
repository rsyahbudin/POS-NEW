<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $purchase_id
 * @property int $ingredient_id
 * @property float $qty
 * @property float $unit_cost
 * @property int $total
 */
class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_cost' => 'float'];
    }
}
