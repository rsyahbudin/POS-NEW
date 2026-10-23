<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\ReportDelivery;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Actions\CloseBusinessDayAction;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ZReport;
use App\Domain\Shift\ZReportSummary;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DayCloseController extends Controller
{
    public function index(Request $request, OutletContext $outlet, ZReport $report, ReportDelivery $delivery): Response
    {
        $data = $request->validate(['date' => 'nullable|date']);
        $date = isset($data['date']) ? CarbonImmutable::parse($data['date'])->toDateString() : BusinessDate::for();
        $id = $outlet->id();
        $users = User::query()->pluck('name', 'id');

        return Inertia::render('admin/day-close', [
            'date' => $date,
            'today' => BusinessDate::for(),
            'preview' => $report->build($date),
            'shifts' => Shift::query()->where('outlet_id', $id)->where('business_date', $date)->orderBy('opened_at')->get()->map(fn (Shift $s) => [
                'id' => $s->id, 'user' => $users[$s->user_id] ?? '-', 'opened_at' => $s->opened_at->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String(),
                'closed_by' => $s->closed_by ? ($users[$s->closed_by] ?? null) : null, 'difference' => $s->difference,
            ])->all(),
            'openShifts' => Shift::query()->where('outlet_id', $id)->whereNull('closed_at')->with('user:id,name')->get()->map(fn ($s) => ['id' => $s->id, 'user' => $s->user?->name, 'opened_at' => $s->opened_at->toIso8601String()]),
            'closed' => BusinessDay::query()->where('outlet_id', $id)->where('date', $date)->whereNotNull('closed_at')->exists(),
            'history' => BusinessDay::query()->where('outlet_id', $id)->whereNotNull('closed_at')->orderByDesc('date')->limit(14)->get(['date', 'summary']),
            'channels' => collect($delivery->configured())->map(fn (string $c) => ReportDelivery::CHANNELS[$c])->values()->all(),
        ]);
    }

    /** Sends the report of the chosen day to the configured channels right now (nothing is locked). */
    public function send(Request $request, ZReport $report, ReportDelivery $delivery): RedirectResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $date = CarbonImmutable::parse($data['date']);
        $summary = ZReportSummary::fromArray($report->build($date->toDateString()));
        $channels = $delivery->deliver('Laporan harian '.$date->format('d M Y'), $delivery->dailyTextFor($date->format('d M Y'), $summary, $date->toDateString()), null, $delivery->dailyHtml($date->toDateString(), $summary));

        return back()->with($channels === [] ? 'error' : 'success', $channels === []
            ? 'Belum ada saluran pengiriman. Isi Telegram, email, atau WhatsApp di Pengaturan > Notifikasi.'
            : 'Laporan dikirim ke '.implode(', ', array_map(fn ($c) => ReportDelivery::CHANNELS[$c], $channels)).'.');
    }

    public function export(Request $request, OutletContext $outlet): \Symfony\Component\HttpFoundation\Response
    {
        $days = BusinessDay::query()->where('outlet_id', $outlet->id())->whereNotNull('closed_at')->orderByDesc('date')->limit(90)->get();
        $rows = [];
        foreach ($days as $d) {
            $z = ZReportSummary::fromArray($d->summary);
            $rows[] = [$d->date->translatedFormat('d M Y'), $z->orders, $z->gross, $z->discounts, $z->refunds, $z->net, $z->voidItems, $z->compItems, $z->cashDifference];
        }

        return ReportFile::download((string) $request->input('format', 'xlsx'), 'Rekap tutup hari', [
            new ReportSheet('Rekap tutup hari', 'Z-report 90 hari terakhir', ['Tanggal', 'Order', 'Omzet kotor (Rp)', 'Diskon (Rp)', 'Refund (Rp)', 'Omzet bersih (Rp)', 'Void (item)', 'Komplimen (item)', 'Selisih kas (Rp)'], $rows,
                ['Total', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3)), array_sum(array_column($rows, 4)), array_sum(array_column($rows, 5)), array_sum(array_column($rows, 6)), array_sum(array_column($rows, 7)), array_sum(array_column($rows, 8))]),
        ]);
    }

    public function close(Request $request, CloseBusinessDayAction $action): RedirectResponse
    {
        $action->handle($this->actor($request), BusinessDate::for());

        return back()->with('success', 'Hari bisnis ditutup. Z-report dikirim ke Telegram jika dikonfigurasi.');
    }
}
