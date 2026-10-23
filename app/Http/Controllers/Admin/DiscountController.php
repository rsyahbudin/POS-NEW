<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\Models\DiscountPreset;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The "Diskon & Promo" page: the cashier's registered discounts and the scheduled promos, away from Pengaturan. */
class DiscountController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(): Response
    {
        $id = $this->outlet->id();

        return Inertia::render('admin/discounts', [
            'discountPresets' => DiscountPreset::query()->where('outlet_id', $id)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'type', 'value', 'needs_pin', 'is_active']),
            'promotions' => Promotion::query()->where('outlet_id', $id)->orderByDesc('is_active')->orderBy('id')->get(),
            'menuChoices' => [
                'categories' => Category::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name']),
                'products' => Product::query()->where('outlet_id', $id)->orderBy('name')->get(['id', 'name', 'category_id']),
            ],
        ]);
    }
}
