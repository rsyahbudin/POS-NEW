<?php

namespace App\Domain\Import;

use App\Domain\Import\Importers\AssetsImporter;
use App\Domain\Import\Importers\CountImporter;
use App\Domain\Import\Importers\IngredientsImporter;
use App\Domain\Import\Importers\ProductsImporter;
use App\Domain\Import\Importers\PurchaseImporter;
use App\Domain\Import\Importers\RecipesImporter;
use App\Domain\Ordering\Exceptions\OrderException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportService
{
    /** @return array<string, Importer> */
    public function importers(): array
    {
        $all = [new IngredientsImporter, new PurchaseImporter, new CountImporter, new RecipesImporter, new ProductsImporter, new AssetsImporter];

        return collect($all)->keyBy(fn (Importer $i) => $i->key())->all();
    }

    public function find(string $key): Importer
    {
        return $this->importers()[$key] ?? throw new OrderException('Jenis impor tidak dikenal.');
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Importer $importer, string $path, ImportContext $ctx): array
    {
        try {
            $read = SheetReader::read($path, $importer->columns());
        } catch (RuntimeException $e) {
            throw new OrderException($e->getMessage());
        }
        if ($read['missing'] !== []) {
            throw new OrderException('Kolom wajib tidak ada di file: '.implode(', ', $read['missing']).'.');
        }
        if ($read['rows'] === []) {
            throw new OrderException('File tidak berisi baris data.');
        }

        $results = $importer->check($read['rows'], $ctx);
        $count = fn (string $s) => count(array_filter($results, fn ($r) => $r['status'] === $s));

        return [
            'summary' => ['total' => count($results), 'ok' => $count('ok'), 'new' => $count('new'), 'update' => $count('update'), 'error' => $count('error')],
            'columns' => collect($importer->columns())->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label']])->values()->all(),
            'rows' => array_map(fn ($r) => ['line' => $r['line'], 'status' => $r['status'], 'message' => $r['message'], 'cells' => $r['cells']], $results),
            'payload' => $read['rows'],
        ];
    }

    /**
     * Re-checks the rows (never trusting the preview) and writes the valid ones in one transaction.
     *
     * @param  list<array{line: int, cells: array<string, string|null>}>  $rows
     */
    public function apply(Importer $importer, array $rows, ImportContext $ctx): string
    {
        return DB::transaction(function () use ($importer, $rows, $ctx) {
            $valid = array_values(array_filter($importer->check($rows, $ctx), fn ($r) => $r['status'] !== 'error'));
            if ($valid === []) {
                throw new OrderException('Tidak ada baris yang bisa diimpor.');
            }

            return $importer->apply($valid, $ctx);
        });
    }
}
