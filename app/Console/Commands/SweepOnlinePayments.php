<?php

namespace App\Console\Commands;

use App\Domain\Payment\Online\OnlinePaymentService;
use Illuminate\Console\Command;

class SweepOnlinePayments extends Command
{
    protected $signature = 'qris:sweep';

    protected $description = 'Expire old QRIS codes, check the gateway for paid ones and book unapplied payments';

    public function handle(OnlinePaymentService $service): int
    {
        $this->info('Diperiksa: '.$service->sweep());

        return self::SUCCESS;
    }
}
