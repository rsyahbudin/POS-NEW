<?php

namespace App\Domain\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

/** Reads the "Data" sheet of an .xlsx/.csv file into rows keyed by the importer's column keys. */
final class SheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * @param  array<string, array{label: string, aliases: list<string>, required?: bool}>  $columns
     * @return array{rows: list<array{line: int, cells: array<string, string|null>}>, missing: list<string>}
     */
    public static function read(string $path, array $columns): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (Throwable) {
            throw new RuntimeException('File tidak bisa dibaca. Pakai file .xlsx dari template.');
        }
        $sheet = $book->getSheetByName('Data') ?? $book->getSheet(0);
        $raw = $sheet->toArray(null, true, false, false);

        // header = first row (of the first 10) that matches at least one known column
        $map = [];
        $headerAt = null;
        foreach (array_slice($raw, 0, 10, true) as $i => $row) {
            $found = [];
            foreach ($row as $col => $cell) {
                $key = self::match(self::norm((string) $cell), $columns);
                if ($key !== null && ! isset($found[$key])) {
                    $found[$key] = $col;
                }
            }
            if ($found !== [] && count($found) >= max(1, (int) floor(count($columns) / 3))) {
                $map = $found;
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            throw new RuntimeException('Baris judul kolom tidak ditemukan. Pakai template dari tombol "Unduh template".');
        }

        $missing = [];
        foreach ($columns as $key => $def) {
            if (($def['required'] ?? false) && ! isset($map[$key])) {
                $missing[] = $def['label'];
            }
        }

        $rows = [];
        foreach (array_slice($raw, (int) $headerAt + 1, null, true) as $i => $row) {
            $cells = [];
            $any = false;
            foreach ($map as $key => $col) {
                $v = $row[$col] ?? null;
                if ($v instanceof \DateTimeInterface) {
                    $v = $v->format('Y-m-d');
                } elseif (is_float($v) && str_contains($columns[$key]['label'], 'Tgl') && $v > 20000 && $v < 80000) {
                    $v = Date::excelToDateTimeObject($v)->format('Y-m-d');
                }
                $v = $v === null ? null : trim((string) $v);
                $v = $v === '' ? null : $v;
                $cells[$key] = $v;
                $any = $any || $v !== null;
            }
            if ($any) {
                $rows[] = ['line' => (int) $i + 1, 'cells' => $cells];
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('Terlalu banyak baris (maksimal '.self::MAX_ROWS.').');
            }
        }

        return ['rows' => $rows, 'missing' => $missing];
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)) ?? '';
    }

    /** @param array<string, array{label: string, aliases: list<string>}> $columns */
    private static function match(string $header, array $columns): ?string
    {
        if ($header === '') {
            return null;
        }
        foreach ($columns as $key => $def) {
            foreach ([$def['label'], ...$def['aliases']] as $alias) {
                $a = self::norm($alias);
                if ($header === $a || str_starts_with($header, $a)) {
                    return $key;
                }
            }
        }

        return null;
    }
}
