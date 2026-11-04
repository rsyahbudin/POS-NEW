<?php

namespace App\Http\Presenters;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Floor\Models\Area;
use App\Domain\Floor\Models\ServiceCall;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Settings\SettingsRepository;
use App\Support\MediaUrl;

class MenuPresenter
{
    /**
     * Sold-out products, variants and options stay in the list, flagged, for the cashier and the QR menu alike.
     * With $reservationOnly it lists just the items of the Ramadan reservation menu.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(int $outletId, bool $reservationOnly = false): array
    {
        return Category::query()->where('outlet_id', $outletId)->orderBy('sort')
            ->with(['products' => fn ($q) => $q->where('is_active', true)->when($reservationOnly, fn ($p) => $p->where('reservation_menu', true))->orderBy('sort')->with(['variants', 'modifierGroups.modifiers', 'bundleItems.product.variants', 'bundleItems.product.modifierGroups.modifiers', 'bundleItems.variant'])])
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'products' => $c->products->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'description' => $p->description,
                    'price' => $p->base_price, 'image' => MediaUrl::public($p->image_path),
                    'is_available' => $p->is_available, 'stock_qty' => $p->stock_qty,
                    'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price, 'is_available' => $v->is_available])->all(),
                    'modifier_groups' => $p->is_bundle ? [] : self::groups($p->modifierGroups),
                    'bundle' => $p->is_bundle ? $p->bundleItems->map(fn ($b) => [
                        'product_id' => $b->product_id, 'name' => $b->product->name, 'qty' => $b->qty,
                        'fixed_variant' => $b->variant?->name,
                        'variants' => $b->variant === null ? $b->product->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price, 'is_available' => $v->is_available])->all() : [],
                        'modifier_groups' => $b->ask_modifiers ? self::groups($b->product->modifierGroups) : [],
                    ])->all() : null,
                ])->values()->all(),
            ])->filter(fn ($c) => $c['products'] !== [])->values()->all();
    }

    /**
     * @param  iterable<ModifierGroup>  $groups
     * @return array<int, array<string, mixed>>
     */
    private static function groups(iterable $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_select) : $g->min_select,
                'max' => $g->max_select, 'required' => $g->is_required,
                'modifiers' => $g->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => $m->price_delta, 'is_available' => $m->is_available])->all(),
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function floor(int $outletId): array
    {
        // one order may stand on several tables (a reservation party): every table of it shows that order, the extra ones marked as joined
        $orders = Order::query()->where('outlet_id', $outletId)->whereIn('status', ['open', 'partially_paid'])->whereNotNull('table_id')->with(['joinedTables:id', 'table:id,name'])->get();
        $open = $orders->keyBy('table_id');
        $joinedTo = [];
        foreach ($orders as $o) {
            foreach ($o->joinedTables as $jt) {
                $open->put($jt->id, $o);
                $joinedTo[$jt->id] = $o->table->name ?? null;
            }
        }
        // bookings about to start: the cashier sees which tables to keep free
        $ahead = max(0, (int) app(SettingsRepository::class)->get('reservation.floor_ahead_minutes', 90));
        $reserved = [];
        foreach (Reservation::query()->where('outlet_id', $outletId)->where('status', 'booked')->where('starts_at', '<=', now()->addMinutes($ahead))->where('ends_at', '>', now())->with('tables:id')->orderBy('starts_at')->get() as $r) {
            foreach ($r->tables as $rt) {
                $reserved[$rt->id] ??= ['name' => $r->name, 'pax' => $r->pax, 'time' => $r->starts_at->setTimezone('Asia/Jakarta')->format('H:i'), 'code' => $r->public_id];
            }
        }
        $calling = ServiceCall::query()->where('outlet_id', $outletId)->where('status', 'pending')->pluck('table_id')->flip();
        // what the floor view shows on a busy table: how many items, how many are ready to serve, how many QR rounds wait for the cashier
        $orderIds = $open->pluck('id');
        $lines = OrderItem::query()->whereIn('order_id', $orderIds)->whereIn('status', ['new', 'preparing', 'ready'])
            ->selectRaw("order_id, SUM(qty) AS qty, SUM(CASE WHEN status = 'ready' THEN qty ELSE 0 END) AS ready")->groupBy('order_id')->get()->keyBy('order_id');
        $waiting = OrderRound::query()->whereIn('order_id', $orderIds)->where('status', 'pending')->selectRaw('order_id, COUNT(*) AS n')->groupBy('order_id')->pluck('n', 'order_id');

        return Area::query()->where('outlet_id', $outletId)->orderBy('sort')->with('tables')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'tables' => $a->tables->map(fn ($t) => [
                'id' => $t->id, 'public_id' => $t->public_id, 'name' => $t->name, 'capacity' => $t->capacity,
                'status' => $t->status->value, 'qr_enabled' => $t->qr_enabled, 'calling' => $calling->has($t->id), 'reserved' => $reserved[$t->id] ?? null,
                'order' => ($o = $open->get($t->id)) ? [
                    'id' => $o->id, 'number' => $o->number, 'grand_total' => $o->grand_total, 'bill_requested' => $o->bill_requested_at !== null, 'opened_at' => $o->created_at?->toIso8601String(),
                    'joined_to' => $joinedTo[$t->id] ?? null,
                    'customer_name' => $o->customer_name, 'items' => (int) ($lines->get($o->id)->qty ?? 0), 'ready' => (int) ($lines->get($o->id)->ready ?? 0), 'pending_qr' => (int) ($waiting[$o->id] ?? 0),
                ] : null,
            ])->all(),
        ])->all();
    }
}
