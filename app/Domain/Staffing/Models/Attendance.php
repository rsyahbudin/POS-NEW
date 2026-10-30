<?php

namespace App\Domain\Staffing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of work: clock in, then clock out. An open one (no clock out) means the person is at work, or forgot to clock out.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property string $method_in face or manual
 * @property string|null $method_out
 * @property float|null $distance
 * @property string|null $note
 * @property int|null $edited_by
 * @property-read User $user
 */
class Attendance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime', 'distance' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
