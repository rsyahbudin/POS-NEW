<?php

use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\Events\ServiceCallChanged;
use App\Domain\Floor\Models\ServiceCall;
use App\Domain\Floor\Models\Table;
use App\Domain\Floor\ServiceCalls;
use App\Http\Presenters\MenuPresenter;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

function callTable(): Table
{
    return Table::query()->where('qr_enabled', true)->firstOrFail();
}

it('keeps the call button off until it is switched on', function () {
    $table = callTable();
    $this->get("/m/{$table->qr_token}")->assertInertia(fn ($p) => $p->where('call.enabled', false));
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help'])->assertStatus(422);
    expect(ServiceCall::query()->count())->toBe(0);
});

it('lets a guest call staff, shows it on the table map and rings only when allowed', function () {
    Event::fake([ServiceCallChanged::class]);
    setting('call.enabled', true);
    $table = callTable();

    $this->get("/m/{$table->qr_token}")->assertInertia(fn ($p) => $p->where('call.enabled', true)->has('call.reasons.bill'));
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'bill'])->assertOk()->assertJsonPath('call.status', 'pending')->assertJsonPath('call.reason', 'Minta bill');
    Event::assertDispatched(ServiceCallChanged::class, fn ($e) => $e->tableName === $table->name && $e->status === 'pending');

    // pressing again right away does not ring twice, one open call per table
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help'])->assertOk();
    expect(ServiceCall::query()->count())->toBe(1)->and(ServiceCall::query()->first()->repeats)->toBe(0);
    $this->travel(ServiceCalls::RERING_SECONDS + 1)->seconds();
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'other'])->assertOk();
    expect(ServiceCall::query()->first()->repeats)->toBe(1)->and(ServiceCall::query()->first()->reason)->toBe('other');

    $flat = collect(MenuPresenter::floor($table->outlet_id))->flatMap(fn ($a) => $a['tables']);
    expect($flat->firstWhere('id', $table->id)['calling'])->toBeTrue();

    // the QR page itself shows the state of the guest's call
    $this->getJson("/m/{$table->qr_token}/status")->assertJsonPath('call.status', 'pending');
});

it('sends calls to the cashier and waiter screen, the kitchen screen, or both, as set', function () {
    setting('call.enabled', true);
    $table = callTable();
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help'])->assertOk();
    $pos = userWithRole('cashier');
    $kitchen = userWithRole('kitchen');

    $this->actingAs($pos)->getJson('/pos/calls?to=pos')->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.table', $table->name);
    $this->actingAs($kitchen)->getJson('/pos/calls?to=kitchen')->assertJsonCount(0, 'calls');     // kitchen is off by default

    setting('call.to_kitchen', true);
    $this->actingAs($kitchen)->getJson('/pos/calls?to=kitchen')->assertJsonCount(1, 'calls');
    setting('call.to_pos', false);
    $this->actingAs($pos)->getJson('/pos/calls?to=pos')->assertJsonCount(0, 'calls');

    $this->actingAs($pos)->get('/pos')->assertInertia(fn ($p) => $p->where('can.handle_calls', true)->has('calls', 0));
    setting('call.to_pos', true);
    $this->actingAs($pos)->get('/pos')->assertInertia(fn ($p) => $p->has('calls', 1));
});

it('lets staff mark a call handled, and the guest sees it handled', function () {
    setting('call.enabled', true);
    $table = callTable();
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help']);
    $call = ServiceCall::query()->firstOrFail();

    $this->actingAs(userWithRole('waiter'))->postJson("/pos/calls/{$call->id}/done")->assertOk();
    $call->refresh();
    expect($call->status)->toBe('handled')->and($call->handled_by)->toBe(userWithRole('waiter')->id);
    $this->getJson("/m/{$table->qr_token}/status")->assertJsonPath('call.status', 'handled');

    // a new call after it was handled opens a fresh one
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help'])->assertOk();
    expect(ServiceCall::query()->count())->toBe(2);
});

it('needs the handle-calls permission on the staff side', function () {
    setting('call.enabled', true);
    $table = callTable();
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help']);
    $call = ServiceCall::query()->firstOrFail();
    $waiter = userWithRole('waiter');
    $waiter->roles->firstOrFail()->revokePermissionTo('handle-calls');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($waiter->fresh())->getJson('/pos/calls')->assertForbidden();
    $this->actingAs($waiter->fresh())->postJson("/pos/calls/{$call->id}/done")->assertForbidden();
    $this->actingAs($waiter->fresh())->get('/pos')->assertInertia(fn ($p) => $p->where('can.handle_calls', false)->has('calls', 0));
});

it('shows calls on the kitchen screen for people allowed to handle them', function () {
    setting('call.enabled', true);
    setting('call.to_kitchen', true);
    $table = callTable();
    $this->postJson("/m/{$table->qr_token}/call", ['reason' => 'help']);

    $station = Station::query()->firstOrFail();
    $this->actingAs(userWithRole('kitchen'))->get("/kds/{$station->id}")->assertInertia(fn ($p) => $p->where('canHandleCalls', true)->has('calls', 1));
});

it('tells the screens whether alarms keep ringing until handled, and offers no water request', function () {
    setting('call.enabled', true);
    $table = callTable();
    $this->get("/m/{$table->qr_token}")->assertInertia(fn ($p) => $p->has('call.reasons.bill')->missing('call.reasons.water'));

    $cashier = userWithRole('cashier');
    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('settings.keep_ringing', true)->where('settings.repeat_seconds', 5));
    setting('alert.repeat_seconds', 3);
    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('settings.repeat_seconds', 3));
    setting('alert.keep_ringing', false);
    $this->actingAs($cashier)->get('/pos')->assertInertia(fn ($p) => $p->where('settings.keep_ringing', false));
    $station = Station::query()->firstOrFail();
    $this->actingAs(userWithRole('kitchen'))->get("/kds/{$station->id}")->assertInertia(fn ($p) => $p->where('keepRinging', false));
});

it('tells the QR page whether a kitchen screen exists, so order progress is not promised without one', function () {
    $table = callTable();
    setting('kitchen.enabled', false);
    $this->get("/m/{$table->qr_token}")->assertInertia(fn ($p) => $p->where('kds', false));
    setting('kitchen.enabled', true);
    $this->get("/m/{$table->qr_token}")->assertInertia(fn ($p) => $p->where('kds', true));
});
