<?php

namespace App\Domain\Staffing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pattern of working hours (Pagi 08:00-16:00). Not the cash shift of a cashier: that is App\Domain\Shift.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $starts_at HH:MM[:SS]
 * @property string $ends_at HH:MM[:SS], earlier than the start when the shift runs past midnight
 * @property bool $is_active
 */
class WorkShiftType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function startMinute(): int
    {
        return self::minutes($this->starts_at);
    }

    /** Minute of the day the shift ends; past 1440 when it ends after midnight. */
    public function endMinute(): int
    {
        $end = self::minutes($this->ends_at);

        return $end <= $this->startMinute() ? $end + 1440 : $end;
    }

    public function durationMinutes(): int
    {
        return $this->endMinute() - $this->startMinute();
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
