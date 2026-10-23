<?php

namespace App\Domain\Shift\Jobs;

use App\Domain\Notification\DailyReportSender;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\ZReportSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends the daily report to every channel set up under Pengaturan > Notifikasi once the business day is closed. */
class SendZReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $businessDayId) {}

    public function handle(DailyReportSender $sender): void
    {
        $day = BusinessDay::findOrFail($this->businessDayId);
        $sender->send($day->date->toDateString(), false, ZReportSummary::fromArray($day->summary));
    }
}
