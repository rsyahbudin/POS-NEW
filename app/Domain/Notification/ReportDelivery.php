<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Jobs\DeliverMessageJob;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reporting\SalesReports;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Domain\Shift\ZReportSummary;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
     * @return list<string> channels used
     */
    public function deliver(string $subject, string $text, ?array $only = null, ?string $html = null): array
    {
        $channels = array_values(array_filter($this->configured(), fn ($c) => $only === null || in_array($c, $only, true)));
        foreach ($channels as $channel) {
            DeliverMessageJob::dispatch($channel, $subject, $text, $channel === 'email' ? $html : null);
        }

        return $channels;
    }

    /** The daily report as a designed email: headline, comparison, payment methods, best sellers, things to check. */
    public function dailyHtml(string $date, ZReportSummary $s): string
    {
        $sales = app(SalesReports::class);
        $day = CarbonImmutable::parse($date);

        return view('emails.daily-report', [
            'outlet' => (string) Outlet::query()->find(app(OutletContext::class)->id())?->name,
            'dateLabel' => $day->translatedFormat('l, d F Y'),
            's' => $s,
            'average' => $s->orders > 0 ? intdiv($s->net, $s->orders) : 0,
            'compare' => $sales->dashboard($date),
            'top' => $sales->by('product', $date, $date)->take(5)->map(fn ($r) => ['name' => $r->label, 'qty' => $r->orders, 'total' => $r->total])->values()->all(),
            'hours' => $this->hourly($sales->by('hour', $date, $date)),
            'week' => $this->week($sales, $day),
            'types' => $sales->by('type', $date, $date)->map(fn ($r) => ['name' => $r->display, 'orders' => $r->orders, 'total' => $r->total])->values()->all(),
            'cashiers' => $sales->by('cashier', $date, $date)->map(fn ($r) => ['name' => $r->label, 'orders' => $r->orders, 'total' => $r->total])->values()->all(),
            'url' => url("/admin/reports/sales?from={$date}&to={$date}"),
        ])->render();
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
     * @return list<array{name: string, orders: int, total: int, today: bool}>
     */
    private function week(SalesReports $sales, CarbonImmutable $day): array
    {
        $by = $sales->by('day', $day->subDays(6)->toDateString(), $day->toDateString())->keyBy('label');
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $day->subDays($i);
            $row = $by->get($d->toDateString());
            $out[] = ['name' => $d->translatedFormat('D, d M'), 'orders' => (int) ($row->orders ?? 0), 'total' => (int) ($row->total ?? 0), 'today' => $i === 0];
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
        $lines[] = "Void: {$s->voidItems} item ({$m($s->voidValue)})";
        $lines[] = "Komplimen/staff meal: {$s->compItems} item ({$m($s->compValue)})";
        $lines[] = "Selisih kas: {$m($s->cashDifference)}";
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
