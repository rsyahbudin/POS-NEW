<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\DailyReportSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends the daily report of one business date right away (used when the last shift of the day closes). */
class SendDailyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $date) {}

    public function handle(DailyReportSender $sender): void
    {
        $sender->send($this->date);
    }
}
