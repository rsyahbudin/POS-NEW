<?php

namespace App\Domain\Notification;

use App\Domain\Asset\Models\Asset;
use App\Domain\Inventory\Models\Ingredient;

/** Alerts that follow the state of something (low stock, equipment needing attention) and clear themselves. */
final class Alerts
{
    public static function ingredient(Ingredient $i): void
    {
        $notifier = app(Notifier::class);
        $key = "low:{$i->id}";
        $low = $i->is_active && (($i->min_stock > 0 && $i->stock <= $i->min_stock) || $i->stock < 0);
        if (! $low) {
            $notifier->resolve($i->outlet_id, $key);

            return;
        }
        $left = rtrim(rtrim(number_format($i->stock, 3, ',', '.'), '0'), ',');
        $min = rtrim(rtrim(number_format($i->min_stock, 3, ',', '.'), '0'), ',');
        $notifier->notify(
            $i->outlet_id, 'stock.low', $i->stock <= 0 ? "{$i->name} habis" : "{$i->name} hampir habis",
            "Sisa {$left} {$i->unit} (batas {$min} {$i->unit}). Waktunya belanja.", '/stock', $i->stock <= 0 ? 'danger' : 'warning',
            ['manage-inventory', 'record-purchase', 'view-stock'], null, $key,
        );
    }

    public static function asset(Asset $a): void
    {
        $notifier = app(Notifier::class);
        $key = "asset:{$a->id}";
        if (! in_array($a->condition, ['needs_service', 'broken'], true)) {
            $notifier->resolve($a->outlet_id, $key);

            return;
        }
        $notifier->notify(
            $a->outlet_id, 'asset.attention', "{$a->name} ".($a->condition === 'broken' ? 'rusak' : 'perlu servis'),
            trim("{$a->code}".($a->location ? " · {$a->location}" : '')), '/assets', $a->condition === 'broken' ? 'danger' : 'warning',
            ['manage-assets'], null, $key,
        );
    }
}
