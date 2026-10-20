<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Period;
use App\Domain\Settings\OutletContext;
use App\Models\User;
use App\Support\Audit;

/** Monthly close: no new journals can be posted into the period. */
class ClosePeriodAction
{
    public function __construct(private OutletContext $outlet) {}

    public function handle(User $user, int $year, int $month): Period
    {
        $period = Period::query()->firstOrCreate(['outlet_id' => $this->outlet->id(), 'year' => $year, 'month' => $month]);
        $period->update(['closed_at' => now(), 'closed_by' => $user->id]);
        Audit::log('period.closed', $period, ['year' => $year, 'month' => $month], $user);

        return $period;
    }
}
