<?php

namespace App\Domain\Staffing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person working one shift type on one date. A person may have up to three on the same day.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property int $work_shift_type_id
 * @property int|null $created_by
 * @property-read WorkShiftType $type
 */
class WorkSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    /** @return BelongsTo<WorkShiftType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(WorkShiftType::class, 'work_shift_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
