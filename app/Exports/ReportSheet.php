<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One tidy worksheet: title block, dark header row, thousands-separated
 * numbers (real numbers, so Excel can sum them), borders, frozen header,
 * optional bold totals row.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles, WithTitle
{
    private const HEADER_ROW = 4;

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $totals  same width as headings
     */
    public function __construct(
        private string $title,
        private string $subtitle,
        private array $headings,
        private array $rows,
        private ?array $totals = null,
    ) {}

    public function array(): array
    {
        $data = [[$this->title], [$this->subtitle], [''], $this->headings, ...array_values($this->rows)];
        if ($this->totals !== null) {
            $data[] = $this->totals;
        }

        return $data;
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', $this->title) ?? 'Laporan', 0, 31);
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        $formats = [];
        foreach (array_keys($this->headings) as $i) {
            if ($this->isNumeric($i)) {
                $formats[Coordinate::stringFromColumnIndex($i + 1)] = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1;
            }
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
        $lastRow = self::HEADER_ROW + count($this->rows) + ($this->totals !== null ? 1 : 0);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('666666');
        $header = $sheet->getStyle('A'.self::HEADER_ROW.":{$lastCol}".self::HEADER_ROW);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        foreach (array_keys($this->headings) as $i) {
            if ($this->isNumeric($i)) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->getStyle("{$col}".self::HEADER_ROW.":{$col}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        $sheet->getStyle('A'.self::HEADER_ROW.":{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        for ($r = self::HEADER_ROW + 2; $r <= $lastRow; $r += 2) {
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        }

        if ($this->totals !== null) {
            $total = $sheet->getStyle("A{$lastRow}:{$lastCol}{$lastRow}");
            $total->getFont()->setBold(true);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        }

        return [];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $event->sheet->getDelegate()->freezePane('A'.(self::HEADER_ROW + 1));
            $event->sheet->getDelegate()->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        }];
    }

    /** A column is numeric when every non-empty body cell is an int. */
    private function isNumeric(int $col): bool
    {
        $seen = false;
        foreach ($this->rows as $row) {
            $v = $row[$col] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (! is_int($v)) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    /** @return array{title: string, headings: array<int, string>, rows: array<int, array<int, mixed>>, totals: array<int, mixed>|null} */
    public function section(): array
    {
        return ['title' => $this->title, 'headings' => $this->headings, 'rows' => $this->rows, 'totals' => $this->totals];
    }
}
