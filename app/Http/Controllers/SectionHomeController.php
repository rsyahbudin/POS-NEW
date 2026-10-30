<?php

namespace App\Http\Controllers;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The sidebar has one entry for a group of pages (Absensi, Pengeluaran); this sends each person to the first page they may open. */
class SectionHomeController extends Controller
{
    public function staff(Request $request, SettingsRepository $settings): RedirectResponse
    {
        $u = $request->user();
        $attendance = (bool) $settings->get('attendance.enabled', false);
        $schedule = (bool) $settings->get('schedule.enabled', false);
        $to = match (true) {
            $attendance && ($u?->can('view-own-attendance') || $u?->can('use-attendance-kiosk')) => '/attendance/mine',
            $schedule && $u?->can('view-schedule') => '/schedule',
            $attendance && $u?->can('manage-attendance') => '/attendance',
            ($attendance || $schedule) && $u?->can('approve-longshift') => '/longshift',
            default => null,
        };
        abort_if($to === null, 404);

        return redirect($to);
    }

    public function spending(Request $request): RedirectResponse
    {
        $u = $request->user();
        $to = match (true) {
            (bool) $u?->can('input-expense') => '/admin/expenses',
            (bool) $u?->can('manage-fixed-expenses') => '/admin/fixed-expenses',
            (bool) $u?->can('view-cash-ledger') => '/admin/cash-ledger',
            default => null,
        };
        abort_if($to === null, 403);

        return redirect($to);
    }
}
