<?php

namespace App\Domain\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $tax_rule_id
 * @property string $name_snapshot
 * @property string $kind
 * @property int $rate
 * @property int $base
 * @property int $amount
 * @property bool $is_inclusive
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderCharge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_inclusive' => 'boolean'];
    }
}
