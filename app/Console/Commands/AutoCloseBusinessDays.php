<?php

namespace App\Console\Commands;

use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Actions\CloseBusinessDayAction;
use App\Domain\Shift\Models\BusinessDay;
use App\Domain\Shift\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Closes past business days that nobody closed: builds the Z-report and sends it to Telegram. A day waits only
 * for its shifts; if one was left open, managers get a notification instead.
 */
class AutoCloseBusinessDays extends Command
{
    protected $signature = 'days:auto-close';

    protected $description = 'Close finished business days automatically (Z-report to Telegram)';

    public function handle(SettingsRepository $settings, OutletContext $outlet, CloseBusinessDayAction $close, Notifier $notifier): int
    {
        if (! (bool) $settings->get('operations.auto_close_day', true)) {
            return self::SUCCESS;
        }

        $outletId = $outlet->id();
        $today = CarbonImmutable::now()->setTimezone(config('app.timezone'))->toDateString();
        $since = CarbonImmutable::parse($today)->subDays(14)->toDateString();

        $dates = Order::query()->where('outlet_id', $outletId)->whereBetween('business_date', [$since, $today])->where('business_date', '<', $today)->distinct()->pluck('business_date')
            ->merge(Shift::query()->where('outlet_id', $outletId)->whereBetween('business_date', [$since, $today])->where('business_date', '<', $today)->distinct()->pluck('business_date'))
            ->map(fn ($d) => substr((string) $d, 0, 10))->unique()->sort()->values();
        $closed = BusinessDay::query()->where('outlet_id', $outletId)->whereNotNull('closed_at')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10));

        $done = 0;
        foreach ($dates->diff($closed) as $date) {
            $open = Shift::query()->where('outlet_id', $outletId)->whereNull('closed_at')->where('business_date', '<=', $date)->with('user:id,name')->get();
            if ($open->isNotEmpty()) {
                // A shift that runs past midnight is normal; it only counts as forgotten after half a day.
                foreach ($open->filter(fn ($s) => $s->opened_at->lt(now()->subHours(12))) as $shift) {
                    $notifier->notify(
                        $outletId, 'shift.forgotten', "Shift {$shift->user?->name} belum ditutup",
                        'Dibuka '.$shift->opened_at->setTimezone('Asia/Jakarta')->translatedFormat('d M H:i').'. Laporan hari itu menunggu shift ini ditutup.',
                        '/shift', 'warning', ['manage-shifts-any'], null, "shift.open:{$shift->id}",
                    );
                }

                continue;
            }

            try {
                $close->handle(null, $date);
                $done++;
            } catch (OrderException $e) {
                $this->warn("{$date}: {$e->getMessage()}");
            }
        }

        $this->info("Hari ditutup otomatis: {$done}.");

        return self::SUCCESS;
    }
}
