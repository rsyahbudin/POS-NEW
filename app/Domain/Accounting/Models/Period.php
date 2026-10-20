<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int $month
 * @property int $year
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Period extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
