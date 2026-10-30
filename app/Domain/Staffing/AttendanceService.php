<?php

namespace App\Domain\Staffing;

use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A scanned face clocks the person in, or out when they are already clocked in. */
class AttendanceService
{
    /** The same person scanning again within this many seconds is ignored (they are still standing at the camera). */
    public const REPEAT_SECONDS = 60;

    /** "Bukan saya" may undo a scan for this many seconds. */
    public const UNDO_SECONDS = 120;

    /** An open record older than this was a forgotten clock-out: a new scan starts a new day instead of closing it. */
    public const STALE_HOURS = 18;

    public function __construct(private FaceMatcher $matcher, private LongshiftService $longshift) {}

    /**
     * @param  list<float>  $descriptor
     * @return array{status: 'no_match'}|array{status: 'in'|'out'|'repeat', attendance: Attendance, user: User}
     */
    public function scan(int $outletId, array $descriptor, ?Carbon $now = null): array
    {
        $now ??= now();
        $hit = $this->matcher->match($outletId, $descriptor);
        if ($hit === null) {
            return ['status' => 'no_match'];
        }
        $user = $hit['user'];

        return DB::transaction(function () use ($outletId, $user, $hit, $now) {
            $latest = Attendance::query()->where('outlet_id', $outletId)->where('user_id', $user->id)->lockForUpdate()->latest('clock_in_at')->first();

            if ($latest !== null) {
                $last = $latest->clock_out_at ?? $latest->clock_in_at;
                if ($last->diffInSeconds($now, true) < self::REPEAT_SECONDS) {
                    return ['status' => 'repeat', 'attendance' => $latest, 'user' => $user];
                }
                if ($latest->clock_out_at === null && $latest->clock_in_at->diffInHours($now, true) < self::STALE_HOURS) {
                    $latest->update(['clock_out_at' => $now, 'method_out' => 'face']);
                    $this->longshift->syncAttendanceDay($outletId, $user->id, $latest->work_date);

                    return ['status' => 'out', 'attendance' => $latest, 'user' => $user];
                }
            }

            $in = Attendance::create([
                'outlet_id' => $outletId, 'user_id' => $user->id, 'work_date' => $now->toDateString(), 'clock_in_at' => $now, 'method_in' => 'face', 'distance' => $hit['distance'],
            ]);

            return ['status' => 'in', 'attendance' => $in, 'user' => $user];
        });
    }

    /** Takes back the scan that was just made (the wrong person was recognised). Returns false when it is too late. */
    public function undo(int $outletId, int $attendanceId, string $action, ?Carbon $now = null): bool
    {
        $now ??= now();

        return DB::transaction(function () use ($outletId, $attendanceId, $action, $now) {
            $a = Attendance::query()->where('outlet_id', $outletId)->lockForUpdate()->find($attendanceId);
            if ($a === null || $a->method_in !== 'face') {
                return false;
            }
            if ($action === 'in' && $a->clock_out_at === null && $a->clock_in_at->diffInSeconds($now, true) <= self::UNDO_SECONDS) {
                Audit::log('attendance.undone', $a, ['user_id' => $a->user_id, 'action' => 'in']);
                $a->delete();
                $this->longshift->syncAttendanceDay($outletId, $a->user_id, $a->work_date);

                return true;
            }
            if ($action === 'out' && $a->clock_out_at !== null && $a->method_out === 'face' && $a->clock_out_at->diffInSeconds($now, true) <= self::UNDO_SECONDS) {
                Audit::log('attendance.undone', $a, ['user_id' => $a->user_id, 'action' => 'out']);
                $a->update(['clock_out_at' => null, 'method_out' => null]);
                $this->longshift->syncAttendanceDay($outletId, $a->user_id, $a->work_date);

                return true;
            }

            return false;
        });
    }

    /**
     * Minutes late against the first shift planned that day (0 when on time or within the grace, null when nothing was planned).
     */
    public function lateMinutes(Attendance $a, int $graceMinutes): ?int
    {
        $first = WorkSchedule::query()->where('user_id', $a->user_id)->whereDate('work_date', $a->work_date)->with('type')->get()
            ->map(fn (WorkSchedule $s) => $s->type->startMinute())->min();
        if ($first === null) {
            return null;
        }
        $in = (int) $a->clock_in_at->format('G') * 60 + (int) $a->clock_in_at->format('i');
        $late = $in - $first;

        return $late > $graceMinutes ? $late : 0;
    }
}
