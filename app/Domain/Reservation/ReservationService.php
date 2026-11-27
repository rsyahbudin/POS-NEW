<?php

namespace App\Domain\Reservation;

use App\Domain\Catalog\Models\Product;
use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Floor\Models\Area;
use App\Domain\Floor\Models\Table;
use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\ItemBuilder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Enums\PaymentMethodType;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Settings\Models\Outlet;
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
        private RecordPaymentAction $payments,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('reservation.enabled', false);
    }

    public function maxPax(): int
    {
        return max(1, (int) $this->settings->get('reservation.max_pax', 30));
    }

    public function capacity(): int
    {
        return max(1, (int) $this->settings->get('reservation.capacity', 200));
    }

    /** @return string a setting holding a time of day, "18:00", with the fallback when it is not one */
    private function clock(string $key, string $default): string
    {
        $v = (string) $this->settings->get($key, $default);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : $default;
    }

    /** The one time every reservation shares: when the fast is broken. */
    public function time(): string
    {
        return $this->clock('reservation.time', '18:00');
    }

    public function cutoffTime(): string
    {
        return $this->clock('reservation.cutoff_time', '15:00');
    }

    public function changeUntilTime(): string
    {
        return $this->clock('reservation.change_until', '17:00');
    }

    public function fullMenuFromTime(): string
    {
        return $this->clock('reservation.full_menu_from', '19:00');
    }

    public function packageRequired(): bool
    {
        return (bool) $this->settings->get('reservation.package_required', true);
    }

    public function dpPercent(): int
    {
        return min(100, max(1, (int) $this->settings->get('reservation.dp_percent', 30)));
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
    public function window(string $date): array
    {
        $start = CarbonImmutable::parse("{$date} {$this->time()}", self::ZONE);
        $end = $start->addMinutes(max(30, (int) $this->settings->get('reservation.duration_minutes', 120)));
        $db = (string) config('app.timezone');

        return [$start->setTimezone($db), $end->setTimezone($db)];
    }

    /** People already booked for a day (waiting for payment, confirmed or seated). */
    public function booked(string $date, ?int $exceptReservation = null): int
    {
        $day = CarbonImmutable::parse($date, self::ZONE);
        $db = (string) config('app.timezone');

        return (int) Reservation::query()->where('outlet_id', $this->outlet->id())->whereIn('status', ['pending', 'booked', 'seated'])
            ->whereBetween('starts_at', [$day->startOfDay()->setTimezone($db), $day->endOfDay()->setTimezone($db)])
            ->when($exceptReservation !== null, fn ($q) => $q->where('id', '!=', $exceptReservation))->sum('pax');
    }

    /**
     * Whether a day takes new bookings, and how many people still fit.
     *
     * @return array{open: bool, reason: string|null, capacity: int, remaining: int}
     */
    public function availability(string $date): array
    {
        $capacity = $this->capacity();
        $remaining = max(0, $capacity - $this->booked($date));
        $now = CarbonImmutable::now(self::ZONE);
        $reason = null;
        if (! $this->isBookableDate($date)) {
            $reason = 'Tanggal itu belum bisa dipesan.';
        } elseif ($date === $now->toDateString() && $now->format('H:i') >= $this->cutoffTime()) {
            $reason = "Reservasi hari ini ditutup pukul {$this->cutoffTime()}.";
        } elseif ($remaining <= 0) {
            $reason = 'Kuota hari itu sudah penuh.';
        }

        return ['open' => $reason === null, 'reason' => $reason, 'capacity' => $capacity, 'remaining' => $remaining];
    }

    /** The last moment the guest may change or cancel on their own: today's "change until" time on the reservation day. */
    public function changeDeadline(Reservation $r): CarbonImmutable
    {
        $day = $r->starts_at->setTimezone(self::ZONE)->toDateString();

        return CarbonImmutable::parse("{$day} {$this->changeUntilTime()}", self::ZONE);
    }

    public function guestCanChange(Reservation $r): bool
    {
        return in_array($r->status, ['pending', 'booked'], true) && CarbonImmutable::now(self::ZONE)->lt($this->changeDeadline($r));
    }

    /** From when a seated party may order the normal menu from its table. */
    public function fullMenuOpensAt(Reservation $r): CarbonImmutable
    {
        $day = $r->starts_at->setTimezone(self::ZONE)->toDateString();

        return CarbonImmutable::parse("{$day} {$this->fullMenuFromTime()}", self::ZONE);
    }

    /**
     * A table QR order on a table where a reservation party sits: before the normal menu opens (and unless staff opened it
     * early) only the reservation menu can be added. Staff orders are never held back.
     *
     * @param  list<array<string, mixed>>  $rows  item rows built for the order
     */
    public function guardQr(Order $order, array $rows): void
    {
        $r = Reservation::query()->where('order_id', $order->id)->first();
        if ($r === null || $r->full_menu || CarbonImmutable::now(self::ZONE)->gte($this->fullMenuOpensAt($r))) {
            return;
        }
        $allowed = Product::query()->where('outlet_id', $order->outlet_id)->where('reservation_menu', true)->pluck('id')->all();
        foreach ($rows as $row) {
            if (! in_array((int) ($row['product_id'] ?? 0), $allowed, true)) {
                throw new OrderException("Menu biasa bisa dipesan mulai pukul {$this->fullMenuFromTime()}. Panggil staf kalau perlu lebih awal.");
            }
        }
    }

    /**
     * Tables for a party in one area (or anywhere): a table that seats them normally, else one they can pack into, else a
     * run of neighbouring tables that seat them normally, else a run they can pack into.
     *
     * @param  list<int>  $extraBusy  tables to treat as taken as well
     * @return array{ids: list<int>, tight: bool}|null
     */
    public function pickTables(int $outletId, int $pax, CarbonImmutable $start, CarbonImmutable $end, ?int $areaId = null, ?int $exceptReservation = null, array $extraBusy = []): ?array
    {
        $busy = array_values(DB::table('reservation_tables')->join('reservations', 'reservations.id', '=', 'reservation_tables.reservation_id')
            ->where('reservations.outlet_id', $outletId)->whereIn('reservations.status', ['pending', 'booked', 'seated'])
            ->where('reservations.starts_at', '<', $end)->where('reservations.ends_at', '>', $start)
            ->when($exceptReservation !== null, fn ($q) => $q->where('reservations.id', '!=', $exceptReservation))
            ->pluck('reservation_tables.table_id')->map(fn ($id) => (int) $id)->merge($extraBusy)->all());

        $tables = Table::query()->where('outlet_id', $outletId)->when($areaId !== null, fn ($q) => $q->where('area_id', $areaId))->orderBy('area_id')->orderBy('id')->get();
        // one table seating them normally, one table packed tight, then joined tables normally, then joined and packed
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$squeeze, $join]) {
            $ids = $this->fit($tables, $busy, $pax, $squeeze, $join);
            if ($ids !== null) {
                $normal = (int) $tables->whereIn('id', $ids)->sum('capacity');

                return ['ids' => $ids, 'tight' => $normal < $pax];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Table>  $tables
     * @param  list<int>  $busy
     * @return list<int>|null
     */
    private function fit(Collection $tables, array $busy, int $pax, bool $squeeze, bool $join): ?array
    {
        $seats = fn (Table $t): int => $squeeze ? $t->squeezeCapacity() : $t->capacity;
        $free = $tables->reject(fn (Table $t) => in_array($t->id, $busy, true));
        $single = $free->filter(fn (Table $t) => $seats($t) >= $pax)->sortBy($seats)->first();
        if ($single !== null || ! $join) {
            return $single === null ? null : [$single->id];
        }

        $best = null;
        foreach ($tables->groupBy('area_id') as $area) {
            $run = [];
            $total = 0;
            foreach ($area->values() as $t) {
                if (in_array($t->id, $busy, true)) {
                    $run = [];
                    $total = 0;

                    continue;
                }
                $run[] = $t;
                $total += $seats($t);
                while (count($run) > 1 && $total - $seats($run[0]) >= $pax) {
                    $total -= $seats(array_shift($run));
                }
                if ($total >= $pax && ($best === null || count($run) < count($best))) {
                    $best = $run;
                }
            }
        }

        return $best === null ? null : array_map(fn (Table $t) => $t->id, $best);
    }

    /** @return list<int>|null the tables for a party, or null when nothing fits */
    public function allocate(int $outletId, int $pax, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptReservation = null, ?int $areaId = null): ?array
    {
        return $this->pickTables($outletId, $pax, $start, $end, $areaId, $exceptReservation)['ids'] ?? null;
    }

    /** How many parties of this size still fit in an area, each on its own tables. */
    public function unitsFor(int $outletId, int $areaId, int $pax, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $taken = [];
        $n = 0;
        while ($n < 30 && ($pick = $this->pickTables($outletId, $pax, $start, $end, $areaId, null, $taken)) !== null) {
            $taken = array_merge($taken, $pick['ids']);
            $n++;
        }

        return $n;
    }

    /**
     * The areas a guest can pick for a day and party size, with how many such parties each can still take.
     *
     * @return list<array{id: int, name: string, units: int}>
     */
    public function areaOptions(string $date, int $pax): array
    {
        [$start, $end] = $this->window($date);
        $outletId = $this->outlet->id();
        $out = [];
        foreach (Area::query()->where('outlet_id', $outletId)->whereHas('tables')->orderBy('sort')->orderBy('id')->get() as $area) {
            $out[] = ['id' => $area->id, 'name' => $area->name, 'units' => $this->unitsFor($outletId, $area->id, $pax, $start, $end)];
        }

        return $out;
    }

    /** The area with the most room left for this party, so quiet areas fill first; null when no area can take it. */
    private function bestArea(int $outletId, int $pax, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $best = null;
        $most = 0;
        foreach (Area::query()->where('outlet_id', $outletId)->whereHas('tables')->orderBy('sort')->orderBy('id')->get() as $area) {
            $units = $this->unitsFor($outletId, $area->id, $pax, $start, $end);
            if ($units > $most) {
                $best = $area->id;
                $most = $units;
            }
        }

        return $best;
    }

    /**
     * Holds tables for a booking in its area (or the best one) and records whether the party is packed in.
     *
     * @return bool whether tables were found
     */
    private function assignTables(Reservation $r, ?int $areaId): bool
    {
        $start = CarbonImmutable::instance($r->starts_at);
        $end = CarbonImmutable::instance($r->ends_at);
        $areaId ??= $this->bestArea($r->outlet_id, $r->pax, $start, $end);
        $pick = $areaId === null ? null : $this->pickTables($r->outlet_id, $r->pax, $start, $end, $areaId, $r->id);
        if ($pick === null) {
            return false;
        }
        $r->tables()->sync($pick['ids']);
        $r->update(['area_id' => $areaId, 'tight' => $pick['tight']]);

        return true;
    }

    /**
     * Prices the lines against the menu and says how many packages they hold.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{rows: list<array<string, mixed>>, summary: list<array<string, mixed>>, total: int, packages: int}
     */
    private function compose(array $lines, int $outletId, bool $fullMenu): array
    {
        if ($lines === []) {
            throw new OrderException('Pilih menu untuk dipesan.');
        }
        $ids = array_values(array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines)));
        $products = Product::query()->where('outlet_id', $outletId)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $product = $products->get($id);
            if ($product === null || (! $fullMenu && ! $product->reservation_menu)) {
                throw new OrderException('Ada menu yang tidak termasuk menu reservasi.');
            }
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->items->build($lines, $outletId);
        $packages = 0;
        foreach ($rows as $row) {
            if ($products->get((int) ($row['product_id'] ?? 0))?->reservation_package === true) {
                $packages += (int) $row['qty'];
            }
        }

        return [
            'rows' => $rows, 'packages' => $packages, 'total' => (int) array_sum(array_column($rows, 'line_total')),
            'summary' => array_map(fn (array $row) => [
                'name' => $row['name_snapshot'], 'qty' => $row['qty'], 'total' => $row['line_total'],
                'options' => implode(', ', array_filter(array_column((array) ($row['modifiers_snapshot'] ?? []), 'name'))),
            ], $rows),
        ];
    }

    /** One package per person: fewer packages than people needs staff approval (when the rule is on and the menu has packages). */
    private function packageShort(int $packages, int $pax, int $outletId): bool
    {
        if (! $this->packageRequired() || ! Product::query()->where('outlet_id', $outletId)->where('reservation_package', true)->exists()) {
            return false;
        }

        return $packages < $pax;
    }

    /** What the guest is asked to pay now. */
    public function amountDue(string $type, int $total): int
    {
        return $type === 'full' ? $total : (int) (ceil($total * $this->dpPercent() / 100 / 1000) * 1000);
    }

    /**
     * @param  array<string, mixed>  $data  name, phone, pax, date (Y-m-d), payment_type (dp|full), note, items[]
     */
    public function create(array $data, ?User $by = null): Reservation
    {
        if ($by === null && ! $this->enabled()) {
            throw new OrderException('Reservasi belum dibuka.');
        }
        $date = (string) ($data['date'] ?? '');
        $pax = (int) ($data['pax'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $phone = preg_replace('/[^\d+]/', '', (string) ($data['phone'] ?? '')) ?? '';
        $type = (string) ($data['payment_type'] ?? 'dp');
        if ($name === '' || strlen($phone) < 8) {
            throw new OrderException('Isi nama dan nomor telepon yang bisa dihubungi.');
        }
        if (! in_array($type, ['dp', 'full'], true)) {
            throw new OrderException('Pilih bayar DP atau lunas.');
        }
        // customer service books on behalf of a caller: closing time, quota and the per-booking limit do not stop them
        if ($pax < 1 || $pax > ($by === null ? $this->maxPax() : 200)) {
            throw new OrderException('Jumlah orang 1 sampai '.($by === null ? $this->maxPax() : 200).'.');
        }
        if ($by !== null && ($date === '' || $date < CarbonImmutable::now(self::ZONE)->toDateString())) {
            throw new OrderException('Pilih tanggal hari ini atau sesudahnya.');
        }
        $outletId = $this->outlet->id();
        $made = $this->compose(is_array($data['items'] ?? null) ? array_values($data['items']) : [], $outletId, (bool) ($data['full_menu'] ?? false) && $by !== null);

        $reservation = DB::transaction(function () use ($outletId, $name, $phone, $pax, $date, $type, $data, $made, $by) {
            // one booking at a time decides who gets the last places of the day
            Outlet::query()->whereKey($outletId)->lockForUpdate()->value('id');
            if ($by === null) {
                $day = $this->availability($date);
                if (! $day['open']) {
                    throw new OrderException((string) $day['reason']);
                }
                if ($pax > $day['remaining']) {
                    throw new OrderException("Sisa kuota hari itu {$day['remaining']} orang.");
                }
            }
            [$start, $end] = $this->window($date);
            $areaId = isset($data['area_id']) && (int) $data['area_id'] > 0 ? (int) $data['area_id'] : null;
            if ($areaId !== null && ! Area::query()->where('outlet_id', $outletId)->whereKey($areaId)->exists()) {
                throw new OrderException('Area itu tidak ada.');
            }
            $hasTables = Table::query()->where('outlet_id', $outletId)->exists();
            $areaId ??= $hasTables ? $this->bestArea($outletId, $pax, CarbonImmutable::instance($start), CarbonImmutable::instance($end)) : null;
            $pick = $areaId === null ? null : $this->pickTables($outletId, $pax, CarbonImmutable::instance($start), CarbonImmutable::instance($end), $areaId);
            if ($pick === null && $by === null && $hasTables) {
                $areaName = $areaId === null ? null : Area::query()->whereKey($areaId)->value('name');
                throw new OrderException($areaName === null ? "Semua area sudah penuh untuk {$pax} orang." : "Area {$areaName} sudah penuh untuk {$pax} orang. Pilih area lain atau hubungi kami.");
            }

            $booking = Reservation::create([
                'outlet_id' => $outletId, 'public_id' => strtoupper(Str::random(8)), 'name' => $name, 'phone' => $phone, 'pax' => $pax,
                'starts_at' => $start, 'ends_at' => $end, 'status' => 'pending', 'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 200) : null,
                'items' => array_values($data['items']), 'summary' => $made['summary'], 'items_total' => $made['total'],
                'payment_type' => $type, 'amount_due' => $this->amountDue($type, $made['total']),
                'package_short' => $this->packageShort($made['packages'], $pax, $outletId), 'full_menu' => (bool) ($data['full_menu'] ?? false) && $by !== null,
                'area_id' => $areaId, 'tight' => $pick['tight'] ?? false,
            ]);
            if ($pick !== null) {
                $booking->tables()->sync($pick['ids']);
            }

            return $booking->refresh();
        });

        if ($by !== null) {
            Audit::log('reservation.created_by_staff', $reservation, ['pax' => $pax, 'date' => $date], $by);
            if (isset($data['paid']) && (int) $data['paid'] > 0) {
                $this->confirm($reservation, (int) $data['paid'], (bool) ($data['waive_package'] ?? false), $by);
            }

            return $reservation->refresh();
        }

        Notifier::later(fn (Notifier $n) => $n->notify(
            $outletId, 'reservation.new', "Reservasi baru: {$reservation->name}, {$pax} orang",
            $reservation->starts_at->setTimezone(self::ZONE)->translatedFormat('d M').' · menunggu konfirmasi pembayaran'.($reservation->package_short ? ' · paket kurang dari jumlah orang' : ''),
            '/reservations', 'info', ['manage-reservations'],
        ));

        return $reservation;
    }

    /**
     * The guest (or staff) changes a booking: people, phone, note, quantities of the lines already ordered, and more menu.
     * The guest can do it until the change deadline of the day; staff any time before seating.
     *
     * @param  array<string, mixed>  $data  pax, phone, note, keep (list of {index, qty}), add (list of new order lines)
     */
    public function update(Reservation $r, array $data, ?User $by = null): Reservation
    {
        $this->guardEditable($r, $by);
        $pax = (int) ($data['pax'] ?? $r->pax);
        if ($pax < 1 || $pax > ($by === null ? $this->maxPax() : 200)) {
            throw new OrderException('Jumlah orang 1 sampai '.($by === null ? $this->maxPax() : 200).'.');
        }
        $name = $by !== null && isset($data['name']) && trim((string) $data['name']) !== '' ? mb_substr(trim((string) $data['name']), 0, 80) : $r->name;
        $phone = preg_replace('/[^\d+]/', '', (string) ($data['phone'] ?? $r->phone)) ?? '';
        if (strlen($phone) < 8) {
            throw new OrderException('Isi nomor telepon yang bisa dihubungi.');
        }

        $lines = [];
        $keep = is_array($data['keep'] ?? null) ? $data['keep'] : null;
        foreach ($r->items as $i => $line) {
            $qty = (int) $line['qty'];
            if ($keep !== null) {
                $hit = collect($keep)->first(fn ($k) => (int) ($k['index'] ?? -1) === $i);
                $qty = $hit === null ? 0 : (int) ($hit['qty'] ?? 0);
            }
            if ($qty > 0) {
                $lines[] = ['qty' => min(50, $qty)] + $line;
            }
        }
        foreach (is_array($data['add'] ?? null) ? $data['add'] : [] as $add) {
            $lines[] = $add;
        }
        $made = $this->compose($lines, $r->outlet_id, $r->full_menu);

        $moved = null;
        if ($pax !== $r->pax) {
            $current = $r->tables()->get();
            if ($current->isNotEmpty() && (int) $current->sum('capacity') >= $pax) {
                $moved = ['ids' => $current->pluck('id')->all(), 'tight' => false];
            } elseif ($current->isNotEmpty() && (int) $current->sum(fn (Table $t) => $t->squeezeCapacity()) >= $pax) {
                $moved = ['ids' => $current->pluck('id')->all(), 'tight' => true];
            } else {
                $moved = $this->pickTables($r->outlet_id, $pax, CarbonImmutable::instance($r->starts_at), CarbonImmutable::instance($r->ends_at), $r->area_id, $r->id);
                if ($moved === null && $by === null) {
                    throw new OrderException("Tempat untuk {$pax} orang di area itu sudah penuh. Hubungi kami untuk mengatur ulang.");
                }
            }
            $r->tables()->sync($moved['ids'] ?? []);
        }

        $r->update([
            'tight' => $moved['tight'] ?? ($pax === $r->pax ? $r->tight : false),
            'pax' => $pax, 'name' => $name, 'phone' => $phone, 'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 200) : $r->note,
            'items' => $lines, 'summary' => $made['summary'], 'items_total' => $made['total'], 'amount_due' => $this->amountDue($r->payment_type, $made['total']),
            'package_short' => $this->packageShort($made['packages'], $pax, $r->outlet_id), 'changed_at' => now(),
        ]);
        Audit::log('reservation.changed', $r, ['pax' => $pax, 'total' => $made['total']], $by);
        if ($by === null) {
            Notifier::later(fn (Notifier $n) => $n->notify(
                $r->outlet_id, 'reservation.changed', "Reservasi diubah: {$r->name}, {$pax} orang", 'Pesanan atau jumlah orang berubah. Cek selisih pembayarannya.', '/reservations', 'warning', ['manage-reservations'],
            ));
        }

        return $r->refresh();
    }

    /** The guest cancels their own booking, within the same deadline. */
    public function cancelByGuest(Reservation $r): void
    {
        $this->guardEditable($r, null);
        $r->update(['status' => 'cancelled', 'changed_at' => now()]);
        Audit::log('reservation.cancelled_by_guest', $r, ['name' => $r->name, 'paid' => $r->paid_amount]);
        Notifier::later(fn (Notifier $n) => $n->notify(
            $r->outlet_id, 'reservation.cancelled', "Reservasi dibatalkan: {$r->name}", $r->paid_amount > 0 ? 'Sudah membayar Rp '.number_format($r->paid_amount, 0, ',', '.').'. Atur pengembalian atau DP-nya.' : 'Belum ada pembayaran.', '/reservations', 'warning', ['manage-reservations'],
        ));
    }

    private function guardEditable(Reservation $r, ?User $by): void
    {
        if (! in_array($r->status, ['pending', 'booked'], true)) {
            throw new OrderException('Reservasi ini sudah tidak bisa diubah.');
        }
        if ($by === null && ! $this->guestCanChange($r)) {
            throw new OrderException("Perubahan setelah pukul {$this->changeUntilTime()} lewat pengelola. Hubungi kami.");
        }
    }

    /**
     * Staff confirm that money arrived (a deposit or the full amount): the booking now counts as confirmed. A booking with
     * fewer packages than people needs the explicit say-so of staff.
     */
    public function confirm(Reservation $r, int $amount, bool $waivePackage, User $by): void
    {
        if (! in_array($r->status, ['pending', 'booked'], true)) {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        if ($amount < 0) {
            throw new OrderException('Nominal tidak boleh minus.');
        }
        if ($r->package_short && ! $r->package_waived && ! $waivePackage) {
            throw new OrderException('Paket kurang dari jumlah orang. Centang "setujui paket kurang" kalau tetap diterima.');
        }
        $r->update([
            'status' => 'booked', 'paid_amount' => $amount, 'confirmed_at' => $r->confirmed_at ?? now(), 'confirmed_by' => $r->confirmed_by ?? $by->id,
            'package_waived' => $r->package_waived || ($r->package_short && $waivePackage),
        ]);
        Audit::log('reservation.confirmed', $r, ['paid' => $amount, 'type' => $r->payment_type], $by);
    }

    /** Lets one party order the normal menu (the guest sees the full menu when changing the booking, and the QR hold is lifted). */
    public function setFullMenu(Reservation $r, bool $on, User $by): void
    {
        $r->update(['full_menu' => $on]);
        Audit::log('reservation.full_menu', $r, ['on' => $on], $by);
    }

    /**
     * Gives tables to the day's bookings that have none yet, earliest booking first.
     *
     * @return int how many bookings got tables
     */
    public function autoAssign(string $date, User $by): int
    {
        $day = CarbonImmutable::parse($date, self::ZONE);
        $db = (string) config('app.timezone');
        $done = 0;
        $pending = Reservation::query()->where('outlet_id', $this->outlet->id())->whereIn('status', ['pending', 'booked'])->whereDoesntHave('tables')
            ->whereBetween('starts_at', [$day->startOfDay()->setTimezone($db), $day->endOfDay()->setTimezone($db)])->orderBy('id')->get();
        foreach ($pending as $r) {
            if ($this->assignTables($r, $r->area_id)) {
                $done++;
            }
        }
        Audit::log('reservation.auto_assigned', null, ['date' => $date, 'count' => $done], $by);

        return $done;
    }

    /** The party arrived: open the order on the first table, join the others to it, and send the pre-order to the kitchen. */
    public function seat(Reservation $reservation, User $by): Order
    {
        if ($reservation->status === 'pending') {
            throw new OrderException('Konfirmasi pembayarannya dulu sebelum mendudukkan tamu.');
        }
        if ($reservation->status !== 'booked') {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $tables = $reservation->tables()->get();
        if ($tables->isEmpty()) {
            // nobody gave this party tables yet: the waiter at the door should not be stuck, so find free ones now
            if (! $this->assignTables($reservation, $reservation->area_id) && ! $this->assignTables($reservation, null)) {
                throw new OrderException('Belum ada meja kosong yang cukup untuk '.$reservation->pax.' orang. Minta CS atau manager mengatur mejanya.');
            }
            $tables = $reservation->tables()->get();
            if ($tables->isEmpty()) {
                throw new OrderException('Meja belum bisa dipilihkan. Minta CS atau manager mengatur mejanya.');
            }
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
            Audit::log('reservation.seated', $reservation, ['order' => $order->number, 'tables' => $tables->pluck('name')->all(), 'paid' => $reservation->paid_amount], $by);

            return $order;
        });
    }

    /**
     * The deposit (or whatever was paid ahead) becomes a payment on the party's bill, so the cashier finds it already
     * taken off. Needs an open shift and a transfer or QRIS payment method; it never goes beyond what the bill still owes.
     *
     * @return int the amount taken off the bill
     */
    public function applyDeposit(Reservation $r, User $by): int
    {
        if ($r->status !== 'seated' || $r->order_id === null) {
            throw new OrderException('DP dipotong setelah tamu didudukkan.');
        }
        if ($r->deposit_payment_id !== null) {
            throw new OrderException('DP sudah dipotong dari bill.');
        }
        if ($r->paid_amount <= 0) {
            throw new OrderException('Belum ada pembayaran yang dikonfirmasi.');
        }
        $order = Order::query()->findOrFail($r->order_id);
        $bill = $order->bills()->where('is_unallocated', false)->first() ?? throw new OrderException('Bill pesanan belum ada.');
        $method = PaymentMethod::query()->where('outlet_id', $r->outlet_id)->where('is_active', true)->whereIn('type', [PaymentMethodType::Transfer->value, PaymentMethodType::Qris->value])
            ->orderByRaw("case type when 'transfer' then 0 else 1 end")->orderBy('sort')->first()
            ?? throw new OrderException('Belum ada metode pembayaran transfer atau QRIS. Tambahkan di Pengaturan > Pembayaran.');
        $amount = min($r->paid_amount, $bill->remaining());
        if ($amount <= 0) {
            throw new OrderException('Bill sudah tidak punya sisa untuk dipotong.');
        }

        $payment = $this->payments->handle($bill, $by, $method->id, $amount, "reservation-{$r->id}-deposit", "DP reservasi {$r->public_id}");
        $r->update(['deposit_payment_id' => $payment->id]);
        Audit::log('reservation.deposit_applied', $r, ['amount' => $amount, 'order' => $order->number], $by);

        return $amount;
    }

    /**
     * An existing booking as the change form shows it.
     *
     * @return array<string, mixed>
     */
    public function editingProps(Reservation $r): array
    {
        return [
            'code' => $r->public_id, 'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'pax' => $r->pax, 'note' => $r->note, 'date' => $r->starts_at->setTimezone(self::ZONE)->toDateString(),
            'payment_type' => $r->payment_type, 'paid' => $r->paid_amount, 'status' => $r->status,
            // the lines already ordered, kept by their position so their quantity can change
            'lines' => collect($r->items)->values()->map(fn ($line, $i) => ['index' => $i, 'qty' => (int) $line['qty']] + (array) ($r->summary[$i] ?? ['name' => '', 'total' => 0, 'options' => '']))->all(),
        ];
    }

    /**
     * What a booking form needs to know about the rules.
     *
     * @return array<string, mixed>
     */
    public function formRules(): array
    {
        $id = $this->outlet->id();

        return [
            'time' => $this->time(), 'maxPax' => $this->maxPax(), 'dpPercent' => $this->dpPercent(),
            'cutoff' => $this->cutoffTime(), 'changeUntil' => $this->changeUntilTime(), 'packageRequired' => $this->packageRequired(),
            'packageIds' => Product::query()->where('outlet_id', $id)->where('reservation_package', true)->pluck('id')->all(),
            'paymentInfo' => (string) $this->settings->get('reservation.payment_info', ''),
        ];
    }

    public function close(Reservation $reservation, string $status, User $by): void
    {
        if (! in_array($reservation->status, ['pending', 'booked'], true) || ! in_array($status, ['cancelled', 'no_show'], true)) {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $reservation->update(['status' => $status]);
        Audit::log("reservation.{$status}", $reservation, ['name' => $reservation->name, 'paid' => $reservation->paid_amount], $by);
    }

    /**
     * Move a booking to other tables, as long as they are free for its window and seat everybody.
     *
     * @param  list<int>  $tableIds
     */
    public function retable(Reservation $reservation, array $tableIds, User $by): void
    {
        if (! in_array($reservation->status, ['pending', 'booked'], true)) {
            throw new OrderException('Reservasi ini sudah tidak aktif.');
        }
        $tables = Table::query()->where('outlet_id', $reservation->outlet_id)->whereIn('id', $tableIds)->get();
        if ($tables->isEmpty() || $tables->count() !== count(array_unique($tableIds)) || $tables->sum(fn (Table $t) => $t->squeezeCapacity()) < $reservation->pax) {
            throw new OrderException('Meja yang dipilih belum cukup untuk '.$reservation->pax.' orang.');
        }
        $taken = DB::table('reservation_tables')->join('reservations', 'reservations.id', '=', 'reservation_tables.reservation_id')
            ->where('reservations.outlet_id', $reservation->outlet_id)->whereIn('reservations.status', ['pending', 'booked', 'seated'])->where('reservations.id', '!=', $reservation->id)
            ->where('reservations.starts_at', '<', $reservation->ends_at)->where('reservations.ends_at', '>', $reservation->starts_at)
            ->whereIn('reservation_tables.table_id', $tables->pluck('id'))->exists();
        if ($taken) {
            throw new OrderException('Salah satu meja itu sudah dipesan di jam yang sama.');
        }
        $reservation->tables()->sync($tables->pluck('id')->all());
        $reservation->update(['area_id' => $tables->first()->area_id, 'tight' => $tables->sum('capacity') < $reservation->pax]);
        Audit::log('reservation.retabled', $reservation, ['tables' => $tables->pluck('name')->all()], $by);
    }
}
