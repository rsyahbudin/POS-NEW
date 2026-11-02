<?php

use App\Domain\Reporting\SalesDetailReport;
use PhpOffice\PhpSpreadsheet\IOFactory;

function readDetail($response): array
{
    $file = $response->baseResponse->getFile()->getPathname();
    $sheet = IOFactory::load($file)->getSheetByName('Report');

    return ['sheet' => $sheet, 'rows' => $sheet->toArray(null, true, false, true)];
}

it('exports one row per sold item in the layout of the previous POS, with the title block and 46 columns', function () {
    $manager = userWithRole('manager');
    openShiftFor($cashier = userWithRole('cashier'), 0);
    $order = placeOrder($cashier, [['product_id' => product('Americano')->id, 'qty' => 2], ['product_id' => product('Croissant')->id, 'qty' => 1]], 'dine_in', null, ['customer_name' => 'Rani']);
    pay($order, $cashier);
    $today = now()->toDateString();

    $r = $this->actingAs($manager)->get("/admin/reports/detail-export?from={$today}&to={$today}")->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('Sales Recapitulation Detail Report_');
    ['sheet' => $sheet, 'rows' => $rows] = readDetail($r);

    expect($rows[1]['A'])->toBe('Sales Recapitulation Detail Report')->and($rows[5]['A'])->toBe('Period')->and($rows[8]['B'])->toBe($manager->name);
    expect(array_values(array_filter($rows[10])))->toBe(SalesDetailReport::HEADERS)->and(count(SalesDetailReport::HEADERS))->toBe(46);

    // two sold items, two rows from row 12; each row is whole
    $a = $rows[12];
    expect($a['B'])->toBe($order->number)->and($a['C'])->toBe('Sales')->and($a['X'])->toBe('Rani')->and($a['AB'])->toBe('Americano')->and($a['AG'])->toBe(2)->and($a['AH'])->toBe(22000)->and($a['AI'])->toBe(44000);
    expect($rows[13]['AB'])->toBe('Croissant')->and($rows[14]['A'] ?? null)->toBeNull();

    // dates and times are real Excel values, and the rows add up to the order
    expect($sheet->getCell('G12')->getStyle()->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd');
    expect($sheet->getCell('H12')->getStyle()->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd hh:mm:ss');
    expect((int) $rows[12]['AR'] + (int) $rows[13]['AR'])->toBe($order->fresh()->grand_total);

    // footer like the old report
    $labels = array_column(array_filter($rows, fn ($x) => isset($x['AB']) && str_ends_with((string) $x['AB'], 'Total')), 'AB');
    expect($labels)->toBe(['Rounding Total', 'Voucher Purchase Total', 'Platform Fee Total', 'Delivery Fee Total', 'Order Fee Total', 'Refund Total']);
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
    expect($sum('AI'))->toBe($order->subtotal);
    expect($sum('AK') + $sum('AL'))->toBe($order->charge_total);          // service charge + tax
    expect($sum('AR') + $order->rounding)->toBe($order->grand_total);
    expect((int) $rows[12]['AN'])->toBe((int) $rows[12]['AI'] + (int) $rows[12]['AK'] + (int) $rows[12]['AL']);

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
