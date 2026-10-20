<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Actions\CloseBusinessDayAction;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ZReport;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DayCloseController extends Controller
{
    public function index(OutletContext $outlet, ZReport $report): Response
    {
        $date = BusinessDate::for();
        $id = $outlet->id();

        return Inertia::render('admin/day-close', [
            'date' => $date,
            'preview' => $report->build($date),
            'openShifts' => Shift::query()->where('outlet_id', $id)->whereNull('closed_at')->with('user:id,name')->get()->map(fn ($s) => ['id' => $s->id, 'user' => $s->user?->name, 'opened_at' => $s->opened_at->toIso8601String()]),
            'closed' => BusinessDay::query()->where('outlet_id', $id)->where('date', $date)->whereNotNull('closed_at')->exists(),
            'history' => BusinessDay::query()->where('outlet_id', $id)->whereNotNull('closed_at')->orderByDesc('date')->limit(14)->get(['date', 'summary']),
        ]);
    }

    public function close(Request $request, CloseBusinessDayAction $action): RedirectResponse
    {
        $action->handle($this->actor($request), BusinessDate::for());

        return back()->with('success', 'Hari bisnis ditutup. Z-report dikirim ke Telegram jika dikonfigurasi.');
    }
}
