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
 * A standard shift is 9 hours (a setting). Whole hours beyond it are "longshift", counted per hour, and need the manager on
 * duty to approve them, both when planned in the schedule and when really worked in the attendance.
 */
class LongshiftService
{
    public function __construct(private SettingsRepository $settings, private Notifier $notifier) {}

    public function standardMinutes(): int
    {
        return max(1, (int) $this->settings->get('schedule.standard_hours', 9)) * 60;
    }

    /** Whole hours beyond the standard shift. */
    public function extraHours(int $minutes): int
    {
        return max(0, intdiv($minutes - $this->standardMinutes(), 60));
    }

    /** Re-checks the planned day of one person. Someone who may approve gets their own plan approved at once. */
    public function syncSchedule(int $outletId, int $userId, CarbonInterface $date, ?User $actor): void
    {
        $minutes = (int) WorkSchedule::query()->where('user_id', $userId)->whereDate('work_date', $date)->with('type')->get()
            ->unique('work_shift_type_id')->sum(fn (WorkSchedule $s) => $s->type->durationMinutes());
        $this->upsert($outletId, $userId, $date, 'schedule', $this->extraHours($minutes), null, $actor);
    }

    /** Re-checks the hours really worked by one person on one day (all clocked-out records of that day added up). */
    public function syncAttendanceDay(int $outletId, int $userId, CarbonInterface $date): void
    {
        $records = Attendance::query()->where('user_id', $userId)->whereDate('work_date', $date)->whereNotNull('clock_out_at')->orderBy('clock_in_at')->get();
        $minutes = (int) $records->sum(fn (Attendance $a) => $a->clock_in_at->diffInMinutes($a->clock_out_at, true));
        $this->upsert($outletId, $userId, $date, 'attendance', $this->extraHours($minutes), $records->last()?->id, null);
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

    private function upsert(int $outletId, int $userId, CarbonInterface $date, string $source, int $hours, ?int $attendanceId, ?User $actor): void
    {
        $row = LongshiftApproval::query()->where('user_id', $userId)->whereDate('work_date', $date)->where('source', $source)->first();
        if ($hours === 0) {
            if ($row !== null) {
                $this->notifier->resolve($outletId, "longshift:{$row->id}");
                $row->delete();
            }

            return;
        }
        if ($row !== null && $row->hours === $hours) {
            $row->update(['attendance_id' => $attendanceId ?? $row->attendance_id]);

            return;
        }
        $row = LongshiftApproval::query()->updateOrCreate(
            ['user_id' => $userId, 'work_date' => $date->toDateString(), 'source' => $source],
            ['outlet_id' => $outletId, 'hours' => $hours, 'status' => 'pending', 'approved_hours' => null, 'decided_by' => null, 'decided_at' => null, 'note' => null, 'attendance_id' => $attendanceId, 'requested_by' => $actor?->id],
        );
        if ($actor !== null && $source === 'schedule' && $actor->can('approve-longshift')) {
            $this->decide($row, $actor, true, null, 'Disetujui saat menyusun jadwal');

            return;
        }
        $name = User::query()->whereKey($userId)->value('name');
        $this->notifier->notify(
            $outletId, 'longshift.pending', "Longshift {$name}: {$hours} jam menunggu persetujuan",
            ($source === 'schedule' ? 'Dijadwalkan' : 'Tercatat di absensi').' '.$date->translatedFormat('d M Y').'.', '/longshift', 'warning', ['approve-longshift'], null, "longshift:{$row->id}",
        );
    }
}
