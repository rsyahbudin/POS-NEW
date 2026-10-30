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
    $day = now()->startOfWeek()->toDateString();

    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $mod->id, 'date' => $day, 'entries' => [['type_id' => $shift->id, 'positions' => ['mod', 'cashier']]]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $mod->id)->pluck('position')->sort()->values()->all())->toBe(['cashier', 'mod']);

    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => [['type_id' => $shift->id]]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->pluck('position')->all())->toBe(['cashier']);

    $this->actingAs($mod)->get('/schedule')->assertInertia(fn ($p) => $p->where('standard_hours', 9)->where("coverage.{$day}.cashier", 2)->where("coverage.{$day}.mod", 1)->where("coverage.{$day}.people", 2));
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

it('a planned longshift waits for the MOD, unless the planner may approve it', function () {
    staffingOn();
    $manager = userWithRole('manager');
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    $a = shiftType('Pagi', '08:00', '17:00');   // 9 h
    $b = shiftType('Malam', '18:00', '21:00');  // 3 h
    $day = now()->startOfWeek()->toDateString();

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day, 'entries' => [['type_id' => $a->id]]])->assertSessionHasNoErrors();
    expect(LongshiftApproval::query()->count())->toBe(0);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day, 'entries' => [['type_id' => $a->id], ['type_id' => $b->id]]])->assertSessionHasNoErrors();
    $row = LongshiftApproval::query()->firstOrFail();
    expect($row->hours)->toBe(3)->and($row->status)->toBe('pending')->and($row->source)->toBe('schedule');
    expect(AppNotification::query()->where('kind', 'longshift.pending')->count())->toBe(1);

    // the manager has no right to approve
    $this->actingAs($manager)->post("/longshift/{$row->id}/decide", ['approve' => true])->assertForbidden();
    // the MOD approves, for fewer hours than asked
    $this->actingAs($mod)->post("/longshift/{$row->id}/decide", ['approve' => true, 'hours' => 2, 'note' => 'ok'])->assertSessionHas('success');
    expect($row->fresh()->status)->toBe('approved')->and($row->fresh()->approved_hours)->toBe(2)->and($row->fresh()->decided_by)->toBe($mod->id);
    expect(AppNotification::query()->where('kind', 'longshift.pending')->whereNull('resolved_at')->count())->toBe(0);

    // a MOD planning a longshift for someone: approved on the spot, under their name
    $kitchen = userWithRole('kitchen');
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $kitchen->id, 'date' => $day, 'entries' => [['type_id' => $a->id], ['type_id' => $b->id]]])->assertSessionHasNoErrors();
    $own = LongshiftApproval::query()->where('user_id', $kitchen->id)->firstOrFail();
    expect($own->status)->toBe('approved')->and($own->decided_by)->toBe($mod->id)->and($own->approved_hours)->toBe(3);

    // planning back to a normal day removes the longshift
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $kitchen->id, 'date' => $day, 'entries' => [['type_id' => $a->id]]])->assertSessionHasNoErrors();
    expect(LongshiftApproval::query()->where('user_id', $kitchen->id)->count())->toBe(0);
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
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => 'in', 'descriptor' => face(3)])->assertJsonPath('status', 'in');
    expect(LongshiftApproval::query()->count())->toBe(0);

    $this->travelTo(now()->setTime(20, 30));    // 12.5 h at work
    $this->actingAs($kiosk)->postJson('/attendance/kiosk/scan', ['action' => 'out', 'descriptor' => face(3)])->assertJsonPath('status', 'out');
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
