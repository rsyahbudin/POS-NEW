<?php

namespace App\Domain\Notification;

use App\Domain\Expense\Models\Expense;
use App\Domain\Notification\Jobs\DeliverMessageJob;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reporting\SalesReports;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\CashMovement;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Domain\Shift\ZReportSummary;
use App\Exports\ReportSheet;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Sends reports and important alerts to the owner's phone or inbox: Telegram, email and WhatsApp (through a
 * gateway). A channel is used when it is filled in under Pengaturan > Notifikasi.
 */
class ReportDelivery
{
    public const CHANNELS = ['telegram' => 'Telegram', 'email' => 'Email', 'whatsapp' => 'WhatsApp'];

    public function __construct(private SettingsRepository $settings) {}

    /** @return list<string> */
    public function emails(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->settings->get('notify.email_to', '')) ?: []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @return list<string> digits only, country code 62 */
    public function whatsappTargets(): array
    {
        return array_values(array_filter(array_map(function (string $n) {
            $d = preg_replace('/\D/', '', $n) ?? '';

            return str_starts_with($d, '0') ? '62'.substr($d, 1) : $d;
        }, preg_split('/[,;\s]+/', (string) $this->settings->get('whatsapp.targets', '')) ?: []), fn ($d) => strlen($d) >= 9));
    }

    /**
     * Channels that are filled in.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        $out = [];
        if ($this->settings->get('telegram.bot_token') && $this->settings->get('telegram.chat_id')) {
            $out[] = 'telegram';
        }
        if ($this->emails() !== []) {
            $out[] = 'email';
        }
        if ($this->settings->get('whatsapp.token') && $this->whatsappTargets() !== []) {
            $out[] = 'whatsapp';
        }

        return $out;
    }

    /**
     * Queues the message on every configured channel (or only the given ones).
     *
     * @param  list<string>|null  $only
     * @param  string|null  $html  a designed version of the message, used by email (the plain text is its fallback)
     * @param  string|null  $pdfPath  a PDF of the report, attached to email and sent as a file on Telegram
     * @return list<string> channels used
     */
    public function deliver(string $subject, string $text, ?array $only = null, ?string $html = null, ?string $pdfPath = null): array
    {
        $channels = array_values(array_filter($this->configured(), fn ($c) => $only === null || in_array($c, $only, true)));
        foreach ($channels as $channel) {
            DeliverMessageJob::dispatch($channel, $subject, $text, $channel === 'email' ? $html : null, in_array($channel, ['email', 'telegram'], true) ? $pdfPath : null);
        }

        return $channels;
    }

    /** @return array<string, mixed> everything the daily report shows, shared by the email, the PDF and the text */
    public function dailyData(string $date, ZReportSummary $s): array
    {
        $sales = app(SalesReports::class);
        $day = CarbonImmutable::parse($date);
        $people = fn (string $dimension) => $sales->by($dimension, $date, $date)->map(fn ($r) => ['name' => $r->label, 'orders' => $r->orders, 'total' => $r->total])->values()->all();

        return [
            'outlet' => (string) Outlet::query()->find(app(OutletContext::class)->id())?->name,
            'dateLabel' => $day->translatedFormat('l, d F Y'),
            's' => $s,
            'average' => $s->orders > 0 ? intdiv($s->net, $s->orders) : 0,
            'compare' => $sales->dashboard($date),
            'top' => $sales->by('product', $date, $date)->take(5)->map(fn ($r) => ['name' => $r->label, 'qty' => $r->orders, 'total' => $r->total])->values()->all(),
            'hours' => $this->hourly($sales->by('hour', $date, $date)),
            'week' => $this->week($sales, $day),
            'types' => $sales->by('type', $date, $date)->map(fn ($r) => ['name' => $r->display, 'orders' => $r->orders, 'total' => $r->total])->values()->all(),
            'recorders' => $people('cashier'),
            'receivers' => $people('receiver'),
            'expenses' => $this->expenses($date),
            'weekLabel' => $day->translatedFormat('l, d M'),
            'url' => url("/admin/reports/sales?from={$date}&to={$date}"),
        ];
    }

    /** The daily report as a designed email: headline, comparison, hours, payment methods, best sellers, expenses, things to check. */
    public function dailyHtml(string $date, ZReportSummary $s): string
    {
        return view('emails.daily-report', $this->dailyData($date, $s))->render();
    }

    /**
     * Cash that left the business that day: taken out of the drawer during a shift, and expenses booked for the date.
     *
     * @return array{items: list<array{source: string, label: string, by: string|null, amount: int}>, total: int}
     */
    public function expenses(string $date): array
    {
        $outletId = app(OutletContext::class)->id();
        $items = [];
        CashMovement::query()->where('direction', 'out')->whereHas('shift', fn ($q) => $q->where('outlet_id', $outletId)->where('business_date', $date))->with('creator:id,name')->oldest()->get()
            ->each(function (CashMovement $m) use (&$items) {
                $items[] = ['source' => 'Kas laci', 'label' => $m->reason, 'by' => $m->creator?->name, 'amount' => $m->amount];
            });
        Expense::query()->where('outlet_id', $outletId)->where('date', $date)->with(['category:id,name', 'creator:id,name'])->oldest('id')->get()
            ->each(function (Expense $e) use (&$items) {
                $items[] = ['source' => 'Pengeluaran', 'label' => trim($e->category->name.($e->note ? " · {$e->note}" : '')), 'by' => $e->creator?->name, 'amount' => $e->amount];
            });

        return ['items' => $items, 'total' => (int) array_sum(array_column($items, 'amount'))];
    }

    /** Writes the daily PDF to disk (the queued jobs read it from there) and returns its path. */
    public function dailyPdfFile(string $date, ZReportSummary $s): string
    {
        $name = 'daily-reports/laporan-harian-'.$date.'.pdf';
        Storage::disk('local')->put($name, $this->dailyPdf($date, $s));

        return Storage::disk('local')->path($name);
    }

    /** The same report as a PDF (attached to the email and sent as a file on Telegram). */
    public function dailyPdf(string $date, ZReportSummary $s): string
    {
        $d = $this->dailyData($date, $s);
        $sub = $d['dateLabel'];
        $m = fn (int $v) => $v;
        $sheets = [
            new ReportSheet('Ringkasan', $sub, ['Keterangan', 'Nilai (Rp)'], [
                ['Jumlah order', $s->orders], ['Omzet kotor', $s->gross], ['Diskon', $s->discounts], ['Refund', $s->refunds], ['Penjualan bersih', $s->net],
                ['Rata-rata per order', $d['average']], ['Item dibatalkan', $s->voidItems], ['Nilai pembatalan', $s->voidValue], ['Komplimen / staff meal (item)', $s->compItems],
                ['Nilai komplimen', $s->compValue], ['Selisih kas', $s->cashDifference],
            ]),
            new ReportSheet('Penjualan per jam', 'Dihitung saat order selesai dibayar', ['Jam', 'Order', 'Penjualan (Rp)'],
                array_map(fn ($h) => ["{$h['hour']}:00–{$h['hour']}:59", $h['orders'], $h['total']], array_values(array_filter($d['hours'], fn ($h) => $h['orders'] > 0))),
                ['Total', (int) array_sum(array_column($d['hours'], 'orders')), (int) array_sum(array_column($d['hours'], 'total'))]),
            new ReportSheet('Per metode bayar', $sub, ['Metode', 'Nilai (Rp)'], array_map(fn ($n, $t) => [(string) $n, $m($t)], array_keys($s->byMethod), array_values($s->byMethod))),
            new ReportSheet('Menu terlaris', $sub, ['Menu', 'Qty', 'Nilai (Rp)'], array_map(fn ($p) => [$p['name'], $p['qty'], $p['total']], $d['top'])),
            new ReportSheet('Order dicatat oleh', $sub, ['Staf', 'Order', 'Nilai (Rp)'], array_map(fn ($p) => [$p['name'], $p['orders'], $p['total']], $d['recorders'])),
            new ReportSheet('Pembayaran diterima oleh', $sub, ['Staf', 'Order', 'Nilai (Rp)'], array_map(fn ($p) => [$p['name'], $p['orders'], $p['total']], $d['receivers'])),
            new ReportSheet('Pengeluaran kas', $sub, ['Sumber', 'Keterangan', 'Oleh', 'Nominal (Rp)'], array_map(fn ($e) => [$e['source'], $e['label'], $e['by'] ?? '-', $e['amount']], $d['expenses']['items']),
                ['Total', '', '', $d['expenses']['total']]),
            new ReportSheet('7 hari terakhir', $sub, ['Hari', 'Order', 'Penjualan (Rp)'], array_map(fn ($w) => [$w['name'].($w['tag'] ? " ({$w['tag']})" : ''), $w['orders'], $w['total']], $d['week'])),
        ];

        return Pdf::loadView('pdf.table', [
            'outlet' => $d['outlet'], 'sections' => array_map(fn (ReportSheet $x) => $x->section(), $sheets), 'title' => 'Laporan harian', 'subtitle' => $sub,
            'printedBy' => null, 'printedAt' => now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
        ])->setPaper('a4', 'portrait')->output();
    }

    /**
     * Every hour from the first to the last sale, so quiet hours in between show as gaps in the chart.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{hour: string, orders: int, total: int}>
     */
    private function hourly($rows): array
    {
        $by = $rows->keyBy(fn ($r) => (int) substr($r->label, 0, 2));
        if ($by->isEmpty()) {
            return [];
        }
        $out = [];
        for ($h = (int) $by->keys()->min(); $h <= (int) $by->keys()->max(); $h++) {
            $row = $by->get($h);
            $out[] = ['hour' => sprintf('%02d', $h), 'orders' => $row->orders ?? 0, 'total' => $row->total ?? 0];
        }

        return $out;
    }

    /**
     * The 7 days up to and including the report day; days without sales count as zero.
     *
     * @return list<array{name: string, tag: string|null, orders: int, total: int, today: bool}>
     */
    private function week(SalesReports $sales, CarbonImmutable $day): array
    {
        $by = $sales->by('day', $day->subDays(6)->toDateString(), $day->toDateString())->keyBy('label');
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $day->subDays($i);
            $row = $by->get($d->toDateString());
            $out[] = ['name' => $d->translatedFormat('D, d M'), 'tag' => $i === 0 && $d->isToday() ? 'hari ini' : null, 'orders' => (int) ($row->orders ?? 0), 'total' => (int) ($row->total ?? 0), 'today' => $i === 0];
        }

        return $out;
    }

    public function dailyTextFor(string $label, ZReportSummary $s, ?string $date = null): string
    {
        $m = fn (int $v) => Money::formatRupiah($v);
        $lines = ["Laporan harian {$label}", "Order: {$s->orders}", "Omzet kotor: {$m($s->gross)}", "Diskon: {$m($s->discounts)}", "Refund: {$m($s->refunds)}", "Omzet bersih: {$m($s->net)}"];
        foreach ($s->byMethod as $name => $total) {
            $lines[] = "  {$name}: {$m($total)}";
        }
        $lines[] = "Pembatalan: {$s->voidItems} item ({$m($s->voidValue)})";
        $lines[] = "Komplimen/staff meal: {$s->compItems} item ({$m($s->compValue)})";
        $lines[] = "Selisih kas: {$m($s->cashDifference)}";
        if ($date !== null) {
            $out = $this->expenses($date);
            if ($out['items'] !== []) {
                $lines[] = '';
                $lines[] = "Pengeluaran kas: {$m($out['total'])}";
                foreach ($out['items'] as $e) {
                    $lines[] = "  {$e['label']}: {$m($e['amount'])}".($e['by'] ? " ({$e['by']})" : '');
                }
            }
        }
        $hours = $date === null ? [] : $this->hourly(app(SalesReports::class)->by('hour', $date, $date));
        if ($hours !== []) {
            $lines[] = '';
            $lines[] = 'Penjualan per jam:';
            foreach ($hours as $h) {
                if ($h['orders'] > 0) {
                    $lines[] = "  {$h['hour']}:00  {$h['orders']} order  {$m($h['total'])}";
                }
            }
        }

        return implode("\n", $lines);
    }

    public function shiftText(Shift $shift): string
    {
        $m = fn (int $v) => Money::formatRupiah($v);
        $cash = app(ShiftCash::class)->summarize($shift);
        $by = Payment::query()->where('payments.shift_id', $shift->id)->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->selectRaw('payment_methods.name as name, SUM(payments.amount) as total')->groupBy('payment_methods.name')->pluck('total', 'name');
        $who = User::query()->whereKey($shift->user_id)->value('name');
        $closer = $shift->closed_by ? User::query()->whereKey($shift->closed_by)->value('name') : null;
        $at = fn ($t) => $t?->setTimezone('Asia/Jakarta')->translatedFormat('d M H:i');

        $lines = ["Laporan shift {$who}", 'Buka '.$at($shift->opened_at).' · tutup '.$at($shift->closed_at).($closer && $closer !== $who ? " (ditutup {$closer})" : ''), "Modal awal: {$m($shift->opening_cash)}"];
        foreach ($by as $name => $total) {
            $lines[] = "  {$name}: {$m((int) $total)}";
        }
        $lines[] = 'Total penjualan: '.$m((int) $by->sum());
        $lines[] = "Kas seharusnya: {$m($cash['expected'])}";
        $lines[] = 'Kas dihitung: '.$m((int) $shift->counted_cash);
        $lines[] = 'Selisih: '.$m((int) $shift->difference).($shift->difference_reason ? " ({$shift->difference_reason})" : '');

        return implode("\n", $lines);
    }
}
