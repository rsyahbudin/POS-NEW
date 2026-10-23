<?php

namespace App\Console\Commands;

use App\Domain\Reporting\Models\ReceiptExport;
use App\Domain\Reporting\ReceiptArchive;
use Illuminate\Console\Command;

class PruneReceiptExports extends Command
{
    protected $signature = 'receipt-exports:prune {--days=30}';

    protected $description = 'Delete monthly receipt PDFs (and failed requests) older than the given number of days';

    public function handle(ReceiptArchive $archive): int
    {
        $old = ReceiptExport::query()->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->get();
        $old->each(fn (ReceiptExport $e) => $archive->delete($e));
        $this->info("Dihapus: {$old->count()} berkas struk bulanan.");

        return self::SUCCESS;
    }
}
