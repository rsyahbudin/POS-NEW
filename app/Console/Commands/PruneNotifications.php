<?php

namespace App\Console\Commands;

use App\Domain\Notification\Notifier;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete old notifications so the bell stays fast (info after 7 days, anything after 30, resolved after 3)';

    public function handle(Notifier $notifier): int
    {
        $this->info("Dihapus: {$notifier->prune()} notifikasi.");

        return self::SUCCESS;
    }
}
