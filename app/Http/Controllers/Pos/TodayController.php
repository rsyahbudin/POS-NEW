<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Reporting\SalesReports;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Today's sales at a glance for staff who have no sidebar/dashboard (cashier, waiter, kitchen). */
class TodayController extends Controller
{
    public function __invoke(Request $request, SalesReports $reports): Response
    {
        $today = BusinessDate::for();
        $user = $request->user();

        return Inertia::render('pos/today', [
            'date' => $today,
            'metrics' => $reports->dashboard($today),
            'hourly' => $reports->by('hour', $today, $today)->values(),
            'topProducts' => $reports->by('product', $today, $today)->take(8)->values(),
            'back' => ($user?->can('receive-payment') || $user?->can('create-order')) ? '/pos' : '/kds',
        ]);
    }
}
