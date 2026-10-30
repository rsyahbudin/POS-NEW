<?php

use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\LongshiftService;
use App\Domain\Staffing\Models\Attendance;
use App\Domain\Staffing\Models\FaceTemplate;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;
use Spatie\Permission\Models\Role;

function shiftType(string $name, string $from, string $to): WorkShiftType
{
    return WorkShiftType::create(['outlet_id' => app(OutletContext::class)->id(), 'name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'is_active' => true]);
}

function staffingOn(): void
{
    setting('schedule.enabled', true);
    setting('attendance.enabled', true);
}

it('has a MOD role that can plan the schedule, approve longshift and cover any station', function () {
    $mod = userWithRole('mod');
    foreach (['manage-schedule', 'approve-longshift', 'create-order', 'receive-payment', 'update-kitchen-status', 'handle-calls'] as $p) {
        expect($mod->can($p))->toBeTrue($p);
    }
    expect(Role::findByName('mod')->allow_pin_login)->toBeTrue()->and(userWithRole('manager')->can('approve-longshift'))->toBeFalse()->and(userWithRole('owner')->can('approve-longshift'))->toBeTrue();
});

it('schedules one person on several stations in the same shift, defaulting to their own role', function () {
    staffingOn();
    $mod = userWithRole('mod');
    $cashier = userWithRole('cashier');
    $shift = shiftType('Shift 1', '09:00', '18:00');
    $day = now()->startOfWeek()->addWeek()->toDateString();

    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $mod->id, 'date' => $day, 'entries' => [['type_id' => $shift->id, 'positions' => ['mod', 'cashier']]]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $mod->id)->pluck('position')->sort()->values()->all())->toBe(['cashier', 'mod']);

    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => [['type_id' => $shift->id]]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->pluck('position')->all())->toBe(['cashier']);

    $this->actingAs($mod)->get("/schedule?week={$day}")->assertInertia(fn ($p) => $p->where('standard_hours', 9)->where("coverage.{$day}.cashier", 2)->where("coverage.{$day}.mod", 1)->where("coverage.{$day}.people", 2));
    // saving again replaces, a bogus station is dropped
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $mod->id, 'date' => $day, 'entries' => [['type_id' => $shift->id, 'positions' => ['waiter', 'ceo']]]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $mod->id)->pluck('position')->all())->toBe(['waiter']);
});

it('a nine hour shift is normal, more than nine hours in a day is longshift counted per whole hour', function () {
    staffingOn();
    $service = app(LongshiftService::class);
    expect($service->extraHours(9 * 60))->toBe(0)->and($service->extraHours(9 * 60 + 59))->toBe(0)->and($service->extraHours(12 * 60))->toBe(3)->and($service->extraHours(11 * 60 + 30))->toBe(2);

    setting('schedule.standard_hours', 8);
    expect($service->extraHours(9 * 60))->toBe(1);
});

it('two shifts planned in one day are a plan, never a longshift', function () {
    staffingOn();
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $a = shiftType('Shift 1', '09:00', '18:00');
    $b = shiftType('Shift 2', '14:00', '23:00');
    $day = now()->startOfWeek()->addWeek()->toDateString();

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day, 'entries' => [['type_id' => $a->id], ['type_id' => $b->id]]])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(LongshiftApproval::query()->count())->toBe(0)->and(AppNotification::query()->where('kind', 'longshift.pending')->count())->toBe(0);
    expect(app(LongshiftService::class)->plannedMinutes($waiter->id, now()->startOfWeek()->addWeek()))->toBe(14 * 60);
});

it('longshift is time worked beyond the schedule: a scheduled double shift is covered, more than that is not', function () {
    staffingOn();
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    $a = shiftType('Shift 1', '09:00', '18:00');
    $b = shiftType('Shift 2', '14:00', '23:00');
    $service = app(LongshiftService::class);
    $day = now()->subDay()->startOfDay();
    $work = fn (string $in, string $out) => Attendance::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => $day->toDateString(), 'clock_in_at' => $day->setTimeFromTimeString($in), 'clock_out_at' => $out > $in ? $day->setTimeFromTimeString($out) : $day->addDay()->setTimeFromTimeString($out), 'method_in' => 'face', 'method_out' => 'face']);

    // scheduled on both shifts (14 h), worked all 14 h: nothing to approve
    foreach ([$a, $b] as $t) {
        WorkSchedule::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => $day->toDateString(), 'work_shift_type_id' => $t->id, 'position' => 'waiter']);
    }
    $record = $work('09:00', '23:00');
    $service->syncAttendanceDay($waiter->outlet_id, $waiter->id, $day);
    expect(LongshiftApproval::query()->count())->toBe(0);

    // stays until 01:00: 2 h beyond the plan, with the plan and the real hours written down
    $record->update(['clock_out_at' => $day->addDay()->setTime(1, 0)]);
    $service->syncAttendanceDay($waiter->outlet_id, $waiter->id, $day);
    $row = LongshiftApproval::query()->firstOrFail();
    expect($row->hours)->toBe(2)->and($row->planned_minutes)->toBe(14 * 60)->and($row->worked_minutes)->toBe(16 * 60)->and($row->status)->toBe('pending');
    expect(AppNotification::query()->where('kind', 'longshift.pending')->first()->body)->toContain('16')->toContain('14');

    // the MOD corrects that past day's plan to cover the whole stretch: the longshift goes away
    WorkSchedule::query()->where('user_id', $waiter->id)->delete();
    $night = shiftType('Malam', '18:00', '01:00');
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day->toDateString(), 'entries' => [['type_id' => $a->id], ['type_id' => $night->id]]])->assertSessionHasNoErrors();
    expect(LongshiftApproval::query()->count())->toBe(0);

    // a day with no schedule at all (the back office): beyond one standard shift
    $boss = userWithRole('manager');
    Attendance::create(['outlet_id' => $boss->outlet_id, 'user_id' => $boss->id, 'work_date' => $day->toDateString(), 'clock_in_at' => $day->setTime(8, 0), 'clock_out_at' => $day->setTime(20, 30), 'method_in' => 'face', 'method_out' => 'face']);
    $service->syncAttendanceDay($boss->outlet_id, $boss->id, $day);
    expect(LongshiftApproval::query()->where('user_id', $boss->id)->firstOrFail()->hours)->toBe(3);
});

it('hours really worked beyond nine become a longshift when the person clocks out, and the MOD checks them again', function () {
    staffingOn();
    $kiosk = userWithRole('manager');
    $mod = userWithRole('mod');
    $budi = userWithRole('waiter');
    foreach ([0.0, 0.2, 0.4] as $n) {
        FaceTemplate::create(['outlet_id' => $budi->outlet_id, 'user_id' => $budi->id, 'descriptor' => face(3, $n), 'consented_at' => now()]);
    }
    $this->travelTo(now()->setTime(8, 0));
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => 'in', 'user_id' => $budi->id, 'descriptor' => face(3)])->assertJsonPath('status', 'in');
    expect(LongshiftApproval::query()->count())->toBe(0);

    $this->travelTo(now()->setTime(20, 30));    // 12.5 h at work
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => 'out', 'user_id' => $budi->id, 'descriptor' => face(3)])->assertJsonPath('status', 'out');
    $row = LongshiftApproval::query()->firstOrFail();
    expect($row->source)->toBe('attendance')->and($row->hours)->toBe(3)->and($row->status)->toBe('pending');

    $this->actingAs($mod)->get('/longshift')->assertOk()->assertInertia(fn ($p) => $p->component('staffing/longshift')->has('pending', 1)->where('pending.0.hours', 3)->where('can.decide', true));
    $this->actingAs($mod)->post("/longshift/{$row->id}/decide", ['approve' => false, 'note' => 'lupa absen pulang'])->assertSessionHas('success');
    expect($row->fresh()->status)->toBe('rejected')->and($row->fresh()->approved_hours)->toBe(0);

    // correcting the record to a normal day clears it
    $att = Attendance::query()->firstOrFail();
    $this->actingAs($mod)->get('/attendance')->assertForbidden();
    $this->actingAs(userWithRole('manager'))->put("/attendance/records/{$att->id}", ['user_id' => $budi->id, 'date' => $att->work_date->toDateString(), 'clock_in' => '08:00', 'clock_out' => '17:00', 'note' => 'koreksi'])->assertSessionHasNoErrors();
    expect(LongshiftApproval::query()->count())->toBe(0);
});

it('exports only approved longshift hours for HR', function () {
    staffingOn();
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    $day = now()->toDateString();
    foreach ([['approved', 2], ['rejected', 0], ['pending', 0]] as $i => [$status, $hours]) {
        LongshiftApproval::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->subDays($i)->toDateString(), 'source' => 'attendance', 'hours' => 3, 'approved_hours' => $hours, 'status' => $status, 'decided_by' => $mod->id]);
    }

    $this->actingAs($mod)->get("/longshift/export?from={$day}&to={$day}&format=xlsx")->assertOk()->assertDownload();
    $this->actingAs($mod)->get('/longshift?from='.now()->subDays(5)->toDateString().'&to='.$day)->assertInertia(fn ($p) => $p->has('decided', 2)->has('pending', 1));
    $this->actingAs($waiter)->get('/longshift')->assertForbidden();
});

it('stays closed when neither the schedule nor the attendance is on', function () {
    $mod = userWithRole('mod');
    $this->actingAs($mod)->get('/longshift')->assertRedirect('/');
    setting('schedule.enabled', true);
    $this->actingAs($mod)->get('/longshift')->assertOk();
});

it('counts every full standard shift of extra hours as one more shift, the rest as hours', function () {
    staffingOn();
    $service = app(LongshiftService::class);
    expect($service->breakdown(3))->toBe(['shifts' => 0, 'hours' => 3])->and($service->breakdown(9))->toBe(['shifts' => 1, 'hours' => 0])
        ->and($service->breakdown(11))->toBe(['shifts' => 1, 'hours' => 2])->and($service->breakdown(18))->toBe(['shifts' => 2, 'hours' => 0]);
    setting('schedule.standard_hours', 8);
    expect($service->breakdown(9))->toBe(['shifts' => 1, 'hours' => 1]);

    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    LongshiftApproval::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->toDateString(), 'source' => 'attendance', 'hours' => 11, 'approved_hours' => 10, 'status' => 'approved', 'decided_by' => $mod->id]);
    $this->actingAs($mod)->get('/longshift')->assertInertia(fn ($p) => $p->where('decided.0.approved_shifts', 1)->where('decided.0.approved_rest', 2)->where('decided.0.shifts', 1)->where('decided.0.rest', 3));
});

it('lets the MOD approve any longshift whether or not the MOD has a shift or an attendance that day', function () {
    staffingOn();
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    // the MOD is neither scheduled nor clocked in: approving is the MOD's job, not tied to a shift
    expect(WorkSchedule::query()->where('user_id', $mod->id)->count())->toBe(0)->and(Attendance::query()->where('user_id', $mod->id)->count())->toBe(0);
    $row = LongshiftApproval::create(['outlet_id' => $waiter->outlet_id, 'user_id' => $waiter->id, 'work_date' => now()->toDateString(), 'source' => 'attendance', 'hours' => 2, 'status' => 'pending']);

    $this->actingAs($mod)->get('/longshift')->assertOk()->assertInertia(fn ($p) => $p->has('pending', 1)->where('can.decide', true));
    $this->actingAs($mod)->post("/longshift/{$row->id}/decide", ['approve' => true])->assertSessionHas('success');
    expect($row->fresh()->status)->toBe('approved')->and($row->fresh()->decided_by)->toBe($mod->id);
});
