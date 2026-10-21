<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Stock counting by staff. Counted quantities are kept as a draft, submitted, and (after a manager
 * approves, or at once when approval is off) applied as the difference to the stock at that time.
 */
class StockCountService
{
    public function __construct(private SettingsRepository $settings) {}

    public function needsApproval(): bool
    {
        return (bool) $this->settings->get('inventory.count_needs_approval', true);
    }

    public function blind(): bool
    {
        return (bool) $this->settings->get('inventory.blind_count', true);
    }

    public function draftFor(User $user, int $outletId): StockCount
    {
        return StockCount::query()->firstOrCreate(['outlet_id' => $outletId, 'user_id' => $user->id, 'status' => 'draft']);
    }

    /**
     * Replaces the draft's counted lines.
     *
     * @param  array<int, float|int|string|null>  $counted  ingredient_id => counted quantity (null/'' = not counted)
     */
    public function save(StockCount $count, array $counted, ?string $note = null): StockCount
    {
        return DB::transaction(function () use ($count, $counted, $note) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'draft') {
                throw new OrderException('Hitungan ini sudah dikirim.');
            }
            $valid = Ingredient::query()->where('outlet_id', $count->outlet_id)->pluck('id')->flip();
            $count->lines()->delete();
            foreach ($counted as $ingredientId => $qty) {
                if ($qty === null || $qty === '' || ! $valid->has((int) $ingredientId)) {
                    continue;
                }
                $count->lines()->create(['ingredient_id' => (int) $ingredientId, 'counted' => max(0, (float) $qty)]);
            }
            $count->update(['note' => $note]);

            return $count;
        });
    }

    public function submit(StockCount $count, User $user): StockCount
    {
        return DB::transaction(function () use ($count, $user) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'draft') {
                throw new OrderException('Hitungan ini sudah dikirim.');
            }
            $lines = $count->lines()->get();
            if ($lines->isEmpty()) {
                throw new OrderException('Isi minimal satu bahan sebelum mengirim.');
            }
            foreach ($lines as $line) {
                $line->update(['system_qty' => Ingredient::query()->whereKey($line->ingredient_id)->value('stock')]);
            }
            $count->update(['status' => 'submitted', 'submitted_at' => now()]);
            Audit::log('stock.count_submitted', $count, ['lines' => $lines->count()], $user);

            return $this->needsApproval() ? $count : $this->approve($count, $user, true);
        });
    }

    public function approve(StockCount $count, User $reviewer, bool $auto = false): StockCount
    {
        return DB::transaction(function () use ($count, $reviewer, $auto) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'submitted') {
                throw new OrderException('Hitungan ini tidak menunggu persetujuan.');
            }
            $name = User::query()->whereKey($count->user_id)->value('name') ?? '-';
            foreach ($count->lines()->get() as $line) {
                $delta = $line->counted - (float) $line->system_qty;
                if (abs($delta) <= 0.0005) {
                    continue;
                }
                $ing = Ingredient::query()->lockForUpdate()->find($line->ingredient_id);
                if ($ing === null) {
                    continue;
                }
                $ing->update(['stock' => $ing->stock + $delta]);
                StockMovement::create([
                    'outlet_id' => $ing->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'opname', 'qty' => $delta,
                    'unit_cost' => $ing->avg_cost, 'user_id' => $count->user_id, 'note' => "Opname #{$count->id} oleh {$name}",
                ]);
            }
            $count->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $auto ? 'Otomatis' : null]);
            Audit::log('stock.count_approved', $count, ['auto' => $auto], $reviewer);

            return $count;
        });
    }

    public function reject(StockCount $count, User $reviewer, string $reason): StockCount
    {
        return DB::transaction(function () use ($count, $reviewer, $reason) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== 'submitted') {
                throw new OrderException('Hitungan ini tidak menunggu persetujuan.');
            }
            $count->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $reason]);
            Audit::log('stock.count_rejected', $count, ['reason' => $reason], $reviewer);

            return $count;
        });
    }
}
