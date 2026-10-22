<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WasteEntry;
use App\Domain\Inventory\Models\WasteReason;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Things thrown away: ingredients (spoiled, spilled, expired) or finished menu portions (made wrong, dropped).
 * Stock goes down at once, the cost is fixed in rupiah at that moment, and the reason comes from a list so the
 * waste report can add it up.
 */
class WasteService
{
    public function __construct(private InventoryService $inventory) {}

    public function reason(int $outletId, int $reasonId): WasteReason
    {
        return WasteReason::query()->where('outlet_id', $outletId)->where('is_active', true)->find($reasonId)
            ?? throw new OrderException('Pilih alasan dari daftar.');
    }

    /** Quantity may be typed in a unit defined for the ingredient (e.g. 1 botol). */
    public function ingredient(Ingredient $ingredient, float $qty, ?string $unit, int $reasonId, ?string $note, User $user): WasteEntry
    {
        if ($qty <= 0) {
            throw new OrderException('Jumlah harus lebih dari 0.');
        }

        return DB::transaction(function () use ($ingredient, $qty, $unit, $reasonId, $note, $user) {
            $reason = $this->reason($ingredient->outlet_id, $reasonId);
            $ing = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $base = Units::toBase($ing, $unit, $qty);
            $cost = (int) round($base * $ing->avg_cost);

            $entry = WasteEntry::create([
                'outlet_id' => $ing->outlet_id, 'user_id' => $user->id, 'kind' => 'ingredient', 'ingredient_id' => $ing->id, 'label' => $ing->name,
                'qty' => $base, 'unit' => $ing->unit, 'reason' => $reason->name, 'note' => $note, 'cost' => $cost,
            ]);
            $this->take($ing, $base, $entry, $reason->name, $note, $user);
            $this->announce($entry, $user);

            return $entry;
        });
    }

    /** Finished portions: the recipe says which ingredients were lost. */
    public function product(Product $product, ?int $variantId, int $portions, int $reasonId, ?string $note, User $user): WasteEntry
    {
        if ($portions < 1) {
            throw new OrderException('Jumlah porsi harus minimal 1.');
        }
        $use = $this->inventory->portion($product->id, $variantId, []);
        if ($use === []) {
            throw new OrderException("{$product->name} belum punya resep, jadi bahan yang terbuang tidak bisa dihitung. Isi resepnya dulu.");
        }

        return DB::transaction(function () use ($product, $variantId, $portions, $reasonId, $note, $user, $use) {
            $reason = $this->reason($product->outlet_id, $reasonId);
            $variant = $variantId ? ProductVariant::query()->where('product_id', $product->id)->find($variantId) : null;
            $ingredients = Ingredient::query()->whereIn('id', array_keys($use))->lockForUpdate()->get()->keyBy('id');
            $cost = (int) round(collect($use)->sum(fn ($qty, $id) => $qty * $portions * (float) ($ingredients[$id]->avg_cost ?? 0)));

            $entry = WasteEntry::create([
                'outlet_id' => $product->outlet_id, 'user_id' => $user->id, 'kind' => 'product', 'product_id' => $product->id,
                'label' => $product->name.($variant ? " ({$variant->name})" : ''), 'qty' => $portions, 'unit' => 'porsi', 'reason' => $reason->name, 'note' => $note, 'cost' => $cost,
            ]);
            foreach ($use as $ingredientId => $perPortion) {
                $ing = $ingredients->get($ingredientId);
                if ($ing !== null) {
                    $this->take($ing, $perPortion * $portions, $entry, $reason->name, $note, $user);
                }
            }
            $this->announce($entry, $user);

            return $entry;
        });
    }

    private function take(Ingredient $ing, float $base, WasteEntry $entry, string $reason, ?string $note, User $user): void
    {
        $ing->update(['stock' => $ing->stock - $base]);
        StockMovement::create([
            'outlet_id' => $ing->outlet_id, 'ingredient_id' => $ing->id, 'type' => 'waste', 'qty' => -$base, 'unit_cost' => $ing->avg_cost,
            'user_id' => $user->id, 'note' => trim($reason.($note ? " · {$note}" : '')), 'waste_entry_id' => $entry->id,
        ]);
    }

    private function announce(WasteEntry $entry, User $user): void
    {
        app(InventoryJournal::class)->waste($entry, $entry->outlet_id, $entry->cost, "Terbuang: {$entry->label} ({$entry->reason})", $user->id);
        Audit::log('inventory.waste', null, ['item' => $entry->label, 'qty' => $entry->qty, 'unit' => $entry->unit, 'reason' => $entry->reason, 'note' => $entry->note, 'amount' => $entry->cost], $user);
        if ($user->can('manage-inventory')) {
            return;
        }
        $qty = rtrim(rtrim(number_format($entry->qty, 3, ',', '.'), '0'), ',');
        Notifier::later(fn (Notifier $n) => $n->notify(
            $entry->outlet_id, 'waste.recorded', "{$user->name} membuang {$entry->label}", "{$qty} {$entry->unit} · {$entry->reason} · Rp ".number_format($entry->cost, 0, ',', '.'),
            '/admin/reports/waste', 'info', ['manage-inventory', 'view-waste-report'],
        ));
    }
}
