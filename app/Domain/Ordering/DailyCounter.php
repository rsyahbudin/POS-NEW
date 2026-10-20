<?php

namespace App\Domain\Ordering;

use Illuminate\Support\Facades\DB;

/** Race-safe per-day sequence (order numbers, call numbers). Call inside a transaction. */
class DailyCounter
{
    public function next(int $outletId, string $date, string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO daily_counters (outlet_id, date, key, value) VALUES (?, ?, ?, 1)
             ON CONFLICT (outlet_id, date, key) DO UPDATE SET value = daily_counters.value + 1
             RETURNING value',
            [$outletId, $date, $key],
        );

        return (int) $row->value;
    }
}
