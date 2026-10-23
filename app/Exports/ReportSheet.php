<?php

namespace App\Exports;

use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One tidy worksheet: title block, dark header row, thousands-separated
 * numbers (real numbers, so Excel can sum them), borders, frozen header,
 * optional bold totals row.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles, WithTitle
{
    private const HEADER_ROW = 5;

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
    ) {
        // a totals line under an empty table only says 0
        if ($this->rows === []) {
            $this->totals = null;
        }
    }

    public function array(): array
    {
        $data = [[$this->title], [$this->context()], [$this->printed()], [''], $this->headings, ...array_values($this->rows)];
        if ($this->totals !== null) {
            $data[] = $this->totals;
        }

        return $data;
    }

    private function context(): string
    {
        $outlet = Outlet::query()->find(app(OutletContext::class)->id())?->name;

        return implode(' · ', array_filter([$outlet, $this->subtitle]));
    }

    private function printed(): string
    {
        $by = auth()->user()?->name;

        return 'Dicetak '.now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i').($by ? " oleh {$by}" : '');
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
                $formats[Coordinate::stringFromColumnIndex($i + 1)] = $this->format($i);
            }
        }

        return $formats;
    }

    /** Whole numbers get thousands separators only; a column with decimals shows one decimal everywhere. */
    private function format(int $col): string
    {
        foreach ($this->rows as $row) {
            if (is_float($row[$col] ?? null) && floor($row[$col]) !== $row[$col]) {
                return '#,##0.0';
            }
        }
        if ($this->totals !== null && is_float($this->totals[$col] ?? null)) {
            return '#,##0.0';
        }

        return '#,##0';
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
        $lastRow = self::HEADER_ROW + count($this->rows) + ($this->totals !== null ? 1 : 0);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->getColor()->setRGB('374151');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
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

        // A column mixing text and numbers (a summary list): format and right-align only the number cells.
        foreach (array_keys($this->headings) as $i) {
            if ($this->isNumeric($i)) {
                continue;
            }
            $col = Coordinate::stringFromColumnIndex($i + 1);
            foreach (array_values($this->rows) as $n => $row) {
                $v = $row[$i] ?? null;
                if (is_int($v) || is_float($v)) {
                    $cell = $sheet->getStyle($col.(self::HEADER_ROW + 1 + $n));
                    $cell->getNumberFormat()->setFormatCode(is_float($v) && floor($v) !== $v ? '#,##0.0' : '#,##0');
                    $cell->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }
        }

        $sheet->getStyle('A'.self::HEADER_ROW.":{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        for ($r = self::HEADER_ROW + 2; $r <= $lastRow; $r += 2) {
            $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
        }

        if ($this->totals !== null) {
            $total = $sheet->getStyle("A{$lastRow}:{$lastCol}{$lastRow}");
            $total->getFont()->setBold(true);
            $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('374151');
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        }

        return [];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $sheet->freezePane('A'.(self::HEADER_ROW + 1));
            $last = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));
            if (count($this->rows) > 1) {
                $sheet->setAutoFilter('A'.self::HEADER_ROW.":{$last}".(self::HEADER_ROW + count($this->rows)));
            }
            $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
            $sheet->setShowGridlines(false);
            $sheet->getHeaderFooter()->setOddFooter('&L'.$this->title.'&RHalaman &P dari &N');
        }];
    }

    /** A column is numeric when every non-empty body cell is a number. */
    private function isNumeric(int $col): bool
    {
        $seen = false;
        foreach ($this->rows as $row) {
            $v = $row[$col] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (! is_int($v) && ! is_float($v)) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    /** @return array{title: string, subtitle: string, headings: array<int, string>, rows: array<int, array<int, mixed>>, totals: array<int, mixed>|null} */
    public function section(): array
    {
        return ['title' => $this->title, 'subtitle' => $this->subtitle, 'headings' => $this->headings, 'rows' => $this->rows, 'totals' => $this->totals];
    }
}
