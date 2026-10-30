<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Staffing\AttendanceService;
use App\Domain\Staffing\FaceMatcher;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\FaceTemplate;
use App\Domain\Staffing\Models\LongshiftApproval;
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

    public function __construct(private OutletContext $outlet, private AttendanceService $service, private FaceMatcher $matcher, private SettingsRepository $settings, private LongshiftService $longshift) {}

    public function kiosk(): Response
    {
        $id = $this->outlet->id();

        return Inertia::render('staffing/kiosk', [
            'enrolled' => FaceTemplate::query()->where('outlet_id', $id)->distinct('user_id')->count('user_id'),
            'undo_seconds' => AttendanceService::UNDO_SECONDS,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:in,out', 'descriptor' => 'required|array|size:128', 'descriptor.*' => 'required|numeric']);
        $result = $this->service->scan($this->outlet->id(), array_values(array_map('floatval', $data['descriptor'])), $data['action']);
        if ($result['status'] === 'no_match') {
            return response()->json(['status' => 'no_match']);
        }
        $a = $result['attendance'];
        $at = $a === null ? null : ($result['status'] === 'out' ? $a->clock_out_at : $a->clock_in_at);

        return response()->json([
            'status' => $result['status'], 'name' => $result['user']->name, 'at' => $at?->toIso8601String(), 'attendance_id' => $a?->id,
            'late' => $result['status'] === 'in' && $a !== null ? $this->service->lateMinutes($a, $this->grace()) : null,
        ]);
    }

    public function undo(Request $request): JsonResponse
    {
        $data = $request->validate(['attendance_id' => 'required|integer', 'action' => 'required|in:in,out']);

        return response()->json(['undone' => $this->service->undo($this->outlet->id(), (int) $data['attendance_id'], $data['action'])]);
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('staffing/attendance', [
            'filters' => compact('from', 'to'),
            'records' => $this->records($from, $to),
            'people' => User::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'grace' => $this->grace(),
        ]);
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $range = CarbonImmutable::parse($from)->translatedFormat('d M Y').' s/d '.CarbonImmutable::parse($to)->translatedFormat('d M Y');
        $rows = $this->records($from, $to);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Absensi {$from} {$to}", [
            new ReportSheet('Absensi', "Periode {$range}", ['Tanggal', 'Nama', 'Jabatan', 'Masuk', 'Pulang', 'Jam kerja', 'Terlambat (menit)', 'Longshift disetujui (jam)', 'Sumber', 'Catatan'], array_map(fn (array $r) => [
                CarbonImmutable::parse($r['work_date'])->translatedFormat('d M Y'), $r['name'], $r['role'] ?? '', CarbonImmutable::parse($r['clock_in_at'])->format('H:i'),
                $r['clock_out_at'] ? CarbonImmutable::parse($r['clock_out_at'])->format('H:i') : 'Belum pulang', $r['hours'] ?? '', $r['late'] ?? '', ($r['longshift']['status'] ?? '') === 'approved' ? $r['longshift']['approved_hours'] : '',
                match ($r['method_in']) {
                    'face' => 'Wajah', 'button' => 'Tombol', default => 'Manual'
                }, $r['note'] ?? '',
            ], $rows), ['Total jam', '', '', '', '', round(array_sum(array_column($rows, 'hours')), 1), '', '', '', '']),
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

        return Attendance::query()->where('outlet_id', $this->outlet->id())->whereBetween('work_date', [$from, $to])->with('user:id,name')->orderByDesc('work_date')->orderBy('clock_in_at')->get()
            ->map(function (Attendance $a) use ($grace, $long) {
                $l = $long[$a->user_id.'|'.$a->work_date->toDateString()] ?? null;

                return [
                    'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->user->name ?? '-', 'role' => $a->user->getRoleNames()->first(), 'work_date' => $a->work_date->toDateString(),
                    'clock_in_at' => $a->clock_in_at->toIso8601String(), 'clock_out_at' => $a->clock_out_at?->toIso8601String(), 'method_in' => $a->method_in, 'note' => $a->note,
                    'hours' => $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at, true) / 60, 1) : null, 'late' => $this->service->lateMinutes($a, $grace),
                    'longshift' => $l === null ? null : ['hours' => $l->hours, 'approved_hours' => (int) $l->approved_hours, 'status' => $l->status],
                ];
            })->values()->all();
    }
}
