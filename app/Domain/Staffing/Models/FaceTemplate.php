<?php

namespace App\Domain\Staffing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One enrolled face sample of a person: 128 numbers from the face model. It cannot be turned back into a picture.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $user_id
 * @property list<float> $descriptor
 * @property Carbon $consented_at
 * @property int|null $enrolled_by
 */
class FaceTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['descriptor' => 'array', 'consented_at' => 'datetime'];
    }
}
