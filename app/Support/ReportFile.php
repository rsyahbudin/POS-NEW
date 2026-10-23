<?php

namespace App\Support;

use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Exports\ReportSheet;
use App\Exports\ReportWorkbook;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Builds the same report as a styled .xlsx or a PDF download. */
final class ReportFile
{
    /**
     * @param  array<int, ReportSheet>  $sheets  one per section (Excel sheet / PDF table)
     */
    public static function download(string $format, string $filename, array $sheets, ?string $title = null): Response
    {
        $name = Str::slugName($filename);
        $first = $sheets[0]->section();
        // The summary sheet is what an owner wants to see first.
        usort($sheets, fn (ReportSheet $a, ReportSheet $b) => ($b->section()['title'] === 'Ringkasan') <=> ($a->section()['title'] === 'Ringkasan'));

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.table', [
                'outlet' => Outlet::find(app(OutletContext::class)->id())?->name,
                'sections' => array_map(fn (ReportSheet $s) => $s->section(), $sheets),
                'title' => $title ?? $first['title'],
                'subtitle' => $first['subtitle'],
                'printedBy' => auth()->user()?->name,
                'printedAt' => now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
            ])->setPaper('a4', 'landscape')->download("{$name}.pdf");
        }

        return Excel::download(count($sheets) === 1 ? $sheets[0] : new ReportWorkbook($sheets), "{$name}.xlsx");
    }
}
