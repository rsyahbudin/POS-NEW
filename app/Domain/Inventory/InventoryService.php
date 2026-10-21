<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\ModifierIngredient;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\RecipeLine;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Ingredient stock with moving-average cost. A sale uses the recipe of the item (plus extras from the chosen
 * modifiers), freezes the cost on the order line (cogs) and writes stock movements; a void puts it back.
 * Everything is off until Pengaturan > Stok turns it on.
 */
class InventoryService
{
    public function __construct(private SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('inventory.enabled', false);
    }

    /**
     * Ingredients one portion uses.
     *
     * @param  array<int, int>  $modifierIds
     * @return array<int, float> ingredient_id => quantity
     */
    public function portion(int $productId, ?int $variantId, array $modifierIds): array
    {
        $use = [];
        $lines = RecipeLine::query()->where('product_id', $productId)
            ->where(fn ($q) => $q->whereNull('variant_id')->when($variantId, fn ($q2) => $q2->orWhere('variant_id', $variantId)))->get();
        foreach ($lines as $l) {
            $use[$l->ingredient_id] = ($use[$l->ingredient_id] ?? 0) + $l->qty;
        }
        if ($modifierIds !== []) {
            foreach (ModifierIngredient::query()->whereIn('modifier_id', $modifierIds)->get() as $m) {
                $use[$m->ingredient_id] = ($use[$m->ingredient_id] ?? 0) + $m->qty;
            }
        }

        return $use;
    }

    /** Current cost of one portion at the moving-average prices. */
    public function portionCost(int $productId, ?int $variantId = null): float
    {
        $use = $this->portion($productId, $variantId, []);
        if ($use === []) {
            return 0.0;
        }
        $costs = Ingredient::query()->whereIn('id', array_keys($use))->pluck('avg_cost', 'id');

        return (float) collect($use)->sum(fn ($qty, $id) => $qty * (float) ($costs[$id] ?? 0));
    }

    public function consume(OrderItem $item): void
    {
        if (! $this->enabled() || $item->inventory_consumed) {
            return;
        }
        $use = $this->portion($item->product_id, $item->variant_id, array_map('intval', $item->modifier_ids ?? []));
        if ($use === []) {
            return;
        }

        $ingredients = Ingredient::query()->whereIn('id', array_keys($use))->lockForUpdate()->get()->keyBy('id');
        $block = (bool) $this->settings->get('inventory.block_when_empty', false);
        $total = 0.0;

        foreach ($use as $id => $perPortion) {
            $need = $perPortion * $item->qty;
            $ing = $ingredients->get($id);
            if ($ing === null) {
                continue;
            }
            if ($block && $ing->stock < $need) {
                throw new OrderException("Bahan {$ing->name} tidak cukup (sisa ".$this->fmt($ing->stock)." {$ing->unit}).");
            }
            $ing->update(['stock' => $ing->stock - $need]);
            StockMovement::create([
                'outlet_id' => $ing->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'sale', 'qty' => -$need,
                'unit_cost' => $ing->avg_cost, 'order_item_id' => $item->id,
            ]);
            $total += $need * $ing->avg_cost;
        }

        $item->update(['inventory_consumed' => true, 'cogs' => (int) round($total)]);
    }

    public function restore(OrderItem $item): void
    {
        if (! $item->inventory_consumed) {
            return;
        }
        $moves = StockMovement::query()->where('order_item_id', $item->id)->where('type', 'sale')->get();
        foreach ($moves as $m) {
            $ing = Ingredient::query()->lockForUpdate()->find($m->ingredient_id);
            if ($ing === null) {
                continue;
            }
            $ing->update(['stock' => $ing->stock + abs($m->qty)]);
            StockMovement::create([
                'outlet_id' => $m->outlet_id, 'ingredient_id' => $m->ingredient_id, 'type' => 'void_return', 'qty' => abs($m->qty),
                'unit_cost' => $m->unit_cost, 'order_item_id' => $item->id,
            ]);
        }
        $item->update(['inventory_consumed' => false, 'cogs' => 0]);
    }

    /**
     * Turns purchase lines as people enter them (quantity in any allowed unit, price as a total or per entered unit)
     * into base-unit quantities and cost per base unit.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{ingredient_id: int, qty: float, unit_cost: float}>
     */
    public function normalizePurchaseItems(int $outletId, array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $ing = Ingredient::query()->where('outlet_id', $outletId)->findOrFail((int) $row['ingredient_id']);
            $qty = (float) $row['qty'];
            $factor = Units::factor($ing, isset($row['unit']) ? (string) $row['unit'] : null);
            $base = $qty * $factor;
            if ($base <= 0) {
                throw new OrderException("Jumlah {$ing->name} harus lebih dari 0.");
            }
            $total = $row['total'] ?? null;
            $perUnit = $row['unit_cost'] ?? null;
            if ($total === null && $perUnit === null) {
                throw new OrderException("Isi harga untuk {$ing->name}.");
            }
            $out[] = [
                'ingredient_id' => $ing->id, 'qty' => round($base, 4),
                'unit_cost' => $total !== null ? (float) $total / $base : (float) $perUnit / $factor,
            ];
        }

        return $out;
    }

    /**
     * Goods received, applied at once (managers). Raises stock and re-averages the cost.
     *
     * @param  array{supplier?: string|null, purchased_on: string, note?: string|null, items: list<array{ingredient_id: int, qty: float|int, unit_cost: float|int}>}  $data
     */
    public function receive(int $outletId, array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($outletId, $data, $user) {
            $purchase = $this->record($outletId, $data, $user);
            $this->apply($purchase, $user);

            return $purchase->refresh();
        });
    }

    /**
     * Staff shopping: applied at once when the user manages inventory or approval is off, otherwise saved as
     * "pending" and applied only after a manager approves. Stock and costs do not move before that.
     *
     * @param  array{supplier?: string|null, purchased_on: string, note?: string|null, items: list<array{ingredient_id: int, qty: float|int, unit_cost: float|int}>}  $data
     */
    public function submitPurchase(int $outletId, array $data, User $user): Purchase
    {
        if ($user->can('manage-inventory') || ! (bool) $this->settings->get('inventory.purchase_needs_approval', true)) {
            return $this->receive($outletId, $data, $user);
        }

        return DB::transaction(function () use ($outletId, $data, $user) {
            $purchase = $this->record($outletId, $data, $user);
            Audit::log('inventory.purchase_submitted', $purchase, ['total' => $purchase->total, 'items' => count($data['items'])], $user);
            Notifier::later(fn (Notifier $n) => $n->notify(
                $outletId, 'purchase.pending', 'Belanja bahan menunggu persetujuan', "{$user->name} · {$purchase->items()->count()} bahan · Rp ".number_format($purchase->total, 0, ',', '.'),
                '/admin/inventory?tab=purchases', 'warning', ['manage-inventory'], null, "purchase:{$purchase->id}",
            ));

            return $purchase;
        });
    }

    public function approvePurchase(Purchase $purchase, User $reviewer): Purchase
    {
        return DB::transaction(function () use ($purchase, $reviewer) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->guardReview($purchase, $reviewer);
            $this->apply($purchase, $reviewer);
            Notifier::later(function (Notifier $n) use ($purchase, $reviewer) {
                $n->resolve($purchase->outlet_id, "purchase:{$purchase->id}");
                if ($purchase->user_id !== null && $purchase->user_id !== $reviewer->id) {
                    $n->notify($purchase->outlet_id, 'purchase.approved', 'Belanja Anda disetujui', "Disetujui oleh {$reviewer->name}. Stok bertambah.", '/stock/purchase', 'info', null, $purchase->user_id);
                }
            });

            return $purchase->refresh();
        });
    }

    public function rejectPurchase(Purchase $purchase, User $reviewer, string $reason): Purchase
    {
        return DB::transaction(function () use ($purchase, $reviewer, $reason) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->guardReview($purchase, $reviewer);
            $purchase->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $reason]);
            Audit::log('inventory.purchase_rejected', $purchase, ['reason' => $reason], $reviewer);
            Notifier::later(function (Notifier $n) use ($purchase, $reviewer, $reason) {
                $n->resolve($purchase->outlet_id, "purchase:{$purchase->id}");
                if ($purchase->user_id !== null) {
                    $n->notify($purchase->outlet_id, 'purchase.rejected', 'Belanja Anda ditolak', "{$reviewer->name}: {$reason}", '/stock/purchase', 'warning', null, $purchase->user_id);
                }
            });

            return $purchase;
        });
    }

    /** Nobody but the owner signs off their own entry. */
    private function guardReview(Purchase $purchase, User $reviewer): void
    {
        if ($purchase->status !== 'pending') {
            throw new OrderException('Pembelian ini tidak menunggu persetujuan.');
        }
        if ($purchase->user_id === $reviewer->id && ! $reviewer->hasRole('owner')) {
            throw new OrderException('Pembelian yang Anda catat sendiri harus disetujui orang lain.');
        }
    }

    /**
     * @param  array{supplier?: string|null, purchased_on: string, note?: string|null, items: list<array{ingredient_id: int, qty: float|int, unit_cost: float|int}>}  $data
     */
    private function record(int $outletId, array $data, User $user): Purchase
    {
        $purchase = Purchase::create([
            'outlet_id' => $outletId, 'supplier' => $data['supplier'] ?? null, 'purchased_on' => $data['purchased_on'],
            'note' => $data['note'] ?? null, 'user_id' => $user->id, 'status' => 'pending',
        ]);
        $sum = 0;
        foreach ($data['items'] as $row) {
            Ingredient::query()->where('outlet_id', $outletId)->findOrFail($row['ingredient_id']);
            $line = (int) round((float) $row['qty'] * (float) $row['unit_cost']);
            $purchase->items()->create(['ingredient_id' => $row['ingredient_id'], 'qty' => (float) $row['qty'], 'unit_cost' => (float) $row['unit_cost'], 'total' => $line]);
            $sum += $line;
        }
        $purchase->update(['total' => $sum]);

        return $purchase;
    }

    /** Puts a recorded purchase into stock and re-averages each ingredient's cost. */
    private function apply(Purchase $purchase, User $reviewer): void
    {
        foreach ($purchase->items()->get() as $item) {
            $ing = Ingredient::query()->lockForUpdate()->findOrFail($item->ingredient_id);
            $avg = $ing->stock <= 0 ? $item->unit_cost : (($ing->stock * $ing->avg_cost) + ($item->qty * $item->unit_cost)) / ($ing->stock + $item->qty);
            $ing->update(['stock' => $ing->stock + $item->qty, 'avg_cost' => $avg]);
            StockMovement::create([
                'outlet_id' => $purchase->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'purchase', 'qty' => $item->qty,
                'unit_cost' => $item->unit_cost, 'user_id' => $purchase->user_id, 'purchase_id' => $purchase->id, 'note' => $purchase->supplier,
            ]);
        }
        $purchase->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        Audit::log('inventory.purchase', $purchase, ['total' => $purchase->total, 'items' => $purchase->items()->count()], $reviewer);
    }

    /** Stock count: sets the stock to what was actually counted and records the difference. */
    public function count(Ingredient $ingredient, float $counted, User $user, ?string $note = null): float
    {
        return DB::transaction(function () use ($ingredient, $counted, $user, $note) {
            $ing = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $diff = $counted - $ing->stock;
            if (abs($diff) > 0.0005) {
                $ing->update(['stock' => $counted]);
                StockMovement::create([
                    'outlet_id' => $ing->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'opname', 'qty' => $diff,
                    'unit_cost' => $ing->avg_cost, 'user_id' => $user->id, 'note' => $note,
                ]);
                Audit::log('inventory.counted', $ing, ['ingredient' => $ing->name, 'difference' => $diff], $user);
            }

            return $diff;
        });
    }

    /** Spoiled, spilled or otherwise lost stock. */
    public function waste(Ingredient $ingredient, float $qty, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($ingredient, $qty, $user, $note) {
            $ing = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $ing->update(['stock' => $ing->stock - $qty]);
            StockMovement::create([
                'outlet_id' => $ing->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'waste', 'qty' => -$qty,
                'unit_cost' => $ing->avg_cost, 'user_id' => $user->id, 'note' => $note,
            ]);
            Audit::log('inventory.waste', $ing, ['ingredient' => $ing->name, 'qty' => $qty, 'note' => $note], $user);
        });
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
