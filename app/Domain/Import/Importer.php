<?php

namespace App\Domain\Import;

use App\Domain\Inventory\Models\Ingredient;
use App\Domain\Inventory\Units;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One kind of Excel import. `check` reads rows without writing anything (used for the preview and again
 * right before applying); `apply` writes the valid ones.
 *
 * A result row: line, status (ok | new | update | error), message, cells (as shown), record (normalised data).
 */
abstract class Importer
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function permission(): string;

    /** Who may use this import. */
    public function allows(User $user): bool
    {
        return $user->can($this->permission()) && (! $this->needsImportPermission() || $user->can('import-data'));
    }

    /** Bulk imports also need the "Impor dari Excel" permission; staff filling their own stock count do not. */
    public function needsImportPermission(): bool
    {
        return true;
    }

    /** @return array<string, array{label: string, aliases: list<string>, required?: bool}> */
    abstract public function columns(): array;

    /** @return list<string> */
    abstract public function notes(): array;

    /** @return array<int, array<int, mixed>> rows in column order, filled from what exists */
    abstract public function templateRows(int $outletId): array;

    /** @return array<string, array<int, string>> column key => suggested values (drop-down) */
    public function lists(int $outletId): array
    {
        return [];
    }

    /** @return list<array{key: string, label: string, type: string, default?: string}> */
    public function extraFields(): array
    {
        return [];
    }

    public function filename(): string
    {
        return 'template-'.$this->key();
    }

    /**
     * @param  list<array{line: int, cells: array<string, string|null>}>  $rows
     * @return list<array{line: int, status: string, message: string|null, cells: array<string, string|null>, record: array<string, mixed>}>
     */
    abstract public function check(array $rows, ImportContext $ctx): array;

    /**
     * @param  list<array{line: int, status: string, message: string|null, cells: array<string, string|null>, record: array<string, mixed>}>  $results  only rows without errors
     */
    abstract public function apply(array $results, ImportContext $ctx): string;

    /** @return Collection<string, Ingredient> lower-case name => ingredient */
    protected function ingredients(int $outletId): Collection
    {
        return Ingredient::query()->where('outlet_id', $outletId)->get()->keyBy(fn (Ingredient $i) => mb_strtolower($i->name));
    }

    /**
     * Quantity as typed (in the given unit, or the ingredient's base unit when empty) converted to the base unit.
     *
     * @return array{0: float|null, 1: string|null} base quantity, or an error message
     */
    protected function toBase(Ingredient $ing, ?string $unit, float $qty): array
    {
        try {
            return [Units::toBase($ing, $unit, $qty), null];
        } catch (OrderException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @param  array<string, string|null>  $cells
     * @param  array<string, mixed>  $record
     * @return array{line: int, status: string, message: string|null, cells: array<string, string|null>, record: array<string, mixed>}
     */
    protected function result(int $line, string $status, ?string $message, array $cells, array $record = []): array
    {
        return ['line' => $line, 'status' => $status, 'message' => $message, 'cells' => $cells, 'record' => $record];
    }
}
