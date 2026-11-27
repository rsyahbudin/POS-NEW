<?php

namespace App\Http\Controllers\Reservation;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public, login-free booking page: pick a day, then order from the reservation menu; the guest can come back to change it. */
class ReservationBookingController extends Controller
{
    public function __construct(private ReservationService $reservations, private OutletContext $outlet, private SettingsRepository $settings) {}

    /** @return array<string, mixed> what the booking form needs besides the menu */
    private function rules(): array
    {
        $id = $this->outlet->id();

        return [
            'time' => $this->reservations->time(), 'maxPax' => $this->reservations->maxPax(), 'dpPercent' => $this->reservations->dpPercent(),
            'cutoff' => $this->reservations->cutoffTime(), 'changeUntil' => $this->reservations->changeUntilTime(),
            'packageRequired' => $this->reservations->packageRequired(),
            'packageIds' => Product::query()->where('outlet_id', $id)->where('reservation_package', true)->pluck('id')->all(),
            'paymentInfo' => (string) $this->settings->get('reservation.payment_info', ''),
        ];
    }

    /** @return array<string, mixed> */
    private function outletProps(): array
    {
        $o = Outlet::query()->find($this->outlet->id());

        return ['name' => $o?->name, 'logo' => MediaUrl::public($o?->logo_path), 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp];
    }

    public function show(): Response
    {
        Inertia::setRootView('public');

        return Inertia::render('reservation/book', [
            'outlet' => $this->outletProps(),
            'period' => $this->reservations->period(),
            'menu' => $this->reservations->period() === null ? [] : MenuPresenter::categories($this->outlet->id(), reservationOnly: true),
            'editing' => null,
        ] + $this->rules());
    }

    /** The same form with an existing booking: change people, quantities and menu, or cancel. */
    public function edit(string $code): Response|RedirectResponse
    {
        Inertia::setRootView('public');
        $r = $this->find($code);
        if (! $this->reservations->guestCanChange($r)) {
            return redirect()->route('reservation.status', $r->public_id);
        }

        return Inertia::render('reservation/book', [
            'outlet' => $this->outletProps(),
            'period' => $this->reservations->period() ?? ['from' => $r->starts_at->toDateString(), 'to' => $r->starts_at->toDateString()],
            'menu' => MenuPresenter::categories($this->outlet->id(), reservationOnly: ! $r->full_menu),
            'editing' => [
                'code' => $r->public_id, 'name' => $r->name, 'phone' => $r->phone, 'pax' => $r->pax, 'note' => $r->note, 'date' => $r->starts_at->setTimezone('Asia/Jakarta')->toDateString(),
                'payment_type' => $r->payment_type, 'paid' => $r->paid_amount, 'status' => $r->status,
                // the lines already ordered, kept by their position so the guest can change their quantity
                'lines' => collect($r->items)->values()->map(fn ($line, $i) => ['index' => $i, 'qty' => (int) $line['qty']] + (array) ($r->summary[$i] ?? ['name' => '', 'total' => 0, 'options' => '']))->all(),
            ],
        ] + $this->rules());
    }

    /** How many people still fit on a day, and whether it takes bookings. */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->reservations->availability($data['date']));
    }

    /** @return array<string, mixed> */
    private function itemRules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:40'],
            'items.*.product_id' => ['required', 'integer'], 'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array'], 'items.*.modifier_ids.*' => ['integer'],
            'items.*.parts' => ['nullable', 'array', 'max:30'], 'items.*.parts.*.product_id' => ['required', 'integer'],
            'items.*.parts.*.variant_id' => ['nullable', 'integer'], 'items.*.parts.*.modifier_ids' => ['nullable', 'array'], 'items.*.parts.*.modifier_ids.*' => ['integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:50'], 'items.*.note' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:30'], 'pax' => ['required', 'integer', 'min:1', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'], 'payment_type' => ['required', 'in:dp,full'], 'note' => ['nullable', 'string', 'max:200'],
        ] + $this->itemRules());
        $data['items'] ??= [];

        try {
            $r = $this->reservations->create($data);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['code' => $r->public_id], 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'pax' => ['required', 'integer', 'min:1', 'max:200'], 'phone' => ['required', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:200'],
            'keep' => ['nullable', 'array', 'max:60'], 'keep.*.index' => ['required', 'integer', 'min:0'], 'keep.*.qty' => ['required', 'integer', 'min:0', 'max:50'],
            'add' => ['nullable', 'array', 'max:40'],
        ] + collect($this->itemRules())->mapWithKeys(fn ($v, $k) => [str_replace('items', 'add', $k) => $v])->except('add')->all());

        try {
            $this->reservations->update($this->find($code), $data);
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['code' => strtoupper($code)]);
    }

    public function cancel(string $code): JsonResponse
    {
        try {
            $this->reservations->cancelByGuest($this->find($code));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['code' => strtoupper($code)]);
    }

    private function find(string $code): Reservation
    {
        return Reservation::query()->where('outlet_id', $this->outlet->id())->where('public_id', strtoupper($code))->firstOrFail();
    }

    /** What the guest sees after booking, and can come back to with the code. */
    public function status(string $code): Response
    {
        Inertia::setRootView('public');
        $r = $this->find($code)->load('tables');
        $o = Outlet::query()->find($r->outlet_id);

        return Inertia::render('reservation/status', [
            'outlet' => ['name' => $o?->name, 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp, 'maps_url' => $o?->maps_url, 'address' => $o?->address],
            'paymentInfo' => (string) $this->settings->get('reservation.payment_info', ''),
            'changeUntil' => $this->reservations->changeUntilTime(),
            'fullMenuFrom' => $this->reservations->fullMenuFromTime(),
            'reservation' => [
                'code' => $r->public_id, 'name' => $r->name, 'pax' => $r->pax, 'status' => $r->status,
                'starts_at' => $r->starts_at->setTimezone('Asia/Jakarta')->toIso8601String(), 'tables' => $r->tables->pluck('name')->all(),
                'summary' => $r->summary, 'total' => $r->items_total, 'note' => $r->note,
                'payment_type' => $r->payment_type, 'amount_due' => $r->amount_due, 'paid' => $r->paid_amount, 'package_short' => $r->package_short && ! $r->package_waived,
                'can_change' => $this->reservations->guestCanChange($r), 'full_menu' => $r->full_menu,
            ],
        ]);
    }
}
