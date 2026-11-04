<?php

namespace App\Http\Controllers\Reservation;

use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public, login-free booking page: pick a day and time, then order from the reservation menu. */
class ReservationBookingController extends Controller
{
    public function __construct(private ReservationService $reservations, private OutletContext $outlet) {}

    public function show(): Response
    {
        Inertia::setRootView('public');
        $o = Outlet::query()->find($this->outlet->id());

        return Inertia::render('reservation/book', [
            'outlet' => ['name' => $o?->name, 'logo' => MediaUrl::public($o?->logo_path), 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp],
            'period' => $this->reservations->period(),
            'slots' => $this->reservations->slots(),
            'maxPax' => $this->reservations->maxPax(),
            'menu' => $this->reservations->period() === null ? [] : MenuPresenter::categories($this->outlet->id(), reservationOnly: true),
        ]);
    }

    /** Which times of a day still have room for this many people. */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'pax' => ['required', 'integer', 'min:1', 'max:200']]);
        if (! $this->reservations->isBookableDate($data['date'])) {
            return response()->json(['slots' => []]);
        }

        return response()->json(['slots' => $this->reservations->availability($data['date'], (int) $data['pax'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:30'], 'pax' => ['required', 'integer', 'min:1', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'], 'slot' => ['required', 'string', 'max:5'], 'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.product_id' => ['required', 'integer'], 'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array'], 'items.*.modifier_ids.*' => ['integer'],
            'items.*.parts' => ['nullable', 'array', 'max:30'], 'items.*.parts.*.product_id' => ['required', 'integer'],
            'items.*.parts.*.variant_id' => ['nullable', 'integer'], 'items.*.parts.*.modifier_ids' => ['nullable', 'array'], 'items.*.parts.*.modifier_ids.*' => ['integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:50'], 'items.*.note' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $r = $this->reservations->create($data);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['code' => $r->public_id], 201);
    }

    /** What the guest sees after booking, and can come back to with the code. */
    public function status(string $code): Response
    {
        Inertia::setRootView('public');
        $r = Reservation::query()->where('public_id', strtoupper($code))->with('tables')->firstOrFail();
        $o = Outlet::query()->find($r->outlet_id);

        return Inertia::render('reservation/status', [
            'outlet' => ['name' => $o?->name, 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp, 'maps_url' => $o?->maps_url, 'address' => $o?->address],
            'reservation' => [
                'code' => $r->public_id, 'name' => $r->name, 'pax' => $r->pax, 'status' => $r->status,
                'starts_at' => $r->starts_at->setTimezone('Asia/Jakarta')->toIso8601String(), 'tables' => $r->tables->pluck('name')->all(),
                'summary' => $r->summary, 'total' => $r->items_total, 'note' => $r->note,
            ],
        ]);
    }
}
