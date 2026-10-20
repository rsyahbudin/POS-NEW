<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Floor staff sign in with their own 6-digit PIN (unique per person, see UserController).
 * Only roles listed in Pengaturan > Keamanan may do this; office roles keep email + password.
 */
class PinLoginController extends Controller
{
    public function __construct(private SettingsRepository $settings) {}

    /** @return list<string> */
    public static function roles(SettingsRepository $settings): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $settings->get('security.pin_login_roles', '')))));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $roles = self::roles($this->settings);

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
