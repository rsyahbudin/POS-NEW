<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property Carbon $date
 * @property int $period_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $memo
 * @property bool $is_manual
 * @property int|null $created_by
 * @property Carbon $posted_at
 * @property int|null $reversed_by
 * @property int|null $reverses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'is_manual' => 'boolean'];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
