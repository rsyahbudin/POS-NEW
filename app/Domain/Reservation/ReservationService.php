<?php

namespace App\Domain\Reservation;

use App\Domain\Catalog\Models\Product;
use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Floor\Models\Table;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\ItemBuilder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ramadan reservations. A guest books a date and slot for a number of people and pre-orders from the reservation menu;
 * the service holds tables for the time window (several joined tables for a big party) and, when the party arrives,
 * seats it: one order on the first table, the other tables joined to it, so everything lands on one bill.
 */
class ReservationService
{
    private const ZONE = 'Asia/Jakarta';

    public function __construct(
        private SettingsRepository $settings,
        private ItemBuilder $items,
        private PlaceOrderAction $place,
        private SyncTableStatusAction $sync,
        private OutletContext $outlet,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('reservation.enabled', false);
    }

    /** @return list<string> the booking times, "17:30" */
    public function slots(): array
    {
        $raw = preg_split('/\s*,\s*/', trim((string) $this->settings->get('reservation.slots', ''))) ?: [];

        return array_values(array_filter($raw, fn ($s) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $s) === 1));
    }

    public function maxPax(): int
    {
        return max(1, (int) $this->settings->get('reservation.max_pax', 20));
    }

    /** @return array{from: string, to: string}|null the first and last bookable day, or null when reservations are closed */
    public function period(): ?array
    {
        $from = (string) $this->settings->get('reservation.start_date', '');
        $to = (string) $this->settings->get('reservation.end_date', '');
        if (! $this->enabled() || $from === '' || $to === '') {
            return null;
        }
        $today = CarbonImmutable::now(self::ZONE)->toDateString();

        return ['from' => max($from, $today), 'to' => $to];
    }

    public function isBookableDate(string $date): bool
    {
        $p = $this->period();

        return $p !== null && $date >= $p['from'] && $date <= $p['to'];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} start and end of the table hold, in the database timezone */
    public function window(string $date, string $slot): array
    {
        $start = CarbonImmutable::parse("{$date} {$slot}", self::ZONE);
        $end = $start->addMinutes(max(30, (int) $this->settings->get('reservation.duration_minutes', 120)));
        $db = (string) config('app.timezone');

        return [$start->setTimezone($db), $end->setTimezone($db)];
    }

    /**
     * Which booking times of a day still have room for this many people.
     *
     * @return array<string, bool>
     */
    public function availability(string $date, int $pax): array
    {
        $out = [];
        $now = CarbonImmutable::now(self::ZONE);
        foreach ($this->slots() as $slot) {
            [$start, $end] = $this->window($date, $slot);
            $out[$slot] = $start->gt($now) && $this->allocate($this->outlet->id(), $pax, $start, $end) !== null;
        }

        return $out;
    }

    /**
     * Tables for a party: one table that fits, else the fewest neighbouring tables of one area whose seats add up.
     *
     * @return list<int>|null
     */
    public function allocate(int $outletId, int $pax, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptReservation = null): ?array
    {
        $busy = DB::table('reservation_tables')->join('reservations', 'reservations.id', '=', 'reservation_tables.reservation_id')
            ->where('reservations.outlet_id', $outletId)->whereIn('reservations.status', ['booked', 'seated'])
            ->where('reservations.starts_at', '<', $end)->where('reservations.ends_at', '>', $start)
            ->when($exceptReservation !== null, fn ($q) => $q->where('reservations.id', '!=', $exceptReservation))
            ->pluck('reservation_tables.table_id')->map(fn ($id) => (int) $id)->all();

        $tables = Table::query()->where('outlet_id', $outletId)->orderBy('area_id')->orderBy('id')->get();
        $free = $tables->reject(fn (Table $t) => in_array($t->id, $busy, true));

        $single = $free->filter(fn (Table $t) => $t->capacity >= $pax)->sortBy('capacity')->first();
        if ($single !== null) {
            return [$single->id];
        }

        $best = null;
        foreach ($tables->groupBy('area_id') as $area) {
            $run = [];
            $seats = 0;
            foreach ($area->values() as $t) {
                if (in_array($t->id, $busy, true)) {
                    $run = [];
                    $seats = 0;

                    continue;
                }
                $run[] = $t->id;
                $seats += $t->capacity;
                while ($seats - $this->capacityOf($tables, $run[0]) >= $pax && count($run) > 1) {
                    $seats -= $this->capacityOf($tables, array_shift($run));
                }
                if ($seats >= $pax && ($best === null || count($run) < count($best))) {
                    $best = $run;
                }
            }
        }

        return $best;
    }

    /** @param  Collection<int, Table>  $tables */
    private function capacityOf($tables, int $id): int
    {
        return (int) ($tables->firstWhere('id', $id)->capacity ?? 0);
    }

    /**
     * @param  array<string, mixed>  $data  name, phone, pax, date (Y-m-d), slot (HH:MM), note, items[]
     */
    public function create(array $data): Reservation
    {
        if (! $this->enabled()) {
            throw new OrderException('Reservasi belum dibuka.');
        }
        $date = (string) ($data['date'] ?? '');
        $slot = (string) ($data['slot'] ?? '');
        $pax = (int) ($data['pax'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $phone = preg_replace('/[^\d+]/', '', (string) ($data['phone'] ?? '')) ?? '';
        if ($name === '' || strlen($phone) < 8) {
            throw new OrderException('Isi nama dan nomor telepon yang bisa dihubungi.');
        }
        if (! $this->isBookableDate($date)) {
            throw new OrderException('Tanggal itu belum bisa dipesan.');
        }
        if (! in_array($slot, $this->slots(), true)) {
            throw new OrderException('Pilih jam yang tersedia.');
        }
        if ($pax < 1 || $pax > $this->maxPax()) {
            throw new OrderException("Jumlah orang 1 sampai {$this->maxPax()}.");
        }

        $lines = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        if ($lines === []) {
            throw new OrderException('Pilih menu untuk dipesan.');
        }
        $outletId = $this->outlet->id();
        $ids = array_values(array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines)));
        if (Product::query()->where('outlet_id', $outletId)->where('reservation_menu', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new OrderException('Ada menu yang tidak termasuk menu reservasi.');
        }
        $rows = $this->items->build($lines, $outletId);

        [$start, $end] = $this->window($date, $slot);
        if ($start->lte(CarbonImmutable::now())) {
            throw new OrderException('Jam itu sudah lewat.');
        }

        $reservation = DB::transaction(function () use ($outletId, $name, $phone, $pax, $start, $end, $data, $lines, $rows) {
            // one booking at a time decides who gets the last tables of a slot
            Table::query()->where('outlet_id', $outletId)->lockForUpdate()->pluck('id');
            $tables = $this->allocate($outletId, $pax, $start, $end);
            if ($tables === null) {
                throw new OrderException('Jam itu sudah penuh. Coba jam atau tanggal lain.');
            }

            $r = Reservation::create([
                'outlet_id' => $outletId, 'public_id' => strtoupper(Str::random(8)), 'name' => $name, 'phone' => $phone, 'pax' => $pax,
                'starts_at' => $start, 'ends_at' => $end, 'status' => 'booked', 'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 200) : null,
                'items' => $lines,
                'summary' => array_map(fn (array $row) => [
                    'name' => $row['name_snapshot'], 'qty' => $row['qty'], 'total' => $row['line_total'],
                    'options' => implode(', ', array_filter(array_column((array) ($row['modifiers_snapshot'] ?? []), 'name'))),
                ], $rows),
                'items_total' => (int) array_sum(array_column($rows, 'line_total')),
            ]);
            $r->tables()->sync($tables);

            return $r;
        });

        $reservation->load('tables');
        Notifier::later(fn (Notifier $n) => $n->notify(
            $outletId, 'reservation.new', "Reservasi baru: {$reservation->name}, {$pax} orang",
            $reservation->starts_at->setTimezone(self::ZONE)->translatedFormat('d M H:i').' · meja '.$reservation->tables->pluck('name')->implode(', '),
            '/reservations', 'info', ['manage-reservations'],
        ));

        return $reservation;
    }

    /** The party arrived: open the order on the first table, join the others to it, and send the pre-order to the kitchen. */
    public function seat(Reservation $reservation, User $by): Order
    {
        if ($reservation->status !== 'booked') {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $tables = $reservation->tables()->get();
        if ($tables->isEmpty()) {
            throw new OrderException('Reservasi belum punya meja.');
        }
        foreach ($tables as $t) {
            if (Order::query()->openOnTable($t->id)->exists()) {
                throw new OrderException("Meja {$t->name} masih terisi.");
            }
        }

        return DB::transaction(function () use ($reservation, $tables, $by) {
            $main = $tables->first();
            $order = $this->place->handle($by, [
                'type' => 'dine_in', 'table_id' => $main->id, 'customer_name' => $reservation->name, 'items' => $reservation->items,
            ]);
            $order->joinedTables()->sync($tables->skip(1)->pluck('id')->all());
            $reservation->update(['status' => 'seated', 'order_id' => $order->id, 'seated_at' => now()]);
            $this->sync->handle($main->id);
            Audit::log('reservation.seated', $reservation, ['order' => $order->number, 'tables' => $tables->pluck('name')->all()], $by);

            return $order;
        });
    }

    public function close(Reservation $reservation, string $status, User $by): void
    {
        if ($reservation->status !== 'booked' || ! in_array($status, ['cancelled', 'no_show'], true)) {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $reservation->update(['status' => $status]);
        Audit::log("reservation.{$status}", $reservation, ['name' => $reservation->name], $by);
    }

    /**
     * Move a booking to other tables, as long as they are free for its window and seat everybody.
     *
     * @param  list<int>  $tableIds
     */
    public function retable(Reservation $reservation, array $tableIds, User $by): void
    {
        if ($reservation->status !== 'booked') {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $tables = Table::query()->where('outlet_id', $reservation->outlet_id)->whereIn('id', $tableIds)->get();
        if ($tables->isEmpty() || $tables->count() !== count(array_unique($tableIds)) || $tables->sum('capacity') < $reservation->pax) {
            throw new OrderException('Meja yang dipilih belum cukup untuk '.$reservation->pax.' orang.');
        }
        $taken = DB::table('reservation_tables')->join('reservations', 'reservations.id', '=', 'reservation_tables.reservation_id')
            ->where('reservations.outlet_id', $reservation->outlet_id)->whereIn('reservations.status', ['booked', 'seated'])->where('reservations.id', '!=', $reservation->id)
            ->where('reservations.starts_at', '<', $reservation->ends_at)->where('reservations.ends_at', '>', $reservation->starts_at)
            ->whereIn('reservation_tables.table_id', $tables->pluck('id'))->exists();
        if ($taken) {
            throw new OrderException('Salah satu meja itu sudah dipesan di jam yang sama.');
        }
        $reservation->tables()->sync($tables->pluck('id')->all());
        Audit::log('reservation.retabled', $reservation, ['tables' => $tables->pluck('name')->all()], $by);
    }
}
