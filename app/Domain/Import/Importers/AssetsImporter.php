<?php

namespace App\Domain\Import\Importers;

use App\Domain\Asset\Models\Asset;
use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportNumber;
use App\Support\Audit;
use Carbon\Carbon;
use Throwable;

/** Equipment register: add assets, or update existing ones by their code. */
class AssetsImporter extends Importer
{
    public function key(): string
    {
        return 'assets';
    }

    public function title(): string
    {
        return 'Daftar aset & alat';
    }

    public function permission(): string
    {
        return 'manage-assets';
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Kode', 'aliases' => ['kode aset']],
            'name' => ['label' => 'Nama', 'aliases' => ['nama aset', 'aset', 'alat'], 'required' => true],
            'category' => ['label' => 'Kategori', 'aliases' => []],
            'brand' => ['label' => 'Merek', 'aliases' => ['brand']],
            'model' => ['label' => 'Model', 'aliases' => ['tipe']],
            'serial' => ['label' => 'No. seri', 'aliases' => ['nomor seri', 'serial']],
            'quantity' => ['label' => 'Jumlah', 'aliases' => ['qty', 'unit']],
            'purchased_on' => ['label' => 'Tgl beli', 'aliases' => ['tanggal beli']],
            'purchase_price' => ['label' => 'Harga per unit', 'aliases' => ['harga satuan', 'harga']],
            'location' => ['label' => 'Lokasi', 'aliases' => ['tempat']],
            'condition' => ['label' => 'Kondisi', 'aliases' => ['status']],
            'notes' => ['label' => 'Catatan', 'aliases' => ['keterangan']],
        ];
    }

    public function notes(): array
    {
        return [
            '1. Satu baris = satu jenis aset. Aset yang sudah ada sudah terisi; ubah isinya lalu unggah lagi untuk memperbarui.',
            '2. Aset BARU: tulis di baris kosong paling bawah. Kode boleh dikosongkan (dibuat otomatis, mis. AST-0007).',
            '3. Aset dikenali dari Kode. Kode yang sudah ada diperbarui, kode baru atau kosong membuat aset baru.',
            '4. Kondisi diisi: Baik, Perlu servis, Rusak, atau Tidak dipakai. Kosong = Baik.',
            '5. Tanggal beli ditulis seperti 2026-03-15 atau 15/03/2026. Harga per unit angka saja.',
        ];
    }

    public function templateRows(int $outletId): array
    {
        return Asset::query()->where('outlet_id', $outletId)->orderBy('code')->get()->map(fn (Asset $a) => [
            $a->code, $a->name, $a->category ?? '', $a->brand ?? '', $a->model ?? '', $a->serial ?? '', $a->quantity, $a->purchased_on?->toDateString() ?? '',
            $a->purchase_price, $a->location ?? '', Asset::CONDITIONS[$a->condition] ?? $a->condition, $a->notes ?? '',
        ])->all();
    }

    public function lists(int $outletId): array
    {
        return [
            'condition' => array_values(Asset::CONDITIONS),
            'category' => Asset::query()->where('outlet_id', $outletId)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all() ?: ['Mesin kopi', 'Grinder', 'Peralatan dapur', 'Kulkas & freezer', 'Furnitur', 'Elektronik'],
        ];
    }

    public function check(array $rows, ImportContext $ctx): array
    {
        $byCode = Asset::query()->where('outlet_id', $ctx->outletId)->get()->keyBy(fn (Asset $a) => mb_strtolower($a->code));
        $conditions = [];
        foreach (Asset::CONDITIONS as $key => $label) {
            $conditions[mb_strtolower($key)] = $key;
            $conditions[mb_strtolower($label)] = $key;
        }
        $conditions += ['tidak dipakai' => 'retired', 'dijual' => 'retired'];
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $name = $c['name'] ?? null;
            $code = $c['code'] ?? null;
            $qty = ($c['quantity'] ?? null) === null ? 1 : ImportNumber::money($c['quantity']);
            $price = ($c['purchase_price'] ?? null) === null ? 0 : ImportNumber::money($c['purchase_price']);
            $condRaw = $c['condition'] ?? null;
            $condition = $condRaw === null ? 'good' : ($conditions[mb_strtolower($condRaw)] ?? null);
            $date = null;
            $dateBad = false;
            if (($c['purchased_on'] ?? null) !== null) {
                try {
                    $date = Carbon::parse(str_contains($c['purchased_on'], '/') ? (Carbon::createFromFormat('d/m/Y', $c['purchased_on']) ?: throw new \InvalidArgumentException('date'))->toDateString() : $c['purchased_on'])->toDateString();
                } catch (Throwable) {
                    $dateBad = true;
                }
            }
            $found = $code !== null ? $byCode->get(mb_strtolower($code)) : null;

            $error = match (true) {
                $name === null => 'Nama aset kosong.',
                mb_strlen($name) > 100 => 'Nama terlalu panjang.',
                $code !== null && mb_strlen($code) > 20 => 'Kode terlalu panjang (maks 20).',
                $qty === null || $qty < 1 || $qty > 9999 => 'Jumlah harus angka 1 sampai 9999.',
                $price === null || $price < 0 => 'Harga harus angka.',
                $condition === null => "Kondisi \"{$condRaw}\" tidak dikenal (Baik, Perlu servis, Rusak, Tidak dipakai).",
                $dateBad => 'Tanggal beli tidak terbaca.',
                $code !== null && isset($seen[mb_strtolower($code)]) => "Kode \"{$code}\" muncul dua kali.",
                default => null,
            };
            if ($error) {
                $out[] = $this->result($row['line'], 'error', $error, $c);

                continue;
            }
            if ($code !== null) {
                $seen[mb_strtolower($code)] = true;
            }
            $out[] = $this->result($row['line'], $found ? 'update' : 'new', null, $c, [
                'id' => $found?->id, 'code' => $code, 'name' => $name, 'category' => $c['category'] ?? null, 'brand' => $c['brand'] ?? null, 'model' => $c['model'] ?? null,
                'serial' => $c['serial'] ?? null, 'quantity' => $qty, 'purchased_on' => $date, 'purchase_price' => $price, 'location' => $c['location'] ?? null,
                'condition' => $condition, 'notes' => $c['notes'] ?? null,
            ]);
        }

        return $out;
    }

    public function apply(array $results, ImportContext $ctx): string
    {
        $new = 0;
        $updated = 0;
        $count = Asset::query()->where('outlet_id', $ctx->outletId)->count();
        foreach ($results as $r) {
            $rec = $r['record'];
            $id = $rec['id'];
            unset($rec['id']);
            if ($id !== null) {
                $rec['code'] = $rec['code'] ?? Asset::query()->whereKey($id)->value('code');
                Asset::query()->whereKey($id)->update($rec);
                $updated++;

                continue;
            }
            if ($rec['code'] === null) {
                do {
                    $code = sprintf('AST-%04d', ++$count);
                } while (Asset::query()->where('outlet_id', $ctx->outletId)->where('code', $code)->exists());
                $rec['code'] = $code;
            }
            Asset::create($rec + ['outlet_id' => $ctx->outletId]);
            $new++;
        }
        Audit::log('import.assets', null, ['new' => $new, 'updated' => $updated], $ctx->user);

        return "Impor selesai: {$new} aset baru, {$updated} diperbarui.";
    }
}
