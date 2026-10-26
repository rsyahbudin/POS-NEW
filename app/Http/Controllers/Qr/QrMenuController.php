<?php

namespace App\Http\Controllers\Qr;

use App\Domain\Floor\Models\ServiceCall;
use App\Domain\Floor\Models\Table;
use App\Domain\Floor\ServiceCalls;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Online\OnlinePaymentService;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public, login-free, table-scoped. Exposes nothing but this table's menu and order status. */
class QrMenuController extends Controller
{
    public function show(string $token): Response|\Illuminate\Http\Response
    {
        Inertia::setRootView('public');

        $table = Table::query()->where('qr_token', $token)->first();
        if (! $table || ! $table->qr_enabled) {
            return response(Inertia::render('qr/unavailable')->toResponse(request())->getContent() ?: '', 404);
        }

        return Inertia::render('qr/menu', [
            'token' => $token,
            'table' => ['name' => $table->name, 'public_id' => $table->public_id],
            'outlet' => Outlet::query()->find($table->outlet_id)?->name,
            'venue' => $this->venue($table->outlet_id),
            'menu' => MenuPresenter::categories($table->outlet_id),
            'outletId' => $table->outlet_id,
            'status' => $this->status($table),
            'qris' => app(OnlinePaymentService::class)->enabled(),
            'call' => ['enabled' => app(ServiceCalls::class)->enabled(), 'reasons' => ServiceCall::REASONS],
            'kds' => (bool) app(SettingsRepository::class)->get('kitchen.enabled', false),
            'autoConfirm' => (bool) app(SettingsRepository::class)->get('qr.auto_confirm', false),
        ]);
    }

    /** @return array<string, mixed> what the guest sees about the cafe: banner, hours, contact, wifi */
    private function venue(int $outletId): array
    {
        $o = Outlet::query()->findOrFail($outletId);

        return [
            'logo' => MediaUrl::public($o->logo_path), 'banner' => MediaUrl::public($o->banner_path), 'tagline' => $o->tagline, 'hours' => $o->opening_hours,
            'address' => $o->address, 'phone' => $o->phone, 'instagram' => $o->instagram, 'wifi_name' => $o->wifi_name, 'wifi_password' => $o->wifi_password,
        ];
    }

    /** @return array<string, mixed> */
    public function status(Table|string $table): array
    {
        $table = $table instanceof Table ? $table : Table::query()->where('qr_token', $table)->firstOrFail();
        $order = Order::query()->where('table_id', $table->id)->whereIn('status', ['open', 'partially_paid'])->with('rounds.items')->latest('id')->first();

        $call = $this->callState($table);
        if (! $order) {
            return ['rounds' => [], 'total' => 0, 'sold_out' => [], 'call' => $call];
        }

        $rounds = $order->rounds->sortBy('id')->map(function ($r) {
            $items = $r->items->whereNotIn('status', ['rejected']);
            $statuses = $items->pluck('status')->map->value;
            $label = match (true) {
                $r->status->value === 'pending' => 'Menunggu Konfirmasi',
                $r->status->value === 'rejected' => 'Ditolak',
                $statuses->isNotEmpty() && $statuses->every(fn ($s) => in_array($s, ['ready', 'voided'], true)) => 'Siap',
                $statuses->contains('preparing') || $statuses->contains('ready') => 'Dibuat',
                default => 'Diterima',
            };

            return [
                'id' => $r->id, 'status' => $label, 'reject_reason' => $r->reject_reason, 'created_at' => $r->created_at?->toIso8601String(),
                'items' => $r->items->map(fn ($i) => ['name' => $i->name_snapshot, 'qty' => $i->qty, 'price' => $i->line_total, 'modifiers' => collect($i->modifiers_snapshot ?? [])->pluck('name')->all(), 'voided' => $i->status->value === 'voided'])->values()->all(),
            ];
        })->values()->all();

        // Provisional total: confirmed live items plus pending rounds at menu price.
        $pendingTotal = (int) $order->rounds->where('status.value', 'pending')->flatMap->items->sum('line_total');

        return ['rounds' => $rounds, 'total' => $order->grand_total + $pendingTotal, 'sold_out' => [], 'call' => $call];
    }

    /** @return array{status: string, reason: string, at: string}|null the guest's latest call of the last 10 minutes */
    private function callState(Table $table): ?array
    {
        $c = ServiceCall::query()->where('table_id', $table->id)->where('created_at', '>=', now()->subMinutes(10))->latest('id')->first();

        return $c === null ? null : ['status' => $c->status, 'reason' => $c->reasonLabel(), 'at' => ($c->handled_at ?? $c->created_at)?->toIso8601String() ?? ''];
    }

    public function call(string $token, Request $request, ServiceCalls $calls): JsonResponse
    {
        $table = Table::query()->where('qr_token', $token)->first();
        abort_unless($table && $table->qr_enabled, 404);
        $data = $request->validate(['reason' => 'nullable|string|max:20']);
        $calls->request($table, (string) ($data['reason'] ?? 'help'));

        return response()->json(['call' => $this->callState($table)]);
    }

    public function statusJson(string $token): JsonResponse
    {
        return response()->json($this->status($token));
    }

    public function store(string $token, Request $request, SubmitQrOrderAction $action): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['nullable', 'string', 'max:120'],
        ]);
        $round = $action->handle($token, $data);

        return response()->json(['round_id' => $round->id, 'status' => $this->status($token)], 201);
    }
}
