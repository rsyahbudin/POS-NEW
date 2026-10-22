<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 */
class WasteReason extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
