<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A packaging of an ingredient ("botol" = 750 ml): lets people enter purchases and counts the way they
 * handle the goods, while stock and recipes stay in the base unit.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property string $name
 * @property float $factor
 */
class IngredientUnit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['factor' => 'float'];
    }
}
