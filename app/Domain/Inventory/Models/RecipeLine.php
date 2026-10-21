<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ingredient used by one portion of a product (variant_id null = every variant).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $ingredient_id
 * @property float $qty
 */
class RecipeLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'float'];
    }
}
