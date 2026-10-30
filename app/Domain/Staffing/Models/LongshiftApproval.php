<?php

namespace App\Domain\Staffing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whole hours worked (or planned) beyond the standard shift on one day, waiting for the manager on duty.
 * One row per person per day per source: the schedule (planned) and the attendance (really worked).
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $source schedule or attendance
 * @property int $hours
 * @property int|null $approved_hours
 * @property string $status pending, approved or rejected
 * @property int|null $attendance_id
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $note
 * @property-read User $user
 */
class LongshiftApproval extends Model
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
