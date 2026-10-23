<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shift\Actions\CloseShiftAction;
use App\Domain\Shift\Models\Shift;
use App\Support\BusinessDate;

function closeWithDifference(Shift $shift, $user): void
{
    app(CloseShiftAction::class)->handle($shift, $user, 50000, 10000, null, 'uang kembalian salah', 'rk-'.$shift->id);
}

it('undoes a close: the shift is open again and the variance and deposit journals are reversed', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    closeWithDifference($shift, $cashier);
    expect($shift->fresh()->closed_at)->not->toBeNull()
        ->and(JournalEntry::query()->where('memo', 'like', 'Selisih kas shift%')->whereNull('reversed_by')->count())->toBe(1);

    $this->actingAs($cashier)->post("/shift/{$shift->id}/reopen", ['reason' => 'salah pencet'])->assertRedirect('/shift')->assertSessionHas('success');

    $fresh = $shift->fresh();
    expect($fresh->closed_at)->toBeNull()->and($fresh->counted_cash)->toBeNull()->and($fresh->difference)->toBeNull()
        ->and(JournalEntry::query()->where('memo', 'like', 'Selisih kas shift%')->whereNotNull('reversed_by')->count())->toBe(1)
        ->and(JournalEntry::query()->where('memo', 'like', 'Setoran kas shift%')->whereNotNull('reversed_by')->count())->toBe(1);

    // closing again after the undo works and books fresh entries
    closeWithDifference($fresh, $cashier);
    expect(JournalEntry::query()->where('memo', 'like', 'Selisih kas shift%')->whereNull('reversed_by')->whereNull('reverses')->count())->toBe(1);
});

it('lets a cashier undo only their own recent close, and a manager any last close', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    app(CloseShiftAction::class)->handle($shift, userWithRole('manager'), 44000, 0, null, null, 'rk2');

    $this->actingAs($cashier)->post("/shift/{$shift->id}/reopen")->assertSessionHas('error');       // closed by the manager
    $this->actingAs(userWithRole('manager'))->post("/shift/{$shift->id}/reopen")->assertSessionHas('success');
    expect($shift->fresh()->closed_at)->toBeNull();

    app(CloseShiftAction::class)->handle($shift->fresh(), $cashier, 44000, 0, null, null, 'rk3');
    $this->travel(31)->minutes();
    $this->actingAs($cashier)->post("/shift/{$shift->id}/reopen")->assertSessionHas('error');       // too late for a cashier
    $this->actingAs(userWithRole('manager'))->post("/shift/{$shift->id}/reopen")->assertSessionHas('success');
    $this->actingAs(userWithRole('waiter'))->post("/shift/{$shift->id}/reopen")->assertForbidden();
});

it('refuses to reopen once another shift exists', function () {
    $manager = userWithRole('manager');
    $first = openShiftFor($manager, 0);
    app(CloseShiftAction::class)->handle($first, $manager, 0, 0, null, null, 'rk4');
    $second = openShiftFor($manager, 0);
    $this->actingAs($manager)->post("/shift/{$first->id}/reopen")->assertSessionHas('error');
    expect($second->fresh()->isOpen())->toBeTrue();
});

it('offers the undo on the shift page only to people who may use it', function () {
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    app(CloseShiftAction::class)->handle($shift, $cashier, 0, 0, null, null, 'rk6');

    $this->actingAs($cashier)->get('/shift')->assertInertia(fn ($p) => $p->where('can.reopen_id', $shift->id));
    $this->actingAs(userWithRole('waiter'))->get('/shift')->assertInertia(fn ($p) => $p->where('can.reopen_id', null));
});

it('dates sales by the open shift, so a shift running past midnight is one day, and by today otherwise', function () {
    $this->travelTo(now()->setTime(20, 0));
    $cashier = userWithRole('cashier');
    $shift = openShiftFor($cashier, 0);
    $day = $shift->business_date->toDateString();
    expect(BusinessDate::for())->toBe($day);

    $this->travelTo(now()->addDay()->setTime(1, 30));
    expect(BusinessDate::for())->toBe($day);

    app(CloseShiftAction::class)->handle($shift, $cashier, 0, 0, null, null, 'rk7');
    expect(BusinessDate::for())->toBe(now()->toDateString());
});
