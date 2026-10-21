<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $unit
 * @property float $stock
 * @property float $avg_cost
 * @property float $min_stock
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ingredient extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['stock' => 'float', 'avg_cost' => 'float', 'min_stock' => 'float', 'is_active' => 'boolean'];
    }
}
