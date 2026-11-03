<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One menu item inside a bundle product, with how many of it the bundle includes.
 *
 * @property int $id
 * @property int $bundle_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $qty
 * @property bool $ask_modifiers
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class BundleItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'sort' => 'integer', 'ask_modifiers' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
