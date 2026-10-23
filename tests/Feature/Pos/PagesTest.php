<?php

use App\Domain\Catalog\Models\Station;
use App\Domain\Payment\Actions\RefundPaymentAction;
use App\Domain\Reporting\SalesReports;
use App\Support\BusinessDate;

it('redirects guests to login and routes staff by role from /', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->actingAs(userWithRole('owner'))->get('/')->assertRedirect(route('dashboard'));
    $this->actingAs(userWithRole('cashier'))->get('/')->assertRedirect(route('pos.index'));
    $this->actingAs(userWithRole('kitchen'))->get('/')->assertRedirect(route('kds.index'));
});

it('renders every staff page for an authorised user with the expected props', function (string $role, string $url, string $component) {
    $this->actingAs(userWithRole($role))->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
})->with([
    ['owner', '/dashboard', 'dashboard'],
    ['cashier', '/pos', 'pos/index'],
    ['cashier', '/shift', 'shift/index'],
    ['manager', '/admin/menu', 'admin/menu'],
    ['manager', '/admin/tables', 'admin/tables'],
    ['manager', '/admin/expenses', 'admin/expenses'],
    ['manager', '/admin/reports/sales', 'admin/reports/sales'],
    ['manager', '/admin/reports/adjustments', 'admin/reports/adjustments'],
    ['manager', '/admin/accounting', 'admin/accounting'],
    ['manager', '/admin/day-close', 'admin/day-close'],
    ['owner', '/admin/users', 'admin/users'],
    ['owner', '/admin/settings', 'admin/settings'],
]);

it('renders the KDS for a station', function () {
    $station = Station::query()->first();
    $this->actingAs(userWithRole('kitchen'))->get("/kds/{$station->id}")->assertOk()->assertInertia(fn ($p) => $p->component('kds/show')->has('tickets'));
});

it('gives the manager read-only accounting and the owner write access', function () {
    $this->actingAs(userWithRole('manager'))->get('/admin/accounting')->assertInertia(fn ($p) => $p->where('canManage', false));
    $this->actingAs(userWithRole('owner'))->get('/admin/accounting')->assertInertia(fn ($p) => $p->where('canManage', true));
});

it('never sends the Telegram token to the browser', function () {
    setting('telegram.bot_token', 'SECRET');
    $this->actingAs(userWithRole('owner'))->get('/admin/settings')->assertInertia(fn ($p) => $p->where('settings', fn ($s) => collect($s)->get('telegram.bot_token') === '••••••••'));
});

it('exports reports as xlsx and pdf', function () {
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    $today = BusinessDate::for();
    $manager = userWithRole('manager');

    $this->actingAs($manager)->get("/admin/reports/export?from={$today}&to={$today}&dimension=product&format=xlsx")->assertOk()->assertDownload();
    $this->actingAs($manager)->get("/admin/reports/export?from={$today}&to={$today}&dimension=day&format=pdf")->assertOk();
    $this->actingAs($manager)->get("/admin/accounting/export?from={$today}&to={$today}&report=profit_loss&format=pdf")->assertOk();
    $this->actingAs($manager)->get("/admin/accounting/export?from={$today}&to={$today}&report=journal&format=xlsx")->assertOk();
});

it('prints a PDF of QR codes for the tables', function () {
    $res = $this->actingAs(userWithRole('manager'))->get('/admin/tables-qr.pdf');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('pdf');
});

it('reports sales per order not per bill and nets out refunds', function () {
    openShiftFor(userWithRole('cashier'), 0);
    $o = placeOrder();
    $p = pay($o);
    app(RefundPaymentAction::class)->handle($p, userWithRole('manager'), 4000, 'komplain', 'r');
    $d = BusinessDate::for();
    $s = app(SalesReports::class);

    expect($s->summary($d, $d))->toBe(['orders' => 1, 'gross' => 44000, 'refunds' => 4000, 'net' => 40000, 'discounts' => 0, 'subtotal' => 44000, 'charges' => 0, 'rounding' => 0, 'average' => 40000])
        ->and($s->by('day', $d, $d)->first())->toMatchObject(['subtotal' => 44000, 'refunds' => 4000, 'total' => 40000, 'average' => 40000])
        ->and($s->by('type', $d, $d)->first()->display)->toBeIn(['Dine-in', 'Takeaway', 'Online / Ojol'])
        ->and($s->dashboard($d)['revenue'])->toBe(40000)
        ->and($s->by('product', $d, $d)->first()->total)->toBe(44000)
        ->and($s->by('method', $d, $d)->first()->total)->toBe(40000);
    foreach (SalesReports::DIMENSIONS as $dim) {
        expect($s->by($dim, $d, $d))->not->toBeEmpty();
    }
});
