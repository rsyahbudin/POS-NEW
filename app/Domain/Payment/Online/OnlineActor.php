<?php

namespace App\Domain\Payment\Online;

use App\Domain\Settings\OutletContext;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** The "person" who receives online payments: a login-less system user, so reports say "Pembayaran Online" instead of a cashier. */
class OnlineActor
{
    public const EMAIL = 'online@system.local';

    public static function user(): User
    {
        return User::query()->where('email', self::EMAIL)->first() ?? User::create([
            'name' => 'Pembayaran Online', 'email' => self::EMAIL, 'password' => Hash::make(Str::random(48)),
            'outlet_id' => app(OutletContext::class)->id(), 'is_active' => false,
        ]);
    }
}
