<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Station;
use App\Domain\Notification\Alerts;
use App\Domain\Notification\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property int|null $preferred_supplier_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ingredient extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function (Ingredient $i) {
            if ($i->wasRecentlyCreated || $i->wasChanged(['stock', 'min_stock', 'is_active'])) {
                Notifier::later(fn () => Alerts::ingredient($i->refresh()));
            }
        });
    }

    /** @return BelongsToMany<Station, $this> the stations that hold this ingredient (several may share one, e.g. sugar) */
    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'ingredient_station');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<IngredientUnit, $this> */
    public function packs(): HasMany
    {
        return $this->hasMany(IngredientUnit::class);
    }

    protected function casts(): array
    {
        return ['stock' => 'float', 'avg_cost' => 'float', 'min_stock' => 'float', 'is_active' => 'boolean'];
    }
}
