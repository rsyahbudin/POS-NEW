<?php

namespace App\Domain\Shift;

use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\Shift;
use App\Models\User;

/**
 * Which open shift a user's payments are booked into.
 *
 * Normally their own. A small cafe has one drawer: whoever opens the shift (owner, manager or cashier), everyone else
 * who may take payments works inside it. If the setting allows it, a user without a shift of their own uses the
 * outlet's open shift (the most recent one when several are open). `payments.received_by` still records who took the money.
 */
class ShiftResolver
{
    public function __construct(private SettingsRepository $settings, private OutletContext $outlet) {}

    public function forUser(User $user): ?Shift
    {
        $own = Shift::query()->where('user_id', $user->id)->whereNull('closed_at')->first();
        if ($own || ! $this->canShare($user)) {
            return $own;
        }

        return Shift::query()->where('outlet_id', $this->outlet->id())->whereNull('closed_at')->latest('opened_at')->first();
    }

    public function canShare(User $user): bool
    {
        return (bool) $this->settings->get('shift.shared_for_managers', true)
            && ($user->can('manage-shifts-any') || $user->can('receive-payment'));
    }
}
