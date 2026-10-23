<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Reporting\SalesReports;
use App\Domain\Shift\Models\Shift;
use App\Support\BusinessDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('keeps item notes from the cashier and from the QR guest', function () {
    $item = placeOrder(items: [['product_id' => product('Americano')->id, 'qty' => 1, 'note' => 'less ice']])->items->first();
    expect($item->note)->toBe('less ice');

    $round = app(SubmitQrOrderAction::class)->handle(Table::query()->skip(3)->first()->qr_token, [
        'customer_name' => 'Rani', 'items' => [['product_id' => product('Americano')->id, 'qty' => 1, 'note' => 'tanpa gula']],
    ]);
    expect(OrderItem::query()->where('order_round_id', $round->id)->value('note'))->toBe('tanpa gula');
});

it('lets only the owner edit role permissions and applies them immediately', function () {
    $cashier = userWithRole('cashier');
    expect($cashier->can('void'))->toBeFalse();

    $this->actingAs(userWithRole('manager'))->get('/admin/roles')->assertForbidden();
    $this->actingAs(userWithRole('owner'))->get('/admin/roles')->assertOk()->assertInertia(fn ($p) => $p->component('admin/roles')->has('groups')->has('roles', 5));

    $role = Role::findByName('cashier');
    $perms = [...$role->permissions->pluck('name')->all(), 'void'];
    $this->actingAs(userWithRole('owner'))->put("/admin/roles/{$role->id}", ['permissions' => $perms])->assertRedirect();

    expect($cashier->fresh()->can('void'))->toBeTrue();
    expect(Activity::query()->where('event', 'role.permissions_changed')->count())->toBe(1);

    // owner cannot be edited, unknown permissions are rejected
    $owner = Role::findByName('owner');
    $this->actingAs(userWithRole('owner'))->put("/admin/roles/{$owner->id}", ['permissions' => []])->assertSessionHas('error');
    $this->actingAs(userWithRole('owner'))->put("/admin/roles/{$role->id}", ['permissions' => ['hack-everything']])->assertSessionHasErrors();
});

it('creates and deletes custom roles but never built-in ones, nor roles in use', function () {
    $owner = userWithRole('owner');
    $this->actingAs($owner)->post('/admin/roles', ['name' => 'Supervisor Bar', 'permissions' => ['create-order', 'void']])->assertRedirect();
    $custom = Role::findByName('supervisor-bar');
    expect($custom->hasPermissionTo('void'))->toBeTrue();

    $this->actingAs($owner)->delete('/admin/roles/'.Role::findByName('cashier')->id)->assertSessionHas('error');
    $this->actingAs($owner)->delete("/admin/roles/{$custom->id}")->assertSessionHas('success');
    expect(Role::query()->where('name', 'supervisor-bar')->exists())->toBeFalse();
});

it('does not overwrite edited role permissions when the seeder runs again', function () {
    Role::findByName('waiter')->givePermissionTo('receive-payment');
    $this->seed(RolesAndPermissionsSeeder::class);
    expect(Role::findByName('waiter')->hasPermissionTo('receive-payment'))->toBeTrue();
});

it('closes a shift from one counted cash amount and shows takings per payment method', function () {
    $cashier = userWithRole('cashier');
    openShiftFor($cashier, 100000);
    pay(placeOrder());                                                                                // cash 44.000
    pay(placeOrder(tableId: Table::query()->skip(1)->value('id')), type: 'qris'); // QRIS 44.000

    $page = $this->actingAs($cashier)->get('/shift')->assertOk();
    $page->assertInertia(fn ($p) => $p->where('shift.summary.expected', 144000)
        ->where('shift.by_method', fn ($m) => collect($m)->pluck('total', 'type')->sortKeys()->all() === ['cash' => 44000, 'qris' => 44000]));

    $this->actingAs($cashier)->post('/shift/close', ['counted_cash' => 144000, 'cash_left' => 100000, 'close_key' => 'c1'])->assertRedirect(route('login'));
    $shift = Shift::query()->latest('id')->first();
    expect($shift->counted_cash)->toBe(144000)->and($shift->difference)->toBe(0)->and($shift->cash_deposited)->toBe(44000);
});

it('generates account codes automatically so staff only deal with names', function () {
    $this->actingAs(userWithRole('owner'))->post('/admin/accounting/accounts', ['name' => 'Bank BCA — QRIS', 'type' => 'asset'])->assertRedirect();
    $acc = Account::query()->where('name', 'Bank BCA — QRIS')->first();
    expect($acc->code)->toMatch('/^1-\d+$/');
});

it('builds styled Excel files with real numbers, totals and a frozen header', function () {
    openShiftFor(userWithRole('cashier'), 0);
    pay(placeOrder());
    $d = BusinessDate::for();
    $manager = userWithRole('manager');

    $res = $this->actingAs($manager)->get("/admin/reports/export?from={$d}&to={$d}&dimension=product&format=xlsx")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
    file_put_contents($path, $res->baseResponse->getFile()->getContent());
    $book = IOFactory::load($path);
    $sheet = $book->getSheetByName('Penjualan Produk');

    expect($book->getSheetNames())->toBe(['Ringkasan', 'Penjualan Produk'])   // the summary opens first
        ->and($sheet->getCell('A1')->getValue())->toBe('Penjualan Produk')
        ->and($sheet->getCell('A2')->getValue())->toContain('Periode')
        ->and($sheet->getCell('A3')->getValue())->toStartWith('Dicetak')
        ->and($sheet->getCell('A5')->getValue())->toBe('Produk')
        ->and($sheet->getCell('C6')->getValue())->toBe(44000)                // numeric cell, not text
        ->and($sheet->getStyle('C6')->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($sheet->getCell('A7')->getValue())->toBe('Total')
        ->and($sheet->getFreezePane())->toBe('A6');
    $summary = $book->getSheetByName('Ringkasan');
    expect($summary->getStyle('B6')->getNumberFormat()->getFormatCode())->toBe('#,##0');
    @unlink($path);

    foreach (['/admin/expenses/export', '/admin/day-close/export', '/admin/accounting/export?report=accounts', '/admin/accounting/export?report=cash_flow'] as $url) {
        $this->actingAs($manager)->get($url.(str_contains($url, '?') ? '&' : '?')."from={$d}&to={$d}&format=xlsx")->assertOk();
    }
    $this->actingAs($manager)->get("/admin/reports/export?from={$d}&to={$d}&report=adjustments&format=xlsx")->assertOk();
    $this->actingAs($manager)->get("/admin/reports/export?from={$d}&to={$d}&report=adjustments&format=pdf")->assertOk();
});

it('shows 14 days of daily sales on the dashboard for the trend chart', function () {
    $this->actingAs(userWithRole('owner'))->get('/dashboard')->assertInertia(fn ($p) => $p->has('daily')->has('hourly')->has('topProducts'));
    expect(SalesReports::DIMENSIONS)->toContain('day');
});
