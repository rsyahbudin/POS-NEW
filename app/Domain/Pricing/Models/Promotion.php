<?php

namespace App\Domain\Pricing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $type
 * @property int $value
 * @property string $starts_at
 * @property string $ends_at
 * @property array<mixed>|null $days
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Promotion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'array', 'is_active' => 'boolean'];
    }

    public function isActiveAt(CarbonInterface $at): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if (is_array($this->days) && $this->days !== [] && ! in_array($at->dayOfWeekIso, $this->days, true)) {
            return false;
        }
        $time = $at->format('H:i:s');

        return $time >= $this->starts_at && $time <= $this->ends_at;
    }
}
