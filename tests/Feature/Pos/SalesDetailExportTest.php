<?php

use App\Domain\Reporting\SalesDetailReport;
use App\Domain\Reporting\SalesReports;
use PhpOffice\PhpSpreadsheet\IOFactory;

function readDetail($response): array
{
    $file = $response->baseResponse->getFile()->getPathname();
    $sheet = IOFactory::load($file)->getSheetByName('Report');

    return ['sheet' => $sheet, 'rows' => $sheet->toArray(null, true, false, true)];
}

it('puts one row per sold item in the Sales Recapitulation Excel, next to the summary sheets, with 32 columns and a total line', function () {
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Croissant')->id, 'qty' => 1]], 'dine_in', null, ['customer_name' => 'Rani']);
    pay($order, $cashier);
    $today = now()->toDateString();

    $r = $this->actingAs($manager)->get("/admin/reports/export?format=xlsx&from={$today}&to={$today}")->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('sales-recapitulation');
    ['sheet' => $sheet, 'rows' => $rows] = readDetail($r);
    expect(array_slice(IOFactory::load($r->baseResponse->getFile()->getPathname())->getSheetNames(), 0, 2))->toBe(['Ringkasan', 'Report']);

    expect(array_values(array_filter($rows[5])))->toBe(SalesDetailReport::HEADERS)->and(count(SalesDetailReport::HEADERS))->toBe(32);

    // two sold items, two rows from row 6; each row is whole
    $a = $rows[6];
    expect($a['B'])->toBe($order->number)->and($a['C'])->toBe('Sales')->and($a['L'])->toBe('Rani')->and($a['N'])->toBe($cashier->name)->and($a['P'])->toBe('Americano')->and($a['T'])->toBe(2)->and($a['U'])->toBe(22000)->and($a['V'])->toBe(44000);
    expect($rows[7]['P'])->toBe('Croissant')->and($rows[8]['A'])->toBe('Total');

    // the rows add up to the order
    expect((int) $rows[6]['AD'] + (int) $rows[7]['AD'])->toBe($order->fresh()->grand_total)->and((int) $rows[8]['AD'])->toBe($order->fresh()->grand_total);
    expect($sheet->getHighestRow())->toBe(8);
});

it('shares service charge, tax and bill discount over the items so that every row and the order add up', function () {
    enableTax(false);
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 1], ['product_id' => product('Croissant')->id, 'qty' => 3]]);
    pay($order, $cashier);
    $order->refresh();
    $today = now()->toDateString();

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/export?format=xlsx&from={$today}&to={$today}")->assertOk());
    $sum = fn (string $col) => array_sum(array_map(fn ($i) => (int) $rows[$i][$col], [6, 7]));

    expect($order->charge_total)->toBeGreaterThan(0);
    expect($sum('V'))->toBe($order->subtotal);
    expect($sum('X') + $sum('Y'))->toBe($order->charge_total);          // service charge + tax
    expect($sum('AD') + $order->rounding)->toBe($order->grand_total);
    expect((int) $rows[6]['Z'])->toBe((int) $rows[6]['V'] + (int) $rows[6]['X'] + (int) $rows[6]['Y']);

    $shares = SalesDetailReport::share(100, [1, 1, 1]);
    expect(array_sum($shares))->toBe(100)->and($shares)->toBe([34, 33, 33]);
    expect(SalesDetailReport::share(10, [0, 0]))->toBe([0, 0])->and(SalesDetailReport::share(0, [5, 5]))->toBe([0, 0]);
});

it('limits the export to the chosen range, leaves out voided items, and needs the sales report permission', function () {
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier);
    pay($order, $cashier);
    $yesterday = now()->subDays(3)->toDateString();

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/export?format=xlsx&from={$yesterday}&to={$yesterday}")->assertOk());
    expect(isset($rows[6]))->toBeFalse();                                         // nothing in that range

    $this->actingAs(userWithRole('waiter'))->get('/admin/reports/export?format=xlsx')->assertForbidden();
});

it('keeps Nett Sales free of tax and service, whether they are added on top or already inside the price, and matches the sales report', function (bool $inclusive) {
    enableTax($inclusive);
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 1], ['product_id' => product('Croissant')->id, 'qty' => 3]]);
    pay($order, $cashier);
    $today = now()->toDateString();

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/export?format=xlsx&from={$today}&to={$today}")->assertOk());
    $nett = array_sum(array_map(fn ($i) => (int) $rows[$i]['AA'], [6, 7]));
    $summary = app(SalesReports::class)->summary($today, $today);

    expect($summary['tax'] + $summary['service'])->toBeGreaterThan(0)
        ->and($nett)->toBe($summary['net_sales'])
        ->and($nett)->toBeLessThan($order->fresh()->grand_total);
})->with([false, true]);
