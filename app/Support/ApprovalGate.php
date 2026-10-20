<?php

namespace App\Support;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * "PIN manager": a cashier may perform a guarded action once a manager types a
 * 6-digit PIN on the cashier's device. The approving manager is returned so it
 * can be stored in the audit trail.
 */
class ApprovalGate
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /**
     * @return User the user accountable for the action (actor itself if permitted)
     */
    public function resolve(User $actor, string $permission, ?string $pin): User
    {
        if ($actor->can($permission)) {
            return $actor;
        }

        if ($pin === null || $pin === '') {
            throw new OrderException('Aksi ini butuh PIN manager.');
        }

        $this->ensureNotLocked($actor);

        $approver = User::query()
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->get()
            ->first(fn (User $u) => $u->can($permission) && Hash::check($pin, (string) $u->pin_hash));

        if (! $approver) {
            $this->registerFailure($actor);

            throw new OrderException('PIN manager salah.');
        }

        $actor->forceFill(['pin_attempts' => 0, 'pin_locked_until' => null])->save();

        return $approver;
    }

    private function ensureNotLocked(User $actor): void
    {
        if ($actor->pin_locked_until && $actor->pin_locked_until->isFuture()) {
            throw new OrderException('Terlalu banyak PIN salah. Coba lagi setelah '.$actor->pin_locked_until->format('H:i').'.');
        }
    }

    private function registerFailure(User $actor): void
    {
        $attempts = $actor->pin_attempts + 1;
        $actor->forceFill([
            'pin_attempts' => $attempts >= self::MAX_ATTEMPTS ? 0 : $attempts,
            'pin_locked_until' => $attempts >= self::MAX_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : $actor->pin_locked_until,
        ])->save();
        Audit::log('pin.failed', $actor, ['attempts' => $attempts], $actor);
    }
}
