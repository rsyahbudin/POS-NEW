<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Models\IngredientUnit;
use App\Domain\Ordering\Exceptions\OrderException;

/**
 * Every ingredient has one base unit used by stock and recipes. g, ml and pcs are the common ones (with kg / liter
 * conversions); any other short name (ikat, lembar, porsi ...) may be used too. Quantities may be entered in the base unit, in kg / liter, or in a packaging defined for that ingredient
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

    /** A base unit as it is stored: the usual spelling for g / ml / pcs, otherwise the typed name in lower case. */
    public static function normalize(string $value): string
    {
        return self::base($value) ?? mb_strtolower(trim($value));
    }

    /** A usable base unit name: starts with a letter, at most 12 characters. kg / liter stay conversions of g / ml. */
    public static function isValidName(string $value): bool
    {
        if (in_array(mb_strtolower(trim($value)), ['kg', 'kilo', 'kilogram', 'l', 'lt', 'liter', 'litre'], true)) {
            return false;
        }

        return preg_match('/^\p{L}[\p{L}\p{N} .\/-]{0,11}$/u', trim($value)) === 1;
    }

    /**
     * Base units to suggest: the usual three plus every unit already used by this outlet's ingredients.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function suggestions(int $outletId): array
    {
        $used = Ingredient::query()->where('outlet_id', $outletId)->distinct()->pluck('unit')->all();
        $out = [];
        foreach ([...array_keys(self::BASE), ...$used] as $key) {
            $out[$key] = ['key' => $key, 'label' => self::BASE[$key] ?? $key];
        }

        return array_values($out);
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
