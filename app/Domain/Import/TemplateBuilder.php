<?php

namespace App\Domain\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the .xlsx template: a "Data" sheet (header + rows already filled from what exists), a
 * "Petunjuk" sheet, and a "Daftar" sheet feeding the drop-downs. Drop-downs only suggest: typing a new
 * value is allowed, which is how new items get added.
 */
final class TemplateBuilder
{
    /**
     * @param  array<string, array{label: string, aliases: list<string>, required?: bool}>  $columns
     * @param  array<int, array<int, mixed>>  $rows  rows in column order
     * @param  list<string>  $notes
     * @param  array<string, array<int, string>>  $lists  column key => allowed/suggested values
     */
    public static function download(string $filename, array $columns, array $rows, array $notes, array $lists = [], int $extraBlankRows = 30): StreamedResponse
    {
        $book = new Spreadsheet;
        $data = $book->getActiveSheet()->setTitle('Data');
        $keys = array_keys($columns);

        foreach ($keys as $i => $key) {
            $col = $i + 1;
            $label = $columns[$key]['label'].(($columns[$key]['required'] ?? false) ? ' *' : '');
            $data->setCellValue([$col, 1], $label);
            $data->getColumnDimensionByColumn($col)->setWidth(max(14, min(40, mb_strlen($label) + 6)));
        }
        $lastCol = count($keys);
        $head = $data->getStyle([1, 1, $lastCol, 1]);
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6B4A');
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $data->getRowDimension(1)->setRowHeight(24);
        $data->freezePane('A2');

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $data->setCellValueExplicit([$c + 1, $r + 2], $value, is_numeric($value) && ! is_string($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $lastRow = max(2, count($rows) + 1) + $extraBlankRows;

        // suggestion lists
        $lists_sheet = $book->createSheet()->setTitle('Daftar');
        $col = 1;
        foreach ($lists as $key => $values) {
            $at = array_search($key, $keys, true);
            if ($at === false || $values === []) {
                continue;
            }
            $lists_sheet->setCellValue([$col, 1], $columns[$key]['label']);
            foreach (array_values($values) as $i => $v) {
                $lists_sheet->setCellValue([$col, $i + 2], $v);
            }
            $letter = Coordinate::stringFromColumnIndex($col);
            $dataLetter = Coordinate::stringFromColumnIndex($at + 1);
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)->setShowDropDown(false)->setShowErrorMessage(false)->setShowInputMessage(true)
                ->setPromptTitle($columns[$key]['label'])->setPrompt('Pilih dari daftar, atau ketik nama baru.')
                ->setFormula1('Daftar!$'.$letter.'$2:$'.$letter.'$'.(count($values) + 1));
            for ($r = 2; $r <= $lastRow; $r++) {
                $data->getCell($dataLetter.$r)->setDataValidation(clone $validation);
            }
            $lists_sheet->getColumnDimensionByColumn($col)->setWidth(30);
            $col++;
        }

        $help = $book->createSheet()->setTitle('Petunjuk');
        $help->setCellValue('A1', 'Petunjuk pengisian')->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        foreach ($notes as $i => $note) {
            $help->setCellValue('A'.($i + 3), $note);
        }
        $help->getColumnDimension('A')->setWidth(110);
        $help->getStyle('A3:A'.(count($notes) + 3))->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);

        return new StreamedResponse(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
        ]);
    }
}
