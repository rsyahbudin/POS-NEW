<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Staffing\AttendanceService;
use App\Domain\Staffing\FaceMatcher;
use App\Domain\Staffing\GeoFence;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\AttendanceRequest;
use App\Domain\Staffing\Models\FaceTemplate;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Positions;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Face attendance: the kiosk that clocks people in and out, enrolling faces, and the attendance log with corrections. */
class AttendanceController extends Controller
{
    public const MAX_SAMPLES = 5;

    public function __construct(private OutletContext $outlet, private AttendanceService $service, private FaceMatcher $matcher, private SettingsRepository $settings, private LongshiftService $longshift, private GeoFence $geo) {}

    public function kiosk(): Response
    {
        $this->assertKioskOn();
        $id = $this->outlet->id();

        return Inertia::render('staffing/kiosk', [
            'enrolled' => FaceTemplate::query()->where('outlet_id', $id)->distinct('user_id')->count('user_id'),
            // names only, and only people with a face on file: the kiosk asks who is standing there before it looks
            'people' => User::query()->where('outlet_id', $id)->where('is_active', true)->whereIn('id', FaceTemplate::query()->where('outlet_id', $id)->select('user_id'))->orderBy('name')->get(['id', 'name'])->all(),
            'undo_seconds' => AttendanceService::UNDO_SECONDS,
            'geofence' => $this->geo->enabled(),
            'radius' => $this->geo->radius(),
        ]);
    }

    /** The shared kiosk: the person picks their name, then the camera checks the face is theirs. */
    public function scan(Request $request): JsonResponse
    {
        $this->assertKioskOn();
        $request->validate(['user_id' => 'required|integer']);

        return $this->handleScan($request, (int) $request->input('user_id'));
    }

    /** The person is signed in: the account is who they are, nobody picks a name. */
    public function meScan(Request $request): JsonResponse
    {
        return $this->handleScan($request, $this->actor($request)->id);
    }

    private function handleScan(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:in,out', 'descriptor' => 'required|array|size:128', 'descriptor.*' => 'required|numeric',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'accuracy' => 'nullable|numeric|min:0',
        ]);
        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) ? (float) $data['lng'] : null;
        // Where the device is comes first: a scan from outside the radius never reaches the face match.
        $place = $this->geo->check($this->outlet->id(), $lat, $lng, isset($data['accuracy']) ? (float) $data['accuracy'] : null);
        if (! $place['ok']) {
            return response()->json(['status' => $place['reason'], 'distance' => $place['distance'], 'radius' => $this->geo->radius()]);
        }
        $result = $this->service->scan($this->outlet->id(), $userId, array_values(array_map('floatval', $data['descriptor'])), $data['action'], null, ['lat' => $lat, 'lng' => $lng, 'distance' => $place['distance']]);

        return $this->present($result);
    }

    private function assertKioskOn(): void
    {
        abort_unless((bool) $this->settings->get('attendance.shared_kiosk', false), 404);
    }

    /** The reason for a clock-in or clock-out that did not fit the schedule; the face was checked a moment ago. */
    public function reason(Request $request): JsonResponse
    {
        $this->assertKioskOn();
        $data = $request->validate(['ticket' => 'required|string|max:80', 'reason' => 'required|string|min:5|max:200']);

        return $this->present($this->service->confirm($this->outlet->id(), $data['ticket'], $data['reason']));
    }

    /** The reason for the signed-in person's own clock-in or clock-out; the ticket is theirs only. */
    public function meReason(Request $request): JsonResponse
    {
        $data = $request->validate(['ticket' => 'required|string|max:80', 'reason' => 'required|string|min:5|max:200']);

        return $this->present($this->service->confirm($this->outlet->id(), $data['ticket'], $data['reason'], null, $this->actor($request)->id));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function present(array $result): JsonResponse
    {
        $status = (string) $result['status'];
        if (in_array($status, ['unknown', 'expired'], true)) {
            return response()->json(['status' => $status]);
        }
        $user = $result['user'];
        if (in_array($status, ['wait', 'mismatch', 'ambiguous', 'not_enrolled'], true)) {
            // wrong face, too fast, or nobody on file: nothing is booked, and nothing says whose face it looked like
            return response()->json(['status' => $status, 'name' => $user->name, 'retry_after' => $result['retry_after'] ?? null]);
        }
        if ($status === 'need_reason') {
            return response()->json(['status' => 'need_reason', 'name' => $user->name, 'flag' => $result['flag'], 'text' => $result['text'], 'ticket' => $result['ticket'] ?? null]);
        }
        $a = $result['attendance'] ?? null;
        $at = $a === null ? null : ($status === 'out' ? $a->clock_out_at : $a->clock_in_at);

        return response()->json([
            'status' => $status, 'name' => $user->name, 'at' => $at?->toIso8601String(), 'attendance_id' => $a?->id,
            'late' => $status === 'in' && $a !== null ? $this->service->lateMinutes($a, $this->grace()) : null,
            'flag' => $result['flag'] ?? null,
            // the station(s) the person is scheduled on today, so the kiosk can say "Dapur / Bar" next to the name
            'stations' => WorkSchedule::query()->where('user_id', $user->id)->whereDate('work_date', now()->toDateString())->pluck('position')->reject(fn ($p) => $p === 'none')->unique()->map(fn ($p) => Positions::ALL[$p] ?? $p)->values()->all(),
        ]);
    }

    public function undo(Request $request): JsonResponse
    {
        $this->assertKioskOn();
        $data = $request->validate(['attendance_id' => 'required|integer', 'action' => 'required|in:in,out']);

        return response()->json(['undone' => $this->service->undo($this->outlet->id(), (int) $data['attendance_id'], $data['action'])]);
    }

    /** Everyone's attendance for the managers: who is at work now, who is late or missing, a summary per person, and the log. */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $request->validate(['user_id' => 'nullable|integer']);
        $all = $this->records($from, $to);
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;

        return Inertia::render('staffing/attendance', [
            'filters' => ['from' => $from, 'to' => $to, 'user_id' => $userId],
            'records' => $userId === null ? $all : array_values(array_filter($all, fn (array $r) => $r['user_id'] === $userId)),
            'summary' => $this->summary($all),
            'on_duty' => $this->onDuty(),
            'absent' => $this->absent(),
            'people' => User::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'grace' => $this->grace(),
            'standard_hours' => intdiv($this->longshift->standardMinutes(), 60),
        ]);
    }

    /** One's own attendance: today's state and the month's history. */
    public function mine(Request $request): Response
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);
        $user = $this->actor($request);
        $month = CarbonImmutable::parse(((string) $request->input('month', now()->format('Y-m'))).'-01')->startOfDay();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $mine = array_values(array_filter($this->records($from, $to), fn (array $r) => $r['user_id'] === $user->id));
        $open = Attendance::query()->where('user_id', $user->id)->whereNull('clock_out_at')->where('clock_in_at', '>', now()->subHours(AttendanceService::STALE_HOURS))->latest('clock_in_at')->first();

        return Inertia::render('staffing/mine', [
            'name' => $user->name,
            'month' => $month->format('Y-m'),
            'records' => $mine,
            'totals' => $this->summary($mine)[0] ?? ['days' => 0, 'hours' => 0, 'late' => 0, 'late_minutes' => 0, 'long_hours' => 0, 'open' => 0],
            'planned' => WorkSchedule::query()->where('user_id', $user->id)->whereBetween('work_date', [$from, $to])->with('type')->orderBy('work_date')->get()->groupBy(fn (WorkSchedule $w) => $w->work_date->toDateString())->map(
                fn ($day) => $day->groupBy('work_shift_type_id')->map(function ($group) {
                    $type = $group->firstOrFail()->type;

                    return ['name' => $type->name, 'starts_at' => substr($type->starts_at, 0, 5), 'ends_at' => substr($type->ends_at, 0, 5), 'positions' => $group->pluck('position')->reject(fn ($p) => $p === 'none')->map(fn ($p) => Positions::ALL[$p] ?? $p)->values()->all()];
                })->values()->all(),
            )->all() ?: (object) [],
            'today' => now()->toDateString(),
            'standard_hours' => intdiv($this->longshift->standardMinutes(), 60),
            'requests' => AttendanceRequest::query()->where('user_id', $user->id)->latest('id')->limit(10)->get()->map(fn (AttendanceRequest $q) => [
                'id' => $q->id, 'work_date' => $q->work_date->toDateString(), 'kind' => $q->kind, 'clock_in' => $q->clock_in === null ? null : substr($q->clock_in, 0, 5), 'clock_out' => $q->clock_out === null ? null : substr($q->clock_out, 0, 5),
                'reason' => $q->reason, 'status' => $q->status, 'decision_note' => $q->decision_note,
            ])->all(),
            'forgot' => Attendance::query()->where('user_id', $user->id)->whereNull('clock_out_at')->where('clock_in_at', '<=', now()->subHours(AttendanceService::STALE_HOURS))->orderByDesc('work_date')->limit(5)->pluck('work_date')->map(fn ($d) => $d->toDateString())->all(),
            'reach_days' => AttendanceRequestController::REACH_DAYS,
            'open_since' => $open?->clock_in_at->toIso8601String(),
            'can' => ['kiosk' => (bool) $user->can('use-attendance-kiosk') && (bool) $this->settings->get('attendance.shared_kiosk', false)],
            // clocking with one's own account: the face must be the face of this account
            'clock' => [
                'allowed' => (bool) $user->can('clock-own-attendance'),
                'enrolled' => FaceTemplate::query()->where('user_id', $user->id)->exists(),
                'geofence' => $this->geo->enabled(),
                'radius' => $this->geo->radius(),
            ],
        ]);
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $range = CarbonImmutable::parse($from)->translatedFormat('d M Y').' s/d '.CarbonImmutable::parse($to)->translatedFormat('d M Y');
        $rows = $this->records($from, $to);

        $summary = $this->summary($rows);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Absensi {$from} {$to}", [
            new ReportSheet('Ringkasan', "Periode {$range}", ['Nama', 'Jabatan', 'Hari hadir', 'Total jam kerja', 'Kali terlambat', 'Total menit terlambat', 'Longshift disetujui (jam)', 'Belum pulang'], array_map(fn (array $p) => [
                $p['name'], $p['role'] ?? '', $p['days'], $p['hours'], $p['late'], $p['late_minutes'], $p['long_hours'], $p['open'],
            ], $summary), ['Total', '', array_sum(array_column($summary, 'days')), round(array_sum(array_column($summary, 'hours')), 1), array_sum(array_column($summary, 'late')), array_sum(array_column($summary, 'late_minutes')), array_sum(array_column($summary, 'long_hours')), array_sum(array_column($summary, 'open'))]),
            new ReportSheet('Absensi', "Periode {$range}", ['Tanggal', 'Nama', 'Jabatan', 'Masuk', 'Pulang', 'Jam kerja', 'Jam diakui', 'Terlambat (menit)', 'Longshift disetujui (jam)', 'Penanda', 'Alasan masuk', 'Alasan pulang', 'Sumber', 'Catatan'], array_map(fn (array $r) => [
                CarbonImmutable::parse($r['work_date'])->translatedFormat('d M Y'), $r['name'], $r['role'] ?? '', CarbonImmutable::parse($r['clock_in_at'])->format('H:i'),
                $r['clock_out_at'] ? CarbonImmutable::parse($r['clock_out_at'])->format('H:i') : 'Belum pulang', $r['hours'] ?? '', $r['recognized_hours'] ?? '', $r['late'] ?? '', ($r['longshift']['status'] ?? '') === 'approved' ? $r['longshift']['approved_hours'] : '',
                trim(($r['in_flag'] ?? '').' '.($r['out_flag'] ?? '').($r['review_status'] === 'pending' ? ' perlu-ditinjau' : '')), $r['in_reason'] ?? '', $r['out_reason'] ?? '',
                match ($r['method_in']) {
                    'face' => 'Wajah', 'button' => 'Tombol', default => 'Manual'
                }, $r['note'] ?? '',
            ], $rows), ['Total jam', '', '', '', '', round(array_sum(array_column($rows, 'hours')), 1), round(array_sum(array_column($rows, 'recognized_hours')), 1), '', '', '', '', '', '', '']),
        ]);
    }

    public function faces(): Response
    {
        $id = $this->outlet->id();
        $counts = FaceTemplate::query()->where('outlet_id', $id)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        return Inertia::render('staffing/faces', [
            'people' => User::query()->where('outlet_id', $id)->where('is_active', true)->with('roles:name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'role' => $u->getRoleNames()->first(), 'samples' => (int) ($counts[$u->id] ?? 0),
            ])->all(),
            'max_samples' => self::MAX_SAMPLES,
        ]);
    }

    public function enroll(Request $request, User $user): RedirectResponse
    {
        $id = $this->outlet->id();
        abort_unless($user->outlet_id === $id, 404);
        $data = $request->validate([
            'consent' => 'accepted', 'samples' => 'required|array|min:3|max:'.self::MAX_SAMPLES, 'samples.*' => 'required|array|size:128', 'samples.*.*' => 'required|numeric',
        ]);
        $samples = array_map(fn (array $s) => array_values(array_map('floatval', $s)), $data['samples']);

        foreach ($samples as $s) {
            if ($other = $this->matcher->duplicateOf($id, $s, $user->id)) {
                return back()->with('error', "Wajah ini mirip dengan {$other->name} yang sudah terdaftar. Pastikan yang difoto benar-benar {$user->name}.");
            }
        }
        FaceTemplate::query()->where('user_id', $user->id)->delete();
        foreach ($samples as $s) {
            FaceTemplate::create(['outlet_id' => $id, 'user_id' => $user->id, 'descriptor' => $s, 'consented_at' => now(), 'enrolled_by' => $request->user()?->id]);
        }
        Audit::log('face.enrolled', $user, ['user' => $user->name, 'samples' => count($samples)], $request->user());

        return back()->with('success', "Wajah {$user->name} didaftarkan.");
    }

    public function forget(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->outlet_id === $this->outlet->id(), 404);
        FaceTemplate::query()->where('user_id', $user->id)->delete();
        Audit::log('face.deleted', $user, ['user' => $user->name], $request->user());

        return back()->with('success', "Data wajah {$user->name} dihapus.");
    }

    /** A correction or a missing record added by hand: always with a note, always audited. */
    public function saveRecord(Request $request, ?Attendance $attendance = null): RedirectResponse
    {
        $id = $this->outlet->id();
        abort_if($attendance !== null && $attendance->outlet_id !== $id, 404);
        $data = $request->validate([
            'user_id' => 'required|integer', 'date' => 'required|date', 'clock_in' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'clock_out' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'note' => 'required|string|max:200',
        ]);
        abort_unless(User::query()->where('outlet_id', $id)->whereKey($data['user_id'])->exists(), 422);

        $in = CarbonImmutable::parse("{$data['date']} {$data['clock_in']}");
        $out = isset($data['clock_out']) ? CarbonImmutable::parse("{$data['date']} {$data['clock_out']}") : null;
        if ($out !== null && $out->lte($in)) {
            $out = $out->addDay();     // shift past midnight
        }
        $fields = ['user_id' => $data['user_id'], 'work_date' => $in->toDateString(), 'clock_in_at' => $in, 'clock_out_at' => $out, 'note' => $data['note'], 'edited_by' => $request->user()?->id];
        $was = $attendance === null ? null : [$attendance->user_id, $attendance->work_date->copy()];
        if ($attendance) {
            $attendance->update($fields + ['method_out' => $out ? ($attendance->method_out ?? 'manual') : null]);
        } else {
            $attendance = Attendance::create($fields + ['outlet_id' => $id, 'method_in' => 'manual', 'method_out' => $out ? 'manual' : null]);
        }
        $this->longshift->syncAttendanceDay($id, (int) $data['user_id'], $in);
        if ($was !== null && ($was[0] !== (int) $data['user_id'] || ! $was[1]->isSameDay($in))) {
            $this->longshift->syncAttendanceDay($id, $was[0], $was[1]);
        }
        Audit::log('attendance.corrected', $attendance, ['user_id' => $data['user_id'], 'date' => $data['date'], 'note' => $data['note']], $request->user());

        return back()->with('success', 'Absensi disimpan.');
    }

    public function deleteRecord(Request $request, Attendance $attendance): RedirectResponse
    {
        abort_unless($attendance->outlet_id === $this->outlet->id(), 404);
        Audit::log('attendance.deleted', $attendance, ['user_id' => $attendance->user_id, 'date' => $attendance->work_date->toDateString()], $request->user());
        $attendance->delete();
        $this->longshift->syncAttendanceDay($attendance->outlet_id, $attendance->user_id, $attendance->work_date);

        return back()->with('success', 'Absensi dihapus.');
    }

    /**
     * Per person: days present, hours worked, times late, approved longshift hours, records still open.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function summary(array $records): array
    {
        $by = [];
        foreach ($records as $r) {
            $p = $by[$r['user_id']] ??= ['user_id' => $r['user_id'], 'name' => $r['name'], 'role' => $r['role'], 'dates' => [], 'hours' => 0.0, 'late' => 0, 'late_minutes' => 0, 'long_hours' => 0, 'long_dates' => [], 'open' => 0];
            $p['dates'][$r['work_date']] = true;
            $p['hours'] += (float) ($r['recognized_hours'] ?? $r['hours'] ?? 0);
            $p['late'] += ($r['late'] ?? 0) > 0 ? 1 : 0;
            $p['late_minutes'] += max(0, (int) ($r['late'] ?? 0));
            $p['open'] += $r['clock_out_at'] === null ? 1 : 0;
            if (($r['longshift']['status'] ?? '') === 'approved' && ! isset($p['long_dates'][$r['work_date']])) {
                $p['long_dates'][$r['work_date']] = true;
                $p['long_hours'] += $r['longshift']['approved_hours'];
            }
            $by[$r['user_id']] = $p;
        }

        return array_values(array_map(fn (array $p) => ['user_id' => $p['user_id'], 'name' => $p['name'], 'role' => $p['role'], 'days' => count($p['dates']), 'hours' => round($p['hours'], 1), 'late' => $p['late'], 'late_minutes' => $p['late_minutes'], 'long_hours' => $p['long_hours'], 'open' => $p['open']], $by));
    }

    /** @return array<int, array{name: string, since: string}> */
    private function onDuty(): array
    {
        return Attendance::query()->where('outlet_id', $this->outlet->id())->whereNull('clock_out_at')->where('clock_in_at', '>', now()->subHours(AttendanceService::STALE_HOURS))->with('user:id,name')->orderBy('clock_in_at')->get()
            ->map(fn (Attendance $a) => ['name' => $a->user->name ?? '-', 'since' => $a->clock_in_at->toIso8601String()])->all();
    }

    /**
     * Scheduled today, the shift started more than the grace ago, and not clocked in yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function absent(): array
    {
        $now = now();
        $minute = (int) $now->format('G') * 60 + (int) $now->format('i');
        $here = Attendance::query()->where('outlet_id', $this->outlet->id())->whereDate('work_date', $now->toDateString())->pluck('user_id')->all();
        $out = [];
        foreach (WorkSchedule::query()->where('outlet_id', $this->outlet->id())->whereDate('work_date', $now->toDateString())->whereNotIn('user_id', $here)->with(['type', 'user:id,name'])->get()->sortBy(fn (WorkSchedule $w) => $w->type->startMinute()) as $w) {
            $late = $minute - $w->type->startMinute();
            if ($late > $this->grace() && ! isset($out[$w->user_id])) {
                $out[$w->user_id] = ['name' => $w->user->name ?? '-', 'shift' => $w->type->name, 'starts_at' => substr($w->type->starts_at, 0, 5), 'late' => $late];
            }
        }

        return array_values($out);
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $today = now()->toDateString();

        return [(string) $request->input('from', now()->subDays(6)->toDateString()), (string) $request->input('to', $today)];
    }

    private function grace(): int
    {
        return max(0, (int) $this->settings->get('attendance.late_grace_minutes', 10));
    }

    /** @return array<int, array<string, mixed>> */
    private function records(string $from, string $to): array
    {
        $grace = $this->grace();
        $long = LongshiftApproval::query()->where('outlet_id', $this->outlet->id())->where('source', 'attendance')->whereBetween('work_date', [$from, $to])->get()
            ->keyBy(fn (LongshiftApproval $l) => $l->user_id.'|'.$l->work_date->toDateString());

        $rows = Attendance::query()->where('outlet_id', $this->outlet->id())->whereBetween('work_date', [$from, $to])->with('user:id,name')->orderByDesc('work_date')->orderBy('clock_in_at')->get()
            ->map(function (Attendance $a) use ($grace, $long) {
                $l = $long[$a->user_id.'|'.$a->work_date->toDateString()] ?? null;
                $hours = $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at, true) / 60, 1) : null;

                return [
                    'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->user->name ?? '-', 'role' => $a->user->getRoleNames()->first(), 'work_date' => $a->work_date->toDateString(),
                    'clock_in_at' => $a->clock_in_at->toIso8601String(), 'clock_out_at' => $a->clock_out_at?->toIso8601String(), 'method_in' => $a->method_in, 'note' => $a->note,
                    'hours' => $hours, 'recognized_hours' => $hours, 'late' => $this->service->lateMinutes($a, $grace),
                    'longshift' => $l === null ? null : ['hours' => $l->hours, 'approved_hours' => (int) $l->approved_hours, 'status' => $l->status],
                    'in_flag' => $a->in_flag, 'out_flag' => $a->out_flag, 'in_reason' => $a->in_reason, 'out_reason' => $a->out_reason, 'review_status' => $a->review_status,
                ];
            })->values()->all();

        // Hours beyond the schedule count only once the manager on duty has approved them: the last record of the day carries that difference.
        $lastOfDay = [];
        foreach ($rows as $i => $r) {
            $lastOfDay[$r['user_id'].'|'.$r['work_date']] = $i;     // rows run oldest first inside a day
        }
        foreach ($lastOfDay as $i) {
            $row = $rows[$i];
            $hours = $row['hours'] ?? null;
            $ls = $row['longshift'] ?? null;
            if ($ls !== null && $hours !== null) {
                $rows[$i]['recognized_hours'] = max(0, round($hours - $ls['hours'] + ($ls['status'] === 'approved' ? $ls['approved_hours'] : 0), 1));
            }
        }

        return $rows;
    }
}
