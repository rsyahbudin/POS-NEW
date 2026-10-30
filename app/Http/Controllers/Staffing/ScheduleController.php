<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;
use App\Domain\Staffing\Positions;
use App\Domain\Staffing\ShiftHours;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use App\Support\PermissionCatalog;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Who works which shift on which day. A person may work up to three shifts a day, as long as they do not overlap. */
class ScheduleController extends Controller
{
    public const MAX_PER_DAY = 3;

    public function __construct(private OutletContext $outlet, private LongshiftService $longshift, private Notifier $notifier) {}

    public function index(Request $request): Response
    {
        $id = $this->outlet->id();
        $request->validate(['week' => 'nullable|date']);
        $week = CarbonImmutable::parse((string) $request->input('week', BusinessDate::for()))->startOfWeek();
        $end = $week->addDays(6);

        $types = WorkShiftType::query()->where('outlet_id', $id)->orderBy('starts_at')->orderBy('id')->get();
        $rows = WorkSchedule::query()->where('outlet_id', $id)->whereBetween('work_date', [$week->toDateString(), $end->toDateString()])->get();
        $typeById = $types->keyBy('id');

        $assign = [];
        $minutes = [];
        $coverage = [];
        $seen = [];
        foreach ($rows as $r) {
            $day = $r->work_date->toDateString();
            $assign[$r->user_id][$day][$r->work_shift_type_id][] = $r->position;
            $seen[$r->user_id][$day][$r->work_shift_type_id] = true;
            $coverage[$day]['people'][$r->user_id] = true;
            if ($r->position !== 'none') {
                $coverage[$day][$r->position][$r->user_id] = true;
            }
        }
        foreach ($seen as $userId => $perDay) {
            foreach ($perDay as $typeIds) {
                $minutes[$userId] = ($minutes[$userId] ?? 0) + ShiftHours::unionMinutes(array_filter(array_map(fn ($tid) => $typeById[$tid] ?? null, array_keys($typeIds))));
            }
        }

        return Inertia::render('staffing/schedule', [
            'week' => $week->toDateString(),
            'days' => collect(range(0, 6))->map(fn (int $i) => $week->addDays($i)->toDateString())->all(),
            'types' => $types->map(fn (WorkShiftType $t) => ['id' => $t->id, 'name' => $t->name, 'starts_at' => substr($t->starts_at, 0, 5), 'ends_at' => substr($t->ends_at, 0, 5), 'is_active' => $t->is_active, 'hours' => round($t->durationMinutes() / 60, 1)])->all(),
            'people' => $this->roster($id)->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'role' => $u->getRoleNames()->first(), 'default_position' => Positions::defaultFor($u), 'allowed_positions' => Positions::allowedFor($u), 'hours' => round(($minutes[$u->id] ?? 0) / 60, 1),
                'shifts' => collect($assign[$u->id] ?? [])->map(fn ($types) => collect($types)->map(fn ($positions, $typeId) => ['type_id' => $typeId, 'positions' => array_values(array_filter($positions, fn ($p) => $p !== 'none'))])->values()->all())->all() ?: (object) [],
            ])->all(),
            'coverage' => collect($coverage)->map(fn ($c) => collect($c)->map(fn ($u) => count($u))->all())->all() ?: (object) [],
            'positions' => collect(Positions::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'standard_hours' => intdiv($this->longshift->standardMinutes(), 60),
            'me' => $request->user()?->id,
            'can' => ['manage' => (bool) $request->user()?->can('manage-schedule'), 'approve' => (bool) $request->user()?->can('approve-longshift')],
            'max_per_day' => self::MAX_PER_DAY,
        ]);
    }

    /**
     * Replaces one person's shifts on one day. Each shift may carry the stations the person covers there (Kasir and MOD at
     * once is fine). An empty list means a day off. Hours beyond the standard shift become a longshift to approve.
     */
    public function setDay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer', 'date' => 'required|date',
            'entries' => 'present|array|max:'.self::MAX_PER_DAY, 'entries.*.type_id' => 'required|integer|distinct',
            'entries.*.positions' => 'nullable|array|max:'.count(Positions::ALL), 'entries.*.positions.*' => 'string',
        ]);
        $person = $this->personOf((int) $data['user_id']);
        $date = CarbonImmutable::parse($data['date']);
        [$before, $after] = $this->saveDay($request, $person, $date, $data['entries']);
        $this->notifyDay($request, $person, $date, $before, $after);

        return back()->with('success', 'Jadwal disimpan.');
    }

    /**
     * Quick filling: tap a day with a shift "brush", or fill many days at once. toggle = add the shift, or take it (or one
     * station of it) away when it is already there; add = only add; clear = day off. All days are saved together or not at all.
     */
    public function paint(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer', 'dates' => 'required|array|min:1|max:31', 'dates.*' => 'date', 'mode' => 'required|in:toggle,add,clear',
            'type_id' => 'nullable|integer', 'position' => 'nullable|string',
        ]);
        if ($data['mode'] !== 'clear' && empty($data['type_id'])) {
            throw new OrderException('Pilih shift dulu.');
        }
        $position = $data['position'] ?? null;
        if ($position !== null && ! isset(Positions::ALL[$position])) {
            throw new OrderException('Posisi tidak dikenal.');
        }
        $person = $this->personOf((int) $data['user_id']);
        $typeId = (int) ($data['type_id'] ?? 0);

        $changed = [];
        DB::transaction(function () use ($request, $person, $data, $typeId, $position, &$changed) {
            foreach (array_unique($data['dates']) as $day) {
                $date = CarbonImmutable::parse($day);
                $now = $this->entriesOf($person->id, $date);
                $next = $now;
                if ($data['mode'] === 'clear') {
                    $next = [];
                } elseif (isset($next[$typeId])) {
                    if ($data['mode'] === 'toggle') {
                        if ($position === null || in_array($position, $next[$typeId], true)) {
                            $next[$typeId] = $position === null ? [] : array_values(array_diff($next[$typeId], [$position]));
                            if ($position === null || $next[$typeId] === []) {
                                unset($next[$typeId]);
                            }
                        } else {
                            $next[$typeId][] = $position;
                        }
                    } elseif ($position !== null && ! in_array($position, $next[$typeId], true)) {
                        $next[$typeId][] = $position;
                    }
                } else {
                    $next[$typeId] = $position === null ? [] : [$position];
                }
                if ($next == $now) {
                    continue;
                }
                $entries = [];
                foreach ($next as $tid => $positions) {
                    $entries[] = ['type_id' => $tid, 'positions' => $positions];
                }
                [$before, $after] = $this->saveDay($request, $person, $date, $entries);
                $changed[] = [$date, $before, $after];
            }
        });

        $future = array_values(array_filter($changed, fn (array $c) => ! $c[0]->isBefore(CarbonImmutable::today())));
        if ($future !== [] && $person->id !== $request->user()?->id) {
            if (count($future) === 1) {
                $this->notifyDay($request, $person, $future[0][0], $future[0][1], $future[0][2]);
            } else {
                $this->tell($this->outlet->id(), $person->id, 'Jadwalmu diubah ('.count($future).' hari)', 'Ada perubahan jadwal mulai '.$future[0][0]->translatedFormat('d M').'. Lihat di menu Absensi › Jadwal.');
            }
        }

        return back()->with('success', $changed === [] ? 'Tidak ada yang berubah.' : count($changed).' hari disimpan.');
    }

    private function personOf(int $userId): User
    {
        $person = User::query()->where('outlet_id', $this->outlet->id())->whereKey($userId)->first() ?? abort(422);
        if (PermissionCatalog::shellFor($person) !== 'floor') {
            throw new OrderException('Owner dan manajemen tidak dijadwalkan shift. Absen mereka tetap dicatat di Absensi.');
        }

        return $person;
    }

    /**
     * Who is scheduled: floor staff (cashier, waiter, kitchen, bar, MOD), never the owner or the back office.
     *
     * @return Collection<int, User>
     */
    private function roster(int $outletId)
    {
        return User::query()->where('outlet_id', $outletId)->where('is_active', true)->with('roles')->orderBy('name')->get()
            ->filter(fn (User $u) => PermissionCatalog::shellFor($u) === 'floor')->values();
    }

    /**
     * What a person has on a day: shift type id => stations (the default of their role is a station like any other).
     *
     * @return array<int, list<string>>
     */
    private function entriesOf(int $userId, CarbonImmutable $date): array
    {
        $out = [];
        foreach (WorkSchedule::query()->where('user_id', $userId)->whereDate('work_date', $date)->orderBy('id')->get() as $row) {
            $out[$row->work_shift_type_id] ??= [];
            if ($row->position !== 'none') {
                $out[$row->work_shift_type_id][] = $row->position;
            }
        }

        return $out;
    }

    /**
     * The one place a day is written: checks, replaces the rows, rechecks longshift. Returns the day before and after as text.
     *
     * @param  array<int, array{type_id: int|string, positions?: array<int, mixed>|null}>  $entries
     * @return array{0: string, 1: string}
     */
    private function saveDay(Request $request, User $person, CarbonImmutable $date, array $entries): array
    {
        $id = $this->outlet->id();
        if (count($entries) > self::MAX_PER_DAY) {
            throw new OrderException('Maksimal '.self::MAX_PER_DAY.' shift per orang per hari.');
        }
        $ids = array_map(fn (array $e) => (int) $e['type_id'], $entries);
        if (WorkShiftType::query()->where('outlet_id', $id)->where('is_active', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new OrderException('Ada shift yang tidak dikenal atau sudah nonaktif.');
        }
        $this->assertMayEdit($request, $date);
        $before = $this->describeDay($person->id, $date);
        DB::transaction(function () use ($id, $entries, $person, $date, $request) {
            WorkSchedule::query()->where('outlet_id', $id)->where('user_id', $person->id)->whereDate('work_date', $date)->delete();
            foreach ($entries as $entry) {
                $positions = Positions::clean($entry['positions'] ?? []) ?: [Positions::defaultFor($person)];
                foreach ($positions as $position) {
                    if ($position !== 'none' && ! in_array($position, Positions::allowedFor($person), true)) {
                        throw new OrderException(Positions::ALL[$position].' bukan station '.$person->name.'. Hanya MOD yang boleh merangkap station lain.');
                    }
                    WorkSchedule::create(['outlet_id' => $id, 'user_id' => $person->id, 'work_date' => $date->toDateString(), 'work_shift_type_id' => (int) $entry['type_id'], 'position' => $position, 'created_by' => $request->user()?->id]);
                }
            }
            Audit::log('schedule.day_set', null, ['user_id' => $person->id, 'date' => $date->toDateString(), 'shifts' => count($entries)], $request->user());
            // a corrected past day changes what was worked beyond the plan
            $this->longshift->syncAttendanceDay($id, $person->id, $date);
        });

        return [$before, $this->describeDay($person->id, $date)];
    }

    private function notifyDay(Request $request, User $person, CarbonImmutable $date, string $before, string $after): void
    {
        if ($before !== $after && $person->id !== $request->user()?->id && ! $date->isBefore(CarbonImmutable::today())) {
            $this->tell($this->outlet->id(), $person->id, "Jadwalmu {$date->translatedFormat('D, d M')} diubah", "Sebelumnya: {$before}. Sekarang: {$after}.");
        }
    }

    /** Copies the schedule of the week before onto this week, keeping what is already planned. */
    public function copyWeek(Request $request): RedirectResponse
    {
        $id = $this->outlet->id();
        $data = $request->validate(['week' => 'required|date']);
        $week = CarbonImmutable::parse($data['week'])->startOfWeek();
        $this->assertMayEdit($request, $week->addDays(6));
        $previous = $week->subWeek();

        $source = WorkSchedule::query()->where('outlet_id', $id)->whereBetween('work_date', [$previous->toDateString(), $previous->addDays(6)->toDateString()])
            ->whereHas('type', fn ($q) => $q->where('is_active', true))->whereIn('user_id', User::query()->where('outlet_id', $id)->where('is_active', true)->select('id'))->get();
        if ($source->isEmpty()) {
            return back()->with('error', 'Minggu sebelumnya belum punya jadwal untuk disalin.');
        }

        $added = 0;
        $touched = [];
        DB::transaction(function () use ($source, $id, $request, &$added, &$touched) {
            foreach ($source as $row) {
                $date = $row->work_date->addWeek()->toDateString();
                $day = WorkSchedule::query()->where('user_id', $row->user_id)->whereDate('work_date', $date);
                $sameType = $day->clone()->where('work_shift_type_id', $row->work_shift_type_id);
                if ($sameType->exists() || $day->clone()->distinct('work_shift_type_id')->count('work_shift_type_id') >= self::MAX_PER_DAY) {
                    continue;
                }
                WorkSchedule::create(['outlet_id' => $id, 'user_id' => $row->user_id, 'work_date' => $date, 'work_shift_type_id' => $row->work_shift_type_id, 'position' => $row->position, 'created_by' => $request->user()?->id]);
                $touched[$row->user_id.'|'.$date] = [$row->user_id, $date];
                $added++;
            }
        });
        $perUser = [];
        foreach ($touched as [$userId]) {
            $perUser[$userId] = ($perUser[$userId] ?? 0) + 1;
        }
        foreach ($perUser as $userId => $count) {
            if ($userId !== $request->user()?->id) {
                $this->tell($id, $userId, 'Jadwal minggu '.$week->translatedFormat('d M').' ditambahkan', "{$count} shift baru masuk jadwalmu. Lihat di menu Jadwal Kerja.");
            }
        }

        return back()->with('success', "{$added} shift disalin dari minggu sebelumnya.");
    }

    public function saveType(Request $request, ?WorkShiftType $type = null): RedirectResponse
    {
        $id = $this->outlet->id();
        abort_if($type !== null && $type->outlet_id !== $id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:40', 'starts_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'ends_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'is_active' => 'boolean',
        ]);
        if ($data['starts_at'] === $data['ends_at']) {
            return back()->with('error', 'Jam mulai dan selesai tidak boleh sama.');
        }
        if ($type === null) {
            WorkShiftType::create($data + ['outlet_id' => $id, 'is_active' => true]);

            return back()->with('success', 'Pola shift disimpan.');
        }

        $timesChanged = substr($type->starts_at, 0, 5) !== $data['starts_at'] || substr($type->ends_at, 0, 5) !== $data['ends_at'];
        if ($timesChanged && WorkSchedule::query()->where('work_shift_type_id', $type->id)->whereDate('work_date', '<', now()->toDateString())->exists()) {
            return back()->with('error', 'Pola ini sudah dipakai di jadwal yang sudah lewat, jam-nya tidak bisa diubah supaya jam kerja lama tidak berubah. Nonaktifkan pola ini lalu buat pola baru.');
        }
        $type->update($data);
        if ($timesChanged) {
            // planned days from today on follow the new hours: tell the people
            $rows = WorkSchedule::query()->where('work_shift_type_id', $type->id)->whereDate('work_date', '>=', now()->toDateString())->get();
            foreach ($rows->pluck('user_id')->unique() as $userId) {
                $this->tell($id, (int) $userId, "Jam shift {$type->name} diubah", "Sekarang {$data['starts_at']}–{$data['ends_at']}. Jadwalmu yang memakai shift ini ikut berubah.");
            }
        }
        Audit::log('schedule.type_saved', $type, ['name' => $type->name, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'is_active' => $data['is_active'] ?? true], $request->user());

        return back()->with('success', 'Pola shift disimpan.');
    }

    /** The team's week (scope=team) or the signed-in person's own month (scope=mine) as PDF or Excel. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $id = $this->outlet->id();
        $data = $request->validate(['scope' => 'required|in:team,mine', 'week' => 'nullable|date', 'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'format' => 'nullable|in:pdf,xlsx']);
        $format = (string) ($data['format'] ?? 'pdf');
        $hours = fn (WorkShiftType $t) => round($t->durationMinutes() / 60, 1);

        if ($data['scope'] === 'mine') {
            $user = $this->actor($request);
            $month = CarbonImmutable::parse(((string) ($data['month'] ?? now()->format('Y-m'))).'-01');
            $rows = WorkSchedule::query()->where('user_id', $user->id)->whereBetween('work_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])->with('type')->orderBy('work_date')->get()->groupBy(fn (WorkSchedule $w) => $w->work_date->toDateString());
            $lines = [];
            $total = 0.0;
            foreach ($rows as $date => $day) {
                $total += ShiftHours::unionMinutes($day->unique('work_shift_type_id')->map(fn (WorkSchedule $w) => $w->type)) / 60;
                foreach ($day->groupBy('work_shift_type_id') as $group) {
                    $type = $group->firstOrFail()->type;
                    $lines[] = [CarbonImmutable::parse($date)->translatedFormat('d M Y'), CarbonImmutable::parse($date)->translatedFormat('l'), $type->name, substr($type->starts_at, 0, 5).'–'.substr($type->ends_at, 0, 5), $group->pluck('position')->reject(fn ($p) => $p === 'none')->map(fn ($p) => Positions::ALL[$p] ?? $p)->implode(' + '), $hours($type)];
                }
            }

            return ReportFile::download($format, "Jadwal {$user->name} {$month->format('Y-m')}", [
                new ReportSheet('Jadwal saya', "{$user->name} · ".$month->translatedFormat('F Y'), ['Tanggal', 'Hari', 'Shift', 'Jam', 'Posisi', 'Durasi (jam)'], $lines, ['Total', '', '', '', '', round($total, 1)]),
            ]);
        }

        $week = CarbonImmutable::parse((string) ($data['week'] ?? BusinessDate::for()))->startOfWeek();
        $days = array_map(fn (int $i) => $week->addDays($i), range(0, 6));
        $rows = WorkSchedule::query()->where('outlet_id', $id)->whereBetween('work_date', [$week->toDateString(), $week->addDays(6)->toDateString()])->with('type')->get()->groupBy(fn (WorkSchedule $w) => $w->user_id.'|'.$w->work_date->toDateString());
        $lines = [];
        foreach ($this->roster($id) as $person) {
            $cells = [];
            $sum = 0.0;
            foreach ($days as $d) {
                $day = $rows[$person->id.'|'.$d->toDateString()] ?? collect();
                $sum += ShiftHours::unionMinutes($day->unique('work_shift_type_id')->map(fn (WorkSchedule $w) => $w->type)) / 60;
                $cells[] = $day->isEmpty() ? '-' : $day->groupBy('work_shift_type_id')->map(function ($group) {
                    $type = $group->firstOrFail()->type;
                    $pos = $group->pluck('position')->reject(fn ($p) => $p === 'none')->map(fn ($p) => Positions::ALL[$p] ?? $p)->implode(' + ');

                    return "{$type->name} ".substr($type->starts_at, 0, 5).'–'.substr($type->ends_at, 0, 5).($pos !== '' ? " ({$pos})" : '');
                })->implode("\n");
            }
            $lines[] = [$person->name, $person->getRoleNames()->first() ?? '', ...$cells, round($sum, 1)];
        }

        return ReportFile::download($format, "Jadwal kerja {$week->toDateString()}", [
            new ReportSheet('Jadwal kerja', 'Minggu '.$week->translatedFormat('d M').' – '.$week->addDays(6)->translatedFormat('d M Y'), ['Karyawan', 'Jabatan', ...array_map(fn (CarbonImmutable $d) => $d->translatedFormat('D d/m'), $days), 'Jam'], $lines),
        ]);
    }

    /** Days that are already over are history (they feed longshift and HR): only the MOD or the owner may correct them. */
    private function assertMayEdit(Request $request, CarbonImmutable $date): void
    {
        $user = $request->user();
        if ($date->isBefore(CarbonImmutable::today()) && ! ($user?->can('approve-longshift') || $user?->hasRole('owner'))) {
            throw new OrderException('Jadwal hari yang sudah lewat hanya bisa diubah oleh MOD atau owner.');
        }
    }

    /** "Pagi 09:00–18:00 (Kasir + MOD); Malam 20:00–23:00" or "libur". */
    private function describeDay(int $userId, CarbonImmutable $date): string
    {
        $text = WorkSchedule::query()->where('user_id', $userId)->whereDate('work_date', $date)->with('type')->get()->groupBy('work_shift_type_id')->map(function ($group) {
            $type = $group->firstOrFail()->type;
            $pos = $group->pluck('position')->reject(fn ($p) => $p === 'none')->map(fn ($p) => Positions::ALL[$p] ?? $p)->implode(' + ');

            return "{$type->name} ".substr($type->starts_at, 0, 5).'–'.substr($type->ends_at, 0, 5).($pos !== '' ? " ({$pos})" : '');
        })->implode('; ');

        return $text === '' ? 'libur' : $text;
    }

    private function tell(int $outletId, int $userId, string $title, string $body): void
    {
        $this->notifier->notify($outletId, 'schedule.changed', $title, $body, '/schedule', 'info', null, $userId);
    }
}
