<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $modifier_group_id
 * @property string $name
 * @property int $price_delta
 * @property int $sort
 * @property bool $is_available
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Modifier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    /** @return BelongsTo<ModifierGroup, $this> */
    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }
}
