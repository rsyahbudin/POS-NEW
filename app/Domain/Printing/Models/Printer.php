<?php

namespace App\Domain\Printing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A physical printer, defined by what it prints: receipts, bills for the waiter, or kitchen/bar tickets
 * of one station. A printer with a LAN address is served by the print bridge; any other one is connected to a device on the print station page.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $kind
 * @property list<string>|null $roles
 * @property int|null $station_id
 * @property int $paper_width
 * @property int $copies
 * @property string|null $host
 * @property int $port
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Printer extends Model
{
    public const KINDS = ['receipt' => 'Struk kasir', 'bill' => 'Bill / tagihan', 'ticket' => 'Tiket pesanan (dapur/bar)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'roles' => 'array'];
    }

    /** @return list<string> everything this printer prints (old rows without roles print just their kind) */
    public function roleList(): array
    {
        return $this->roles ?? [$this->kind];
    }

    /**
     * Printers that print this role: receipt, bill or ticket.
     *
     * @param  Builder<Printer>  $query
     * @return Builder<Printer>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereJsonContains('roles', $role)->orWhere(fn (Builder $o) => $o->whereNull('roles')->where('kind', $role)));
    }

    /** Validation rule: the id of one of this outlet's printers that print the role. */
    public static function existsFor(int $outletId, string $role): Exists
    {
        return Rule::exists('printers', 'id')->where(fn ($q) => $q->where('outlet_id', $outletId)->where(fn ($w) => $w->whereJsonContains('roles', $role)->orWhere(fn ($o) => $o->whereNull('roles')->where('kind', $role))));
    }
}
