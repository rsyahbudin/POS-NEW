<?php

namespace App\Domain\Reporting;

use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Reporting\Models\ReceiptExport;
use App\Domain\Settings\Models\Outlet;
use App\Http\Presenters\ReceiptPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * All receipts (paid bills) of one month as one PDF, for any month in any year. A month can hold thousands of
 * receipts and the PDF library keeps a whole document in memory, so the receipts are rendered in batches and the
 * batches are joined into the final file.
 */
class ReceiptArchive
{
    public const BATCH = 250;

    public const DISK = 'local';

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * Paid bills of the business days in this month.
     *
     * @return Builder<Bill>
     */
    public function bills(int $outletId, int $year, int $month): Builder
    {
        $first = CarbonImmutable::createStrict($year, $month, 1);

        return Bill::query()
            ->where('status', BillStatus::Paid->value)->where('is_unallocated', false)
            ->whereHas('order', fn (Builder $o) => $o->where('outlet_id', $outletId)->whereBetween('business_date', [$first->toDateString(), $first->endOfMonth()->toDateString()]));
    }

    /** @return array{count: int, total: int} */
    public function preview(int $outletId, int $year, int $month): array
    {
        $q = $this->bills($outletId, $year, $month);

        return ['count' => (clone $q)->count(), 'total' => (int) (clone $q)->sum('grand_total')];
    }

    public function build(ReceiptExport $export): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $export->update(['status' => 'running', 'error' => null]);
        $outlet = (string) Outlet::query()->whereKey($export->outlet_id)->value('name');
        $ids = $this->bills($export->outlet_id, $export->year, $export->month)->orderBy('paid_at')->orderBy('id')->pluck('id')->all();
        $total = (int) $this->bills($export->outlet_id, $export->year, $export->month)->sum('grand_total');
        $title = 'Struk '.self::MONTHS[$export->month - 1].' '.$export->year;

        $dir = 'receipt-exports/tmp-'.$export->id;
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory($dir);
        $parts = [];

        try {
            foreach (array_chunk($ids ?: [0], static::BATCH) as $n => $chunk) {
                $bills = Bill::query()->whereIn('id', $chunk)->with(['order.table', 'order.opener', 'items', 'charges.orderCharge', 'payments.method', 'payments.receiver'])->get()->keyBy('id');
                $receipts = [];
                foreach ($chunk as $id) {
                    $bill = $bills->get($id);
                    if ($bill !== null) {
                        $receipts[] = ReceiptPresenter::forBill($bill);
                    }
                }
                $html = view('pdf.receipts', [
                    'title' => $title, 'outlet' => $outlet, 'count' => count($ids), 'total' => $total, 'part' => null,
                    'printedAt' => now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
                    'receipts' => $receipts, 'cover' => $n === 0,
                ])->render();
                $file = "{$dir}/part-{$n}.pdf";
                $disk->put($file, Pdf::loadHTML($html)->setPaper('a4')->output());
                $parts[] = $disk->path($file);
                unset($bills, $receipts, $html);
                gc_collect_cycles();
            }

            $final = "receipt-exports/{$export->id}.pdf";
            $this->merge($parts, $disk->path($final));
            $export->update([
                'status' => 'done', 'receipts' => count($ids), 'total' => $total, 'path' => $final,
                'size' => (int) $disk->size($final), 'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $export->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 290), 'finished_at' => now()]);
        } finally {
            $disk->deleteDirectory($dir);
        }
    }

    /** @param  list<string>  $files */
    private function merge(array $files, string $target): void
    {
        $pdf = new Fpdi;
        foreach ($files as $file) {
            $pages = $pdf->setSourceFile($file);
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $pdf->importPage($i);
                /** @var array{width: float, height: float, orientation: string} $size */
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }
        $pdf->Output('F', $target);
    }

    public function delete(ReceiptExport $export): void
    {
        if ($export->path !== null) {
            Storage::disk(self::DISK)->delete($export->path);
        }
        $export->delete();
    }
}
