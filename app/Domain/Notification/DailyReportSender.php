<?php

namespace App\Domain\Notification;

use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\ZReport;
use App\Domain\Shift\ZReportSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Sends the daily report of a business date. It can be triggered by the last shift closing and again when the day
 * closes; the same numbers are never sent twice, but a changed total (a shift reopened and sold more) is sent again.
 */
class DailyReportSender
{
    public function __construct(private SettingsRepository $settings, private ReportDelivery $delivery, private ZReport $report, private OutletContext $outlet) {}

    /**
     * @param  ZReportSummary|null  $summary  the saved Z-report of a closed day; built from live data when not given
     * @return list<string> channels used, empty when nothing was sent
     */
    public function send(string $date, bool $force = false, ?ZReportSummary $summary = null): array
    {
        if (! $force && ! (bool) $this->settings->get('report.send_daily', true)) {
            return [];
        }

        $summary ??= ZReportSummary::fromArray($this->report->build($date));
        $signature = implode('-', [$summary->orders, $summary->net, $summary->voidItems, $summary->compItems, $summary->cashDifference]);
        $key = "daily-report:{$this->outlet->id()}:{$date}";
        $before = Cache::get($key);
        if (! $force && $before === $signature) {
            return [];
        }

        $label = CarbonImmutable::parse($date)->format('d M Y');
        $subject = 'Laporan harian '.$label.($before !== null ? ' (diperbarui)' : '');
        $channels = $this->delivery->deliver($subject, $this->delivery->dailyTextFor($label, $summary, $date), null, $this->delivery->dailyHtml($date, $summary), $this->delivery->dailyPdfFile($date, $summary));
        if ($channels !== []) {
            Cache::put($key, $signature, now()->addDays(7));
        }

        return $channels;
    }
}
