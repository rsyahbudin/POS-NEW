<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\AttendanceRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "I forgot to clock in / out": the person files a request with a reason, the manager on duty decides. Also the manager on
 * duty's decision on a clock-in that had no schedule behind it.
 */
class AttendanceRequestController extends Controller
{
    /** A request may reach back this many days. Older gaps are for the manager's own correction. */
    public const REACH_DAYS = 14;

    public function __construct(private OutletContext $outlet, private LongshiftService $longshift, private Notifier $notifier) {}

    public function store(Request $request): RedirectResponse
    {
        $id = $this->outlet->id();
        $user = $this->actor($request);
        $data = $request->validate([
            'work_date' => 'required|date|before_or_equal:today|after_or_equal:'.now()->subDays(self::REACH_DAYS)->toDateString(),
            'kind' => 'required|in:missing_out,both',
            'clock_in' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'required_if:kind,both'],
            'clock_out' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'reason' => 'required|string|min:5|max:200',
        ]);
        $date = CarbonImmutable::parse($data['work_date']);
        $records = Attendance::query()->where('user_id', $user->id)->whereDate('work_date', $date);

        if ($data['kind'] === 'missing_out' && ! $records->clone()->whereNull('clock_out_at')->exists()) {
            throw new OrderException('Tidak ada catatan masuk tanpa pulang pada tanggal itu. Pilih "Lupa absen masuk" bila tidak ada catatan sama sekali.');
        }
        if ($data['kind'] === 'both' && $records->exists()) {
            throw new OrderException('Sudah ada catatan absen pada tanggal itu. Pilih "Lupa absen pulang" bila hanya pulangnya yang terlewat.');
        }
        if (AttendanceRequest::query()->where('user_id', $user->id)->whereDate('work_date', $date)->where('status', 'pending')->exists()) {
            throw new OrderException('Sudah ada pengajuan yang menunggu untuk tanggal itu.');
        }

        $req = AttendanceRequest::create([
            'outlet_id' => $id, 'user_id' => $user->id, 'work_date' => $date->toDateString(), 'kind' => $data['kind'],
            'clock_in' => $data['kind'] === 'both' ? $data['clock_in'] : null, 'clock_out' => $data['clock_out'], 'reason' => trim($data['reason']),
        ]);
        Audit::log('attendance.request_filed', $req, ['date' => $date->toDateString(), 'kind' => $data['kind']], $user);
        $this->notifier->notify(
            $id, 'attendance.request', "{$user->name} mengajukan koreksi absen", $date->translatedFormat('d M Y').' · '.($data['kind'] === 'both' ? 'lupa masuk dan pulang' : 'lupa pulang').'. Alasan: '.trim($data['reason']),
            '/longshift', 'info', ['approve-attendance'], null, "attreq:{$req->id}",
        );

        return back()->with('success', 'Pengajuan dikirim. MOD akan memutuskan.');
    }

    public function decide(Request $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        abort_unless($attendanceRequest->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['approve' => 'required|boolean', 'note' => ['nullable', 'string', 'max:200', Rule::requiredIf(fn () => ! $request->boolean('approve'))]]);
        if ($attendanceRequest->status !== 'pending') {
            throw new OrderException('Pengajuan ini sudah diputuskan.');
        }
        $by = $this->actor($request);
        $approve = (bool) $data['approve'];

        DB::transaction(function () use ($attendanceRequest, $by, $approve, $data) {
            $record = $approve ? $this->apply($attendanceRequest, $by) : null;
            $attendanceRequest->update([
                'status' => $approve ? 'approved' : 'rejected', 'attendance_id' => $record?->id, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null,
            ]);
            Audit::log($approve ? 'attendance.request_approved' : 'attendance.request_rejected', $attendanceRequest, ['user_id' => $attendanceRequest->user_id, 'date' => $attendanceRequest->work_date->toDateString()], $by);
            $this->notifier->resolve($attendanceRequest->outlet_id, "attreq:{$attendanceRequest->id}");
            $this->notifier->notify(
                $attendanceRequest->outlet_id, 'attendance.request_decided', 'Pengajuan koreksi absen '.($approve ? 'disetujui' : 'ditolak'), $attendanceRequest->work_date->translatedFormat('d M Y').(! empty($data['note']) ? ' · '.$data['note'] : ''),
                '/attendance/mine', $approve ? 'info' : 'warning', null, $attendanceRequest->user_id,
            );
        });

        return back()->with('success', $approve ? 'Pengajuan disetujui dan absen diperbaiki.' : 'Pengajuan ditolak.');
    }

    /** The manager on duty looks at a clock-in that had no schedule: accept it, or throw it out with a reason. */
    public function review(Request $request, Attendance $attendance): RedirectResponse
    {
        abort_unless($attendance->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['approve' => 'required|boolean', 'note' => ['nullable', 'string', 'max:200', Rule::requiredIf(fn () => ! $request->boolean('approve'))]]);
        if ($attendance->review_status !== 'pending') {
            throw new OrderException('Absen ini sudah ditinjau.');
        }
        $by = $this->actor($request);
        $approve = (bool) $data['approve'];

        DB::transaction(function () use ($attendance, $by, $approve, $data) {
            $this->notifier->resolve($attendance->outlet_id, "attrev:{$attendance->id}");
            if ($approve) {
                $attendance->update(['review_status' => 'approved', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
                Audit::log('attendance.review_approved', $attendance, ['user_id' => $attendance->user_id], $by);

                return;
            }
            Audit::log('attendance.review_rejected', $attendance, ['user_id' => $attendance->user_id, 'date' => $attendance->work_date->toDateString(), 'in' => $attendance->clock_in_at->toIso8601String(), 'out' => $attendance->clock_out_at?->toIso8601String(), 'note' => $data['note'] ?? null], $by);
            $this->notifier->notify($attendance->outlet_id, 'attendance.review_rejected', 'Absen di luar jadwal tidak diterima', $attendance->work_date->translatedFormat('d M Y').' · '.($data['note'] ?? ''), '/attendance/mine', 'warning', null, $attendance->user_id);
            $attendance->delete();
            $this->longshift->syncAttendanceDay($attendance->outlet_id, $attendance->user_id, $attendance->work_date);
        });

        return back()->with('success', $approve ? 'Absen diterima.' : 'Absen ditolak dan dihapus dari catatan.');
    }

    /** Puts the record right as asked. */
    private function apply(AttendanceRequest $r, User $by): Attendance
    {
        $date = CarbonImmutable::parse($r->work_date->toDateString());
        $out = CarbonImmutable::parse($date->toDateString().' '.substr((string) $r->clock_out, 0, 5));
        $note = 'Pengajuan disetujui: '.$r->reason;

        if ($r->kind === 'missing_out') {
            $open = Attendance::query()->where('user_id', $r->user_id)->whereDate('work_date', $date)->whereNull('clock_out_at')->lockForUpdate()->latest('clock_in_at')->first()
                ?? throw new OrderException('Catatan masuk tanpa pulang pada tanggal itu sudah tidak ada.');
            $out = $out->lte($open->clock_in_at) ? $out->addDay() : $out;
            $open->update(['clock_out_at' => $out, 'method_out' => 'manual', 'note' => $note, 'edited_by' => $by->id]);
            $record = $open;
        } else {
            if (Attendance::query()->where('user_id', $r->user_id)->whereDate('work_date', $date)->exists()) {
                throw new OrderException('Sudah ada catatan absen pada tanggal itu.');
            }
            $in = CarbonImmutable::parse($date->toDateString().' '.substr((string) $r->clock_in, 0, 5));
            $out = $out->lte($in) ? $out->addDay() : $out;
            $record = Attendance::create([
                'outlet_id' => $r->outlet_id, 'user_id' => $r->user_id, 'work_date' => $date->toDateString(), 'clock_in_at' => $in, 'clock_out_at' => $out,
                'method_in' => 'manual', 'method_out' => 'manual', 'note' => $note, 'edited_by' => $by->id,
            ]);
        }
        $this->longshift->syncAttendanceDay($r->outlet_id, $r->user_id, $date);

        return $record;
    }
}
