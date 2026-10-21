<?php

namespace App\Domain\Import;

/** Reads numbers typed by people: "1.500", "1,5", "Rp 15.000", 12 or 12.5. Returns null if it is not a number. */
final class ImportNumber
{
    public static function parse(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = trim(str_ireplace(['rp', ' ', "\u{a0}"], '', (string) $value));
        if ($s === '' || ! preg_match('/^-?[\d.,]+$/', $s)) {
            return null;
        }

        $dot = substr_count($s, '.');
        $comma = substr_count($s, ',');
        if ($dot > 0 && $comma > 0) {
            // the last separator is the decimal one
            $decimal = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $thousand = $decimal === '.' ? ',' : '.';
            $s = str_replace($thousand, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($comma > 0) {
            $s = $comma === 1 && preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($dot > 1 || ($dot === 1 && preg_match('/\.\d{3}$/', $s))) {
            $s = str_replace('.', '', $s); // 1.500 / 1.500.000 are thousands
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function money(mixed $value): ?int
    {
        $n = self::parse($value);

        return $n === null ? null : (int) round($n);
    }
}
