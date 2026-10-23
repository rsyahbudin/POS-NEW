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
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Promotion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'array', 'is_active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    /**
     * Is the promo running at this moment? Hours may cross midnight (22:00–02:00); the part after midnight still
     * belongs to the day the promo started, which is the day that days/dates are checked against.
     */
    public function isActiveAt(CarbonInterface $at): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $time = $at->format('H:i:s');
        $overnight = $this->starts_at > $this->ends_at;
        $inWindow = $overnight ? ($time >= $this->starts_at || $time <= $this->ends_at) : ($time >= $this->starts_at && $time <= $this->ends_at);
        if (! $inWindow) {
            return false;
        }

        $day = $overnight && $time <= $this->ends_at ? $at->subDay() : $at;
        if (is_array($this->days) && $this->days !== [] && ! in_array($day->dayOfWeekIso, $this->days, true)) {
            return false;
        }
        $date = $day->toDateString();
        if ($this->starts_on !== null && $date < $this->starts_on->toDateString()) {
            return false;
        }
        if ($this->ends_on !== null && $date > $this->ends_on->toDateString()) {
            return false;
        }

        return true;
    }
}
