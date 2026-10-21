<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $stock_count_id
 * @property int $ingredient_id
 * @property float $counted
 * @property float|null $system_qty
 */
class StockCountLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['counted' => 'float', 'system_qty' => 'float'];
    }
}
