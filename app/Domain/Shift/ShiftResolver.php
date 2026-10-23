<?php

namespace App\Domain\Shift;

use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\Shift;
use App\Models\User;

/**
 * The store has one shift at a time and everybody works inside it: whoever opens it (owner, manager or cashier), the
 * payments of all staff are booked into it. `shifts.user_id` says who opened it and `payments.received_by` still says
 * who took each payment.
 */
class ShiftResolver
{
    public function __construct(private OutletContext $outlet) {}

    /** The outlet's open shift, if any. */
    public function current(): ?Shift
    {
        return Shift::query()->where('outlet_id', $this->outlet->id())->whereNull('closed_at')->latest('opened_at')->first();
    }

    public function forUser(User $user): ?Shift
    {
        return $this->current();
    }
}
