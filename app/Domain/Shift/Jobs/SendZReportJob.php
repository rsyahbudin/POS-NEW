<?php

namespace App\Domain\Shift\Jobs;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\ZReportSummary;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** Sends the Z-report to the owner's Telegram chat (skipped when not configured). */
class SendZReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $businessDayId) {}

    public function handle(SettingsRepository $settings): void
    {
        $token = $settings->get('telegram.bot_token');
        $chat = $settings->get('telegram.chat_id');
        if (! $token || ! $chat) {
            return;
        }

        $day = BusinessDay::findOrFail($this->businessDayId);
        $s = ZReportSummary::fromArray($day->summary);
        $m = fn (int $v) => Money::formatRupiah($v);

        $lines = [
            "Z-Report {$day->date->format('d M Y')}",
            "Order: {$s->orders}",
            "Omzet kotor: {$m($s->gross)}",
            "Diskon: {$m($s->discounts)}",
            "Refund: {$m($s->refunds)}",
            "Omzet bersih: {$m($s->net)}",
        ];
        foreach ($s->byMethod as $name => $total) {
            $lines[] = "  {$name}: {$m($total)}";
        }
        $lines[] = "Void: {$s->voidItems} item ({$m($s->voidValue)})";
        $lines[] = "Komplimen/staff meal: {$s->compItems} item ({$m($s->compValue)})";
        $lines[] = "Selisih kas: {$m($s->cashDifference)}";

        Http::post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => implode("\n", $lines)])->throw();
    }
}
