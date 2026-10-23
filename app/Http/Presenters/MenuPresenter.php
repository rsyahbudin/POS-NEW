<?php

namespace App\Http\Presenters;

use App\Domain\Catalog\Models\Category;
use App\Domain\Floor\Models\Area;
use App\Domain\Ordering\Models\Order;
use App\Support\MediaUrl;

class MenuPresenter
{
    /**
     * Sold-out products, variants and options stay in the list, flagged, for the cashier and the QR menu alike.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(int $outletId): array
    {
        return Category::query()->where('outlet_id', $outletId)->orderBy('sort')
            ->with(['products' => fn ($q) => $q->where('is_active', true)->orderBy('sort')->with(['variants', 'modifierGroups.modifiers'])])
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'products' => $c->products->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'description' => $p->description,
                    'price' => $p->base_price, 'image' => MediaUrl::public($p->image_path),
                    'is_available' => $p->is_available, 'stock_qty' => $p->stock_qty,
                    'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price, 'is_available' => $v->is_available])->all(),
                    'modifier_groups' => $p->modifierGroups->map(fn ($g) => [
                        'id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_select) : $g->min_select,
                        'max' => $g->max_select, 'required' => $g->is_required,
                        'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => $m->price_delta, 'is_available' => $m->is_available])->all(),
                    ])->all(),
                ])->values()->all(),
            ])->filter(fn ($c) => $c['products'] !== [])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function floor(int $outletId): array
    {
        $open = Order::query()->where('outlet_id', $outletId)->whereIn('status', ['open', 'partially_paid'])->whereNotNull('table_id')->get()->keyBy('table_id');

        return Area::query()->where('outlet_id', $outletId)->orderBy('sort')->with('tables')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'tables' => $a->tables->map(fn ($t) => [
                'id' => $t->id, 'public_id' => $t->public_id, 'name' => $t->name, 'capacity' => $t->capacity,
                'status' => $t->status->value, 'qr_enabled' => $t->qr_enabled,
                'order' => ($o = $open->get($t->id)) ? ['id' => $o->id, 'number' => $o->number, 'grand_total' => $o->grand_total, 'bill_requested' => $o->bill_requested_at !== null, 'opened_at' => $o->created_at?->toIso8601String()] : null,
            ])->all(),
        ])->all();
    }
}
