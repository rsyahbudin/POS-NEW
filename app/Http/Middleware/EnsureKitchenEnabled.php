<?php

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The kitchen display is optional (Pengaturan > Dapur). When off, its pages and actions are closed. */
class EnsureKitchenEnabled
{
    public function __construct(private SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('kitchen.enabled', false)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Layar dapur tidak diaktifkan.'], 404)
                : redirect('/')->with('error', 'Layar dapur belum diaktifkan. Aktifkan di Pengaturan > Dapur (KDS).');
        }

        return $next($request);
    }
}
