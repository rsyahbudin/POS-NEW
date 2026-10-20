<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Floor staff sign in with their own 6-digit PIN (unique per person, see UserController).
 * Only roles with "Login dengan PIN" on (Peran & Akses) may do this; office roles keep email + password.
 */
class PinLoginController extends Controller
{
    public static function enabled(): bool
    {
        return Role::query()->where('allow_pin_login', true)->exists();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $roles = Role::query()->where('allow_pin_login', true)->pluck('name')->all();

        $user = $roles === [] ? null : User::query()
            ->role($roles)->where('is_active', true)->whereNotNull('pin_hash')->whereNull('two_factor_confirmed_at')->get()
            ->first(fn (User $u) => Hash::check($data['pin'], (string) $u->pin_hash));

        if ($user === null) {
            throw ValidationException::withMessages(['pin' => 'PIN salah.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
