<?php

namespace App\Console\Commands;

use App\Domain\Notification\Notifier;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Settings\OutletContext;
use Illuminate\Console\Command;

/** Warns when tickets for network printers wait too long: the print bridge is off, or cannot reach a printer. */
class WatchPrintQueue extends Command
{
    protected $signature = 'print:watch';

    protected $description = 'Notify staff when tickets for LAN printers are stuck in the queue';

    public function handle(OutletContext $outlet, Notifier $notifier): int
    {
        $outletId = $outlet->id();
        $stuck = PrintJob::query()->where('outlet_id', $outletId)->where('status', 'pending')->where('created_at', '<', now()->subSeconds(90))
            ->whereIn('printer_id', Printer::query()->whereNotNull('host')->select('id'))->count();

        if ($stuck === 0) {
            $notifier->resolve($outletId, 'print.stuck');
            $this->info('Antrean cetak lancar.');

            return self::SUCCESS;
        }

        $notifier->notify(
            $outletId, 'print.stuck', 'Cetakan tertahan: cek PC jembatan cetak',
            "{$stuck} cetakan belum keluar lebih dari 1,5 menit. Pastikan PC jembatan cetak menyala, terhubung internet, dan printer menyala.",
            '/admin/printers', 'danger', ['manage-printers', 'receive-payment', 'create-order'], null, 'print.stuck',
        );
        $this->warn("Cetakan tertahan: {$stuck}.");

        return self::SUCCESS;
    }
}
