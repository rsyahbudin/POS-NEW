<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Ordering\Exceptions\OrderException;

/**
 * Units are not free text. Every ingredient has one base unit (g, ml or pcs) used by stock and recipes.
 * Quantities may be entered in the base unit, in kg / liter, or in a packaging defined for that ingredient
 * (botol, dus ...) and are converted to the base unit.
 */
final class Units
{
    public const BASE = ['g' => 'gram (g)', 'ml' => 'mililiter (ml)', 'pcs' => 'buah (pcs)'];

    private const SYNONYMS = [
        'g' => 'g', 'gr' => 'g', 'gram' => 'g', 'ml' => 'ml', 'mililiter' => 'ml', 'cc' => 'ml', 'pcs' => 'pcs', 'pc' => 'pcs', 'buah' => 'pcs', 'biji' => 'pcs',
    ];

    /** A base unit written the way people write it, or null. */
    public static function base(?string $value): ?string
    {
        return $value === null ? null : (self::SYNONYMS[mb_strtolower(trim($value))] ?? null);
    }

    /**
     * Every unit that may be typed for this ingredient: lower-case name => ['name', 'factor'].
     *
     * @return array<string, array{name: string, factor: float}>
     */
    public static function options(Ingredient $ing): array
    {
        $out = [mb_strtolower($ing->unit) => ['name' => $ing->unit, 'factor' => 1.0]];
        if ($ing->unit === 'g') {
            $out['kg'] = ['name' => 'kg', 'factor' => 1000.0];
        } elseif ($ing->unit === 'ml') {
            $out['l'] = ['name' => 'l', 'factor' => 1000.0];
            $out['liter'] = ['name' => 'l', 'factor' => 1000.0];
        }
        foreach (IngredientUnit::query()->where('ingredient_id', $ing->id)->get() as $pack) {
            $out[mb_strtolower($pack->name)] = ['name' => $pack->name, 'factor' => $pack->factor];
        }

        return $out;
    }

    /**
     * Units to offer in a drop-down (no duplicates, base first).
     *
     * @return list<array{name: string, factor: float}>
     */
    public static function choices(Ingredient $ing): array
    {
        return array_values(array_unique(array_values(self::options($ing)), SORT_REGULAR));
    }

    public static function describe(Ingredient $ing): string
    {
        return implode(', ', array_map(fn ($o) => $o['factor'] === 1.0 ? $o['name'] : "{$o['name']} (".rtrim(rtrim(number_format($o['factor'], 4, '.', ''), '0'), '.')." {$ing->unit})", self::choices($ing)));
    }

    /** Factor of the chosen unit (base unit when none is given). Throws a readable error for an unknown unit. */
    public static function factor(Ingredient $ing, ?string $unit): float
    {
        if ($unit === null || trim($unit) === '') {
            return 1.0;
        }
        $key = mb_strtolower(trim($unit));
        $options = self::options($ing);
        if (! isset($options[$key])) {
            throw new OrderException("Satuan \"{$unit}\" tidak dikenal untuk {$ing->name}. Pilihan: ".self::describe($ing).'.');
        }

        return $options[$key]['factor'];
    }

    public static function toBase(Ingredient $ing, ?string $unit, float $qty): float
    {
        return round($qty * self::factor($ing, $unit), 4);
    }
}
