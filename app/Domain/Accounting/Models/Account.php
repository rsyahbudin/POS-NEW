<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string|null $system_key
 * @property bool $is_system
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Account extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }
}
