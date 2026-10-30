<?php

namespace App\Console\Commands;

use App\Domain\Expense\FixedExpenseService;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Makes this month's (and, near month end, next month's) fixed-cost bills, then reminds about the ones falling due. */
class RemindFixedExpenses extends Command
{
    protected $signature = 'fixed-expenses:remind';

    protected $description = 'Create monthly fixed-cost bills and notify about bills that are due soon or late';

    public function handle(OutletContext $outlet, SettingsRepository $settings, FixedExpenseService $service): int
    {
        $outletId = $outlet->id();
        $today = CarbonImmutable::today();
        $made = $service->generate($outletId, $today);
        if ($today->day >= 20) {
            $made += $service->generate($outletId, $today->addMonthNoOverflow());
        }
        $reminded = (bool) $settings->get('fixed_expense.remind', false) ? $service->remind($outletId, max(0, (int) $settings->get('fixed_expense.remind_days', 3))) : 0;
        $this->info("Tagihan baru: {$made}. Pengingat: {$reminded}.");

        return self::SUCCESS;
    }
}
