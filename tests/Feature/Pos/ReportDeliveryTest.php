<?php

use App\Domain\Notification\Jobs\DeliverMessageJob;
use App\Domain\Notification\Notifier;
use App\Domain\Notification\ReportDelivery;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\ZReport;
use App\Domain\Shift\ZReportSummary;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function setUpChannels(bool $telegram = true, bool $email = true, bool $wa = true): void
{
    if ($telegram) {
        setting('telegram.bot_token', 'T0KEN');
        setting('telegram.chat_id', '123');
    }
    if ($email) {
        setting('notify.email_to', 'owner@toko.test, manager@toko.test; salah-alamat');
    }
    if ($wa) {
        setting('whatsapp.token', 'WA-TOKEN');
        setting('whatsapp.targets', '0812-3456-7890, 6281200000000');
    }
}

it('knows which channels are filled in and cleans the recipients', function () {
    $d = app(ReportDelivery::class);
    expect($d->configured())->toBe([]);

    setUpChannels();
    expect($d->configured())->toBe(['telegram', 'email', 'whatsapp'])->and($d->emails())->toBe(['owner@toko.test', 'manager@toko.test'])
        ->and($d->whatsappTargets())->toBe(['6281234567890', '6281200000000']);
});

it('sends one message to every configured channel', function () {
    setUpChannels();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true]), 'api.fonnte.com/*' => Http::response(['status' => true])]);
    Mail::fake();

    expect(app(ReportDelivery::class)->deliver('Laporan harian', 'Omzet Rp100.000'))->toBe(['telegram', 'email', 'whatsapp']);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'botT0KEN/sendMessage') && str_contains($r['text'], 'Omzet Rp100.000'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'fonnte.com/send') && $r['target'] === '6281234567890,6281200000000' && str_contains($r['message'], 'Omzet Rp100.000') && $r->hasHeader('Authorization', 'WA-TOKEN'));
    Mail::assertSentCount(0); // raw mail is not a Mailable: check the message through the log transport below
});

it('delivers email through the mail transport to the cleaned recipients', function () {
    setUpChannels(false, true, false);
    config(['mail.default' => 'array']);

    app(ReportDelivery::class)->deliver('Laporan harian 21 Okt', 'Omzet Rp100.000');
    $sent = app('mailer')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1);
    $message = $sent[0]->getOriginalMessage();
    expect($message->getSubject())->toBe('Laporan harian 21 Okt')->and(collect($message->getTo())->map->getAddress()->all())->toBe(['owner@toko.test', 'manager@toko.test']);
});

it('only uses the channels that are filled in', function () {
    setUpChannels(true, false, false);
    Queue::fake();
    expect(app(ReportDelivery::class)->deliver('x', 'y'))->toBe(['telegram']);
    Queue::assertPushed(DeliverMessageJob::class, 1);
    expect(app(ReportDelivery::class)->deliver('x', 'y', ['email']))->toBe([]);
});

it('reports a gateway refusal so the job retries instead of pretending success', function () {
    setUpChannels(false, false, true);
    Http::fake(['api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'token invalid'])]);

    expect(fn () => (new DeliverMessageJob('whatsapp', 'Tes', 'isi'))->handle(app(SettingsRepository::class), app(ReportDelivery::class)))
        ->toThrow(RuntimeException::class, 'token invalid');
});

it('lets the owner test each channel from Settings and explains what is wrong', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->postJson('/admin/settings/test-notification', ['channel' => 'telegram'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'belum diisi'));

    setUpChannels();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true]), 'api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'device disconnected'])]);
    $this->actingAs($owner)->postJson('/admin/settings/test-notification', ['channel' => 'telegram'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Terkirim'));
    $this->actingAs($owner)->postJson('/admin/settings/test-notification', ['channel' => 'whatsapp'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'device disconnected'));
    $this->actingAs($owner)->postJson('/admin/settings/test-notification', ['channel' => 'sms'])->assertStatus(422);
    $this->actingAs(userWithRole('cashier'))->postJson('/admin/settings/test-notification', ['channel' => 'telegram'])->assertForbidden();
});

it('sends the daily report to all channels unless it is switched off', function () {
    setUpChannels(true, false, true);
    Http::fake(['*' => Http::response(['ok' => true, 'status' => true])]);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 44000, 'cash_left' => 0, 'close_key' => 'z1']);

    $this->travelTo(now()->addDay()->setTime(12, 0));
    $this->artisan('days:auto-close')->assertSuccessful();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'Laporan harian') && str_contains($r['text'], 'Rp44.000'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'fonnte.com'));
});

it('does not send the daily report when it is switched off', function () {
    setUpChannels(true, false, false);
    setting('report.send_daily', false);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 44000, 'cash_left' => 0, 'close_key' => 'z2']);

    $this->travelTo(now()->addDay()->setTime(12, 0));
    $this->artisan('days:auto-close')->assertSuccessful();
    Http::assertNothingSent();
});

it('can send a report for each closed shift, with sales per method and the cash difference', function () {
    setUpChannels(true, false, false);
    setting('report.send_shift', true);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 100000);
    pay(placeOrder($cashier), $cashier);

    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 140000, 'cash_left' => 0, 'close_key' => 'z3', 'difference_reason' => 'salah kembalian'])->assertSessionHasNoErrors();
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Laporan shift '.$cashier->name) && str_contains($r['text'], 'Tunai: Rp44.000') && str_contains($r['text'], 'Kas seharusnya: Rp144.000') && str_contains($r['text'], 'Selisih: -Rp4.000') && str_contains($r['text'], 'salah kembalian'));
});

it('forwards only important alerts, and only when asked to', function () {
    setUpChannels(true, false, false);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $outlet = (int) Outlet::query()->value('id');
    $n = app(Notifier::class);

    $n->notify($outlet, 'stock.low', 'Gula habis', 'Sisa 0 g', '/stock', 'danger', ['manage-inventory']);
    Http::assertNothingSent(); // switched off

    setting('notify.forward_alerts', true);
    $n->notify($outlet, 'stock.low', 'Susu hampir habis', 'Sisa 100 ml', '/stock', 'warning', ['manage-inventory']);
    $n->notify($outlet, 'count.approved', 'Hitungan disetujui', null, null, 'info', null, userWithRole('cashier')->id); // personal, info: stays in the bell
    $n->notify($outlet, 'order.x', 'Bukan peringatan', null, null, 'warning', ['manage-inventory']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Peringatan: Susu hampir habis') && str_contains($r['text'], 'Sisa 100 ml'));
});

it('shows the report of any day, lists its shifts, and sends it on request', function () {
    $manager = userWithRole('manager');
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 0);
    pay(placeOrder($cashier), $cashier);
    $d = BusinessDate::for();

    $this->actingAs($manager)->get("/admin/day-close?date={$d}")->assertOk()->assertInertia(fn ($p) => $p->component('admin/day-close')->where('date', $d)->where('preview.net', 44000)->has('shifts', 1)->where('shifts.0.user', $cashier->name)->where('channels', []));
    $this->actingAs($manager)->get('/admin/day-close?date=2020-01-01')->assertOk()->assertInertia(fn ($p) => $p->where('preview.orders', 0)->has('shifts', 0));

    $this->actingAs($manager)->post('/admin/day-close/send', ['date' => $d])->assertSessionHas('error'); // no channel yet
    setUpChannels(true, false, false);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $this->actingAs($manager)->post('/admin/day-close/send', ['date' => $d])->assertSessionHas('success');
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Omzet bersih: Rp44.000'));
    $this->actingAs($cashier)->post('/admin/day-close/send', ['date' => $d])->assertForbidden();
});

it('emails the daily report as a designed message with a plain-text fallback', function () {
    setUpChannels(false, true, false);
    config(['mail.default' => 'array']);
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    $date = BusinessDate::for();
    $summary = ZReportSummary::fromArray(app(ZReport::class)->build($date));
    $delivery = app(ReportDelivery::class);

    $delivery->deliver('Laporan harian', $delivery->dailyTextFor($date, $summary), null, $delivery->dailyHtml($date, $summary));
    $message = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();

    expect($message->getHtmlBody())->toContain('Penjualan bersih')->toContain('Rp44.000')->toContain('Menu terlaris')->toContain('/admin/reports/sales?from='.$date)
        ->and($message->getTextBody())->toContain('Omzet bersih');
});
