<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Printing\Models\Printer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $name
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Category extends Model
{
    protected $guarded = [];

    /** @return BelongsToMany<Printer, $this> */
    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(Printer::class, 'category_printer');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
