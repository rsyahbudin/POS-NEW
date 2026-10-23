<?php

namespace App\Domain\Reporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request for "all receipts of a month as a PDF". The file is built in the background and kept for a while.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int|null $user_id
 * @property int $year
 * @property int $month
 * @property string $status
 * @property int $receipts
 * @property int $total
 * @property int $size
 * @property string|null $path
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class ReceiptExport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filename(): string
    {
        return sprintf('struk-%04d-%02d.pdf', $this->year, $this->month);
    }
}
