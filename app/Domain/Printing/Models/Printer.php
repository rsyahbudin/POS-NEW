<?php

namespace App\Domain\Printing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A physical printer, defined by what it prints: receipts, bills for the waiter, or kitchen/bar tickets
 * of one station. Which device it is connected to is decided on the print station page.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $kind
 * @property int|null $station_id
 * @property int $paper_width
 * @property int $copies
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
        return ['is_active' => 'boolean'];
    }
}
