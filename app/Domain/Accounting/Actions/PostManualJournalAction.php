<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;
use App\Support\Audit;

class PostManualJournalAction
{
    public function __construct(private PostJournalAction $journal) {}

    /** @param  list<array{account_id: int, debit?: int, credit?: int}>  $lines */
    public function handle(User $user, string $date, string $memo, array $lines): JournalEntry
    {
        $entry = $this->journal->handle($date, $memo, $lines, null, $user->id, true);
        Audit::log('journal.manual', $entry, ['memo' => $memo, 'lines' => count($lines)], $user);

        return $entry;
    }
}
