<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Thin wrapper over spatie/laravel-activitylog so audit calls read the same everywhere. */
final class Audit
{
    /** @param  array<string, mixed>  $properties */
    public static function log(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $logger = activity('audit');
        if ($causer ?? auth()->user()) {
            $logger->causedBy($causer ?? auth()->user());
        }
        if ($subject) {
            $logger->performedOn($subject);
        }
        $logger->event($event)->withProperties($properties)->log($event);
    }
}
