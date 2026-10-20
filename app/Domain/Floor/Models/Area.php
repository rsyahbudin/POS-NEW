<?php

namespace App\Domain\Floor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Area extends Model
{
    protected $guarded = [];

    /** @return HasMany<Table, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class)->orderBy('name');
    }
}
