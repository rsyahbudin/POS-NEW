<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Broadcasts a realtime event after the surrounding DB transaction commits
 * (so clients that refetch immediately see the new data) and never lets a
 * WebSocket outage break an order or a payment. Screens fall back to polling.
 */
final class Realtime
{
    public static function dispatch(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
