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

it('exports one row per sold item in the layout of the previous POS, with the title block and 32 columns', function () {
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Croissant')->id, 'qty' => 1]], 'dine_in', null, ['customer_name' => 'Rani']);
    pay($order, $cashier);
    $today = now()->toDateString();

    $r = $this->actingAs($manager)->get("/admin/reports/detail-export?from={$today}&to={$today}")->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('Sales Recapitulation Detail Report_');
    ['sheet' => $sheet, 'rows' => $rows] = readDetail($r);

    expect($rows[1]['A'])->toBe('Sales Recapitulation Detail Report')->and($rows[5]['A'])->toBe('Period')->and($rows[8]['B'])->toBe($manager->name);
    expect(array_values(array_filter($rows[10])))->toBe(SalesDetailReport::HEADERS)->and(count(SalesDetailReport::HEADERS))->toBe(32);

    // two sold items, two rows from row 12; each row is whole
    $a = $rows[12];
    expect($a['B'])->toBe($order->number)->and($a['C'])->toBe('Sales')->and($a['L'])->toBe('Rani')->and($a['N'])->toBe($cashier->name)->and($a['P'])->toBe('Americano')->and($a['T'])->toBe(2)->and($a['U'])->toBe(22000)->and($a['V'])->toBe(44000);
    expect($rows[13]['P'])->toBe('Croissant')->and($rows[14]['A'] ?? null)->toBeNull();

    // dates and times are real Excel values, and the rows add up to the order
    expect($sheet->getCell('G12')->getStyle()->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd');
    expect($sheet->getCell('H12')->getStyle()->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd hh:mm:ss');
    expect((int) $rows[12]['AD'] + (int) $rows[13]['AD'])->toBe($order->fresh()->grand_total);

    // footer like the old report
    $labels = array_column(array_filter($rows, fn ($x) => isset($x['P']) && str_ends_with((string) $x['P'], 'Total')), 'P');
    expect($labels)->toBe(['Rounding Total', 'Refund Total']);
});

it('shares service charge, tax and bill discount over the items so that every row and the order add up', function () {
    enableTax(false);
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 1], ['product_id' => product('Croissant')->id, 'qty' => 3]]);
    pay($order, $cashier);
    $order->refresh();
    $today = now()->toDateString();

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/detail-export?from={$today}&to={$today}")->assertOk());
    $sum = fn (string $col) => array_sum(array_map(fn ($i) => (int) $rows[$i][$col], [12, 13]));

    expect($order->charge_total)->toBeGreaterThan(0);
    expect($sum('V'))->toBe($order->subtotal);
    expect($sum('X') + $sum('Y'))->toBe($order->charge_total);          // service charge + tax
    expect($sum('AD') + $order->rounding)->toBe($order->grand_total);
    expect((int) $rows[12]['Z'])->toBe((int) $rows[12]['V'] + (int) $rows[12]['X'] + (int) $rows[12]['Y']);

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

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/detail-export?from={$yesterday}&to={$yesterday}")->assertOk());
    expect($rows[12]['A'] ?? null)->toBeNull();                                    // nothing in that range

    $this->actingAs($manager)->get('/admin/reports/detail-export?from=2026-12-31&to=2026-01-01')->assertSessionHasErrors('to');
    $this->actingAs(userWithRole('waiter'))->get('/admin/reports/detail-export')->assertForbidden();
});

it('keeps Nett Sales free of tax and service, whether they are added on top or already inside the price, and matches the sales report', function (bool $inclusive) {
    enableTax($inclusive);
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 1], ['product_id' => product('Croissant')->id, 'qty' => 3]]);
    pay($order, $cashier);
    $today = now()->toDateString();

    ['rows' => $rows] = readDetail($this->actingAs($manager)->get("/admin/reports/detail-export?from={$today}&to={$today}")->assertOk());
    $nett = array_sum(array_map(fn ($i) => (int) $rows[$i]['AA'], [12, 13]));
    $summary = app(SalesReports::class)->summary($today, $today);

    expect($summary['tax'] + $summary['service'])->toBeGreaterThan(0)
        ->and($nett)->toBe($summary['net_sales'])
        ->and($nett)->toBeLessThan($order->fresh()->grand_total);
})->with([false, true]);
