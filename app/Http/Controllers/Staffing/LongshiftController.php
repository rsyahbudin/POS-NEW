<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
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
            'standard_hours' => intdiv($this->service->standardMinutes(), 60),
            'can' => ['decide' => (bool) $request->user()?->can('approve-longshift')],
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
            new ReportSheet('Longshift', "Periode {$range}", ['Tanggal', 'Nama', 'Jabatan', 'Asal', 'Jam diajukan', 'Jam disetujui', 'Disetujui oleh', 'Catatan'], array_map(fn (array $r) => [
                CarbonImmutable::parse($r['work_date'])->translatedFormat('d M Y'), $r['name'], $r['role'] ?? '', $r['source'] === 'schedule' ? 'Jadwal' : 'Absensi', $r['hours'], $r['approved_hours'], $r['decided_by'] ?? '', $r['note'] ?? '',
            ], array_values($rows)), ['Total jam', '', '', '', '', array_sum(array_column($rows, 'approved_hours')), '', '']),
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
            'id' => $a->id, 'name' => $a->user->name ?? '-', 'role' => $a->user->getRoleNames()->first(), 'work_date' => $a->work_date->toDateString(), 'source' => $a->source,
            'hours' => $a->hours, 'approved_hours' => (int) $a->approved_hours, 'status' => $a->status, 'decided_by' => $a->decider?->name, 'note' => $a->note,
        ])->all();
    }
}
