<?php

use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\AttendanceService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\FaceTemplate;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;

function attendanceOn(): void
{
    setting('attendance.enabled', true);
}

it('stays closed until attendance is switched on', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->get('/attendance/kiosk')->assertRedirect('/');
    attendanceOn();
    $this->actingAs($manager)->get('/attendance/kiosk')->assertOk()->assertInertia(fn ($p) => $p->component('staffing/kiosk'));
});

it('clocks a recognised person in, then out, and ignores a repeat scan', function () {
    attendanceOn();
    $kiosk = userWithRole('manager');
    $budi = userWithRole('waiter');
    enrolled($budi, 3);
    $this->travelTo(now()->setTime(8, 0));

    $in = $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3, 0.1)])->assertOk()->json();
    expect($in['status'])->toBe('in')->and($in['name'])->toBe($budi->name);
    expect(Attendance::query()->count())->toBe(1);

    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3)])->assertOk()->assertJsonPath('status', 'repeat');
    expect(Attendance::query()->count())->toBe(1);

    $this->travelTo(now()->setTime(16, 30));
    $out = $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3, 0.3)])->assertOk()->json();
    expect($out['status'])->toBe('out');
    $a = Attendance::query()->firstOrFail();
    expect($a->clock_out_at)->not->toBeNull()->and($a->method_in)->toBe('face');
});

it('does not book a stranger or an ambiguous face', function () {
    attendanceOn();
    $kiosk = userWithRole('manager');
    $budi = userWithRole('waiter');
    enrolled($budi, 3);

    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(9)])->assertOk()->assertJsonPath('status', 'no_match');
    expect(Attendance::query()->count())->toBe(0);

    // two people with practically the same face: refuse rather than guess
    $siti = userWithRole('kitchen');
    foreach ([0.0, 0.1] as $n) {
        FaceTemplate::create(['outlet_id' => $siti->outlet_id, 'user_id' => $siti->id, 'descriptor' => face(3, $n + 0.01), 'consented_at' => now()]);
    }
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3, 0.05)])->assertJsonPath('status', 'no_match');
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => [1, 2]])->assertUnprocessable();
});

it('lets the kiosk take back a scan for a short time only', function () {
    attendanceOn();
    $kiosk = userWithRole('manager');
    enrolled(userWithRole('waiter'), 3);

    $id = $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3)])->json('attendance_id');
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/undo', ['attendance_id' => $id, 'action' => 'in'])->assertJsonPath('undone', true);
    expect(Attendance::query()->count())->toBe(0);

    $id = $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3)])->json('attendance_id');
    $this->travelTo(now()->addSeconds(AttendanceService::UNDO_SECONDS + 5));
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/undo', ['attendance_id' => $id, 'action' => 'in'])->assertJsonPath('undone', false);
    expect(Attendance::query()->count())->toBe(1);
});

it('starts a new day instead of closing a record that was left open since yesterday', function () {
    attendanceOn();
    $kiosk = userWithRole('manager');
    $budi = userWithRole('waiter');
    enrolled($budi, 3);
    Attendance::create(['outlet_id' => $budi->outlet_id, 'user_id' => $budi->id, 'work_date' => now()->subDay()->toDateString(), 'clock_in_at' => now()->subHours(30), 'method_in' => 'face']);

    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['descriptor' => face(3)])->assertJsonPath('status', 'in');
    expect(Attendance::query()->count())->toBe(2)->and(Attendance::query()->whereNull('clock_out_at')->count())->toBe(2);
});

it('enrols faces only with consent and enough samples, and refuses a face that already belongs to someone else', function () {
    attendanceOn();
    $manager = userWithRole('manager');
    $budi = userWithRole('waiter');
    $siti = userWithRole('kitchen');
    $samples = [face(3), face(3, 0.2), face(3, 0.4)];

    $this->actingAs($manager)->post("/attendance/faces/{$budi->id}", ['samples' => $samples])->assertSessionHasErrors('consent');
    $this->actingAs($manager)->post("/attendance/faces/{$budi->id}", ['consent' => true, 'samples' => [face(3), face(3, 0.2)]])->assertSessionHasErrors('samples');
    expect(FaceTemplate::query()->count())->toBe(0);

    $this->actingAs($manager)->post("/attendance/faces/{$budi->id}", ['consent' => true, 'samples' => $samples])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(FaceTemplate::query()->where('user_id', $budi->id)->count())->toBe(3);

    $this->actingAs($manager)->post("/attendance/faces/{$siti->id}", ['consent' => true, 'samples' => $samples])->assertSessionHas('error');
    expect(FaceTemplate::query()->where('user_id', $siti->id)->count())->toBe(0);

    // enrolling again replaces the old samples
    $this->actingAs($manager)->post("/attendance/faces/{$budi->id}", ['consent' => true, 'samples' => $samples])->assertSessionHasNoErrors();
    expect(FaceTemplate::query()->where('user_id', $budi->id)->count())->toBe(3);

    $this->actingAs($manager)->delete("/attendance/faces/{$budi->id}")->assertSessionHas('success');
    expect(FaceTemplate::query()->count())->toBe(0);
});

it('lets only managers see and correct the log, and the kiosk only people with the kiosk permission', function () {
    attendanceOn();
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $today = now()->toDateString();

    $this->actingAs($waiter)->get('/attendance')->assertForbidden();
    $this->actingAs($waiter)->get('/attendance/kiosk')->assertForbidden();
    $this->actingAs($waiter)->post('/attendance/kiosk/scan', ['descriptor' => face(1)])->assertForbidden();
    $this->actingAs(userWithRole('cashier'))->get('/attendance/kiosk')->assertOk();

    $this->actingAs($manager)->post('/attendance/records', ['user_id' => $waiter->id, 'date' => $today, 'clock_in' => '08:00', 'clock_out' => '16:00', 'note' => 'lupa absen'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/attendance/records', ['user_id' => $waiter->id, 'date' => $today, 'clock_in' => '08:00'])->assertSessionHasErrors('note');
    $a = Attendance::query()->firstOrFail();
    expect($a->method_in)->toBe('manual')->and($a->edited_by)->toBe($manager->id);

    $this->actingAs($manager)->get("/attendance?from={$today}&to={$today}")->assertInertia(fn ($p) => $p->component('staffing/attendance')->has('records', 1)->where('records.0.hours', 8));
    $this->actingAs($manager)->put("/attendance/records/{$a->id}", ['user_id' => $waiter->id, 'date' => $today, 'clock_in' => '09:00', 'clock_out' => '17:30', 'note' => 'koreksi'])->assertSessionHasNoErrors();
    expect($a->fresh()->clock_in_at->format('H:i'))->toBe('09:00');
    $this->actingAs($manager)->get("/attendance/export?from={$today}&to={$today}&format=xlsx")->assertOk()->assertDownload();
    $this->actingAs($manager)->delete("/attendance/records/{$a->id}")->assertSessionHas('success');
    expect(Attendance::query()->count())->toBe(0);
});

it('counts lateness against the first planned shift, with a grace period', function () {
    attendanceOn();
    setting('attendance.late_grace_minutes', 10);
    $waiter = userWithRole('waiter');
    $type = WorkShiftType::create(['outlet_id' => app(OutletContext::class)->id(), 'name' => 'Pagi', 'starts_at' => '08:00', 'ends_at' => '16:00', 'is_active' => true]);
    WorkSchedule::create(['outlet_id' => $type->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->toDateString(), 'work_shift_type_id' => $type->id]);
    $service = app(AttendanceService::class);
    $day = now()->toDateString();
    $mk = fn (string $time) => Attendance::create(['outlet_id' => $type->outlet_id, 'user_id' => $waiter->id, 'work_date' => $day, 'clock_in_at' => "{$day} {$time}", 'method_in' => 'face']);

    expect($service->lateMinutes($mk('07:55'), 10))->toBe(0)->and($service->lateMinutes($mk('08:09'), 10))->toBe(0)->and($service->lateMinutes($mk('08:25'), 10))->toBe(25);
    $other = userWithRole('kitchen');
    expect($service->lateMinutes(Attendance::create(['outlet_id' => $type->outlet_id, 'user_id' => $other->id, 'work_date' => $day, 'clock_in_at' => "{$day} 09:00", 'method_in' => 'face']), 10))->toBeNull();
});
