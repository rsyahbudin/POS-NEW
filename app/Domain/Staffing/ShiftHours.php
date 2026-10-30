<?php

namespace App\Domain\Staffing;

use App\Domain\Staffing\Models\WorkShiftType;

/**
 * Shift patterns may overlap on purpose (Shift 1 09:00-18:00, Shift 2 14:00-23:00) so no hour of the day is left uncovered.
 * A person on both works 09:00-23:00: the overlapping hours count once.
 */
final class ShiftHours
{
    /**
     * Minutes covered by these shifts together, overlap counted once.
     *
     * @param  iterable<WorkShiftType>  $types
     */
    public static function unionMinutes(iterable $types): int
    {
        $spans = [];
        foreach ($types as $t) {
            $spans[] = [$t->startMinute(), $t->endMinute()];
        }
        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $total = 0;
        $end = null;
        foreach ($spans as [$from, $to]) {
            if ($end === null || $from >= $end) {
                $total += $to - $from;
                $end = $to;
            } elseif ($to > $end) {
                $total += $to - $end;
                $end = $to;
            }
        }

        return $total;
    }
}
