<?php

namespace App\Domain\Asset\Models;

use App\Domain\Notification\Alerts;
use App\Domain\Notification\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Equipment and fixtures of the outlet (machines, grinders, fridges, furniture ...).
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial
 * @property int $quantity
 * @property Carbon|null $purchased_on
 * @property int $purchase_price
 * @property string|null $location
 * @property string $condition
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Asset extends Model
{
    public const CONDITIONS = ['good' => 'Baik', 'needs_service' => 'Perlu servis', 'broken' => 'Rusak', 'retired' => 'Tidak dipakai / dijual'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchased_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::saved(function (Asset $a) {
            if ($a->wasRecentlyCreated || $a->wasChanged('condition')) {
                Notifier::later(fn () => Alerts::asset($a->refresh()));
            }
        });
    }

    /** @return HasMany<AssetLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(AssetLog::class);
    }
}
