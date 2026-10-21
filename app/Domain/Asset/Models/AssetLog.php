<?php

namespace App\Domain\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asset_id
 * @property string $type
 * @property Carbon $happened_on
 * @property int $cost
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
class AssetLog extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['service' => 'Servis', 'repair' => 'Perbaikan', 'condition' => 'Ubah kondisi', 'move' => 'Pindah lokasi', 'note' => 'Catatan'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['happened_on' => 'date'];
    }
}
