<?php

use App\Domain\Notification\Models\AppNotification;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\PrintBridge;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\Models\Setting;

function lanPrinter(string $kind = 'ticket', string $host = '192.168.1.50'): Printer
{
    return Printer::create(['outlet_id' => (int) Outlet::query()->value('id'), 'name' => 'LAN '.$kind, 'kind' => $kind, 'paper_width' => 58, 'copies' => 1, 'host' => $host, 'port' => 9100]);
}

function bridgeHeaders(): array
{
    return ['Authorization' => 'Bearer '.app(PrintBridge::class)->issue()];
}

it('lets only someone who manages printers make a bridge token, and keeps just its hash', function () {
    $this->actingAs(userWithRole('cashier'))->postJson('/admin/printers/bridge-token')->assertForbidden();

    $token = $this->actingAs(userWithRole('owner'))->postJson('/admin/printers/bridge-token')->assertOk()->json('token');

    expect($token)->toStartWith('pb_')->and(app(PrintBridge::class)->accepts($token))->toBeTrue()->and(app(PrintBridge::class)->accepts('pb_wrong'))->toBeFalse();
    expect(Setting::query()->where('key', 'print.bridge_token_hash')->first()->value['v'])->not->toContain($token);

    // a new token replaces the old one
    $new = app(PrintBridge::class)->issue();
    expect(app(PrintBridge::class)->accepts($token))->toBeFalse()->and(app(PrintBridge::class)->accepts($new))->toBeTrue();
});

it('refuses the bridge without a valid token', function () {
    $this->getJson('/bridge/print/jobs')->assertUnauthorized();
    $this->getJson('/bridge/print/jobs', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    app(PrintBridge::class)->issue();
    $this->getJson('/bridge/print/jobs', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

it('hands the bridge only the pending jobs of network printers, with where to send them, and records the result', function () {
    $lan = lanPrinter();
    $device = Printer::create(['outlet_id' => $lan->outlet_id, 'name' => 'Tablet', 'kind' => 'ticket', 'paper_width' => 58, 'copies' => 1]);
    $mine = PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'A-1', 'payload' => ['copies' => 1]]);
    PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $device->id, 'kind' => 'ticket', 'reference' => 'A-2', 'payload' => ['copies' => 1]]);
    $h = bridgeHeaders();

    $res = $this->getJson('/bridge/print/jobs', $h)->assertOk()->assertJsonCount(1, 'jobs');
    expect($res->json('jobs.0.id'))->toBe($mine->id)->and($res->json('jobs.0.printer.host'))->toBe('192.168.1.50')->and($res->json('jobs.0.printer.port'))->toBe(9100);
    expect(app(PrintBridge::class)->online())->toBeTrue();

    $this->postJson("/bridge/print/jobs/{$mine->id}/done", ['ok' => false, 'error' => 'timeout'], $h)->assertOk();
    expect($mine->fresh()->status)->toBe('failed')->and($mine->fresh()->error)->toBe('timeout');
    $this->getJson('/bridge/print/jobs', $h)->assertJsonCount(0, 'jobs');

    // a manager can send it again
    $this->actingAs(userWithRole('owner'))->post("/admin/printers/jobs/{$mine->id}/retry")->assertRedirect();
    expect($mine->fresh()->status)->toBe('pending');
    $this->postJson("/bridge/print/jobs/{$mine->id}/done", ['ok' => true], $h)->assertOk();
    expect($mine->fresh()->status)->toBe('printed');
});

it('parks tickets that waited too long while the bridge was off, instead of printing them late', function () {
    $lan = lanPrinter();
    $old = PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'OLD', 'payload' => ['copies' => 1]]);
    $old->forceFill(['created_at' => now()->subMinutes(45)])->save();
    $fresh = PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'NEW', 'payload' => ['copies' => 1]]);

    $res = $this->getJson('/bridge/print/jobs', bridgeHeaders())->assertOk()->assertJsonCount(1, 'jobs');

    expect($res->json('jobs.0.id'))->toBe($fresh->id)->and($old->fresh()->status)->toBe('failed')->and($old->fresh()->error)->toContain('Kedaluwarsa');
});

it('keeps network printers off the print station page so no device prints them twice', function () {
    $lan = lanPrinter();
    $device = Printer::create(['outlet_id' => $lan->outlet_id, 'name' => 'Tablet', 'kind' => 'ticket', 'paper_width' => 58, 'copies' => 1]);
    PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'A-1', 'payload' => ['copies' => 1]]);
    $cashier = userWithRole('cashier');

    $this->actingAs($cashier)->get('/print-station')->assertInertia(fn ($p) => $p->has('printers', 1)->where('printers.0.id', $device->id));
    $this->actingAs($cashier)->getJson("/print-station/jobs?printers={$lan->id},{$device->id}")->assertJsonCount(0, 'pending');
});

it('saves the LAN address of a printer and rejects nonsense addresses', function () {
    $manager = userWithRole('owner');
    $base = ['name' => 'Bar', 'kind' => 'ticket', 'paper_width' => 80, 'copies' => 1, 'is_active' => true];

    $this->actingAs($manager)->post('/admin/printers', $base + ['host' => '192.168.1.60', 'port' => 9100])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/admin/printers', $base + ['host' => 'http://x/;rm'])->assertSessionHasErrors('host');
    $this->actingAs($manager)->post('/admin/printers', $base + ['host' => ''])->assertSessionHasNoErrors();

    expect(Printer::query()->where('host', '192.168.1.60')->value('port'))->toBe(9100)->and(Printer::query()->whereNull('host')->count())->toBe(1);
});

it('tells staff when a ticket fails or waits too long, and clears the alert once printing works again', function () {
    $lan = lanPrinter();
    $h = bridgeHeaders();
    $job = PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'A-1', 'payload' => ['copies' => 1]]);
    $open = fn (string $kind) => AppNotification::query()->where('kind', $kind)->whereNull('resolved_at')->count();

    $this->postJson("/bridge/print/jobs/{$job->id}/done", ['ok' => false, 'error' => 'timeout'], $h)->assertOk();
    expect($open('print.failed'))->toBe(1);
    $this->postJson("/bridge/print/jobs/{$job->id}/done", ['ok' => false, 'error' => 'timeout'], $h)->assertOk();
    expect($open('print.failed'))->toBe(1);                                      // one alert per printer, not one per ticket
    $this->postJson("/bridge/print/jobs/{$job->id}/done", ['ok' => true], $h)->assertOk();
    expect($open('print.failed'))->toBe(0);

    $stuck = PrintJob::create(['outlet_id' => $lan->outlet_id, 'printer_id' => $lan->id, 'kind' => 'ticket', 'reference' => 'A-2', 'payload' => ['copies' => 1]]);
    $this->artisan('print:watch')->assertSuccessful();
    expect($open('print.stuck'))->toBe(0);                                       // too fresh to worry about

    $stuck->forceFill(['created_at' => now()->subMinutes(3)])->save();
    $this->artisan('print:watch')->assertSuccessful();
    expect($open('print.stuck'))->toBe(1);

    $stuck->update(['status' => 'printed']);
    $this->artisan('print:watch')->assertSuccessful();
    expect($open('print.stuck'))->toBe(0);
});
