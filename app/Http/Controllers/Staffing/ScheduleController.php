<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;
use App\Domain\Staffing\Positions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Who works which shift on which day. A person may work up to three shifts a day, as long as they do not overlap. */
class ScheduleController extends Controller
{
    public const MAX_PER_DAY = 3;

    public function __construct(private OutletContext $outlet, private LongshiftService $longshift) {}

    public function index(Request $request): Response
    {
        $id = $this->outlet->id();
        $request->validate(['week' => 'nullable|date']);
        $week = CarbonImmutable::parse((string) $request->input('week', BusinessDate::for()))->startOfWeek();
        $end = $week->addDays(6);

        $types = WorkShiftType::query()->where('outlet_id', $id)->orderBy('starts_at')->orderBy('id')->get();
        $rows = WorkSchedule::query()->where('outlet_id', $id)->whereBetween('work_date', [$week->toDateString(), $end->toDateString()])->get();
        $duration = $types->mapWithKeys(fn (WorkShiftType $t) => [$t->id => $t->durationMinutes()])->all();

        $assign = [];
        $minutes = [];
        $coverage = [];
        $seen = [];
        foreach ($rows as $r) {
            $day = $r->work_date->toDateString();
            $assign[$r->user_id][$day][$r->work_shift_type_id][] = $r->position;
            if (! isset($seen[$r->user_id][$day][$r->work_shift_type_id])) {
                $seen[$r->user_id][$day][$r->work_shift_type_id] = true;
                $minutes[$r->user_id] = ($minutes[$r->user_id] ?? 0) + ($duration[$r->work_shift_type_id] ?? 0);
            }
            $coverage[$day]['people'][$r->user_id] = true;
            if ($r->position !== 'none') {
                $coverage[$day][$r->position][$r->user_id] = true;
            }
        }
        $flags = LongshiftApproval::query()->where('outlet_id', $id)->where('source', 'schedule')->whereBetween('work_date', [$week->toDateString(), $end->toDateString()])->get()
            ->mapToGroups(fn (LongshiftApproval $a) => [$a->user_id => [$a->work_date->toDateString() => ['hours' => $a->hours, 'status' => $a->status]]])
            ->map(fn ($g) => $g->collapse());

        return Inertia::render('staffing/schedule', [
            'week' => $week->toDateString(),
            'days' => collect(range(0, 6))->map(fn (int $i) => $week->addDays($i)->toDateString())->all(),
            'types' => $types->map(fn (WorkShiftType $t) => ['id' => $t->id, 'name' => $t->name, 'starts_at' => substr($t->starts_at, 0, 5), 'ends_at' => substr($t->ends_at, 0, 5), 'is_active' => $t->is_active, 'hours' => round($t->durationMinutes() / 60, 1)])->all(),
            'people' => User::query()->where('outlet_id', $id)->where('is_active', true)->with('roles:name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'role' => $u->getRoleNames()->first(), 'default_position' => Positions::defaultFor($u), 'hours' => round(($minutes[$u->id] ?? 0) / 60, 1),
                'shifts' => collect($assign[$u->id] ?? [])->map(fn ($types) => collect($types)->map(fn ($positions, $typeId) => ['type_id' => $typeId, 'positions' => array_values(array_filter($positions, fn ($p) => $p !== 'none'))])->values()->all())->all() ?: (object) [],
                'longshift' => $flags[$u->id] ?? (object) [],
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
        $id = $this->outlet->id();
        $data = $request->validate([
            'user_id' => 'required|integer', 'date' => 'required|date',
            'entries' => 'present|array|max:'.self::MAX_PER_DAY, 'entries.*.type_id' => 'required|integer|distinct',
            'entries.*.positions' => 'nullable|array|max:'.count(Positions::ALL), 'entries.*.positions.*' => 'string',
        ]);
        $person = User::query()->where('outlet_id', $id)->whereKey((int) $data['user_id'])->first() ?? abort(422);
        $types = WorkShiftType::query()->where('outlet_id', $id)->where('is_active', true)->whereIn('id', array_column($data['entries'], 'type_id'))->get()->keyBy('id');
        if ($types->count() !== count($data['entries'])) {
            throw new OrderException('Ada shift yang tidak dikenal atau sudah nonaktif.');
        }
        $this->assertNoOverlap($types->values()->all());

        $date = CarbonImmutable::parse($data['date']);
        DB::transaction(function () use ($id, $data, $person, $date, $request) {
            WorkSchedule::query()->where('outlet_id', $id)->where('user_id', $person->id)->whereDate('work_date', $date)->delete();
            foreach ($data['entries'] as $entry) {
                $positions = Positions::clean($entry['positions'] ?? []) ?: [Positions::defaultFor($person)];
                foreach ($positions as $position) {
                    WorkSchedule::create(['outlet_id' => $id, 'user_id' => $person->id, 'work_date' => $date->toDateString(), 'work_shift_type_id' => $entry['type_id'], 'position' => $position, 'created_by' => $request->user()?->id]);
                }
            }
            Audit::log('schedule.day_set', null, ['user_id' => $person->id, 'date' => $date->toDateString(), 'shifts' => count($data['entries'])], $request->user());
            $this->longshift->syncSchedule($id, $person->id, $date, $request->user());
        });

        return back()->with('success', 'Jadwal disimpan.');
    }

    /** Copies the schedule of the week before onto this week, keeping what is already planned. */
    public function copyWeek(Request $request): RedirectResponse
    {
        $id = $this->outlet->id();
        $data = $request->validate(['week' => 'required|date']);
        $week = CarbonImmutable::parse($data['week'])->startOfWeek();
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
            foreach ($touched as [$userId, $date]) {
                $this->longshift->syncSchedule($id, $userId, CarbonImmutable::parse($date), $request->user());
            }
        });

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
        $type ? $type->update($data) : WorkShiftType::create($data + ['outlet_id' => $id, 'is_active' => true]);

        return back()->with('success', 'Pola shift disimpan.');
    }

    /** @param  array<int, WorkShiftType>  $types */
    private function assertNoOverlap(array $types): void
    {
        foreach ($types as $i => $a) {
            foreach (array_slice($types, $i + 1) as $b) {
                if ($a->startMinute() < $b->endMinute() && $b->startMinute() < $a->endMinute()) {
                    throw new OrderException("Shift {$a->name} dan {$b->name} bertabrakan jamnya.");
                }
            }
        }
    }
}
