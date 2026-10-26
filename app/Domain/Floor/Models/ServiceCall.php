<?php

namespace App\Domain\Floor\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A guest pressed "Panggil waiter" on the table's QR page. One open call per table; pressing again re-rings it.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $table_id
 * @property string $reason
 * @property string $status
 * @property int $repeats
 * @property Carbon|null $last_rang_at
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property-read Table $table
 * @property-read User|null $handler
 */
class ServiceCall extends Model
{
    public const REASONS = ['help' => 'Butuh bantuan', 'bill' => 'Minta bill', 'water' => 'Minta air / tambahan', 'other' => 'Lainnya'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_rang_at' => 'datetime', 'handled_at' => 'datetime'];
    }

    /** @return BelongsTo<Table, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? self::REASONS['help'];
    }
}
