<?php

namespace App\Http\Controllers\Reservation;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MenuPresenter;
use App\Support\MediaUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The staff side: who is coming, seat them, change their tables, or let them go. */
class ReservationStaffController extends Controller
{
    private const ZONE = 'Asia/Jakarta';

    public function __construct(private OutletContext $outlet, private ReservationService $reservations) {}

    public function index(Request $request): Response
    {
        $date = (string) $request->query('date', CarbonImmutable::now(self::ZONE)->toDateString());
        $day = CarbonImmutable::parse($date, self::ZONE);
        $from = $day->startOfDay()->setTimezone((string) config('app.timezone'));
        $to = $day->endOfDay()->setTimezone((string) config('app.timezone'));
        $id = $this->outlet->id();

        return Inertia::render('reservations/index', [
            'date' => $day->toDateString(),
            'link' => url('/reservasi'),
            'enabled' => $this->reservations->enabled(),
            'canSeat' => (bool) $this->actor($request)->can('seat-reservations'),
            'outletName' => Outlet::query()->find($id)?->name,
            'day' => $this->reservations->availability($day->toDateString()) + ['booked' => $this->reservations->booked($day->toDateString()), 'time' => $this->reservations->time()],
            'reservations' => Reservation::query()->where('outlet_id', $id)->whereBetween('starts_at', [$from, $to])->with(['tables', 'order:id,number'])->orderBy('id')->get()->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->public_id, 'name' => $r->name, 'phone' => $r->phone, 'pax' => $r->pax, 'status' => $r->status, 'note' => $r->note,
                'starts_at' => $r->starts_at->setTimezone(self::ZONE)->toIso8601String(), 'tables' => $r->tables->map(fn (Table $t) => ['id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity])->all(),
                'summary' => $r->summary, 'total' => $r->items_total, 'order' => $r->order?->number,
                'payment_type' => $r->payment_type, 'amount_due' => $r->amount_due, 'paid' => $r->paid_amount, 'package_short' => $r->package_short, 'package_waived' => $r->package_waived,
                'full_menu' => $r->full_menu, 'deposit_applied' => $r->deposit_payment_id !== null, 'changed_at' => $r->changed_at?->setTimezone(self::ZONE)->toIso8601String(), 'created_at' => $r->created_at?->setTimezone(self::ZONE)->toIso8601String(),
            ])->all(),
            'tables' => Table::query()->where('outlet_id', $id)->with('area:id,name')->orderBy('area_id')->orderBy('id')->get()->map(fn (Table $t) => ['id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity, 'area' => $t->area->name])->all(),
        ]);
    }

    public function seat(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $user = $this->actor($request);

        try {
            $order = $this->reservations->seat($reservation, $user);
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        $dp = '';
        if ($reservation->paid_amount > 0) {
            try {
                $amount = $this->reservations->applyDeposit($reservation->refresh(), $user);
                $dp = ' DP Rp '.number_format($amount, 0, ',', '.').' sudah dipotong dari bill.';
            } catch (OrderException $e) {
                $dp = " DP belum dipotong dari bill: {$e->getMessage()}";
            }
        }

        return back()->with('success', "Tamu didudukkan. Pesanan {$order->number} dibuka dan dikirim ke dapur.{$dp}");
    }

    /** Takes the deposit off the bill after all, when seating could not (no open shift, no transfer method yet). */
    public function deposit(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);

        try {
            $amount = $this->reservations->applyDeposit($reservation, $this->actor($request));
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'DP Rp '.number_format($amount, 0, ',', '.').' sudah dipotong dari bill.');
    }

    /** Customer service takes a booking for a caller: the same form as the public one, without the closing time and quota. */
    public function create(): Response
    {
        Inertia::setRootView('public');
        $o = Outlet::query()->find($this->outlet->id());

        return Inertia::render('reservation/book', [
            'outlet' => ['name' => $o?->name, 'logo' => MediaUrl::public($o?->logo_path), 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp],
            'period' => ['from' => CarbonImmutable::now(self::ZONE)->toDateString(), 'to' => CarbonImmutable::now(self::ZONE)->addDays(90)->toDateString()],
            'menu' => MenuPresenter::categories($this->outlet->id(), reservationOnly: true),
            'editing' => null, 'staff' => true,
        ] + $this->reservations->formRules());
    }

    public function edit(Reservation $reservation): Response
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        Inertia::setRootView('public');
        $o = Outlet::query()->find($this->outlet->id());

        return Inertia::render('reservation/book', [
            'outlet' => ['name' => $o?->name, 'logo' => MediaUrl::public($o?->logo_path), 'phone' => $o?->phone, 'whatsapp' => $o?->whatsapp],
            'period' => ['from' => $reservation->starts_at->toDateString(), 'to' => $reservation->starts_at->toDateString()],
            'menu' => MenuPresenter::categories($this->outlet->id(), reservationOnly: ! $reservation->full_menu),
            'editing' => $this->reservations->editingProps($reservation), 'staff' => true,
        ] + $this->reservations->formRules());
    }

    /** @return array<string, mixed> */
    private function lineRules(string $key): array
    {
        return [
            $key => ['nullable', 'array', 'max:40'],
            "{$key}.*.product_id" => ['required', 'integer'], "{$key}.*.variant_id" => ['nullable', 'integer'],
            "{$key}.*.modifier_ids" => ['nullable', 'array'], "{$key}.*.modifier_ids.*" => ['integer'],
            "{$key}.*.parts" => ['nullable', 'array', 'max:30'], "{$key}.*.parts.*.product_id" => ['required', 'integer'],
            "{$key}.*.parts.*.variant_id" => ['nullable', 'integer'], "{$key}.*.parts.*.modifier_ids" => ['nullable', 'array'], "{$key}.*.parts.*.modifier_ids.*" => ['integer'],
            "{$key}.*.qty" => ['required', 'integer', 'min:1', 'max:50'], "{$key}.*.note" => ['nullable', 'string', 'max:120'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:30'], 'pax' => ['required', 'integer', 'min:1', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'], 'payment_type' => ['required', 'in:dp,full'], 'note' => ['nullable', 'string', 'max:200'],
            'paid' => ['nullable', 'integer', 'min:0'], 'waive_package' => ['nullable', 'boolean'], 'full_menu' => ['nullable', 'boolean'],
        ] + $this->lineRules('items'));
        $data['items'] ??= [];

        try {
            $r = $this->reservations->create($data, $this->actor($request));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['date' => $r->starts_at->setTimezone(self::ZONE)->toDateString()], 201);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'pax' => ['required', 'integer', 'min:1', 'max:200'], 'phone' => ['required', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:200'],
            'keep' => ['nullable', 'array', 'max:60'], 'keep.*.index' => ['required', 'integer', 'min:0'], 'keep.*.qty' => ['required', 'integer', 'min:0', 'max:50'],
        ] + $this->lineRules('add'));

        try {
            $this->reservations->update($reservation, $data, $this->actor($request));
        } catch (OrderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['date' => $reservation->starts_at->setTimezone(self::ZONE)->toDateString()]);
    }

    public function close(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $status = $request->validate(['status' => ['required', 'in:cancelled,no_show']])['status'];

        try {
            $this->reservations->close($reservation, $status, $this->actor($request));
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $status === 'cancelled' ? 'Reservasi dibatalkan.' : 'Ditandai tidak datang.');
    }

    public function retable(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $ids = $request->validate(['table_ids' => ['required', 'array', 'min:1'], 'table_ids.*' => ['integer']])['table_ids'];

        try {
            $this->reservations->retable($reservation, array_values(array_map('intval', $ids)), $this->actor($request));
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Meja reservasi diubah.');
    }

    public function confirm(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:0'], 'waive_package' => ['nullable', 'boolean']]);

        try {
            $this->reservations->confirm($reservation, (int) $data['amount'], (bool) ($data['waive_package'] ?? false), $this->actor($request));
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pembayaran dikonfirmasi. Reservasi sah.');
    }

    public function fullMenu(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $this->reservations->setFullMenu($reservation, $on, $this->actor($request));

        return back()->with('success', $on ? 'Tamu ini boleh memesan menu biasa.' : 'Tamu ini kembali ke menu reservasi.');
    }

    /** Gives tables to the day's bookings that have none. */
    public function assign(Request $request): RedirectResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $n = $this->reservations->autoAssign($date, $this->actor($request));

        return back()->with('success', $n > 0 ? "{$n} reservasi diberi meja." : 'Tidak ada reservasi yang perlu meja, atau mejanya belum cukup.');
    }
}
