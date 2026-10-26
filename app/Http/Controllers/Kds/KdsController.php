<?php

namespace App\Http\Controllers\Kds;

use App\Domain\Catalog\Models\Station;
use App\Domain\Floor\ServiceCalls;
use App\Domain\Ordering\Actions\UpdateItemStatusAction;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KdsController extends Controller
{
    public function __construct(private OutletContext $outlet, private SettingsRepository $settings, private ServiceCalls $calls) {}

    public function index(): RedirectResponse
    {
        $station = Station::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('sort')->firstOrFail();

        return redirect()->route('kds.show', $station);
    }

    public function show(Request $request, Station $station): Response
    {
        $handle = (bool) $request->user()?->can('handle-calls');

        return Inertia::render('kds/show', [
            'station' => ['id' => $station->id, 'name' => $station->name],
            'stations' => Station::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'tickets' => $this->tickets($station),
            'outletId' => $this->outlet->id(),
            'calls' => $handle ? $this->calls->pending('kitchen') : [],
            'canHandleCalls' => $handle,
            'keepRinging' => (bool) $this->settings->get('alert.keep_ringing', true),
            'thresholds' => [
                'warn' => (int) $this->settings->get('kitchen.warn_minutes', 10),
                'danger' => (int) $this->settings->get('kitchen.danger_minutes', 20),
            ],
        ]);
    }

    public function ticketsJson(Station $station): JsonResponse
    {
        return response()->json(['tickets' => $this->tickets($station)]);
    }

    public function advance(OrderItem $item, Request $request, UpdateItemStatusAction $action): JsonResponse
    {
        $item = $action->handle($item, $this->actor($request));

        return response()->json(['status' => $item->status->value]);
    }

    /**
     * Live items plus recently voided / ready ones so nothing disappears silently.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tickets(Station $station): array
    {
        $items = OrderItem::query()
            ->where('station_id', $station->id)
            ->whereHas('round', fn ($q) => $q->where('status', 'confirmed'))
            ->where(function ($q) {
                $q->whereIn('status', ['new', 'preparing'])
                    ->orWhere(fn ($q) => $q->where('status', 'ready')->where('ready_at', '>=', now()->subMinutes((int) $this->settings->get('kitchen.ready_visible_minutes', 5))))
                    ->orWhere(fn ($q) => $q->where('status', 'voided')->where('voided_at', '>=', now()->subMinutes((int) $this->settings->get('kitchen.void_visible_minutes', 10))));
            })
            ->with(['round', 'order.table'])
            ->orderBy('order_round_id')->orderBy('id')
            ->get();

        $tickets = [];
        foreach ($items->groupBy('order_round_id') as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }
            $order = $first->order;
            $round = $first->round;
            $open = $group->contains(fn ($i) => in_array($i->status->value, ['new', 'preparing'], true));

            $tickets[] = [
                'round_id' => $first->order_round_id,
                'order_number' => $order->number,
                'label' => $order->table->name ?? ($order->call_number ? "Takeaway {$order->call_number}" : ($order->platform ? "Online {$order->platform}" : $order->number)),
                'source' => $round->source->value,
                'since' => ($round->confirmed_at ?? $round->created_at)?->toIso8601String(),
                'done' => ! $open,
                'items' => $group->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->name_snapshot, 'qty' => $i->qty, 'note' => $i->note,
                    'modifiers' => collect($i->modifiers_snapshot ?? [])->map(fn ($m) => $m['name'])->all(), 'status' => $i->status->value,
                ])->values()->all(),
            ];
        }
        usort($tickets, fn (array $a, array $b) => strcmp((string) $a['since'], (string) $b['since']));

        return $tickets;
    }
}
