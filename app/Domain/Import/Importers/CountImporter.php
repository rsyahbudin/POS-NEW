<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\Units;

/**
 * Fills the user's stock count draft from a sheet. The draft is then checked and sent as usual
 * (and approved by a manager when that rule is on). The template never shows the system quantity.
 * The Satuan column says what the Hitungan is counted in; it must be a unit defined for the ingredient.
 */
class CountImporter extends Importer
{
    public function key(): string
    {
        return 'count';
    }

    public function needsImportPermission(): bool
    {
        return false;
    }

    public function title(): string
    {
        return 'Hasil stok opname';
    }

    public function permission(): string
    {
        return 'count-stock';
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Bahan', 'aliases' => ['nama bahan', 'nama', 'item'], 'required' => true],
            'unit' => ['label' => 'Satuan', 'aliases' => ['unit']],
            'counted' => ['label' => 'Hitungan', 'aliases' => ['jumlah', 'stok fisik', 'hasil hitung', 'qty'], 'required' => true],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Isi kolom Hitungan dengan jumlah bahan yang sebenarnya ada. Bahan yang Hitungannya kosong tidak ikut dihitung.',
            '2. Satuan = satuan dari angka Hitungan. Boleh satuan dasar (g, ml, pcs), kg / liter, atau kemasan yang sudah ditentukan (mis. 3 botol). Pilih dari daftar; satuan di luar daftar ditolak.',
            '3. Semua bahan yang terdaftar sudah ada di sheet Data. Angka stok di sistem sengaja tidak ditampilkan.',
            '4. Hasil impor masuk ke draf Stok Opname Anda. Periksa lagi di layar Stok Opname lalu tekan Kirim hitungan.',
            '5. Bahan BARU hanya bisa ditambahkan manager (tulis nama dan satuan dasar g, ml, atau pcs). Untuk staf, bahan yang belum terdaftar ditolak.',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return $this->ingredients($outletId)->where('is_active', true)->sortBy('name')->map(fn (Ingredient $i) => [$i->name, $i->unit, ''])->values()->all();
    }

    public function lists(int $outletId): array
    {
        $units = [];
        foreach ($this->ingredients($outletId)->where('is_active', true) as $i) {
            foreach (Units::choices($i) as $choice) {
                $units[$choice['name']] = true;
            }
        }

        return ['name' => $this->ingredients($outletId)->where('is_active', true)->pluck('name')->sort()->values()->all(), 'unit' => array_keys($units)];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = $this->ingredients($ctx->outletId);
        $canCreate = $ctx->user->can('manage-inventory');
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            if ($name !== null && ($c['counted'] ?? null) === null) {
                continue; // not counted
            }
            $counted = ImportNumber::parse($c['counted'] ?? null);
            $unit = $c['unit'] ?? null;
            $key = mb_strtolower((string) $name);
            $ing = $existing->get($key);
            $baseUnit = $ing === null ? Units::base($unit) : $ing->unit;
            $base = null;
            $unitError = null;
            if ($ing !== null && $counted !== null && $counted >= 0) {
                [$base, $unitError] = $this->toBase($ing, $unit, $counted);
            }

            $error = match (true) {
                $name === null => 'Nama bahan kosong.',
                $counted === null || $counted < 0 => 'Hitungan harus angka 0 atau lebih.',
                isset($seen[$key]) => "Bahan \"{$name}\" muncul dua kali.",
                $ing === null && ! $canCreate => "Bahan \"{$name}\" belum terdaftar. Minta manager menambahkannya.",
                $ing === null && $unit === null => "Bahan \"{$name}\" belum terdaftar: isi Satuan dasar (g, ml, atau pcs).",
                $ing === null && $baseUnit === null => "Satuan \"{$unit}\" tidak dikenal untuk bahan baru. Pakai g, ml, atau pcs.",
                $unitError !== null => $unitError,
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $seen[$key] = true;
            $base ??= $counted;
            $out[] = $this->result($row['line'], $ing === null ? 'new' : 'ok', $ing === null ? 'Bahan baru akan dibuat.' : ($base !== $counted ? "= {$base} {$ing->unit}" : null), $c, ['name' => $name, 'base_unit' => $baseUnit, 'counted' => $base]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $existing = $this->ingredients($ctx->outletId);
        $service = app(StockCountService::class);
        $draft = $service->draftFor($ctx->user, $ctx->outletId);
        $merged = $draft->lines()->pluck('counted', 'ingredient_id')->all();
        $created = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $ing = $existing->get(mb_strtolower($rec['name']));
            if ($ing === null) {
                $ing = Ingredient::create(['outlet_id' => $ctx->outletId, 'name' => $rec['name'], 'unit' => $rec['base_unit']]);
                $created++;
            }
            $merged[$ing->id] = $rec['counted'];
        }
        $service->save($draft, $merged, $draft->note);

        return 'Draf stok opname terisi '.count($results).' bahan'.($created > 0 ? " ({$created} bahan baru dibuat)" : '').'. Periksa lalu tekan Kirim hitungan.';
    }
}
