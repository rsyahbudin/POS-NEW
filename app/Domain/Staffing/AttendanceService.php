<?php

namespace App\Domain\Staffing;

use App\Domain\Notification\Notifier;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** A person who names themselves and shows their own face is clocked in, or out when they are already clocked in. */
class AttendanceService
{
    /** The same person scanning again within this many seconds is ignored (they are still standing at the camera). */
    public const REPEAT_SECONDS = 60;

    /** "Bukan saya" may undo a scan for this many seconds. */
    public const UNDO_SECONDS = 120;

    /** An open record older than this was a forgotten clock-out: a new scan starts a new day instead of closing it. */
    public const STALE_HOURS = 18;

    /** Wrong faces in a row for one person before the kiosk stops trying for them, and for how long. */
    public const MAX_MISMATCHES = 5;

    public const LOCK_MINUTES = 5;

    /** From this many wrong faces on, the managers are told. */
    public const ALERT_AFTER = 3;

    public function __construct(private FaceMatcher $matcher, private LongshiftService $longshift, private Notifier $notifier) {}

    /**
     * The person says who they are (picks their name) and chooses Masuk or Pulang, then shows their face. The face must be
     * THAT person's: someone else's face, or a photo of it, books nobody, not even the person it belongs to.
     *
     * @param  'in'|'out'  $action
     * @param  list<float>  $descriptor
     * @param  array{lat: float|null, lng: float|null, distance: int|null}|null  $geo  where the kiosk was, kept with the record
     * @return array{status: 'unknown'}|array{status: 'locked'|'mismatch'|'ambiguous'|'not_enrolled', user: User, attempts_left?: int}|array{status: 'in'|'out'|'repeat'|'already_in'|'not_in', attendance: Attendance|null, user: User}
     */
    public function scan(int $outletId, int $userId, array $descriptor, string $action, ?Carbon $now = null, ?array $geo = null): array
    {
        $now ??= now();
        $user = User::query()->where('outlet_id', $outletId)->where('is_active', true)->find($userId);
        if ($user === null) {
            return ['status' => 'unknown'];
        }
        $key = "attendance.face_fail.{$outletId}.{$userId}";
        $fails = (int) Cache::get($key, 0);
        if ($fails >= self::MAX_MISMATCHES) {
            return ['status' => 'locked', 'user' => $user];
        }

        $hit = $this->matcher->verify($outletId, $userId, $descriptor);
        if (! $hit['ok']) {
            if ($hit['reason'] === 'not_enrolled') {
                return ['status' => 'not_enrolled', 'user' => $user];
            }
            $fails++;
            Cache::put($key, $fails, now()->addMinutes(self::LOCK_MINUTES));
            Audit::log('attendance.face_rejected', $user, ['user' => $user->name, 'action' => $action, 'reason' => $hit['reason'], 'distance' => $hit['distance'], 'attempt' => $fails]);
            if ($fails >= self::ALERT_AFTER) {
                $this->notifier->notify(
                    $outletId, 'attendance.face_rejected', "Absen atas nama {$user->name} ditolak ({$fails}x)", 'Wajah di kamera tidak cocok dengan wajah yang terdaftar. Bisa salah orang atau percobaan memakai wajah lain.',
                    '/attendance', 'warning', ['manage-attendance', 'approve-longshift'], null, "attendance.face_rejected:{$userId}:".$now->toDateString(),
                );
            }

            return ['status' => $hit['reason'] ?? 'mismatch', 'user' => $user, 'attempts_left' => max(0, self::MAX_MISMATCHES - $fails)];
        }
        Cache::forget($key);

        return DB::transaction(function () use ($outletId, $user, $hit, $action, $now, $geo) {
            $latest = Attendance::query()->where('outlet_id', $outletId)->where('user_id', $user->id)->lockForUpdate()->latest('clock_in_at')->first();
            $open = $latest !== null && $latest->clock_out_at === null && $latest->clock_in_at->diffInHours($now, true) < self::STALE_HOURS ? $latest : null;

            if ($latest !== null) {
                $last = $latest->clock_out_at ?? $latest->clock_in_at;
                $sameAction = $action === 'out' ? $latest->clock_out_at !== null : $open !== null;
                if ($sameAction && $last->diffInSeconds($now, true) < self::REPEAT_SECONDS) {
                    return ['status' => 'repeat', 'attendance' => $latest, 'user' => $user];
                }
            }

            if ($action === 'out') {
                if ($open === null) {
                    return ['status' => 'not_in', 'attendance' => $latest, 'user' => $user];
                }
                $open->update(['clock_out_at' => $now, 'method_out' => 'face', 'out_geo_distance_m' => $geo['distance'] ?? null]);
                $this->longshift->syncAttendanceDay($outletId, $user->id, $open->work_date);

                return ['status' => 'out', 'attendance' => $open, 'user' => $user];
            }

            if ($open !== null) {
                return ['status' => 'already_in', 'attendance' => $open, 'user' => $user];
            }
            // an older open record is a forgotten clock-out: it stays open for the manager to correct, and a new day starts
            $in = Attendance::create([
                'outlet_id' => $outletId, 'user_id' => $user->id, 'work_date' => $now->toDateString(), 'clock_in_at' => $now, 'method_in' => 'face', 'distance' => $hit['distance'],
                'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null, 'geo_distance_m' => $geo['distance'] ?? null,
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
