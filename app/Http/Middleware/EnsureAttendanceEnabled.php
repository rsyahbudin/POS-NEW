<?php

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Face attendance is optional (Pengaturan > Operasional > Absensi wajah). When off, its pages and actions are closed. */
class EnsureAttendanceEnabled
{
    public function __construct(private SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('attendance.enabled', false)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Absensi tidak diaktifkan.'], 404)
                : redirect('/')->with('error', 'Absensi wajah belum diaktifkan. Aktifkan di Pengaturan > Operasional.');
        }

        return $next($request);
    }
}
