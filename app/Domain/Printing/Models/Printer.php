<?php

namespace App\Domain\Printing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A physical printer, defined by what it prints: receipts, bills for the waiter, or kitchen/bar tickets
 * of one station. A printer with a LAN address is served by the print bridge; any other one is connected to a device on the print station page.
 *
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property string $kind
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
        return ['is_active' => 'boolean'];
    }
}
