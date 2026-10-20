<?php

namespace App\Http\Controllers\Qr;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Actions\SubmitQrOrderAction;
use App\Domain\Ordering\Models\Order;
use App\Domain\Settings\Models\Outlet;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
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
            'menu' => MenuPresenter::categories($table->outlet_id, onlyAvailable: true),
            'outletId' => $table->outlet_id,
            'status' => $this->status($table),
        ]);
    }

    /** @return array<string, mixed> */
    public function status(Table|string $table): array
    {
        $table = $table instanceof Table ? $table : Table::query()->where('qr_token', $table)->firstOrFail();
        $order = Order::query()->where('table_id', $table->id)->whereIn('status', ['open', 'partially_paid'])->with('rounds.items')->latest('id')->first();

        if (! $order) {
            return ['rounds' => [], 'total' => 0, 'sold_out' => []];
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
                'items' => $r->items->map(fn ($i) => ['name' => $i->name_snapshot, 'qty' => $i->qty, 'modifiers' => collect($i->modifiers_snapshot ?? [])->pluck('name')->all(), 'voided' => $i->status->value === 'voided'])->values()->all(),
            ];
        })->values()->all();

        // Provisional total: confirmed live items plus pending rounds at menu price.
        $pendingTotal = (int) $order->rounds->where('status.value', 'pending')->flatMap->items->sum('line_total');

        return ['rounds' => $rounds, 'total' => $order->grand_total + $pendingTotal, 'sold_out' => []];
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
