<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\SalesReports;
use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private SalesReports $reports) {}

    public function dashboard(): Response
    {
        $today = BusinessDate::for();

        return Inertia::render('dashboard', [
            'date' => $today,
            'metrics' => $this->reports->dashboard($today),
            'hourly' => $this->reports->by('hour', $today, $today),
            'topProducts' => $this->reports->by('product', $today, $today)->take(5)->values(),
        ]);
    }

    public function sales(Request $request): Response
    {
        [$from, $to, $dimension] = $this->params($request);

        return Inertia::render('admin/reports/sales', [
            'filters' => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
            'summary' => $this->reports->summary($from, $to),
            'rows' => $this->reports->by($dimension, $from, $to),
            'dimensions' => SalesReports::DIMENSIONS,
        ]);
    }

    public function adjustments(Request $request): Response
    {
        [$from, $to] = $this->params($request);

        return Inertia::render('admin/reports/adjustments', ['filters' => compact('from', 'to')] + $this->reports->adjustments($from, $to));
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to, $dimension] = $this->params($request);
        $format = $request->input('format', 'xlsx');
        $report = $request->input('report', 'sales');

        if ($report === 'adjustments') {
            $a = $this->reports->adjustments($from, $to);
            $headings = ['Jenis', 'Order', 'Detail', 'Nominal', 'Alasan', 'Pelaku', 'Penyetuju'];
            $rows = [];
            foreach ($a['discounts'] as $r) {
                $rows[] = ['Diskon', $r->number, $r->type.' '.$r->value, (int) $r->amount, $r->reason, $r->actor, $r->approver];
            }
            foreach ($a['voids'] as $r) {
                $rows[] = ['Void', $r->number, "{$r->item} x{$r->qty}", (int) $r->amount, $r->reason, $r->actor, $r->approver];
            }
            foreach ($a['refunds'] as $r) {
                $rows[] = ['Refund', $r->number, '', (int) $r->amount, $r->reason, '', $r->approver];
            }
            foreach ($a['comps'] as $r) {
                $rows[] = ['Komplimen', $r->number, "{$r->item} x{$r->qty} ({$r->type})", (int) $r->amount, $r->reason, '', $r->approver];
            }
            $title = 'Laporan Diskon, Void, Refund';
        } else {
            $headings = [ucfirst($dimension), 'Jumlah', 'Total (Rp)'];
            $rows = $this->reports->by($dimension, $from, $to)->map(fn ($r) => [$r->label, $r->orders, $r->total])->all();
            $title = "Laporan Penjualan per {$dimension}";
        }

        $name = str_replace(' ', '-', strtolower($title))."-{$from}-{$to}";

        return $format === 'pdf'
            ? Pdf::loadView('pdf.table', ['title' => $title, 'subtitle' => "{$from} s/d {$to}", 'headings' => $headings, 'rows' => $rows])->download("{$name}.pdf")
            : Excel::download(new ArrayExport($headings, $rows), "{$name}.xlsx");
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function params(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'dimension' => 'nullable|in:'.implode(',', SalesReports::DIMENSIONS)]);

        return [
            $request->input('from', CarbonImmutable::parse(BusinessDate::for())->subDays(29)->toDateString()),
            $request->input('to', BusinessDate::for()),
            $request->input('dimension', 'day'),
        ];
    }
}
