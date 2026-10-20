<?php

namespace App\Domain\Floor\Models;

use App\Domain\Floor\Enums\TableStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $outlet_id
 * @property int $area_id
 * @property string $name
 * @property int $capacity
 * @property string $qr_token
 * @property bool $qr_enabled
 * @property TableStatus $status
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Area $area
 */
class Table extends Model
{
    use SoftDeletes;

    protected $table = 'tables';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Table $t) {
            $t->public_id ??= (string) Str::ulid();
            $t->qr_token ??= Str::random(32);
        });
    }

    protected function casts(): array
    {
        return ['status' => TableStatus::class, 'qr_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function regenerateToken(): void
    {
        $this->update(['qr_token' => Str::random(32)]);
    }
}
