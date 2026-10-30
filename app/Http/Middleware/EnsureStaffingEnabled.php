<?php

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Longshift approval exists when the schedule or the attendance feature is on (Pengaturan > Operasional). */
class EnsureStaffingEnabled
{
    public function __construct(private SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('schedule.enabled', false) && ! (bool) $this->settings->get('attendance.enabled', false)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Jadwal kerja dan absensi tidak diaktifkan.'], 404)
                : redirect('/')->with('error', 'Aktifkan Jadwal kerja atau Absensi wajah di Pengaturan > Operasional.');
        }

        return $next($request);
    }
}
