<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A discount the owner registered by name (e.g. "Staff 10%", "Owner 20%"). Cashiers pick it from a list instead of
 * typing a value; percent is stored in basis points (1000 = 10%).
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $type
 * @property int $value
 * @property bool $needs_pin
 * @property bool $is_active
 * @property int $sort
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class DiscountPreset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['needs_pin' => 'boolean', 'is_active' => 'boolean'];
    }
}
