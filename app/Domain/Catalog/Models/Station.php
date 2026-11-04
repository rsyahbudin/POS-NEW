<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Models\Ingredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property int $sort
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Station extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> the staff who work for this station */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'station_user');
    }

    /** @return BelongsToMany<Ingredient, $this> */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_station');
    }

    /** @return list<int> the active ingredients this station is responsible for */
    public function ingredientIds(): array
    {
        return array_values($this->ingredients()->where('ingredients.is_active', true)->pluck('ingredients.id')->map(fn ($id) => (int) $id)->all());
    }
}
