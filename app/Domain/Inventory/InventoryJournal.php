<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns stock events into accounting journals, when `inventory.post_journals` is on:
 * purchase (stock up, paid from the purchase cash account), sale (cost of goods sold), waste (loss) and
 * stock count differences. Amounts are the rupiah values frozen on each stock event, so the Persediaan Bahan
 * account follows the stock value in the stock report.
 */
class InventoryJournal
{
    public function __construct(private PostJournalAction $post, private SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('inventory.post_journals', false);
    }

    public function purchase(Model $purchase, int $outletId, int $total, string $date, ?int $userId, string $memo): void
    {
        $this->move(SystemAccount::InventoryAsset, SystemAccount::PurchasePayment, $total, $date, $memo, $purchase, $userId, $outletId);
    }

    public function sale(Model $item, int $outletId, int $cogs, string $memo): void
    {
        $this->move(SystemAccount::Cogs, SystemAccount::InventoryAsset, $cogs, now()->toDateString(), $memo, $item, $this->actor(), $outletId);
    }

    /** A voided item returns its stock: undo the cost-of-goods journal that is still open for it. */
    public function unsale(Model $item, int $outletId, string $memo): void
    {
        $entry = JournalEntry::query()
            ->where('source_type', $item->getMorphClass())->where('source_id', $item->getKey())
            ->whereNull('reversed_by')->whereNull('reverses')->latest('id')->first();
        if ($entry === null) {
            return;
        }
        $lines = $entry->lines->map(fn ($l) => ['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit])->all();
        $reversal = $this->post->handle(now()->toDateString(), $memo, $lines, $item, $this->actor(), false, $outletId);
        $reversal->update(['reverses' => $entry->id]);
        $entry->update(['reversed_by' => $reversal->id]);
    }

    public function waste(Model $source, int $outletId, int $cost, string $memo, ?int $userId): void
    {
        $this->move(SystemAccount::WasteLoss, SystemAccount::InventoryAsset, $cost, now()->toDateString(), $memo, $source, $userId, $outletId);
    }

    /** Positive value = stock found (gain), negative = stock missing (loss). */
    public function count(Model $source, int $outletId, int $value, string $memo, ?int $userId): void
    {
        if ($value >= 0) {
            $this->move(SystemAccount::InventoryAsset, SystemAccount::StockVariance, $value, now()->toDateString(), $memo, $source, $userId, $outletId);
        } else {
            $this->move(SystemAccount::StockVariance, SystemAccount::InventoryAsset, -$value, now()->toDateString(), $memo, $source, $userId, $outletId);
        }
    }

    private function actor(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    private function move(SystemAccount $debit, SystemAccount $credit, int $amount, string $date, string $memo, Model $source, ?int $userId, int $outletId): void
    {
        if ($amount <= 0 || ! $this->enabled()) {
            return;
        }
        $this->post->handle($date, $memo, [
            ['account_id' => $debit->resolve($outletId)->id, 'debit' => $amount],
            ['account_id' => $credit->resolve($outletId)->id, 'credit' => $amount],
        ], $source, $userId, false, $outletId);
    }
}
