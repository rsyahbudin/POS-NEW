<?php

use App\Domain\Notification\Models\AppNotification;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\AttendanceRequest;
use App\Domain\Staffing\Models\FaceTemplate;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;
use App\Models\User;

function reasonSetup(bool $schedule = true): array
{
    setting('attendance.enabled', true);
    $waiter = userWithRole('waiter');
    foreach ([0.0, 0.2, 0.4] as $n) {
        FaceTemplate::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'descriptor' => face(3, $n), 'consented_at' => now()]);
    }
    if ($schedule) {
        $type = WorkShiftType::create(['outlet_id' => $waiter->outlet_id, 'name' => 'Shift 1', 'starts_at' => '09:00', 'ends_at' => '18:00', 'is_active' => true]);
        WorkSchedule::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->toDateString(), 'work_shift_type_id' => $type->id, 'position' => 'waiter']);
    }

    return [$waiter, userWithRole('manager')];
}

function rawScan($test, User $kiosk, User $who, string $action, array $extra = [])
{
    return $test->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => $action, 'user_id' => $who->id, 'descriptor' => face(3)] + $extra);
}

it('lets a clock-in that fits the schedule through without a reason', function () {
    [$waiter, $kiosk] = reasonSetup();
    $this->travelTo(now()->setTime(8, 55));

    rawScan($this, $kiosk, $waiter, 'in')->assertOk()->assertJsonPath('status', 'in');
    expect(Attendance::query()->firstOrFail())->in_flag->toBeNull()->in_reason->toBeNull()->review_status->toBeNull();
});

it('asks for a reason before a clock-in that does not fit, and writes nothing until it has one', function () {
    [$waiter, $kiosk] = reasonSetup();
    $this->travelTo(now()->setTime(10, 20));          // 80 minutes after the shift began

    $first = rawScan($this, $kiosk, $waiter, 'in')->assertOk()->json();
    expect($first['status'])->toBe('need_reason')->and($first['flag'])->toBe('late')->and($first['text'])->toContain('80 menit')->and($first['ticket'])->toBeString();
    expect(Attendance::query()->count())->toBe(0);

    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $first['ticket'], 'reason' => 'abc'])->assertUnprocessable();
    $done = $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $first['ticket'], 'reason' => 'Macet parah di jalan raya'])->assertOk()->json();
    expect($done['status'])->toBe('in')->and($done['name'])->toBe($waiter->name);
    $a = Attendance::query()->firstOrFail();
    expect($a->in_flag)->toBe('late')->and($a->in_reason)->toBe('Macet parah di jalan raya')->and($a->review_status)->toBeNull();    // late needs a reason, not a review

    // the ticket works once
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $first['ticket'], 'reason' => 'sekali lagi ya'])->assertOk()->assertJsonPath('status', 'expired');
    expect(Attendance::query()->count())->toBe(1);
});

it('lets a ticket run out, and never lets one person use the ticket of another outlet or a made-up one', function () {
    [$waiter, $kiosk] = reasonSetup();
    $this->travelTo(now()->setTime(10, 20));
    $first = rawScan($this, $kiosk, $waiter, 'in')->json();

    $this->travelTo(now()->addMinutes(4));
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $first['ticket'], 'reason' => 'terlambat bangun'])->assertJsonPath('status', 'expired');
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => 'dibuat-buat', 'reason' => 'terlambat bangun'])->assertJsonPath('status', 'expired');
    expect(Attendance::query()->count())->toBe(0);
});

it('flags early arrival, early leaving and staying on after the shift, each with a reason', function () {
    [$waiter, $kiosk] = reasonSetup();
    $go = function (string $time, string $action, string $flag) use ($waiter, $kiosk) {
        $this->travelTo(now()->setTime(...array_map('intval', explode(':', $time))));
        $r = rawScan($this, $kiosk, $waiter, $action)->assertOk()->json();
        expect($r['status'])->toBe('need_reason')->and($r['flag'])->toBe($flag);

        return $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $r['ticket'], 'reason' => "alasan {$flag} ya"])->assertOk()->json();
    };

    expect($go('07:50', 'in', 'early')['status'])->toBe('in');
    expect($go('16:30', 'out', 'early_out')['status'])->toBe('out');
    $a = Attendance::query()->firstOrFail();
    expect($a->in_flag)->toBe('early')->and($a->out_flag)->toBe('early_out')->and($a->out_reason)->toBe('alasan early_out ya');

    // staying on: Shift 1 ends 18:00, out at 19:20 (within the same whole hour: no longshift, but a reason is on record)
    $b = Attendance::query()->create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->toDateString(), 'clock_in_at' => now()->setTime(17, 0), 'method_in' => 'face']);
    $a->delete();
    $this->travelTo(now()->setTime(19, 20));
    $r = rawScan($this, $kiosk, $waiter, 'out')->json();
    expect($r['flag'])->toBe('overstay')->and($r['text'])->toContain('80 menit');
});

it('turns staying on for hours into a longshift for the MOD, with the reason beside it, and counts those hours only when approved', function () {
    [$waiter, $kiosk] = reasonSetup();
    $mod = userWithRole('mod');
    $this->travelTo(now()->setTime(9, 0));
    rawScan($this, $kiosk, $waiter, 'in')->assertJsonPath('status', 'in');

    $this->travelTo(now()->setTime(20, 10));          // two hours after 18:00
    $r = rawScan($this, $kiosk, $waiter, 'out')->json();
    expect($r['flag'])->toBe('overstay');
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $r['ticket'], 'reason' => 'Menunggu serah terima dengan shift malam'])->assertJsonPath('status', 'out');

    $row = LongshiftApproval::query()->firstOrFail();
    expect($row->hours)->toBe(2)->and($row->status)->toBe('pending');
    $this->actingAs($mod)->get('/longshift')->assertInertia(fn ($p) => $p->where('pending.0.reasons.0', 'Menunggu serah terima dengan shift malam'));

    // not recognised until approved: 11 h 10 min on the clock, 2 h of it unapproved
    $today = now()->toDateString();
    $recognised = fn () => $this->actingAs(userWithRole('manager'))->get("/attendance?from={$today}&to={$today}")->viewData('page')['props']['records'][0]['recognized_hours'];
    expect($recognised())->toBe(9.2);
    $this->actingAs($mod)->post("/longshift/{$row->id}/decide", ['approve' => true, 'hours' => 1])->assertSessionHas('success');
    expect($recognised())->toBe(10.2);
});

it('does not ask the owner or the back office for a reason: they have no schedule', function () {
    [, $kiosk] = reasonSetup(false);
    foreach ([0.0, 0.2, 0.4] as $n) {
        FaceTemplate::create(['outlet_id' => $kiosk->outlet_id, 'user_id' => $kiosk->id, 'descriptor' => face(7, $n), 'consented_at' => now()]);
    }
    $this->travelTo(now()->setTime(13, 0));

    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => 'in', 'user_id' => $kiosk->id, 'descriptor' => face(7)])->assertOk()->assertJsonPath('status', 'in');
    expect(Attendance::query()->firstOrFail()->in_flag)->toBeNull();
});

it('puts a clock-in with no schedule in the MOD queue with its reason, and lets the MOD accept or throw it out', function () {
    [$waiter, $kiosk] = reasonSetup(false);
    $mod = userWithRole('mod');
    $this->travelTo(now()->setTime(11, 0));

    $r = rawScan($this, $kiosk, $waiter, 'in')->json();
    expect($r['flag'])->toBe('unscheduled');
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $r['ticket'], 'reason' => 'Diminta MOD menggantikan teman'])->assertJsonPath('status', 'in');
    $a = Attendance::query()->firstOrFail();
    expect($a->review_status)->toBe('pending')->and(AppNotification::query()->where('kind', 'attendance.review')->count())->toBe(1);
    $this->actingAs($mod)->get('/longshift')->assertInertia(fn ($p) => $p->has('reviews', 1)->where('reviews.0.reason', 'Diminta MOD menggantikan teman')->where('can.decide_attendance', true));

    // the clock-out of the same day does not ask for the same reason again
    $this->travelTo(now()->setTime(19, 0));
    rawScan($this, $kiosk, $waiter, 'out')->assertJsonPath('status', 'out');

    $this->actingAs($waiter)->post("/attendance/records/{$a->id}/review", ['approve' => true])->assertForbidden();
    $this->actingAs($mod)->post("/attendance/records/{$a->id}/review", ['approve' => false])->assertSessionHasErrors('note');
    $this->actingAs($mod)->post("/attendance/records/{$a->id}/review", ['approve' => true, 'note' => 'oke'])->assertSessionHas('success');
    expect($a->fresh()->review_status)->toBe('approved')->and($a->fresh()->reviewed_by)->toBe($mod->id);
    $this->actingAs($mod)->post("/attendance/records/{$a->id}/review", ['approve' => true])->assertSessionHas('error');   // already looked at
});

it('throws out a clock-in the MOD rejects: the record is gone, the person is told, longshift is recomputed', function () {
    [$waiter, $kiosk] = reasonSetup(false);
    $mod = userWithRole('mod');
    $this->travelTo(now()->setTime(8, 0));
    $r = rawScan($this, $kiosk, $waiter, 'in')->json();
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/reason', ['ticket' => $r['ticket'], 'reason' => 'Iseng datang pagi sekali'])->assertJsonPath('status', 'in');
    $a = Attendance::query()->firstOrFail();

    $this->actingAs($mod)->post("/attendance/records/{$a->id}/review", ['approve' => false, 'note' => 'Tidak ada tugas hari ini'])->assertSessionHas('success');
    expect(Attendance::query()->count())->toBe(0);
    expect(AppNotification::query()->where('user_id', $waiter->id)->where('kind', 'attendance.review_rejected')->count())->toBe(1);
});

it('lets a person who forgot to clock out ask for a correction, and the MOD put it right', function () {
    setting('attendance.enabled', true);
    $waiter = userWithRole('waiter');
    $mod = userWithRole('mod');
    $day = now()->subDays(2)->toDateString();
    $open = Attendance::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => $day, 'clock_in_at' => "{$day} 09:00", 'method_in' => 'face']);

    $ask = fn (array $over = []) => $this->actingAs($waiter)->post('/attendance/requests', $over + ['work_date' => $day, 'kind' => 'missing_out', 'clock_out' => '18:05', 'reason' => 'Lupa karena ramai pelanggan']);
    $ask(['reason' => 'ya'])->assertSessionHasErrors('reason');
    $ask(['work_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('work_date');
    $ask(['work_date' => now()->subDays(40)->toDateString()])->assertSessionHasErrors('work_date');
    $ask(['clock_out' => null])->assertSessionHasErrors('clock_out');
    $ask(['kind' => 'both', 'clock_in' => '09:00'])->assertSessionHas('error');                 // there is already a record that day

    $ask()->assertSessionHasNoErrors()->assertSessionHas('success');
    $req = AttendanceRequest::query()->firstOrFail();
    expect($req->status)->toBe('pending')->and(AppNotification::query()->where('kind', 'attendance.request')->count())->toBe(1);
    $ask()->assertSessionHas('error');                                                          // one pending request per day

    $this->actingAs($waiter)->post("/attendance/requests/{$req->id}/decide", ['approve' => true])->assertForbidden();
    $this->actingAs($mod)->post("/attendance/requests/{$req->id}/decide", ['approve' => false])->assertSessionHasErrors('note');
    $this->actingAs($mod)->post("/attendance/requests/{$req->id}/decide", ['approve' => true])->assertSessionHas('success');

    $open->refresh();
    expect($open->clock_out_at->format('H:i'))->toBe('18:05')->and($open->method_out)->toBe('manual')->and($open->edited_by)->toBe($mod->id)->and($open->note)->toContain('Lupa karena ramai pelanggan');
    expect($req->fresh())->status->toBe('approved')->attendance_id->toBe($open->id);
    expect(AppNotification::query()->where('user_id', $waiter->id)->where('kind', 'attendance.request_decided')->count())->toBe(1);
    expect(AppNotification::query()->where('kind', 'attendance.request')->whereNull('resolved_at')->count())->toBe(0);
    $this->actingAs($mod)->post("/attendance/requests/{$req->id}/decide", ['approve' => true])->assertSessionHas('error');
});

it('creates the whole day when someone forgot both, rejects with a note, and shows the person where their request stands', function () {
    setting('attendance.enabled', true);
    $waiter = userWithRole('waiter');
    $mod = userWithRole('mod');
    $day = now()->subDay()->toDateString();

    $this->actingAs($waiter)->post('/attendance/requests', ['work_date' => $day, 'kind' => 'both', 'clock_in' => '09:00', 'clock_out' => '17:30', 'reason' => 'HP mati, kiosk sepi'])->assertSessionHas('success');
    $req = AttendanceRequest::query()->firstOrFail();
    $this->actingAs($mod)->post("/attendance/requests/{$req->id}/decide", ['approve' => true])->assertSessionHas('success');
    $a = Attendance::query()->firstOrFail();
    expect($a->clock_in_at->format('H:i'))->toBe('09:00')->and($a->clock_out_at->format('H:i'))->toBe('17:30')->and($a->method_in)->toBe('manual');

    $day2 = now()->subDays(3)->toDateString();
    $this->actingAs($waiter)->post('/attendance/requests', ['work_date' => $day2, 'kind' => 'both', 'clock_in' => '09:00', 'clock_out' => '17:00', 'reason' => 'Katanya ada tapi lupa'])->assertSessionHas('success');
    $second = AttendanceRequest::query()->where('work_date', $day2)->firstOrFail();
    $this->actingAs($mod)->post("/attendance/requests/{$second->id}/decide", ['approve' => false, 'note' => 'Tidak ada di CCTV'])->assertSessionHas('success');
    expect(Attendance::query()->count())->toBe(1);

    $this->actingAs($waiter)->get('/attendance/mine')->assertInertia(fn ($p) => $p->has('requests', 2)->where('requests.0.status', 'rejected')->where('requests.0.decision_note', 'Tidak ada di CCTV')->where('requests.1.status', 'approved')->where('reach_days', 14));
});

it('tells a person who left a record open since yesterday, so they can ask for the correction', function () {
    setting('attendance.enabled', true);
    $waiter = userWithRole('waiter');
    $old = now()->subDays(2)->toDateString();
    Attendance::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => $old, 'clock_in_at' => "{$old} 09:00", 'method_in' => 'face']);

    $this->actingAs($waiter)->get('/attendance/mine')->assertInertia(fn ($p) => $p->where('forgot', [$old]));
});
