<?php

namespace App\Domain\Staffing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person who forgot to clock in and/or out asks for the record to be put right. The manager on duty decides.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $kind missing_in, missing_out or both
 * @property string|null $clock_in HH:MM[:SS]
 * @property string|null $clock_out HH:MM[:SS]
 * @property string $reason
 * @property string $status pending, approved or rejected
 * @property int|null $attendance_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read User $user
 */
class AttendanceRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
