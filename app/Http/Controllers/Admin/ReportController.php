<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\SalesReports;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public const DIMENSION_LABELS = ['day' => 'Hari', 'hour' => 'Jam', 'product' => 'Produk', 'category' => 'Kategori', 'method' => 'Metode bayar', 'cashier' => 'Kasir', 'type' => 'Tipe order'];

    public function __construct(private SalesReports $reports) {}

    public function dashboard(): Response
    {
        $today = BusinessDate::for();

        return Inertia::render('dashboard', [
            'date' => $today,
            'metrics' => $this->reports->dashboard($today),
            'hourly' => $this->reports->by('hour', $today, $today),
            'daily' => $this->reports->by('day', CarbonImmutable::parse($today)->subDays(13)->toDateString(), $today),
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
        $format = (string) $request->input('format', 'xlsx');
        $range = $this->rangeLabel($from, $to);

        if ($request->input('report') === 'adjustments') {
            $a = $this->reports->adjustments($from, $to);
            $title = 'Diskon, Void, Refund';
            $sub = "Periode {$range}";
            $sum = fn ($rows) => (int) $rows->sum(fn ($r) => (int) $r->amount);
            $sheets = [
                new ReportSheet('Diskon', $sub, ['Order', 'Jenis', 'Nilai', 'Potongan (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                    $a['discounts']->map(fn ($r) => [$r->number, $r->type === 'percent' ? 'Persen' : ($r->type === 'fixed' ? 'Nominal' : 'Promo'), $r->type === 'percent' ? ((int) $r->value / 100).'%' : (string) $r->value, (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                    ['Total', '', '', $sum($a['discounts']), '', '', '']),
                new ReportSheet('Void', $sub, ['Order', 'Item', 'Qty', 'Nilai (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                    $a['voids']->map(fn ($r) => [$r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                    ['Total', '', '', $sum($a['voids']), '', '', '']),
                new ReportSheet('Refund', $sub, ['Order', 'Nominal (Rp)', 'Alasan', 'Disetujui oleh'],
                    $a['refunds']->map(fn ($r) => [$r->number, (int) $r->amount, $r->reason, $r->approver ?? '-'])->all(),
                    ['Total', $sum($a['refunds']), '', '']),
                new ReportSheet('Komplimen', $sub, ['Order', 'Item', 'Qty', 'Harga jual (Rp)', 'Jenis', 'Alasan', 'Disetujui oleh'],
                    $a['comps']->map(fn ($r) => [$r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->type === 'staff_meal' ? 'Staff meal' : 'Komplimen', $r->reason, $r->approver ?? '-'])->all(),
                    ['Total', '', '', $sum($a['comps']), '', '', '']),
            ];

            return ReportFile::download($format, "{$title} {$from} {$to}", $sheets);
        }

        $label = self::DIMENSION_LABELS[$dimension];
        $rows = $this->reports->by($dimension, $from, $to);
        $qtyDim = in_array($dimension, ['product', 'category'], true);
        $summary = $this->reports->summary($from, $to);
        $sheets = [
            new ReportSheet("Penjualan {$label}", "Periode {$range}", [$label, $qtyDim ? 'Qty' : 'Jumlah order', 'Total (Rp)'],
                $rows->map(fn ($r) => [$r->label, $r->orders, $r->total])->all(),
                ['Total', (int) $rows->sum('orders'), (int) $rows->sum('total')]),
            new ReportSheet('Ringkasan', "Periode {$range}", ['Keterangan', 'Nilai'], [
                ['Jumlah order', $summary['orders']], ['Penjualan kotor (Rp)', $summary['gross']], ['Diskon (Rp)', $summary['discounts']],
                ['Refund (Rp)', $summary['refunds']], ['Penjualan bersih (Rp)', $summary['net']],
            ]),
        ];

        return ReportFile::download($format, "Laporan penjualan {$label} {$from} {$to}", $sheets);
    }

    private function rangeLabel(string $from, string $to): string
    {
        return CarbonImmutable::parse($from)->translatedFormat('d M Y').' s/d '.CarbonImmutable::parse($to)->translatedFormat('d M Y');
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
