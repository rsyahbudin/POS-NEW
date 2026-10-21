<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extra ingredient used when a modifier is chosen (e.g. extra shot).
 *
 * @property int $id
 * @property int $modifier_id
 * @property int $ingredient_id
 * @property float $qty
 */
class ModifierIngredient extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'float'];
    }
}
