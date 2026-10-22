<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Accounting\Actions\SeedChartOfAccountsAction;
use App\Domain\Catalog\Models\Station;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Settings\Models\Outlet;

/** Creates the single outlet with its default chart of accounts, station and (inactive) tax rules. */
class SetupOutletAction
{
    public function __construct(private SeedChartOfAccountsAction $chart) {}

    public function handle(string $name, ?string $address = null): Outlet
    {
        $outlet = Outlet::firstOrCreate([], ['name' => $name, 'address' => $address, 'timezone' => 'Asia/Jakarta']);
        $this->chart->handle($outlet->id);

        Station::firstOrCreate(['outlet_id' => $outlet->id, 'name' => 'Dapur & Bar'], ['sort' => 1]);

        foreach (['Basi / busuk', 'Kadaluarsa', 'Tumpah', 'Rusak / pecah', 'Salah buat', 'Lainnya'] as $i => $reason) {
            WasteReason::firstOrCreate(['outlet_id' => $outlet->id, 'name' => $reason], ['sort' => $i]);
        }

        // All charges ship disabled: no tax at go-live (PRD 4.9).
        TaxRule::firstOrCreate(['outlet_id' => $outlet->id, 'name' => 'Service Charge'], ['kind' => 'service', 'rate_bp' => 500, 'sequence' => 1, 'base' => 'after_discount', 'is_active' => false]);
        TaxRule::firstOrCreate(['outlet_id' => $outlet->id, 'name' => 'PB1', 'kind' => 'tax'], ['rate_bp' => 1000, 'sequence' => 2, 'base' => 'cumulative', 'is_active' => false]);

        return $outlet;
    }
}
