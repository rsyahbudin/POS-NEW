<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\KitchenReports;
use App\Domain\Reporting\MarginReports;
use App\Domain\Reporting\SalesReports;
use App\Domain\Reporting\WasteReports;
use App\Domain\Settings\SettingsRepository;
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

    public function kitchen(Request $request, KitchenReports $kitchen, SettingsRepository $settings): Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'day');
        abort_unless(in_array($dimension, KitchenReports::DIMENSIONS, true), 422);
        $slow = (int) $settings->get('kitchen.danger_minutes', 20);

        return Inertia::render('admin/reports/kitchen', [
            'enabled' => (bool) $settings->get('kitchen.enabled', false),
            'filters' => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
            'summary' => $kitchen->summary($from, $to, $slow),
            'rows' => $kitchen->by($dimension, $from, $to),
            'slowAfter' => $slow,
            'dimensions' => KitchenReports::DIMENSIONS,
        ]);
    }

    public function kitchenExport(Request $request, KitchenReports $kitchen, SettingsRepository $settings): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'day');
        abort_unless(in_array($dimension, KitchenReports::DIMENSIONS, true), 422);
        $labels = ['day' => 'Hari', 'hour' => 'Jam', 'product' => 'Produk', 'station' => 'Stasiun'];
        $sub = 'Periode '.$this->rangeLabel($from, $to);
        $s = $kitchen->summary($from, $to, (int) $settings->get('kitchen.danger_minutes', 20));
        $rows = $kitchen->by($dimension, $from, $to);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Kinerja dapur {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Kinerja dapur {$labels[$dimension]}", $sub, [$labels[$dimension], 'Item', 'Rata-rata (menit)', 'Terlama 10% (menit)', 'Terlama (menit)'],
                $rows->map(fn ($r) => [$r->label, $r->items, $r->avg_minutes, $r->p90_minutes, $r->max_minutes])->all()),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [
                ['Item selesai', $s['items']], ['Rata-rata selesai (menit)', $s['avg_minutes']], ['90% selesai dalam (menit)', $s['p90_minutes']],
                ['Rata-rata menunggu dimulai (menit)', $s['avg_wait']], ['Rata-rata pembuatan (menit)', $s['avg_make']], ['Item lewat batas merah', $s['slow']],
            ]),
        ]);
    }

    public function margin(Request $request, MarginReports $margin, SettingsRepository $settings): Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'product');
        abort_unless(in_array($dimension, MarginReports::DIMENSIONS, true), 422);

        return Inertia::render('admin/reports/margin', [
            'enabled' => (bool) $settings->get('inventory.enabled', false),
            'filters' => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
            'summary' => $margin->summary($from, $to),
            'rows' => $margin->by($dimension, $from, $to),
            'stock' => $margin->stock(),
            'dimensions' => MarginReports::DIMENSIONS,
        ]);
    }

    public function marginExport(Request $request, MarginReports $margin): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'product');
        abort_unless(in_array($dimension, MarginReports::DIMENSIONS, true), 422);
        $labels = ['product' => 'Produk', 'category' => 'Kategori', 'day' => 'Hari'];
        $sub = 'Periode '.$this->rangeLabel($from, $to);
        $s = $margin->summary($from, $to);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Margin {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Margin per {$labels[$dimension]}", $sub, [$labels[$dimension], 'Terjual', 'Penjualan', 'HPP', 'Laba kotor', 'Margin %'],
                $margin->by($dimension, $from, $to)->map(fn ($r) => [$r->label, $r->qty, $r->revenue, $r->cogs, $r->profit, $r->margin_pct])->all()),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [
                ['Penjualan', $s['revenue']], ['HPP (biaya bahan)', $s['cogs']], ['Laba kotor', $s['profit']], ['Margin %', $s['margin_pct']], ['Porsi tanpa resep/HPP', $s['uncosted_qty']],
            ]),
            new ReportSheet('Stok bahan', 'Saat ini', ['Bahan', 'Satuan', 'Stok', 'Biaya rata-rata', 'Nilai stok', 'Batas minimum'],
                $margin->stock()->map(fn ($r) => [$r->name, $r->unit, $r->stock, $r->avg_cost, $r->value, $r->min_stock])->all()),
        ]);
    }

    public function waste(Request $request, WasteReports $waste): Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'item');
        abort_unless(in_array($dimension, WasteReports::DIMENSIONS, true), 422);

        return Inertia::render('admin/reports/waste', [
            'filters' => ['from' => $from, 'to' => $to, 'dimension' => $dimension],
            'summary' => $waste->summary($from, $to),
            'rows' => $waste->by($dimension, $from, $to),
            'entries' => $waste->entries($from, $to),
            'dimensions' => WasteReports::DIMENSIONS,
        ]);
    }

    public function wasteExport(Request $request, WasteReports $waste): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'item');
        abort_unless(in_array($dimension, WasteReports::DIMENSIONS, true), 422);
        $labels = ['item' => 'Item', 'reason' => 'Alasan', 'person' => 'Pencatat', 'day' => 'Hari'];
        $sub = 'Periode '.$this->rangeLabel($from, $to);
        $s = $waste->summary($from, $to);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Waste {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Waste per {$labels[$dimension]}", $sub, [$labels[$dimension], 'Kejadian', 'Nilai (Rp)', '% dari total'], array_map(fn ($r) => [$r->label, $r->entries, $r->cost, $r->share_pct], $waste->by($dimension, $from, $to))),
            new ReportSheet('Rincian', $sub, ['Waktu', 'Item', 'Jumlah', 'Satuan', 'Alasan', 'Catatan', 'Nilai (Rp)', 'Pencatat'],
                array_map(fn ($e) => [$e['at'], $e['label'], $e['qty'], $e['unit'], $e['reason'], $e['note'] ?? '', $e['cost'], $e['user'] ?? '-'], $waste->entries($from, $to, 5000))),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [['Total nilai terbuang', $s['cost']], ['Jumlah kejadian', $s['entries']], ['Item terbanyak', $s['top_item'] ?? '-'], ['Alasan terbanyak', $s['top_reason'] ?? '-']]),
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
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'dimension' => 'nullable|in:'.implode(',', array_unique([...SalesReports::DIMENSIONS, ...KitchenReports::DIMENSIONS, ...MarginReports::DIMENSIONS, ...WasteReports::DIMENSIONS]))]);

        return [
            $request->input('from', CarbonImmutable::parse(BusinessDate::for())->subDays(29)->toDateString()),
            $request->input('to', BusinessDate::for()),
            $request->input('dimension', 'day'),
        ];
    }
}
