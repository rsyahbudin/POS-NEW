<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Period;
use App\Domain\Settings\OutletContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posts a balanced journal entry. Always call inside the caller's transaction
 * so a payment and its journal commit (or fail) together.
 */
class PostJournalAction
{
    /**
     * @param  array<int, array{account_id: int, debit?: int, credit?: int}>  $lines
     */
    public function handle(
        CarbonInterface|string $date,
        string $memo,
        array $lines,
        ?Model $source = null,
        ?int $userId = null,
        bool $manual = false,
        ?int $outletId = null,
    ): JournalEntry {
        $outletId ??= app(OutletContext::class)->id();
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $clean = [];
        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);
            if ($debit < 0 || $credit < 0) {
                throw new AccountingException('Nominal jurnal tidak boleh negatif.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new AccountingException('Satu baris jurnal hanya boleh debit atau kredit.');
            }
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            $clean[] = ['account_id' => $line['account_id'], 'debit' => $debit, 'credit' => $credit];
        }

        if (count($clean) < 2) {
            throw new AccountingException('Jurnal minimal punya dua baris.');
        }
        if (array_sum(array_column($clean, 'debit')) !== array_sum(array_column($clean, 'credit'))) {
            throw new AccountingException('Jurnal tidak seimbang: total debit harus sama dengan total kredit.');
        }

        return DB::transaction(function () use ($date, $memo, $clean, $source, $userId, $manual, $outletId) {
            [$year, $month] = [(int) substr($date, 0, 4), (int) substr($date, 5, 2)];
            $period = Period::query()->firstOrCreate(['outlet_id' => $outletId, 'year' => $year, 'month' => $month]);
            if ($period->isClosed()) {
                throw new AccountingException("Periode {$month}/{$year} sudah ditutup.");
            }

            $entry = JournalEntry::create([
                'outlet_id' => $outletId,
                'date' => $date,
                'period_id' => $period->id,
                'source_type' => $source ? $source->getMorphClass() : null,
                'source_id' => $source?->getKey(),
                'memo' => $memo,
                'is_manual' => $manual,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);
            $entry->lines()->createMany($clean);

            return $entry;
        });
    }
}
