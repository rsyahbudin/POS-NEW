<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Station;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Notification\Notifier;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** When stock is held per station and counting is a duty, tells the station's staff and the managers about a station that has not sent its count in time. */
class RemindStockCounts extends Command
{
    protected $signature = 'stock:remind-opname';

    protected $description = 'Notify when a station has not sent its stock count by the deadline';

    public function handle(OutletContext $outlet, SettingsRepository $settings, Notifier $notifier): int
    {
        $frequency = (string) $settings->get('stock.opname_frequency', 'off');
        if ($frequency === 'off' || ! (bool) $settings->get('stock.by_station', false)) {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('Asia/Jakarta');
        [$hour, $minute] = array_map('intval', explode(':', (string) $settings->get('stock.opname_deadline', '22:00')) + [1 => 0]);
        $start = $now->startOfDay();
        if ($frequency === 'weekly') {
            $start = $start->subDays((($now->dayOfWeekIso - (int) $settings->get('stock.opname_weekday', 1)) + 7) % 7);
        }
        if ($now < $start->setTime($hour, $minute)) {
            return self::SUCCESS;
        }

        $outletId = $outlet->id();
        $period = $start->format('Y-m-d');
        $since = $start->setTimezone((string) config('app.timezone'));
        $late = 0;

        foreach (Station::query()->where('outlet_id', $outletId)->where('is_active', true)->with(['users:id'])->get() as $station) {
            $ingredients = $station->ingredientIds();
            if ($ingredients === [] || $station->users->isEmpty()) {
                continue;
            }
            $key = "opname:{$station->id}:{$period}";
            $done = StockCount::query()->where('outlet_id', $outletId)->where('status', '!=', 'draft')->where('submitted_at', '>=', $since)
                ->whereHas('lines', fn ($q) => $q->whereIn('ingredient_id', $ingredients))->exists();
            if ($done) {
                $notifier->resolve($outletId, $key);
                foreach ($station->users as $user) {
                    $notifier->resolve($outletId, "{$key}:{$user->id}");
                }

                continue;
            }

            $late++;
            $title = "Stok opname {$station->name} belum dikirim";
            $body = 'Batasnya sudah lewat. Hitung bahan station ini dan kirim hasilnya.';
            foreach ($station->users as $user) {
                $notifier->notify($outletId, 'stock.count_late', $title, $body, '/stock/count', 'warning', null, $user->id, "{$key}:{$user->id}");
            }
            $notifier->notify($outletId, 'stock.count_late', $title, "Staf {$station->name} belum mengirim hitungan stok bahannya.", '/stock/count', 'warning', ['manage-inventory'], null, $key);
        }
        $this->info("Station terlambat opname: {$late}.");

        return self::SUCCESS;
    }
}
