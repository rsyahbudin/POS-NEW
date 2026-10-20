<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated staff are logged out on the next request; owners must have
 * confirmed 2FA (PRD 8) before using anything but their security settings.
 */
class EnsureStaffAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun dinonaktifkan.']);
        }

        if ($user && config('pos.require_owner_2fa') && $user->hasRole('owner') && ! $user->two_factor_confirmed_at
            && ! $request->routeIs('security.*', 'two-factor.*', 'password.*', 'profile.*', 'logout', 'user-password.*', 'appearance.*', 'passkey.*')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Aktifkan 2FA untuk akun owner.'], 403)
                : redirect()->route('security.edit')->with('error', 'Akun owner wajib mengaktifkan 2FA.');
        }

        return $next($request);
    }
}
