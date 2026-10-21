<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\ActivityLog;
use App\Domain\Settings\OutletContext;
use App\Exports\ReportSheet;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Who did what and when, from the audit trail. */
class ActivityController extends Controller
{
    public function __construct(private ActivityLog $log, private OutletContext $outlet) {}

    public function index(Request $request): Response
    {
        [$from, $to, $user, $group, $q] = $this->filters($request);

        return Inertia::render('admin/activity', [
            'filters' => ['from' => $from, 'to' => $to, 'user_id' => $user, 'group' => $group, 'q' => $q],
            'rows' => $this->log->rows($from, $to, $user, $group, $q),
            'users' => User::query()->where('outlet_id', $this->outlet->id())->orderBy('name')->get(['id', 'name'])->all(),
            'groups' => collect($this->log->groups())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        [$from, $to, $user, $group, $q] = $this->filters($request);
        $rows = $this->log->rows($from, $to, $user, $group, $q, 5000);

        return ReportFile::download((string) $request->input('format', 'xlsx'), "Jejak aktivitas {$from} {$to}", [
            new ReportSheet('Jejak aktivitas', "Periode {$from} s/d {$to}", ['Waktu', 'Pelaku', 'Aksi', 'Kelompok', 'Detail'],
                array_map(fn ($r) => [$r['at'], $r['actor'], $r['event'], $r['group'], $r['detail']], $rows)),
        ]);
    }

    /** @return array{0: string, 1: string, 2: int|null, 3: string|null, 4: string|null} */
    private function filters(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'user_id' => 'nullable|integer', 'group' => 'nullable|string|max:20', 'q' => 'nullable|string|max:80']);
        $today = BusinessDate::for();

        return [
            $data['from'] ?? CarbonImmutable::parse($today)->subDays(6)->toDateString(), $data['to'] ?? $today,
            isset($data['user_id']) ? (int) $data['user_id'] : null, $data['group'] ?? null, $data['q'] ?? null,
        ];
    }
}
