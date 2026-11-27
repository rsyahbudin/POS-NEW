<?php

namespace App\Domain\Reservation\Models;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A table booking with a pre-order. While booked it holds its tables for the time window; when the guests arrive it is
 * seated: an order opens on the first table, the other tables join that order, and the pre-order goes to the kitchen.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $public_id
 * @property string $name
 * @property string $phone
 * @property int $pax
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status pending | booked | seated | cancelled | no_show
 * @property string|null $note
 * @property list<array<string, mixed>> $items
 * @property list<array{name: string, qty: int, total: int, options: string}> $summary
 * @property int $items_total
 * @property string $payment_type
 * @property int $amount_due
 * @property int $paid_amount
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property bool $package_short
 * @property bool $package_waived
 * @property bool $full_menu
 * @property Carbon|null $changed_at
 * @property Carbon|null $created_at
 * @property int|null $deposit_payment_id
 * @property int|null $area_id
 * @property bool $tight
 * @property int|null $order_id
 * @property Carbon|null $seated_at
 * @property-read Collection<int, Table> $tables
 */
class Reservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'seated_at' => 'datetime', 'confirmed_at' => 'datetime', 'changed_at' => 'datetime', 'items' => 'array', 'summary' => 'array', 'package_short' => 'boolean', 'package_waived' => 'boolean', 'full_menu' => 'boolean', 'tight' => 'boolean'];
    }

    /** @return BelongsToMany<Table, $this> */
    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(Table::class, 'reservation_tables')->orderBy('tables.name');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
