<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Models\ReceiptExport;
use App\Domain\Reporting\ReceiptArchive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportReceiptsPdfJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(ReceiptArchive $archive): void
    {
        $export = ReceiptExport::query()->find($this->exportId);
        if ($export !== null && in_array($export->status, ['pending', 'running'], true)) {
            $archive->build($export);
        }
    }
}
