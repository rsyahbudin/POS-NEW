<?php

namespace App\Domain\Shift\Actions;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Jobs\SendZReportJob;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ZReport;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class CloseBusinessDayAction
{
    public function __construct(private ZReport $report, private OutletContext $outlet) {}

    /** @param  User|null  $user  null when the system closes the day by itself */
    public function handle(?User $user, string $date): BusinessDay
    {
        return DB::transaction(function () use ($user, $date) {
            $outletId = $this->outlet->id();
            $day = BusinessDay::query()->firstOrCreate(['outlet_id' => $outletId, 'date' => $date]);
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);
            if ($day->closed_at) {
                throw new OrderException('Hari bisnis ini sudah ditutup.');
            }
            if (Shift::query()->where('outlet_id', $outletId)->whereNull('closed_at')->where('business_date', '<=', $date)->exists()) {
                throw new OrderException('Masih ada shift yang terbuka. Tutup semua shift dulu.');
            }

            $day->update(['closed_at' => now(), 'closed_by' => $user?->id, 'summary' => $this->report->build($date)]);
            Audit::log('day.closed', $day, ['date' => $date], $user);
            SendZReportJob::dispatch($day->id)->afterCommit();

            return $day;
        });
    }
}
