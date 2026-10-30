<?php

use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\Models\LongshiftApproval;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;
use App\Domain\Staffing\ShiftHours;

function workType(string $name, string $from, string $to): WorkShiftType
{
    return WorkShiftType::create(['outlet_id' => app(OutletContext::class)->id(), 'name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'is_active' => true]);
}

/** @param  list<int>  $ids */
function entries(array $ids): array
{
    return array_map(fn (int $id) => ['type_id' => $id], $ids);
}

function monday(): string
{
    return now()->startOfWeek()->addWeek()->toDateString();
}

it('stays closed until the schedule is switched on in the settings', function () {
    $manager = userWithRole('manager');
    $this->actingAs($manager)->get('/schedule')->assertRedirect('/');
    $this->actingAs($manager)->get('/dashboard')->assertInertia(fn ($p) => $p->where('features.schedule', false));

    setting('schedule.enabled', true);
    $this->actingAs($manager)->get('/schedule')->assertOk()->assertInertia(fn ($p) => $p->component('staffing/schedule')->where('can.manage', true)->where('max_per_day', 3));
});

it('lets a manager give a person up to three shifts in one day, and a day off by clearing them', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $cashier = userWithRole('cashier');
    $pagi = workType('Pagi', '06:00', '10:00');
    $siang = workType('Siang', '11:00', '15:00');
    $malam = workType('Malam', '17:00', '23:00');
    $extra = workType('Tambahan', '23:30', '23:50');
    $day = monday();

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => entries([$pagi->id, $siang->id, $malam->id])])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->count())->toBe(3);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => entries([$pagi->id, $siang->id, $malam->id, $extra->id])])->assertSessionHasErrors('entries');
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->count())->toBe(3);

    // saving again replaces, it does not pile up
    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => entries([$malam->id])])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->pluck('work_shift_type_id')->all())->toBe([$malam->id]);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => entries([])])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->count())->toBe(0);
});

it('lets shift patterns overlap on purpose, and counts the hours a person works on both once', function () {
    setting('schedule.enabled', true);
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    $cashier = userWithRole('cashier');
    $s1 = workType('Shift 1', '09:00', '18:00');
    $s2 = workType('Shift 2', '14:00', '23:00');
    $night = workType('Malam', '22:00', '02:00');
    $day = monday();

    // one person on each: nobody refused, the afternoon is covered twice
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day, 'entries' => entries([$s1->id])])->assertSessionHasNoErrors();
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'entries' => entries([$s2->id])])->assertSessionHasNoErrors();
    expect(LongshiftApproval::query()->count())->toBe(0);

    // the same person on both works 09:00-23:00 = 14 h, not 18 h; two shifts in a day is a plan, never a longshift
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $day, 'entries' => entries([$s1->id, $s2->id])])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WorkSchedule::query()->where('user_id', $waiter->id)->count())->toBe(2);
    expect(LongshiftApproval::query()->count())->toBe(0);
    $this->actingAs($mod)->get("/schedule?week={$day}")->assertInertia(fn ($p) => $p->where('people', fn ($people) => collect($people)->firstWhere('id', $waiter->id)['hours'] == 14));

    expect($night->durationMinutes())->toBe(240)->and(ShiftHours::unionMinutes([$s1, $s2]))->toBe(14 * 60)
        ->and(ShiftHours::unionMinutes([$s1, $night]))->toBe(9 * 60 + 4 * 60)
        ->and(ShiftHours::unionMinutes([$s2, $s1, $s2]))->toBe(14 * 60)
        ->and(ShiftHours::unionMinutes([]))->toBe(0);
});

it('shows everyone the week, and lets only managers change it', function () {
    setting('schedule.enabled', true);
    $cashier = userWithRole('cashier');
    $pagi = workType('Pagi', '08:00', '16:00');
    WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $cashier->id, 'work_date' => monday(), 'work_shift_type_id' => $pagi->id]);

    $this->actingAs($cashier)->get('/schedule?week='.monday())->assertOk()->assertInertia(fn ($p) => $p->where('can.manage', false)->where('me', $cashier->id)
        ->where('people', fn ($people) => collect($people)->firstWhere('id', $cashier->id)['hours'] == 8)->where('coverage.'.monday().'.people', 1));
    $this->actingAs($cashier)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => monday(), 'entries' => entries([])])->assertForbidden();
    $this->actingAs($cashier)->post('/schedule/types', ['name' => 'X', 'starts_at' => '08:00', 'ends_at' => '09:00'])->assertForbidden();
    expect(WorkSchedule::query()->count())->toBe(1);
});

it('copies last week onto this week without touching what is planned, and manages shift patterns', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $pagi = workType('Pagi', '08:00', '16:00');
    $last = now()->startOfWeek();
    foreach ([0, 1, 2] as $i) {
        WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $waiter->id, 'work_date' => $last->addDays($i)->toDateString(), 'work_shift_type_id' => $pagi->id]);
    }
    WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $waiter->id, 'work_date' => monday(), 'work_shift_type_id' => $pagi->id]);

    $this->actingAs($manager)->post('/schedule/copy', ['week' => monday()])->assertSessionHas('success');
    expect(WorkSchedule::query()->whereBetween('work_date', [monday(), now()->startOfWeek()->addWeek()->addDays(6)->toDateString()])->count())->toBe(3);
    $this->actingAs($manager)->post('/schedule/copy', ['week' => monday()])->assertSessionHas('success');
    expect(WorkSchedule::query()->whereBetween('work_date', [monday(), now()->startOfWeek()->addWeek()->addDays(6)->toDateString()])->count())->toBe(3);

    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Malam', 'starts_at' => '22:00', 'ends_at' => '06:00'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Salah', 'starts_at' => '08:00', 'ends_at' => '08:00'])->assertSessionHas('error');
    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Salah', 'starts_at' => '8am', 'ends_at' => '10:00'])->assertSessionHasErrors('starts_at');
    $this->actingAs($manager)->put("/schedule/types/{$pagi->id}", ['name' => 'Pagi', 'starts_at' => '08:00', 'ends_at' => '16:00', 'is_active' => false])->assertSessionHasNoErrors();
    expect($pagi->fresh()->is_active)->toBeFalse();
});

it('keeps history safe: past days only for MOD or owner, a pattern used in the past keeps its hours', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $mod = userWithRole('mod');
    $waiter = userWithRole('waiter');
    $pagi = workType('Pagi', '08:00', '16:00');
    $yesterday = now()->subDay()->toDateString();

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $yesterday, 'entries' => entries([$pagi->id])])->assertSessionHas('error');
    expect(WorkSchedule::query()->count())->toBe(0);
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $yesterday, 'entries' => entries([$pagi->id])])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WorkSchedule::query()->count())->toBe(1);

    // the hours of a pattern already used in a past day cannot be rewritten
    $this->actingAs($manager)->put("/schedule/types/{$pagi->id}", ['name' => 'Pagi', 'starts_at' => '07:00', 'ends_at' => '17:00', 'is_active' => true])->assertSessionHas('error');
    expect(substr($pagi->fresh()->starts_at, 0, 5))->toBe('08:00');
    // but a rename is fine, and so is a pattern used only from today on
    $this->actingAs($manager)->put("/schedule/types/{$pagi->id}", ['name' => 'Shift 1', 'starts_at' => '08:00', 'ends_at' => '16:00', 'is_active' => true])->assertSessionHasNoErrors()->assertSessionHas('success');
    $future = workType('Sore', '16:00', '20:00');
    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => now()->addDays(2)->toDateString(), 'entries' => entries([$future->id])])->assertSessionHasNoErrors();
    $this->actingAs($manager)->put("/schedule/types/{$future->id}", ['name' => 'Sore', 'starts_at' => '15:00', 'ends_at' => '20:00', 'is_active' => true])->assertSessionHas('success');
    expect(substr($future->fresh()->starts_at, 0, 5))->toBe('15:00');
});

it('tells a worker when their upcoming schedule is changed, but not for the planner themselves or for history', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $pagi = workType('Pagi', '08:00', '16:00');
    $soon = now()->addDays(2)->toDateString();

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $soon, 'entries' => entries([$pagi->id])])->assertSessionHasNoErrors();
    $n = AppNotification::query()->where('kind', 'schedule.changed')->get();
    expect($n)->toHaveCount(1)->and($n[0]->user_id)->toBe($waiter->id)->and($n[0]->body)->toContain('libur')->and($n[0]->body)->toContain('Pagi');

    // saving the same plan again changes nothing: no second notice
    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => $soon, 'entries' => entries([$pagi->id])])->assertSessionHasNoErrors();
    expect(AppNotification::query()->where('kind', 'schedule.changed')->count())->toBe(1);

    // planning your own day does not notify you
    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $manager->id, 'date' => $soon, 'entries' => entries([$pagi->id])])->assertSessionHasNoErrors();
    expect(AppNotification::query()->where('kind', 'schedule.changed')->where('user_id', $manager->id)->count())->toBe(0);
});

it('lets everyone download the team week and their own month as PDF or Excel', function () {
    setting('schedule.enabled', true);
    $waiter = userWithRole('waiter');
    $pagi = workType('Pagi', '08:00', '16:00');
    WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $waiter->id, 'work_date' => monday(), 'work_shift_type_id' => $pagi->id, 'position' => 'waiter']);

    $week = monday();
    $this->actingAs($waiter)->get("/schedule/export?scope=team&week={$week}&format=pdf")->assertOk();
    $this->actingAs($waiter)->get("/schedule/export?scope=team&week={$week}&format=xlsx")->assertOk()->assertDownload();
    $this->actingAs($waiter)->get('/schedule/export?scope=mine&month='.now()->format('Y-m').'&format=pdf')->assertOk();
    $this->actingAs($waiter)->get('/schedule/export?scope=other')->assertSessionHasErrors('scope');
    $this->actingAs(userWithRole('cashier'))->get('/schedule/export?scope=mine&month='.now()->format('Y-m').'&format=xlsx')->assertOk();
});

it('fills days quickly: tap toggles a shift, a row fills many days, clear makes days off, all saved together', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $cashier = userWithRole('cashier');
    $a = workType('Shift 1', '09:00', '18:00');
    $b = workType('Shift 2', '14:00', '23:00');
    $days = collect(range(0, 6))->map(fn (int $i) => now()->startOfWeek()->addWeek()->addDays($i)->toDateString())->all();
    $paint = fn (array $body) => $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $cashier->id] + $body);
    $rows = fn () => WorkSchedule::query()->where('user_id', $cashier->id);

    // a tap adds the shift with the person's own station, the next tap on the same shift takes it away
    $paint(['dates' => [$days[0]], 'mode' => 'toggle', 'type_id' => $a->id])->assertSessionHasNoErrors()->assertSessionHas('success', '1 hari disimpan.');
    expect($rows()->count())->toBe(1)->and($rows()->value('position'))->toBe('cashier');
    $paint(['dates' => [$days[0]], 'mode' => 'toggle', 'type_id' => $a->id])->assertSessionHas('success');
    expect($rows()->count())->toBe(0);

    // a row: Monday to Friday, then the same again changes nothing
    $paint(['dates' => array_slice($days, 0, 5), 'mode' => 'add', 'type_id' => $a->id])->assertSessionHas('success', '5 hari disimpan.');
    expect($rows()->count())->toBe(5);
    $paint(['dates' => array_slice($days, 0, 5), 'mode' => 'add', 'type_id' => $a->id])->assertSessionHas('success', 'Tidak ada yang berubah.');
    expect($rows()->count())->toBe(5);

    // a second shift on top
    $paint(['dates' => [$days[1]], 'mode' => 'toggle', 'type_id' => $b->id])->assertSessionHasNoErrors();
    expect($rows()->whereDate('work_date', $days[1])->count())->toBe(2);
    // a cashier cannot be put on another station
    $paint(['dates' => [$days[1]], 'mode' => 'toggle', 'type_id' => $a->id, 'position' => 'mod'])->assertSessionHas('error');
    expect($rows()->whereDate('work_date', $days[1])->count())->toBe(2);

    // the MOD covers an empty station: Kasir + MOD on the same shift, and tapping MOD again takes only that station away
    $mod = userWithRole('mod');
    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $mod->id, 'dates' => [$days[2]], 'mode' => 'toggle', 'type_id' => $a->id])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $mod->id, 'dates' => [$days[2]], 'mode' => 'toggle', 'type_id' => $a->id, 'position' => 'cashier'])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $mod->id)->pluck('position')->sort()->values()->all())->toBe(['cashier', 'mod']);
    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $mod->id, 'dates' => [$days[2]], 'mode' => 'toggle', 'type_id' => $a->id, 'position' => 'cashier'])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $mod->id)->pluck('position')->all())->toBe(['mod']);

    // clear the week
    $paint(['dates' => $days, 'mode' => 'clear'])->assertSessionHas('success');
    expect($rows()->count())->toBe(0);

    // invalid: no shift chosen, unknown station, too many days
    $paint(['dates' => [$days[0]], 'mode' => 'add'])->assertSessionHas('error');
    $paint(['dates' => [$days[0]], 'mode' => 'add', 'type_id' => $a->id, 'position' => 'ceo'])->assertSessionHas('error');
    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $cashier->id, 'dates' => [], 'mode' => 'clear'])->assertSessionHasErrors('dates');
    $this->actingAs(userWithRole('waiter'))->post('/schedule/paint', ['user_id' => $cashier->id, 'dates' => [$days[0]], 'mode' => 'clear'])->assertForbidden();
});

it('saves nothing at all when one of the days may not be changed, and tells the worker once for a whole row', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $a = workType('Shift 1', '09:00', '18:00');
    $past = now()->subDay()->toDateString();
    $soon = [now()->addDays(2)->toDateString(), now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString()];

    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $waiter->id, 'dates' => [$soon[0], $past], 'mode' => 'add', 'type_id' => $a->id])->assertSessionHas('error');
    expect(WorkSchedule::query()->count())->toBe(0);                        // the future day was rolled back too

    $this->actingAs($manager)->post('/schedule/paint', ['user_id' => $waiter->id, 'dates' => $soon, 'mode' => 'add', 'type_id' => $a->id])->assertSessionHasNoErrors();
    $n = AppNotification::query()->where('kind', 'schedule.changed')->get();
    expect($n)->toHaveCount(1)->and($n[0]->title)->toContain('3 hari');
});

it('schedules floor staff only, grouped by their own station: no owner, no manager, no foreign station', function () {
    setting('schedule.enabled', true);
    $mod = userWithRole('mod');
    $a = workType('Shift 1', '09:00', '18:00');
    $day = monday();

    $this->actingAs($mod)->get("/schedule?week={$day}")->assertInertia(fn ($p) => $p->where('people', function ($people) {
        $by = collect($people)->keyBy('name');

        return $by->keys()->sort()->values()->all() === ['Dapur', 'Kasir', 'MOD', 'Waiter'] && $by['Dapur']['default_position'] === 'kitchen' && $by['Dapur']['allowed_positions'] === ['kitchen']
            && count($by['MOD']['allowed_positions']) === 4;
    }));

    foreach (['owner', 'manager'] as $role) {
        $boss = userWithRole($role);
        $this->actingAs($mod)->post('/schedule/day', ['user_id' => $boss->id, 'date' => $day, 'entries' => entries([$a->id])])->assertSessionHas('error');
        $this->actingAs($mod)->post('/schedule/paint', ['user_id' => $boss->id, 'dates' => [$day], 'mode' => 'add', 'type_id' => $a->id])->assertSessionHas('error');
    }
    expect(WorkSchedule::query()->count())->toBe(0);

    $kitchen = userWithRole('kitchen');
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $kitchen->id, 'date' => $day, 'entries' => [['type_id' => $a->id, 'positions' => ['cashier']]]])->assertSessionHas('error');
    $this->actingAs($mod)->post('/schedule/day', ['user_id' => $kitchen->id, 'date' => $day, 'entries' => [['type_id' => $a->id]]])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WorkSchedule::query()->where('user_id', $kitchen->id)->pluck('position')->all())->toBe(['kitchen']);

    $this->actingAs($mod)->get("/schedule/export?scope=team&week={$day}&format=xlsx")->assertOk()->assertDownload();
});
