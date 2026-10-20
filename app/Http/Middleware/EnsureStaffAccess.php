<?php

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated staff are logged out on the next request; owners must have
 * confirmed 2FA (PRD 8) when Pengaturan > Keamanan requires it before using anything but their security settings.
 */
class EnsureStaffAccess
{
    public function __construct(private SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun dinonaktifkan.']);
        }

        if ($user && (bool) $this->settings->get('security.require_owner_2fa', false) && $user->hasRole('owner') && ! $user->two_factor_confirmed_at
            && ! $request->routeIs('security.*', 'two-factor.*', 'password.*', 'profile.*', 'logout', 'user-password.*', 'appearance.*', 'passkey.*')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Aktifkan 2FA untuk akun owner.'], 403)
                : redirect()->route('security.edit')->with('error', 'Akun owner wajib mengaktifkan 2FA.');
        }

        return $next($request);
    }
}
