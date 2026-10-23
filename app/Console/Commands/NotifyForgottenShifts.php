<?php

namespace App\Console\Commands;

use App\Domain\Notification\Notifier;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Models\Shift;
use Illuminate\Console\Command;

/** Tells the managers about a shift that has been open for more than half a day (probably forgotten, not a late night). */
class NotifyForgottenShifts extends Command
{
    protected $signature = 'shifts:remind-open';

    protected $description = 'Notify managers about shifts left open for more than 12 hours';

    public function handle(OutletContext $outlet, Notifier $notifier): int
    {
        $outletId = $outlet->id();
        $shifts = Shift::query()->where('outlet_id', $outletId)->whereNull('closed_at')->where('opened_at', '<', now()->subHours(12))->with('user:id,name')->get();
        foreach ($shifts as $shift) {
            $notifier->notify(
                $outletId, 'shift.forgotten', "Shift {$shift->user?->name} belum ditutup",
                'Dibuka '.$shift->opened_at->setTimezone('Asia/Jakarta')->translatedFormat('d M H:i').'. Tutup shift supaya kas dan laporan harian tercatat.',
                '/shift', 'warning', ['manage-shifts-any'], null, "shift.open:{$shift->id}",
            );
        }
        $this->info("Shift terlalu lama terbuka: {$shifts->count()}.");

        return self::SUCCESS;
    }
}
