<?php

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $kind
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string $level
 * @property array<int, string>|null $permissions
 * @property int|null $user_id
 * @property string|null $dedupe_key
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'resolved_at' => 'datetime'];
    }
}
