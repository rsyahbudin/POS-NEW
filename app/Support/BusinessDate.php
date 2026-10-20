<?php

namespace App\Support;

use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * Business day starts at a configurable hour (default 04:00) so late-night
 * operations stay in the same business date.
 */
final class BusinessDate
{
    public static function for(?CarbonImmutable $moment = null): string
    {
        $moment ??= CarbonImmutable::now();
        $start = app(SettingsRepository::class)->get('operations.business_day_start', '04:00');
        [$h, $m] = array_map('intval', explode(':', $start));

        return $moment->setTimezone(config('app.timezone'))
            ->subHours($h)->subMinutes($m)
            ->toDateString();
    }
}
