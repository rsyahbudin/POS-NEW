<?php

namespace App\Domain\Payment\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Payment\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property PaymentMethodType $type
 * @property int $account_id
 * @property bool $is_active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class PaymentMethod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => PaymentMethodType::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
