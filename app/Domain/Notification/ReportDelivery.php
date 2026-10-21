<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Jobs\DeliverMessageJob;
use App\Domain\Payment\Models\Payment;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Domain\Shift\ZReportSummary;
use App\Models\User;
use App\Support\Money;

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
     * @return list<string> channels used
     */
    public function deliver(string $subject, string $text, ?array $only = null): array
    {
        $channels = array_values(array_filter($this->configured(), fn ($c) => $only === null || in_array($c, $only, true)));
        foreach ($channels as $channel) {
            DeliverMessageJob::dispatch($channel, $subject, $text);
        }

        return $channels;
    }

    public function dailyText(BusinessDay $day): string
    {
        return $this->dailyTextFor($day->date->format('d M Y'), ZReportSummary::fromArray($day->summary));
    }

    public function dailyTextFor(string $label, ZReportSummary $s): string
    {
        $m = fn (int $v) => Money::formatRupiah($v);
        $lines = ["Laporan harian {$label}", "Order: {$s->orders}", "Omzet kotor: {$m($s->gross)}", "Diskon: {$m($s->discounts)}", "Refund: {$m($s->refunds)}", "Omzet bersih: {$m($s->net)}"];
        foreach ($s->byMethod as $name => $total) {
            $lines[] = "  {$name}: {$m($total)}";
        }
        $lines[] = "Void: {$s->voidItems} item ({$m($s->voidValue)})";
        $lines[] = "Komplimen/staff meal: {$s->compItems} item ({$m($s->compValue)})";
        $lines[] = "Selisih kas: {$m($s->cashDifference)}";

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
