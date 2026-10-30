<?php

namespace App\Domain\Staffing;

use App\Domain\Notification\Notifier;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;

/**
 * Longshift is time really worked beyond what was scheduled that day (or beyond one standard shift, 9 hours by default, on a day
 * with no schedule). Two scheduled shifts in a day are simply a longer plan, not a longshift. Whole hours only, and each
 * full standard shift of extra hours counts as one more shift. The manager on duty approves it.
 */
class LongshiftService
{
    public function __construct(private SettingsRepository $settings, private Notifier $notifier) {}

    public function standardMinutes(): int
    {
        return max(1, (int) $this->settings->get('schedule.standard_hours', 9)) * 60;
    }

    /** Whole hours worked beyond a baseline (the scheduled minutes, or the standard shift when nothing was scheduled). */
    public function extraHours(int $workedMinutes, ?int $baselineMinutes = null): int
    {
        return max(0, intdiv($workedMinutes - ($baselineMinutes ?: $this->standardMinutes()), 60));
    }

    /**
     * Longshift hours counted the way HR pays them: every full standard shift worth of extra hours (9 by default) is one more shift,
     * the rest stays as hours. 3 h = 3 hours; 9 h = 1 shift; 11 h = 1 shift + 2 hours.
     *
     * @return array{shifts: int, hours: int}
     */
    public function breakdown(int $extraHours): array
    {
        $standard = max(1, intdiv($this->standardMinutes(), 60));

        return ['shifts' => intdiv($extraHours, $standard), 'hours' => $extraHours % $standard];
    }

    /** Minutes of work the schedule covers for one person on one day, overlapping shifts counted once. */
    public function plannedMinutes(int $userId, CarbonInterface $date): int
    {
        return ShiftHours::unionMinutes(WorkSchedule::query()->where('user_id', $userId)->whereDate('work_date', $date)->with('type')->get()->unique('work_shift_type_id')->map(fn (WorkSchedule $s) => $s->type));
    }

    /**
     * Re-checks one person's day: the hours really worked (all clocked-out records added up) against what was scheduled.
     * Call it after a clock-out, a correction, or a change of that day's schedule.
     */
    public function syncAttendanceDay(int $outletId, int $userId, CarbonInterface $date): void
    {
        $records = Attendance::query()->where('user_id', $userId)->whereDate('work_date', $date)->whereNotNull('clock_out_at')->orderBy('clock_in_at')->get();
        $worked = (int) $records->sum(fn (Attendance $a) => $a->clock_in_at->diffInMinutes($a->clock_out_at, true));
        $planned = $this->plannedMinutes($userId, $date);
        $this->upsert($outletId, $userId, $date, $this->extraHours($worked, $planned), $records->last()?->id, $planned, $worked);
    }

    public function decide(LongshiftApproval $row, User $by, bool $approve, ?int $hours = null, ?string $note = null): void
    {
        $row->update([
            'status' => $approve ? 'approved' : 'rejected', 'approved_hours' => $approve ? min($row->hours, max(0, $hours ?? $row->hours)) : 0,
            'decided_by' => $by->id, 'decided_at' => now(), 'note' => $note,
        ]);
        Audit::log($approve ? 'longshift.approved' : 'longshift.rejected', $row, ['user_id' => $row->user_id, 'date' => $row->work_date->toDateString(), 'hours' => $row->approved_hours, 'source' => $row->source], $by);
        $this->notifier->resolve($row->outlet_id, "longshift:{$row->id}");
    }

    private function upsert(int $outletId, int $userId, CarbonInterface $date, int $hours, ?int $attendanceId, int $planned, int $worked): void
    {
        $row = LongshiftApproval::query()->where('user_id', $userId)->whereDate('work_date', $date)->where('source', 'attendance')->first();
        if ($hours === 0) {
            if ($row !== null) {
                $this->notifier->resolve($outletId, "longshift:{$row->id}");
                $row->delete();
            }

            return;
        }
        if ($row !== null && $row->hours === $hours) {
            $row->update(['attendance_id' => $attendanceId ?? $row->attendance_id, 'planned_minutes' => $planned, 'worked_minutes' => $worked]);

            return;
        }
        $row = LongshiftApproval::query()->updateOrCreate(
            ['user_id' => $userId, 'work_date' => $date->toDateString(), 'source' => 'attendance'],
            ['outlet_id' => $outletId, 'hours' => $hours, 'status' => 'pending', 'approved_hours' => null, 'decided_by' => null, 'decided_at' => null, 'note' => null, 'attendance_id' => $attendanceId, 'planned_minutes' => $planned, 'worked_minutes' => $worked],
        );
        $name = User::query()->whereKey($userId)->value('name');
        $this->notifier->notify(
            $outletId, 'longshift.pending', "Longshift {$name}: {$hours} jam menunggu persetujuan",
            'Bekerja '.round($worked / 60, 1).' jam, dijadwalkan '.($planned > 0 ? round($planned / 60, 1).' jam' : 'tidak ada').' · '.$date->translatedFormat('d M Y').'.', '/longshift', 'warning', ['approve-longshift'], null, "longshift:{$row->id}",
        );
    }
}
