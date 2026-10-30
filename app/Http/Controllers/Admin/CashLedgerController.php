<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Support\BusinessDate;
use App\Support\MediaUrl;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Cash drawer report: for each shift what went in and out of the drawer, and what was spent on what. */
class CashLedgerController extends Controller
{
    public function __construct(private OutletContext $outlet, private ShiftCash $cash) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('admin/cash-ledger', ['filters' => compact('from', 'to')] + $this->data($from, $to));
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $d = $this->data($from, $to);
        $range = CarbonImmutable::parse($from)->translatedFormat('d M Y').' s/d '.CarbonImmutable::parse($to)->translatedFormat('d M Y');
        $t = $d['totals'];

        $shifts = array_map(fn (array $s) => [
            CarbonImmutable::parse($s['business_date'])->translatedFormat('d M Y'), $s['opened_by'] ?? '', $s['opening'], $s['cash_sales'], $s['cash_in'], $s['cash_out'], $s['cash_refunds'],
            $s['expected'], $s['counted'] ?? '', $s['difference'] ?? '', $s['deposited'] ?? '',
        ], $d['shifts']);
        $moves = [];
        foreach ($d['shifts'] as $s) {
            foreach ($s['movements'] as $m) {
                $moves[] = [
                    CarbonImmutable::parse($s['business_date'])->translatedFormat('d M Y'), CarbonImmutable::parse($m['at'])->format('H:i'), $m['direction'] === 'in' ? 'Masuk' : 'Keluar',
                    $m['category'] ?? '', $m['reason'], $m['by'] ?? '', $m['receipt'] ? 'Ada' : '', $m['direction'] === 'in' ? $m['amount'] : -$m['amount'],
                ];
            }
        }

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Laporan kas laci {$from} {$to}", [
            new ReportSheet('Ringkasan', "Periode {$range}", ['Keterangan', 'Nominal (Rp)'], [
                ['Modal awal laci', $t['opening']], ['Penjualan tunai', $t['cash_sales']], ['Kas masuk', $t['cash_in']], ['Kas keluar', -$t['cash_out']],
                ['Refund tunai', -$t['cash_refunds']], ['Kas seharusnya di laci', $t['expected']], ['Selisih hitung kas', $t['difference']],
            ]),
            new ReportSheet('Per shift', "Periode {$range}", ['Tanggal', 'Dibuka oleh', 'Modal awal', 'Penjualan tunai', 'Kas masuk', 'Kas keluar', 'Refund tunai', 'Seharusnya', 'Dihitung', 'Selisih', 'Disetor'], $shifts),
            new ReportSheet('Kas masuk keluar', "Periode {$range}", ['Tanggal', 'Jam', 'Arah', 'Kategori', 'Keterangan', 'Dicatat oleh', 'Nota', 'Nominal (Rp)'], $moves, ['Total', '', '', '', '', '', '', $t['cash_in'] - $t['cash_out']]),
            new ReportSheet('Belanja per kategori', "Periode {$range}", ['Kategori', 'Jumlah', 'Total (Rp)'], array_map(fn ($c) => [$c['name'], $c['count'], $c['total']], $d['by_category']), ['Total', array_sum(array_column($d['by_category'], 'count')), $t['cash_out']]),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return [(string) $request->input('from', date('Y-m-01')), (string) $request->input('to', BusinessDate::for())];
    }

    /** @return array<string, mixed> */
    private function data(string $from, string $to): array
    {
        $shifts = Shift::query()->where('outlet_id', $this->outlet->id())->whereBetween('business_date', [$from, $to])
            ->with(['user:id,name', 'movements' => fn ($q) => $q->with(['creator:id,name', 'category:id,name'])->oldest()])->orderBy('opened_at')->get();

        $rows = [];
        $byCategory = [];
        $totals = ['opening' => 0, 'cash_sales' => 0, 'cash_in' => 0, 'cash_out' => 0, 'cash_refunds' => 0, 'expected' => 0, 'difference' => 0];
        foreach ($shifts as $shift) {
            $sum = $this->cash->summarize($shift);
            foreach (['opening', 'cash_sales', 'cash_in', 'cash_out', 'cash_refunds', 'expected'] as $k) {
                $totals[$k] += $sum[$k];
            }
            $totals['difference'] += (int) $shift->difference;
            $moves = [];
            foreach ($shift->movements as $m) {
                if ($m->direction === 'out') {
                    $name = $m->category->name ?? 'Tanpa kategori';
                    $byCategory[$name] = ['name' => $name, 'count' => ($byCategory[$name]['count'] ?? 0) + 1, 'total' => ($byCategory[$name]['total'] ?? 0) + $m->amount];
                }
                $moves[] = [
                    'id' => $m->id, 'direction' => $m->direction, 'amount' => $m->amount, 'reason' => $m->reason, 'category' => $m->category->name ?? null,
                    'by' => $m->creator?->name, 'at' => $m->created_at?->toIso8601String(), 'receipt' => $m->receipt_path ? MediaUrl::public($m->receipt_path) : null,
                ];
            }
            $rows[] = [
                'id' => $shift->id, 'business_date' => $shift->business_date->toDateString(), 'opened_by' => $shift->user?->name, 'open' => $shift->closed_at === null,
                'opening' => $sum['opening'], 'cash_sales' => $sum['cash_sales'], 'cash_in' => $sum['cash_in'], 'cash_out' => $sum['cash_out'], 'cash_refunds' => $sum['cash_refunds'],
                'expected' => $sum['expected'], 'counted' => $shift->counted_cash, 'difference' => $shift->difference, 'deposited' => $shift->cash_deposited,
                'movements' => $moves,
            ];
        }
        usort($byCategory, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['shifts' => $rows, 'totals' => $totals, 'by_category' => $byCategory];
    }
}
