<?php

namespace App\Support;

use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * The date a sale belongs to. While a shift is open it is the date that shift was opened, so a shift that runs past
 * midnight stays one day; with no shift open it is simply today's date. There is no cut-off hour to configure.
 */
final class BusinessDate
{
    public static function for(?CarbonImmutable $moment = null): string
    {
        if ($moment === null) {
            $open = Shift::query()->where('outlet_id', app(OutletContext::class)->id())->whereNull('closed_at')->value('business_date');
            if ($open !== null) {
                return substr((string) $open, 0, 10);
            }
        }

        return ($moment ?? CarbonImmutable::now())->setTimezone(config('app.timezone'))->toDateString();
    }
}
