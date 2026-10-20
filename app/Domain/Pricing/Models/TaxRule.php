<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\ChargeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property ChargeKind $kind
 * @property int $rate_bp
 * @property int $sequence
 * @property string $base
 * @property bool $is_inclusive
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['kind' => ChargeKind::class, 'is_inclusive' => 'boolean', 'is_active' => 'boolean'];
    }
}
