<?php

namespace App\Domain\Printing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int $printer_id
 * @property string $kind
 * @property string|null $reference
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $printed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrintJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'printed_at' => 'datetime'];
    }
}
