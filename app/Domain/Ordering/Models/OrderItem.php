<?php

namespace App\Domain\Ordering\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Station;
use App\Domain\Ordering\Enums\CompType;
use App\Domain\Ordering\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_round_id
 * @property int $order_id
 * @property int $product_id
 * @property int|null $station_id
 * @property string $name_snapshot
 * @property int $unit_price
 * @property int $qty
 * @property array<mixed>|null $modifiers_snapshot
 * @property string|null $note
 * @property int $line_total
 * @property ItemStatus $status
 * @property int|null $voided_by
 * @property int|null $void_approved_by
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property bool $stock_reserved
 * @property int|null $variant_id
 * @property array<int, int>|null $modifier_ids
 * @property int $cogs
 * @property bool $inventory_consumed
 * @property CompType|null $comp_type
 * @property string|null $comp_reason
 * @property int|null $comp_approved_by
 * @property Carbon|null $preparing_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read OrderRound $round
 * @property-read Product $product
 */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'comp_type' => CompType::class,
            'modifiers_snapshot' => 'array',
            'voided_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'stock_reserved' => 'boolean',
            'modifier_ids' => 'array',
            'inventory_consumed' => 'boolean',
        ];
    }

    /** @return BelongsTo<OrderRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(OrderRound::class, 'order_round_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
