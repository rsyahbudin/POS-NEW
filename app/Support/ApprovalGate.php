<?php

namespace App\Support;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * "PIN manager": a cashier may perform a guarded action once a manager types a
 * 6-digit PIN on the cashier's device. The approving manager is returned so it
 * can be stored in the audit trail.
 */
class ApprovalGate
{
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

    /**
     * Cancelling an unpaid order or item: any staff who can take orders may do it (reason is mandatory and
     * reported). Pengaturan > Keamanan can require a manager PIN again.
     */
    public function resolveCancel(User $actor, ?string $pin): User
    {
        if (! (bool) app(SettingsRepository::class)->get('security.cancel_needs_pin', false)) {
            return $actor;
        }

        return $this->resolve($actor, 'void', $pin);
    }

    private function ensureNotLocked(User $actor): void
    {
        if ($actor->pin_locked_until && $actor->pin_locked_until->isFuture()) {
            throw new OrderException('Terlalu banyak PIN salah. Coba lagi setelah '.$actor->pin_locked_until->format('H:i').'.');
        }
    }

    private function registerFailure(User $actor): void
    {
        $settings = app(SettingsRepository::class);
        $max = max(1, (int) $settings->get('security.pin_max_attempts', 5));
        $lock = max(1, (int) $settings->get('security.pin_lock_minutes', 15));
        $attempts = $actor->pin_attempts + 1;
        $actor->forceFill([
            'pin_attempts' => $attempts >= $max ? 0 : $attempts,
            'pin_locked_until' => $attempts >= $max ? now()->addMinutes($lock) : $actor->pin_locked_until,
        ])->save();
        Audit::log('pin.failed', $actor, ['attempts' => $attempts], $actor);
    }
}
