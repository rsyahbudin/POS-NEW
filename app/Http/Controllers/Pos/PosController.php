<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Domain\Shift\ShiftResolver;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Http\Presenters\OrderPresenter;
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
                'discount' => $user->can('discount-within-limit'),
                'confirm_qr' => $user->can('receive-payment'),
            ],
            'settings' => [
                'discount_limit_percent' => (int) $this->settings->get('discount.cashier_limit_percent', 10),
                'qr_alert_seconds' => (int) $this->settings->get('qr.confirm_alert_seconds', 180),
                'kds_enabled' => (bool) $this->settings->get('kitchen.enabled', false),
                'quick_cash' => array_values(array_filter(array_map('intval', explode(',', (string) $this->settings->get('pos.quick_cash', '50000,100000,150000,200000'))))),
                'outlet_id' => $outletId,
            ],
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
}
