<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Models\Supplier;
use App\Domain\Notification\ReportDelivery;
use App\Domain\Reporting\KitchenReports;
use App\Domain\Reporting\MarginReports;
use App\Domain\Reporting\PurchaseReports;
use App\Domain\Reporting\SalesDetailReport;
use App\Domain\Reporting\SalesReports;
use App\Domain\Reporting\WasteReports;
use App\Domain\Settings\OutletContext;
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
    public const DIMENSION_LABELS = ['day' => 'Hari', 'hour' => 'Jam', 'product' => 'Produk', 'category' => 'Kategori', 'method' => 'Metode bayar', 'cashier' => 'Pencatat order', 'receiver' => 'Penerima pembayaran', 'type' => 'Tipe order'];

    public function __construct(private SalesReports $reports) {}

    public function dashboard(Request $request): Response
    {
        $request->validate(['period' => 'nullable|in:today,yesterday,week,month,last_month,custom', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $today = CarbonImmutable::parse(BusinessDate::for());
        $period = (string) $request->input('period', 'today');
        [$from, $to] = match ($period) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->subDays(6), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse((string) $request->input('from', $today->toDateString())), CarbonImmutable::parse((string) $request->input('to', $today->toDateString()))],
            default => [$today, $today],
        };
        [$f, $t] = [$from->toDateString(), $to->toDateString()];
        $single = $f === $t;

        return Inertia::render('dashboard', [
            'period' => $period,
            'filters' => ['from' => $f, 'to' => $t],
            'overview' => $this->reports->overview($f, $t),
            'hourly' => $single ? $this->reports->by('hour', $f, $t) : [],
            'daily' => $this->dailySeries($single ? $from->subDays(13) : $from, $to),
            'topProducts' => $this->reports->by('product', $f, $t)->take(5)->values(),
            'methods' => $this->reports->by('method', $f, $t)->values(),
            'types' => $this->reports->by('type', $f, $t)->values(),
        ]);
    }

    /**
     * Net Sales for every day of the range, days without sales as 0, so a bar chart shows the real shape.
     *
     * @return list<array{label: string, display: string, net_sales: int}>
     */
    private function dailySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sold = $this->reports->by('day', $from->toDateString(), $to->toDateString())->keyBy('label');
        $series = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $series[] = ['label' => $d->toDateString(), 'display' => $d->translatedFormat('j M'), 'net_sales' => (int) ($sold[$d->toDateString()]->net_sales ?? 0)];
        }

        return $series;
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

    public function purchases(Request $request, PurchaseReports $purchases): Response
    {
        [$from, $to, $dimension, $status, $supplier] = $this->purchaseParams($request);

        return Inertia::render('admin/reports/purchases', [
            'filters' => ['from' => $from, 'to' => $to, 'dimension' => $dimension, 'status' => $status, 'supplier_id' => $supplier],
            'summary' => $purchases->summary($from, $to, $status, $supplier),
            'rows' => $purchases->by($dimension, $from, $to, $status, $supplier),
            'entries' => $purchases->entries($from, $to, $status, $supplier),
            'dimensions' => PurchaseReports::DIMENSIONS,
            'statuses' => PurchaseReports::STATUSES,
            'suppliers' => Supplier::query()->where('outlet_id', app(OutletContext::class)->id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function purchasesExport(Request $request, PurchaseReports $purchases): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to, $dimension, $status, $supplier] = $this->purchaseParams($request);
        $labels = ['ingredient' => 'Bahan', 'supplier' => 'Pemasok', 'day' => 'Hari', 'person' => 'Pencatat'];
        $sub = 'Periode '.$this->rangeLabel($from, $to).' · '.['approved' => 'disetujui', 'pending' => 'menunggu persetujuan', 'rejected' => 'ditolak', 'all' => 'semua status'][$status];
        $s = $purchases->summary($from, $to, $status, $supplier);
        $by = $purchases->by($dimension, $from, $to, $status, $supplier);
        $head = $dimension === 'ingredient'
            ? [$labels[$dimension], 'Jumlah beli', 'Satuan', 'Kali beli', 'Harga rata-rata/satuan (Rp)', 'Termurah (Rp)', 'Termahal (Rp)', 'Total (Rp)', '% dari total']
            : [$labels[$dimension], 'Jumlah nota', 'Total (Rp)', '% dari total'];
        $rows = array_map(fn ($r) => $dimension === 'ingredient'
            ? [$r->label, $r->qty, $r->unit, $r->notes, $r->average, $r->low, $r->high, $r->total, $r->share_pct]
            : [$this->pretty($dimension, $r->label), $r->notes, $r->total, $r->share_pct], $by);
        $total = (int) array_sum(array_map(fn ($r) => $r->total, $by));

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Belanja {$labels[$dimension]} {$from} {$to}", [
            new ReportSheet("Belanja per {$labels[$dimension]}", $sub, $head, $rows, $dimension === 'ingredient' ? ['Total', '', '', '', '', '', '', $total, ''] : ['Total', '', $total, '']),
            new ReportSheet('Rincian belanja', $sub, ['Tanggal', 'Pemasok', 'Bahan', 'Jumlah', 'Satuan', 'Harga/satuan (Rp)', 'Total (Rp)', 'Dicatat oleh', 'Status', 'Catatan'],
                array_map(fn ($e) => [$e['date'], $e['supplier'], $e['ingredient'], $e['qty'], $e['unit'], $e['unit_cost'], $e['total'], $e['user'] ?? '-', $e['status'], $e['note'] ?? ''], $purchases->entries($from, $to, $status, $supplier, 5000))),
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai'], [['Total belanja', $s['total']], ['Jumlah nota', $s['notes']], ['Jenis bahan dibeli', $s['ingredients']], ['Rata-rata per nota', $s['average']], ['Bahan termahal', $s['top_ingredient'] ?? '-'], ['Pemasok terbesar', $s['top_supplier'] ?? '-']]),
        ]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: int|null} */
    private function purchaseParams(Request $request): array
    {
        [$from, $to] = $this->params($request);
        $dimension = (string) $request->input('dimension', 'ingredient');
        $status = (string) $request->input('status', 'approved');
        abort_unless(in_array($dimension, PurchaseReports::DIMENSIONS, true) && in_array($status, PurchaseReports::STATUSES, true), 422);

        return [$from, $to, $dimension, $status, $request->integer('supplier_id') ?: null];
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
            $title = 'Diskon, Pembatalan, Refund';
            $sheets = $this->adjustmentSheets($from, $to, "Periode {$range}");

            return ReportFile::download($format, "{$title} {$from} {$to}", $sheets, $title);
        }

        $label = self::DIMENSION_LABELS[$dimension];
        $title = "Sales Recapitulation per {$label}";

        // The PDF follows the screen (one grouping); Excel is the whole pack so nothing needs a second download.
        if ($format === 'pdf') {
            return ReportFile::download($format, "Sales Recapitulation {$label} {$from} {$to}", [$this->salesSheet($dimension, $from, $to, $range), $this->summarySheet($from, $to, $range)], $title);
        }

        $sheets = [$this->summarySheet($from, $to, $range), $this->detailSheet($from, $to, $range)];
        foreach (array_keys(self::DIMENSION_LABELS) as $dim) {
            $sheets[] = $this->salesSheet($dim, $from, $to, $range);
        }
        $sheets[] = $this->transactionSheet($from, $to, $range);
        array_push($sheets, ...$this->adjustmentSheets($from, $to, "Periode {$range}"));
        $sheets[] = $this->expenseSheet($from, $to, $range);

        return ReportFile::download($format, "Sales Recapitulation {$from} {$to}", $sheets, 'Sales Recapitulation');
    }

    private function salesSheet(string $dimension, string $from, string $to, string $range): ReportSheet
    {
        $label = self::DIMENSION_LABELS[$dimension];
        $rows = $this->reports->by($dimension, $from, $to);
        $qtyDim = in_array($dimension, ['product', 'category'], true);
        $orders = (int) $rows->sum('orders');

        if (in_array($dimension, SalesReports::ORDER_DIMENSIONS, true)) {
            return new ReportSheet("Per {$label}", "Periode {$range}", [$label, 'Order', 'Gross Sales', 'Diskon', 'Net Sales', 'Pajak & service', 'Pembulatan', 'Refund', 'Total diterima', 'Net Sales/order'],
                $rows->map(fn ($r) => [$r->display, $r->orders, $r->subtotal, $r->discount, $r->net_sales, $r->charges, $r->rounding, $r->refunds, $r->total, $r->average])->all(),
                ['Total', $orders, (int) $rows->sum('subtotal'), (int) $rows->sum('discount'), (int) $rows->sum('net_sales'), (int) $rows->sum('charges'), (int) $rows->sum('rounding'), (int) $rows->sum('refunds'), (int) $rows->sum('total'),
                    $orders > 0 ? intdiv((int) $rows->sum('net_sales'), $orders) : 0]);
        }

        $net = (int) $rows->sum('total');

        return new ReportSheet("Per {$label}", "Periode {$range}", [$label, $qtyDim ? 'Qty' : 'Jumlah transaksi', 'Total (Rp)', '% dari total'],
            $rows->map(fn ($r) => [$r->display, $r->orders, $r->total, $net > 0 ? round($r->total / $net * 100, 1) : 0])->all(),
            ['Total', $orders, $net, $net > 0 ? 100 : 0]);
    }

    /** One row per sold item, so the whole recap (summary, groupings and per-item detail) lives in one Excel file. */
    private function detailSheet(string $from, string $to, string $range): ReportSheet
    {
        $data = app(SalesDetailReport::class)->build($from, $to);
        $rows = array_map(fn (array $line) => array_map(fn ($v) => $v instanceof CarbonImmutable ? $v->format('Y-m-d H:i:s') : $v, $line), $data['rows']);
        $heads = SalesDetailReport::HEADERS;
        $totals = array_fill(0, count($heads), '');
        $totals[0] = 'Total';
        foreach ($heads as $i => $head) {
            if (in_array($head, ['Qty', 'Subtotal', 'Discount', 'Service Charge', 'Tax', 'Total', 'Nett Sales', 'DPP', 'Bill Discount', 'Total After Bill Discount'], true)) {
                $totals[$i] = (int) array_sum(array_column($rows, $i));
            }
        }

        return new ReportSheet('Report', "Periode {$range}", $heads, $rows, $totals);
    }

    private function transactionSheet(string $from, string $to, string $range): ReportSheet
    {
        $rows = $this->reports->transactions($from, $to);
        $types = ['dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'online' => 'Online / Ojol'];
        $net = fn ($r) => (int) $r->grand_total - (int) $r->refunded_total;

        return new ReportSheet('Transaksi', "Periode {$range}", ['Tanggal', 'Jam', 'No. order', 'Tipe', 'Meja', 'Atas nama', 'Menu', 'Dicatat oleh', 'Diterima oleh', 'Metode bayar', 'Gross Sales', 'Diskon', 'Pajak & service', 'Pembulatan', 'Total ditagih', 'Refund', 'Total diterima', 'Status'],
            $rows->map(fn ($r) => [
                CarbonImmutable::parse($r->business_date)->translatedFormat('d M Y'),
                $r->closed_at ? CarbonImmutable::parse($r->closed_at)->setTimezone('Asia/Jakarta')->format('H:i') : '-',
                $r->number, $types[$r->type] ?? $r->type, $r->table_name ?? '-', $r->customer_name ?? '-', $r->items ?? '-', $r->recorder ?? '-', $r->receiver ?? '-', $r->methods ?? '-',
                (int) $r->subtotal, (int) $r->discount_total, (int) $r->charge_total, (int) $r->rounding, (int) $r->grand_total, (int) $r->refunded_total, $net($r),
                $r->status === 'refunded' ? 'Dikembalikan' : 'Lunas',
            ])->all(),
            ['Total', '', '', '', '', '', '', '', '', '', (int) $rows->sum('subtotal'), (int) $rows->sum('discount_total'), (int) $rows->sum('charge_total'), (int) $rows->sum('rounding'), (int) $rows->sum('grand_total'), (int) $rows->sum('refunded_total'), (int) $rows->sum($net), '']);
    }

    private function summarySheet(string $from, string $to, string $range): ReportSheet
    {
        $summary = $this->reports->summary($from, $to);
        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $prevTo = CarbonImmutable::parse($from)->subDay();
        $prev = $this->reports->summary($prevTo->subDays($days - 1)->toDateString(), $prevTo->toDateString());
        $change = fn (int $now, int $then) => $then > 0 ? round(($now - $then) / $then * 100, 1) : '-';
        $expenses = app(ReportDelivery::class)->expenses($from, $to);
        $methods = $this->reports->by('method', $from, $to);
        $hours = $this->reports->by('hour', $from, $to)->sortByDesc('orders')->first();
        $best = $this->reports->by('day', $from, $to)->sortByDesc('net_sales')->first();
        $top = $this->reports->by('product', $from, $to)->take(5);

        $perDay = intdiv($summary['net_sales'], max(1, (int) $days));
        $rows = [
            ['RINGKASAN', ''], ['Periode', $range], ['Jumlah hari', $days],
            ['Jumlah order', $summary['orders']], ['Net Sales (Rp)', $summary['net_sales']], ['Gross Sales (Rp)', $summary['gross_sales']],
            ['Item terjual', $summary['items']], ['Net Sales per order (Rp)', $summary['average']], ['Net Sales rata-rata per hari (Rp)', $perDay],
            ['', ''], ['DARI GROSS SALES KE TOTAL DITERIMA', ''],
            ['Gross Sales (Rp)', $summary['gross_sales']], ['Diskon (Rp)', -$summary['discounts']], ['Net Sales (Rp)', $summary['net_sales']],
            ['Pajak (Rp)', $summary['tax']], ['Service charge (Rp)', $summary['service']], ['Pembulatan (Rp)', $summary['rounding']],
            ['Total ditagih (Rp)', $summary['gross']], ['Refund (Rp)', -$summary['refunds']], ['Total diterima (Rp)', $summary['net']],
            ['', ''], ['DIBANDINGKAN '.$days.' HARI SEBELUMNYA', ''],
            ['Net Sales periode sebelumnya (Rp)', $prev['net_sales']], ['Growth Net Sales (%)', $change($summary['net_sales'], $prev['net_sales'])],
            ['Gross Sales periode sebelumnya (Rp)', $prev['gross_sales']], ['Growth Gross Sales (%)', $change($summary['gross_sales'], $prev['gross_sales'])],
            ['Order periode sebelumnya', $prev['orders']], ['Growth order (%)', $change($summary['orders'], $prev['orders'])],
            ['', ''], ['SOROTAN', ''],
            ['Hari terbaik', $best ? "{$best->display} · Rp ".number_format($best->net_sales, 0, ',', '.') : '-'],
            ['Jam tersibuk', $hours ? "{$hours->label} · {$hours->orders} order" : '-'],
        ];
        foreach ($top->values() as $i => $p) {
            $rows[] = ['Menu terlaris #'.($i + 1), "{$p->label} · {$p->orders} terjual · Rp ".number_format($p->total, 0, ',', '.')];
        }
        $rows[] = ['', ''];
        $rows[] = ['METODE BAYAR', ''];
        foreach ($methods as $m) {
            $rows[] = [$m->label.' (Rp)', $m->total];
        }
        $rows[] = ['', ''];
        $rows[] = ['PENGELUARAN KAS', ''];
        $rows[] = ['Total pengeluaran kas (Rp)', $expenses['total']];
        $rows[] = ['Jumlah catatan', count($expenses['items'])];

        return new ReportSheet('Ringkasan', "Periode {$range}", ['Keterangan', 'Nilai'], $rows);
    }

    /** @return list<ReportSheet> */
    private function adjustmentSheets(string $from, string $to, string $sub): array
    {
        $a = $this->reports->adjustments($from, $to);
        $sum = fn ($rows) => (int) $rows->sum(fn ($r) => (int) $r->amount);

        return [
            new ReportSheet('Diskon', $sub, ['Tanggal', 'Order', 'Jenis', 'Nilai', 'Potongan (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                $a['discounts']->map(fn ($r) => [$this->when($r), $r->number, self::discountKind($r->type), self::discountValue($r->type, (int) $r->value), (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                ['Total', '', '', '', $sum($a['discounts']), '', '', '']),
            new ReportSheet('Pembatalan', $sub, ['Tanggal', 'Order', 'Item', 'Qty', 'Nilai (Rp)', 'Alasan', 'Dilakukan oleh', 'Disetujui oleh'],
                $a['voids']->map(fn ($r) => [$this->when($r), $r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->reason, $r->actor ?? '-', $r->approver ?? '-'])->all(),
                ['Total', '', '', '', $sum($a['voids']), '', '', '']),
            new ReportSheet('Refund', $sub, ['Tanggal', 'Order', 'Nominal (Rp)', 'Alasan', 'Disetujui oleh'],
                $a['refunds']->map(fn ($r) => [$this->when($r), $r->number, (int) $r->amount, $r->reason, $r->approver ?? '-'])->all(),
                ['Total', '', $sum($a['refunds']), '', '']),
            new ReportSheet('Komplimen', $sub, ['Tanggal', 'Order', 'Item', 'Qty', 'Harga jual (Rp)', 'Jenis', 'Alasan', 'Disetujui oleh'],
                $a['comps']->map(fn ($r) => [$this->when($r), $r->number, $r->item, (int) $r->qty, (int) $r->amount, $r->type === 'staff_meal' ? 'Staff meal' : 'Komplimen', $r->reason, $r->approver ?? '-'])->all(),
                ['Total', '', '', '', $sum($a['comps']), '', '', '']),
        ];
    }

    private function expenseSheet(string $from, string $to, string $range): ReportSheet
    {
        $e = app(ReportDelivery::class)->expenses($from, $to);

        return new ReportSheet('Pengeluaran kas', "Periode {$range}", ['Tanggal', 'Sumber', 'Keterangan', 'Dicatat oleh', 'Nominal (Rp)'],
            array_map(fn ($i) => [CarbonImmutable::parse($i['date'])->translatedFormat('d M Y'), $i['source'], $i['label'], $i['by'] ?? '-', $i['amount']], $e['items']),
            ['Total', '', '', '', $e['total']]);
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
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'dimension' => 'nullable|in:'.implode(',', array_unique([...SalesReports::DIMENSIONS, ...KitchenReports::DIMENSIONS, ...MarginReports::DIMENSIONS, ...WasteReports::DIMENSIONS, ...PurchaseReports::DIMENSIONS]))]);

        return [
            $request->input('from', CarbonImmutable::parse(BusinessDate::for())->subDays(29)->toDateString()),
            $request->input('to', BusinessDate::for()),
            $request->input('dimension', 'day'),
        ];
    }
}
