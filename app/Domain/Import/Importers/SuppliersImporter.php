<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Inventory\Models\Supplier;
use App\Support\Audit;

/** Supplier list: new ones are added, existing ones (same name) get their contact data updated. */
class SuppliersImporter extends Importer
{
    public function key(): string
    {
        return 'suppliers';
    }

    public function title(): string
    {
        return 'Daftar pemasok';
    }

    public function permission(): string
    {
        return 'manage-suppliers';
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Pemasok', 'aliases' => ['nama pemasok', 'nama', 'supplier', 'toko'], 'required' => true],
            'phone' => ['label' => 'Telepon / WA', 'aliases' => ['telepon', 'telp', 'hp', 'wa', 'kontak']],
            'address' => ['label' => 'Alamat', 'aliases' => []],
            'note' => ['label' => 'Catatan', 'aliases' => ['keterangan']],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Satu baris = satu pemasok. Pemasok yang sudah ada sudah terisi; ubah kontak atau alamatnya lalu unggah lagi untuk memperbarui.',
            '2. Pemasok dikenali dari nama. Nama baru membuat pemasok baru.',
            '3. Telepon boleh ditulis 0812... atau 62812....',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return Supplier::query()->where('outlet_id', $outletId)->orderBy('name')->get()->map(fn (Supplier $s) => [$s->name, $s->phone ?? '', $s->address ?? '', $s->note ?? ''])->all();
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $existing = Supplier::query()->where('outlet_id', $ctx->outletId)->get()->keyBy(fn (Supplier $s) => mb_strtolower($s->name));
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $key = mb_strtolower((string) $name);
            $error = match (true) {
                $name === null => 'Nama pemasok kosong.',
                mb_strlen($name) > 80 => 'Nama pemasok terlalu panjang.',
                isset($seen[$key]) => "Pemasok \"{$name}\" muncul dua kali.",
                mb_strlen((string) ($c['phone'] ?? '')) > 30 => 'Telepon terlalu panjang.',
                mb_strlen((string) ($c['address'] ?? '')) > 200 => 'Alamat terlalu panjang.',
                mb_strlen((string) ($c['note'] ?? '')) > 300 => 'Catatan terlalu panjang.',
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            $seen[$key] = true;
            $out[] = $this->result($row['line'], $existing->has($key) ? 'update' : 'new', null, $c, ['name' => $name, 'phone' => $c['phone'] ?? null, 'address' => $c['address'] ?? null, 'note' => $c['note'] ?? null]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $existing = Supplier::query()->where('outlet_id', $ctx->outletId)->get()->keyBy(fn (Supplier $s) => mb_strtolower($s->name));
        $new = 0;
        $updated = 0;
        foreach ($results as $r) {
            $rec = $r['record'];
            $found = $existing->get(mb_strtolower($rec['name']));
            if ($found) {
                $found->update(['phone' => $rec['phone'], 'address' => $rec['address'], 'note' => $rec['note']]);
                $updated++;
            } else {
                Supplier::create(['outlet_id' => $ctx->outletId] + $rec);
                $new++;
            }
        }
        Audit::log('import.ingredients', null, ['suppliers_new' => $new, 'suppliers_updated' => $updated], $ctx->user);

        return "Impor selesai: {$new} pemasok baru, {$updated} diperbarui.";
    }
}
