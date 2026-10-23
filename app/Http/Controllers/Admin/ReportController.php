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
            'detailed' => in_array($dimension, SalesReports::ORDER_DIMENSIONS, true),
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
                $rows->map(fn ($r) => [$this->pretty($dimension, $r->label), $r->items, $r->avg_minutes, $r->p90_minutes, $r->max_minutes])->all()),
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
        $by = $margin->by($dimension, $from, $to);
        $stock = $margin->stock();

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Margin {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Margin per {$labels[$dimension]}", $sub, [$labels[$dimension], 'Terjual', 'Penjualan', 'HPP', 'Laba kotor', 'Margin %'],
                $by->map(fn ($r) => [$this->pretty($dimension, $r->label), $r->qty, $r->revenue, $r->cogs, $r->profit, $r->margin_pct])->all(),
                ['Total', (int) $by->sum('qty'), (int) $by->sum('revenue'), (int) $by->sum('cogs'), (int) $by->sum('profit'),
                    (int) $by->sum('revenue') > 0 ? round((int) $by->sum('profit') / (int) $by->sum('revenue') * 100, 1) : 0.0]),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [
                ['Penjualan', $s['revenue']], ['HPP (biaya bahan)', $s['cogs']], ['Laba kotor', $s['profit']], ['Margin %', $s['margin_pct']], ['Porsi tanpa resep/HPP', $s['uncosted_qty']],
            ]),
            new ReportSheet('Stok bahan', 'Saat ini', ['Bahan', 'Satuan', 'Stok', 'Biaya rata-rata', 'Nilai stok', 'Batas minimum'],
                $stock->map(fn ($r) => [$r->name, $r->unit, $r->stock, $r->avg_cost, $r->value, $r->min_stock])->all(),
                ['Total nilai stok', '', '', '', (int) $stock->sum('value'), '']),
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
        $wasteBy = $waste->by($dimension, $from, $to);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Waste {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Waste per {$labels[$dimension]}", $sub, [$labels[$dimension], 'Kejadian', 'Nilai (Rp)', '% dari total'], array_map(fn ($r) => [$this->pretty($dimension, $r->label), $r->entries, $r->cost, $r->share_pct], $wasteBy),
                ['Total', (int) array_sum(array_map(fn ($r) => $r->entries, $wasteBy)), (int) array_sum(array_map(fn ($r) => $r->cost, $wasteBy)), '']),
            new ReportSheet('Rincian', $sub, ['Waktu', 'Item', 'Jumlah', 'Satuan', 'Alasan', 'Catatan', 'Nilai (Rp)', 'Pencatat'],
                array_map(fn ($e) => [$e['at'], $e['label'], $e['qty'], $e['unit'], $e['reason'], $e['note'] ?? '', $e['cost'], $e['user'] ?? '-'], $waste->entries($from, $to, 5000))),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [['Total nilai terbuang', $s['cost']], ['Jumlah kejadian', $s['entries']], ['Item terbanyak', $s['top_item'] ?? '-'], ['Alasan terbanyak', $s['top_reason'] ?? '-']]),
        ]);
    }

    public function adjustments(Request $request): Response
    {
        [$from, $to] = $this->params($request);

        $a = $this->reports->adjustments($from, $to);
        $a['discounts'] = $a['discounts']->map(function ($r) {
            $r->kind = self::discountKind($r->type);
            $r->value_label = self::discountValue($r->type, (int) $r->value);

            return $r;
        });

        return Inertia::render('admin/reports/adjustments', ['filters' => compact('from', 'to')] + $a);
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
                new ReportSheet('Diskon', $sub, ['Tanggal', 'Order', 'Jenis', 'Nilai', 'Potongan (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                    $a['discounts']->map(fn ($r) => [$this->when($r), $r->number, self::discountKind($r->type), self::discountValue($r->type, (int) $r->value), (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                    ['Total', '', '', '', $sum($a['discounts']), '', '', '']),
                new ReportSheet('Void', $sub, ['Tanggal', 'Order', 'Item', 'Qty', 'Nilai (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                    $a['voids']->map(fn ($r) => [$this->when($r), $r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                    ['Total', '', '', '', $sum($a['voids']), '', '', '']),
                new ReportSheet('Refund', $sub, ['Tanggal', 'Order', 'Nominal (Rp)', 'Alasan', 'Disetujui oleh'],
                    $a['refunds']->map(fn ($r) => [$this->when($r), $r->number, (int) $r->amount, $r->reason, $r->approver ?? '-'])->all(),
                    ['Total', '', $sum($a['refunds']), '', '']),
                new ReportSheet('Komplimen', $sub, ['Tanggal', 'Order', 'Item', 'Qty', 'Harga jual (Rp)', 'Jenis', 'Alasan', 'Disetujui oleh'],
                    $a['comps']->map(fn ($r) => [$this->when($r), $r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->type === 'staff_meal' ? 'Staff meal' : 'Komplimen', $r->reason, $r->approver ?? '-'])->all(),
                    ['Total', '', '', '', $sum($a['comps']), '', '', '']),
            ];

            return ReportFile::download($format, "{$title} {$from} {$to}", $sheets, $title);
        }

        $label = self::DIMENSION_LABELS[$dimension];
        $rows = $this->reports->by($dimension, $from, $to);
        $qtyDim = in_array($dimension, ['product', 'category'], true);
        $summary = $this->reports->summary($from, $to);
        $detailed = in_array($dimension, SalesReports::ORDER_DIMENSIONS, true);
        $sheets = [
            $detailed
                ? new ReportSheet("Penjualan {$label}", "Periode {$range}", [$label, 'Order', 'Subtotal menu', 'Diskon', 'Pajak & biaya', 'Pembulatan', 'Refund', 'Penjualan bersih', 'Rata-rata/order'],
                    $rows->map(fn ($r) => [$r->display, $r->orders, $r->subtotal, $r->discount, $r->charges, $r->rounding, $r->refunds, $r->total, $r->average])->all(),
                    ['Total', (int) $rows->sum('orders'), (int) $rows->sum('subtotal'), (int) $rows->sum('discount'), (int) $rows->sum('charges'), (int) $rows->sum('rounding'), (int) $rows->sum('refunds'), (int) $rows->sum('total'),
                        (int) $rows->sum('orders') > 0 ? intdiv((int) $rows->sum('total'), (int) $rows->sum('orders')) : 0])
                : new ReportSheet("Penjualan {$label}", "Periode {$range}", [$label, $qtyDim ? 'Qty' : 'Jumlah transaksi', 'Total (Rp)'],
                    $rows->map(fn ($r) => [$r->display, $r->orders, $r->total])->all(),
                    ['Total', (int) $rows->sum('orders'), (int) $rows->sum('total')]),
            new ReportSheet('Ringkasan', "Periode {$range}", ['Keterangan', 'Nilai'], [
                ['Jumlah order', $summary['orders']], ['Subtotal menu (Rp)', $summary['subtotal']], ['Diskon (Rp)', $summary['discounts']],
                ['Pajak & biaya layanan (Rp)', $summary['charges']], ['Pembulatan (Rp)', $summary['rounding']], ['Total ditagih (Rp)', $summary['gross']],
                ['Refund (Rp)', $summary['refunds']], ['Penjualan bersih (Rp)', $summary['net']], ['Rata-rata per order (Rp)', $summary['average']],
            ]),
        ];

        return ReportFile::download($format, "Laporan penjualan {$label} {$from} {$to}", $sheets, "Laporan penjualan per {$label}");
    }

    /** Group names for the file: a date reads as "Jum, 23 Okt 2026". */
    private function pretty(string $dimension, string $label): string
    {
        return $dimension === 'day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $label) === 1 ? CarbonImmutable::parse($label)->translatedFormat('D, d M Y') : $label;
    }

    private function when(\stdClass $r): string
    {
        if (! empty($r->at)) {
            return CarbonImmutable::parse($r->at)->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i');
        }

        return empty($r->date) ? '-' : CarbonImmutable::parse($r->date)->translatedFormat('d M Y');
    }

    public static function discountKind(string $type): string
    {
        return match ($type) {
            'percent' => 'Persen', 'fixed' => 'Nominal', default => 'Promo',
        };
    }

    public static function discountValue(string $type, int $value): string
    {
        return match ($type) {
            'percent' => rtrim(rtrim(number_format($value / 100, 2, ',', '.'), '0'), ',').'%',
            'fixed' => 'Rp '.number_format($value, 0, ',', '.'),
            default => '-',
        };
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
