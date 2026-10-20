<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/** Posted journals are immutable; corrections are made with a reversing entry. */
class ReverseJournalAction
{
    public function __construct(private PostJournalAction $post) {}

    public function handle(JournalEntry $entry, string $memo, ?int $userId = null, ?string $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $memo, $userId, $date) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->reversed_by !== null) {
                throw new AccountingException('Jurnal ini sudah dibalik.');
            }
            if ($entry->reverses !== null) {
                throw new AccountingException('Jurnal pembalik tidak bisa dibalik lagi.');
            }

            $lines = $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
            ])->all();

            $reversal = $this->post->handle($date ?? now()->toDateString(), $memo, $lines, null, $userId, true, $entry->outlet_id);
            $reversal->update(['reverses' => $entry->id, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id]);
            $entry->update(['reversed_by' => $reversal->id]);

            return $reversal;
        });
    }
}
