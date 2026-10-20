<?php

namespace App\Domain\Shift\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property Carbon $date
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property array<mixed>|null $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BusinessDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'closed_at' => 'datetime', 'date' => 'date:Y-m-d'];
    }
}
