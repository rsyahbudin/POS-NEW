<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\AttendanceRequest;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The manager on duty approves or rejects hours beyond the standard shift. Approved hours go to the HR export. */
class LongshiftController extends Controller
{
    public function __construct(private OutletContext $outlet, private LongshiftService $service) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('staffing/longshift', [
            'filters' => compact('from', 'to'),
            'pending' => $this->rows(LongshiftApproval::query()->where('status', 'pending')->orderBy('work_date')),
            'decided' => $this->rows(LongshiftApproval::query()->where('status', '!=', 'pending')->whereBetween('work_date', [$from, $to])->orderByDesc('work_date')),
            'requests' => AttendanceRequest::query()->where('outlet_id', $this->outlet->id())->where('status', 'pending')->with('user:id,name')->oldest()->get()->map(fn (AttendanceRequest $q) => [
                'id' => $q->id, 'name' => $q->user->name ?? '-', 'work_date' => $q->work_date->toDateString(), 'kind' => $q->kind, 'clock_in' => $q->clock_in === null ? null : substr($q->clock_in, 0, 5),
                'clock_out' => $q->clock_out === null ? null : substr($q->clock_out, 0, 5), 'reason' => $q->reason, 'filed' => $q->created_at?->toIso8601String(),
            ])->all(),
            'reviews' => Attendance::query()->where('outlet_id', $this->outlet->id())->where('review_status', 'pending')->with('user:id,name')->oldest('clock_in_at')->get()->map(fn (Attendance $a) => [
                'id' => $a->id, 'name' => $a->user->name ?? '-', 'work_date' => $a->work_date->toDateString(), 'clock_in_at' => $a->clock_in_at->toIso8601String(), 'clock_out_at' => $a->clock_out_at?->toIso8601String(),
                'reason' => trim(($a->in_reason ?? '').' '.($a->out_reason ?? '')),
            ])->all(),
            'standard_hours' => intdiv($this->service->standardMinutes(), 60),
            'can' => ['decide' => (bool) $request->user()?->can('approve-longshift'), 'decide_attendance' => (bool) $request->user()?->can('approve-attendance')],
        ]);
    }

    public function decide(Request $request, LongshiftApproval $approval): RedirectResponse
    {
        abort_unless($approval->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['approve' => 'required|boolean', 'hours' => 'nullable|integer|min:0|max:24', 'note' => 'nullable|string|max:200']);
        $this->service->decide($approval, $this->actor($request), (bool) $data['approve'], isset($data['hours']) ? (int) $data['hours'] : null, $data['note'] ?? null);

        return back()->with('success', $data['approve'] ? 'Longshift disetujui.' : 'Longshift ditolak.');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->range($request);
        $range = CarbonImmutable::parse($from)->translatedFormat('d M Y').' s/d '.CarbonImmutable::parse($to)->translatedFormat('d M Y');
        $rows = array_filter($this->rows(LongshiftApproval::query()->where('status', 'approved')->whereBetween('work_date', [$from, $to])->orderBy('work_date')), fn (array $r) => $r['approved_hours'] > 0);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Longshift {$from} {$to}", [
            new ReportSheet('Longshift', "Periode {$range}", ['Tanggal', 'Nama', 'Jabatan', 'Dijadwalkan (jam)', 'Bekerja (jam)', 'Jam diajukan', 'Jam disetujui', 'Setara shift tambahan', 'Sisa jam', 'Disetujui oleh', 'Catatan'], array_map(fn (array $r) => [
                CarbonImmutable::parse($r['work_date'])->translatedFormat('d M Y'), $r['name'], $r['role'] ?? '', $r['planned_hours'], $r['worked_hours'], $r['hours'], $r['approved_hours'], $r['approved_shifts'], $r['approved_rest'], $r['decided_by'] ?? '', $r['note'] ?? '',
            ], array_values($rows)), ['Total', '', '', '', '', '', array_sum(array_column($rows, 'approved_hours')), array_sum(array_column($rows, 'approved_shifts')), array_sum(array_column($rows, 'approved_rest')), '', '']),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return [(string) $request->input('from', now()->startOfMonth()->toDateString()), (string) $request->input('to', now()->toDateString())];
    }

    /**
     * @param  Builder<LongshiftApproval>  $query
     * @return array<int, array<string, mixed>>
     */
    private function rows($query): array
    {
        return $query->where('outlet_id', $this->outlet->id())->with(['user:id,name', 'decider:id,name'])->get()->map(fn (LongshiftApproval $a) => [
            'reasons' => Attendance::query()->where('user_id', $a->user_id)->whereDate('work_date', $a->work_date)->get()->flatMap(fn (Attendance $r) => [$r->in_reason, $r->out_reason])->filter()->values()->all(),
            'planned_hours' => round((int) $a->planned_minutes / 60, 1), 'worked_hours' => round((int) $a->worked_minutes / 60, 1),
            'shifts' => $this->service->breakdown($a->hours)['shifts'], 'rest' => $this->service->breakdown($a->hours)['hours'],
            'approved_shifts' => $this->service->breakdown((int) $a->approved_hours)['shifts'], 'approved_rest' => $this->service->breakdown((int) $a->approved_hours)['hours'],
            'id' => $a->id, 'name' => $a->user->name ?? '-', 'role' => $a->user->getRoleNames()->first(), 'work_date' => $a->work_date->toDateString(), 'source' => $a->source,
            'hours' => $a->hours, 'approved_hours' => (int) $a->approved_hours, 'status' => $a->status, 'decided_by' => $a->decider?->name, 'note' => $a->note,
        ])->all();
    }
}
