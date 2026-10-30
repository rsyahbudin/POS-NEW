<?php

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The work schedule is optional (Pengaturan > Operasional > Jadwal kerja). When off, its pages and actions are closed. */
class EnsureScheduleEnabled
{
    public function __construct(private SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('schedule.enabled', false)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Jadwal kerja tidak diaktifkan.'], 404)
                : redirect('/')->with('error', 'Jadwal kerja belum diaktifkan. Aktifkan di Pengaturan > Operasional.');
        }

        return $next($request);
    }
}
