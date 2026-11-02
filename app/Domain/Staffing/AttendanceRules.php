<?php

namespace App\Domain\Staffing;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a clock-in or clock-out does not match the schedule the person must give a reason on the spot. The back office is
 * not scheduled, so it is exempt. A clock-in with no schedule at all also waits for the manager on duty to look at it.
 */
class AttendanceRules
{
    public function __construct(private SettingsRepository $settings) {}

    public function lateGrace(): int
    {
        return max(0, (int) $this->settings->get('attendance.late_grace_minutes', 10));
    }

    public function earlyGrace(): int
    {
        return max(0, (int) $this->settings->get('attendance.early_grace_minutes', 30));
    }

    public function outGrace(): int
    {
        return max(0, (int) $this->settings->get('attendance.out_grace_minutes', 15));
    }

    /**
     * What is off about this clock-in or clock-out, or null when it fits the schedule.
     *
     * @param  'in'|'out'  $action
     * @return array{flag: string, text: string, review: bool}|null
     */
    public function deviation(User $user, string $action, CarbonInterface $now, ?Attendance $open): ?array
    {
        if (PermissionCatalog::shellFor($user) === 'office') {
            return null;
        }
        $day = CarbonImmutable::parse($action === 'out' && $open !== null ? $open->work_date->toDateString() : $now->toDateString());
        $shifts = WorkSchedule::query()->where('user_id', $user->id)->whereDate('work_date', $day)->with('type')->get()->map(fn (WorkSchedule $w) => $w->type)->unique('id');
        $minutes = intdiv($now->getTimestamp() - $day->startOfDay()->getTimestamp(), 60);

        if ($shifts->isEmpty()) {
            if ($action === 'out' && $open?->in_flag === 'unscheduled') {
                return null;     // the reason was given when clocking in
            }

            return ['flag' => 'unscheduled', 'text' => 'Tidak ada jadwal shift untuk Anda pada hari ini.', 'review' => true];
        }
        if ($action === 'in') {
            $start = (int) $shifts->min(fn ($t) => $t->startMinute());
            $delta = $minutes - $start;
            if ($delta > $this->lateGrace()) {
                return ['flag' => 'late', 'text' => "Masuk {$delta} menit setelah jam mulai shift (".self::clock($start).').', 'review' => false];
            }
            if ($delta < -$this->earlyGrace()) {
                return ['flag' => 'early', 'text' => 'Masuk '.abs($delta).' menit sebelum shift mulai ('.self::clock($start).').', 'review' => false];
            }

            return null;
        }
        $end = (int) $shifts->max(fn ($t) => $t->endMinute());
        $delta = $minutes - $end;
        if ($delta > $this->outGrace()) {
            return ['flag' => 'overstay', 'text' => "Pulang {$delta} menit setelah shift berakhir (".self::clock($end).'). Jam lebih dari jadwal baru diakui bila disetujui MOD.', 'review' => false];
        }
        if ($delta < -$this->outGrace()) {
            return ['flag' => 'early_out', 'text' => 'Pulang '.abs($delta).' menit sebelum shift berakhir ('.self::clock($end).').', 'review' => false];
        }

        return null;
    }

    private static function clock(int $minutes): string
    {
        $m = $minutes % 1440;

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
