<?php

namespace App\Http\Controllers\Reservation;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\ReservationService;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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
            'reservations' => Reservation::query()->where('outlet_id', $id)->whereBetween('starts_at', [$from, $to])->with(['tables', 'order:id,number'])->orderBy('starts_at')->get()->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->public_id, 'name' => $r->name, 'phone' => $r->phone, 'pax' => $r->pax, 'status' => $r->status, 'note' => $r->note,
                'starts_at' => $r->starts_at->setTimezone(self::ZONE)->toIso8601String(), 'tables' => $r->tables->map(fn (Table $t) => ['id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity])->all(),
                'summary' => $r->summary, 'total' => $r->items_total, 'order' => $r->order?->number,
            ])->all(),
            'tables' => Table::query()->where('outlet_id', $id)->with('area:id,name')->orderBy('area_id')->orderBy('id')->get()->map(fn (Table $t) => ['id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity, 'area' => $t->area->name])->all(),
        ]);
    }

    public function seat(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->outlet_id === $this->outlet->id(), 404);

        try {
            $order = $this->reservations->seat($reservation, $this->actor($request));
        } catch (OrderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Tamu didudukkan. Pesanan {$order->number} dibuka dan dikirim ke dapur.");
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
}
