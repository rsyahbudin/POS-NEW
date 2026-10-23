<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Pricing\Models\DiscountPreset;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Domain\Shift\ShiftResolver;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Http\Presenters\OrderPresenter;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function __construct(private OutletContext $outlet, private SettingsRepository $settings) {}

    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $outletId = $this->outlet->id();
        $shift = app(ShiftResolver::class)->forUser($user);

        return Inertia::render('pos/index', [
            'menu' => MenuPresenter::categories($outletId),
            'paymentMethods' => PaymentMethod::query()->where('outlet_id', $outletId)->where('is_active', true)->orderBy('sort')->get(['id', 'name', 'type']),
            'shift' => $shift ? ['id' => $shift->id, 'opened_at' => $shift->opened_at->toIso8601String(), 'mine' => $shift->user_id === $user->id, 'owner' => $shift->user?->name] : null,
            'suggestedOpening' => app(OpenShiftAction::class)->suggestedOpeningCash(),
            'can' => [
                'pay' => $user->can('receive-payment'),
                'void' => $user->can('void'),
                'refund' => $user->can('refund'),
                'discount' => $user->can('discount-within-limit') || $user->can('discount-custom'),
                'discount_custom' => $user->can('discount-custom'),
                'open_shift' => $user->can('open-close-shift'),
                'confirm_qr' => $user->can('receive-payment'),
            ],
            'settings' => [
                'discount_limit_percent' => (int) $this->settings->get('discount.cashier_limit_percent', 10),
                'qr_alert_seconds' => (int) $this->settings->get('qr.confirm_alert_seconds', 180),
                'kds_enabled' => (bool) $this->settings->get('kitchen.enabled', false),
                'quick_cash' => array_values(array_filter(array_map('intval', explode(',', (string) $this->settings->get('pos.quick_cash', '50000,100000,150000,200000'))))),
                'outlet_id' => $outletId,
            ],
            'discountPresets' => DiscountPreset::query()->where('outlet_id', $outletId)->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'type', 'value', 'needs_pin']),
            'state' => $this->state(),
        ]);
    }

    /** Polled by the screen (and refreshed on websocket events) to stay in sync. */
    /** @return array<string, mixed> */
    public function state(): array
    {
        $outletId = $this->outlet->id();

        $pending = OrderRound::query()->where('status', 'pending')->where('source', 'qr')
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId))
            ->with(['items', 'order.table'])->oldest()->get()
            ->map(fn (OrderRound $r) => OrderPresenter::round($r) + [
                'order_id' => $r->order_id, 'table' => $r->order->table?->name, 'table_id' => $r->order->table_id,
            ])->all();

        // QR rounds the system accepted by itself (auto-confirm): cashier still gets a notification.
        $recentQr = OrderRound::query()->where('source', 'qr')->where('status', 'confirmed')->whereNull('confirmed_by')
            ->where('created_at', '>=', now()->subMinutes(3))
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId))->with('order.table')->latest('id')->limit(20)->get()
            ->map(fn (OrderRound $r) => ['id' => $r->id, 'table' => $r->order->table?->name, 'customer_name' => $r->customer_name])->all();

        $kds = (bool) $this->settings->get('kitchen.enabled', false);
        $ready = ! $kds ? [] : OrderItem::query()->where('status', 'ready')->where('ready_at', '>=', now()->subMinutes((int) $this->settings->get('pos.ready_alert_minutes', 15)))
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId)->whereIn('status', ['open', 'partially_paid']))
            ->with('order.table')->get()
            ->map(fn (OrderItem $i) => ['id' => $i->id, 'name' => $i->name_snapshot, 'qty' => $i->qty, 'table' => $i->order->table->name ?? $i->order->call_number ?? $i->order->number, 'order_id' => $i->order_id])->all();

        $orders = Order::query()->where('outlet_id', $outletId)->whereIn('status', ['open', 'partially_paid'])
            ->whereNull('table_id')->latest('id')->limit(30)->get()
            ->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'type' => $o->type->value, 'call_number' => $o->call_number, 'platform' => $o->platform, 'grand_total' => $o->grand_total, 'status' => $o->status->value])->all();

        return [
            'floor' => MenuPresenter::floor($outletId),
            'pending_qr' => $pending,
            'recent_qr' => $recentQr,
            'ready_items' => $ready,
            'takeaway_orders' => $orders,
            'sold_out' => Product::query()->where('outlet_id', $outletId)->where('is_available', false)->pluck('id')->all(),
        ];
    }

    public function stateJson(): JsonResponse
    {
        return response()->json($this->state());
    }

    /**
     * Today's bills (business day), newest first. Managers and owners (sales report permission) see the whole outlet;
     * everybody else sees what they opened or took payment for.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $all = $user->can('view-sales-reports');
        $orders = Order::query()
            ->where('outlet_id', $this->outlet->id())->where('business_date', BusinessDate::for())
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('opened_by', $user->id)->orWhereHas('payments', fn ($p) => $p->where('received_by', $user->id))))
            ->with(['table:id,name', 'opener:id,name', 'bills:id,order_id,label,status,grand_total,is_unallocated'])
            ->latest('id')->limit(200)->get();

        $paid = $orders->whereIn('status', [OrderStatus::Paid, OrderStatus::Refunded]);

        return response()->json([
            'scope' => $all ? 'all' : 'mine',
            'summary' => [
                'paid' => $paid->count(),
                'total' => (int) $paid->sum(fn (Order $o) => $o->grand_total - $o->refunded_total),
                'open' => $orders->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyPaid])->count(),
                'cancelled' => $orders->where('status', OrderStatus::Cancelled)->count(),
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'where' => $o->table->name ?? $o->call_number ?? $o->platform ?? ucfirst((string) $o->type->value),
                'at' => $o->created_at?->toIso8601String(), 'status' => $o->status->value, 'status_label' => $o->status->label(),
                'total' => $o->grand_total, 'refunded' => $o->refunded_total, 'by' => $o->opener?->name,
                'bills' => $o->bills->where('is_unallocated', false)->map(fn ($b) => ['id' => $b->id, 'label' => $b->label, 'status' => $b->status->value, 'total' => $b->grand_total])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
