<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;

/**
 * Which ingredients a person counts and buys. With "stock per station" on, staff only handle the ingredients of the
 * stations they work for; whoever holds "Stok semua station" (managers, the owner) handles all of them.
 */
class StockScope
{
    public function __construct(private SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('stock.by_station', false);
    }

    /** @return list<int>|null the ingredient ids this person may handle, or null for no limit */
    public function ingredientIds(User $user, int $outletId): ?array
    {
        if (! $this->enabled() || $user->can('stock-all-stations')) {
            return null;
        }

        $stations = $user->stations()->pluck('stations.id');

        return array_values(Ingredient::query()->where('outlet_id', $outletId)
            ->whereHas('stations', fn ($q) => $q->whereIn('stations.id', $stations))
            ->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** @return list<string> */
    public function stationNames(User $user): array
    {
        return array_values($user->stations()->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all());
    }
}
