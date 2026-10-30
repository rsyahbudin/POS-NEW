<?php

use App\Domain\Settings\OutletContext;
use App\Domain\Staffing\Models\WorkSchedule;
use App\Domain\Staffing\Models\WorkShiftType;

function workType(string $name, string $from, string $to): WorkShiftType
{
    return WorkShiftType::create(['outlet_id' => app(OutletContext::class)->id(), 'name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'is_active' => true]);
}

function monday(): string
{
    return now()->startOfWeek()->toDateString();
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

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'type_ids' => [$pagi->id, $siang->id, $malam->id]])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->count())->toBe(3);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'type_ids' => [$pagi->id, $siang->id, $malam->id, $extra->id]])->assertSessionHasErrors('type_ids');
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->count())->toBe(3);

    // saving again replaces, it does not pile up
    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'type_ids' => [$malam->id]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->where('user_id', $cashier->id)->pluck('work_shift_type_id')->all())->toBe([$malam->id]);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => $day, 'type_ids' => []])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->count())->toBe(0);
});

it('refuses two shifts that overlap, and counts the hours of a shift past midnight', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $a = workType('Pagi', '08:00', '16:00');
    $b = workType('Sore', '15:00', '20:00');
    $late = workType('Malam', '22:00', '02:00');
    $ok = workType('Petang', '16:00', '20:00');

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => monday(), 'type_ids' => [$a->id, $b->id]])->assertSessionHas('error');
    expect(WorkSchedule::query()->count())->toBe(0);

    $this->actingAs($manager)->post('/schedule/day', ['user_id' => $waiter->id, 'date' => monday(), 'type_ids' => [$a->id, $ok->id]])->assertSessionHasNoErrors();
    expect(WorkSchedule::query()->count())->toBe(2);
    expect($late->durationMinutes())->toBe(240);
});

it('shows everyone the week, and lets only managers change it', function () {
    setting('schedule.enabled', true);
    $cashier = userWithRole('cashier');
    $pagi = workType('Pagi', '08:00', '16:00');
    WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $cashier->id, 'work_date' => monday(), 'work_shift_type_id' => $pagi->id]);

    $this->actingAs($cashier)->get('/schedule')->assertOk()->assertInertia(fn ($p) => $p->where('can.manage', false)->where('me', $cashier->id)
        ->where('people', fn ($people) => collect($people)->firstWhere('id', $cashier->id)['hours'] == 8)->where('coverage.'.monday(), 1));
    $this->actingAs($cashier)->post('/schedule/day', ['user_id' => $cashier->id, 'date' => monday(), 'type_ids' => []])->assertForbidden();
    $this->actingAs($cashier)->post('/schedule/types', ['name' => 'X', 'starts_at' => '08:00', 'ends_at' => '09:00'])->assertForbidden();
    expect(WorkSchedule::query()->count())->toBe(1);
});

it('copies last week onto this week without touching what is planned, and manages shift patterns', function () {
    setting('schedule.enabled', true);
    $manager = userWithRole('manager');
    $waiter = userWithRole('waiter');
    $pagi = workType('Pagi', '08:00', '16:00');
    $last = now()->startOfWeek()->subWeek();
    foreach ([0, 1, 2] as $i) {
        WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $waiter->id, 'work_date' => $last->addDays($i)->toDateString(), 'work_shift_type_id' => $pagi->id]);
    }
    WorkSchedule::create(['outlet_id' => $pagi->outlet_id, 'user_id' => $waiter->id, 'work_date' => monday(), 'work_shift_type_id' => $pagi->id]);

    $this->actingAs($manager)->post('/schedule/copy', ['week' => monday()])->assertSessionHas('success');
    expect(WorkSchedule::query()->whereBetween('work_date', [monday(), now()->startOfWeek()->addDays(6)->toDateString()])->count())->toBe(3);
    $this->actingAs($manager)->post('/schedule/copy', ['week' => monday()])->assertSessionHas('success');
    expect(WorkSchedule::query()->whereBetween('work_date', [monday(), now()->startOfWeek()->addDays(6)->toDateString()])->count())->toBe(3);

    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Malam', 'starts_at' => '22:00', 'ends_at' => '06:00'])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Salah', 'starts_at' => '08:00', 'ends_at' => '08:00'])->assertSessionHas('error');
    $this->actingAs($manager)->post('/schedule/types', ['name' => 'Salah', 'starts_at' => '8am', 'ends_at' => '10:00'])->assertSessionHasErrors('starts_at');
    $this->actingAs($manager)->put("/schedule/types/{$pagi->id}", ['name' => 'Pagi', 'starts_at' => '07:00', 'ends_at' => '15:00', 'is_active' => false])->assertSessionHasNoErrors();
    expect($pagi->fresh()->is_active)->toBeFalse();
});
