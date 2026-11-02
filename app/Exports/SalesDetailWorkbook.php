<?php

namespace App\Exports;

use App\Domain\Reporting\SalesDetailReport;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Writes the "Sales Recapitulation Detail Report" sheet: a title block, the 46 column header, the item rows and the totals underneath. */
class SalesDetailWorkbook
{
    private const HEADER_ROW = 10;

    private const FIRST_ROW = 12;

    /**
     * @param  array{rows: list<list<mixed>>, rounding: int, refunds: int, orders: int}  $data
     */
    public function write(array $data, string $outlet, string $from, string $to, string $user, string $reportName, string $path): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Report');
        $now = CarbonImmutable::now('Asia/Jakarta');
        $meta = [
            1 => ['Sales Recapitulation Detail Report'], 2 => [$outlet], 3 => ['Generated', $now->format('d-m-Y H:i:s')],
            5 => ['Period', CarbonImmutable::parse($from)->format('d-m-Y').' - '.CarbonImmutable::parse($to)->format('d-m-Y')], 6 => ['Branch', $outlet], 7 => ['Sales Type', 'Sales'],
            8 => ['Generated Username', $user], 9 => ['Report File Name', $reportName],
        ];
        foreach ($meta as $row => $cells) {
            foreach ($cells as $i => $value) {
                $sheet->setCellValue([$i + 1, $row], $value);
            }
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true);

        $last = Coordinate::stringFromColumnIndex(count(SalesDetailReport::HEADERS));
        foreach (SalesDetailReport::HEADERS as $i => $title) {
            $sheet->setCellValue([$i + 1, self::HEADER_ROW], $title);
        }
        $head = $sheet->getStyle('A'.self::HEADER_ROW.':'.$last.self::HEADER_ROW);
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $row = self::FIRST_ROW;
        foreach ($data['rows'] as $line) {
            foreach ($line as $i => $value) {
                $col = $i + 1;
                if ($value instanceof CarbonImmutable) {
                    $sheet->setCellValue([$col, $row], Date::PHPToExcel($value));
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValue([$col, $row], $value);
                } else {
                    $sheet->setCellValueExplicit([$col, $row], (string) $value, DataType::TYPE_STRING);
                }
            }
            $row++;
        }
        $end = max(self::FIRST_ROW, $row - 1);

        // dates are real dates, money and quantities are real numbers (Excel can sum and pivot them)
        $sheet->getStyle('G'.self::FIRST_ROW.':G'.$end)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        foreach (['H', 'I', 'AT'] as $c) {
            $sheet->getStyle($c.self::FIRST_ROW.':'.$c.$end)->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');
        }
        $sheet->getStyle('AG'.self::FIRST_ROW.':AG'.$end)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('AH'.self::FIRST_ROW.':AR'.$end)->getNumberFormat()->setFormatCode('#,##0');

        $totals = $row + 1;
        foreach ([['Rounding Total', $data['rounding']], ['Voucher Purchase Total', 0], ['Platform Fee Total', null], ['Delivery Fee Total', 0], ['Order Fee Total', 0], ['Refund Total', $data['refunds']]] as $i => [$label, $value]) {
            $sheet->setCellValue('AB'.($totals + $i), $label);
            if ($value !== null) {
                $sheet->setCellValue('AR'.($totals + $i), $value);
                $sheet->getStyle('AR'.($totals + $i))->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getStyle('AB'.($totals + $i))->getFont()->setBold(true);
        }

        foreach (range(1, count(SalesDetailReport::HEADERS)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setWidth(24);
        }
        $sheet->freezePane('A'.self::FIRST_ROW);
        $sheet->setAutoFilter('A'.self::HEADER_ROW.':'.$last.$end);

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }
}
