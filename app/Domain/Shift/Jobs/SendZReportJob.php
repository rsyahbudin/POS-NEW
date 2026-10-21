<?php

namespace App\Domain\Shift\Jobs;

use App\Domain\Notification\ReportDelivery;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\BusinessDay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends the daily report to every channel set up under Pengaturan > Notifikasi. */
class SendZReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $businessDayId) {}

    public function handle(SettingsRepository $settings, ReportDelivery $delivery): void
    {
        if (! (bool) $settings->get('report.send_daily', true)) {
            return;
        }

        $day = BusinessDay::findOrFail($this->businessDayId);
        $delivery->deliver('Laporan harian '.$day->date->format('d M Y'), $delivery->dailyText($day));
    }
}
