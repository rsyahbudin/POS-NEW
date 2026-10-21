<?php

namespace App\Domain\Ordering\Actions;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\DailyCounter;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\OrderType;
use App\Domain\Ordering\Enums\RoundSource;
use App\Domain\Ordering\Enums\RoundStatus;
use App\Domain\Ordering\Events\QrOrderSubmitted;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\ItemBuilder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Settings\SettingsRepository;
use App\Support\Audit;
use App\Support\BusinessDate;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Guest self-order. Lands as a pending round; nothing reaches the kitchen until
 * a cashier confirms it. Prices are rebuilt on the server.
 */
class SubmitQrOrderAction
{
    public function __construct(
        private ItemBuilder $items,
        private DailyCounter $counter,
        private SettingsRepository $settings,
        private ConfirmRoundAction $confirm,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function handle(string $token, array $data): OrderRound
    {
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            throw new OrderException('Nama panggilan wajib diisi.');
        }

        $round = DB::transaction(function () use ($token, $data, $name) {
            $table = Table::query()->where('qr_token', $token)->lockForUpdate()->first();
            if (! $table || ! $table->qr_enabled) {
                throw new OrderException('QR meja ini tidak aktif.');
            }

            $window = (int) $this->settings->get('qr.window_minutes', 10);
            $max = (int) $this->settings->get('qr.max_submits_per_window', 5);
            $recent = OrderRound::query()
                ->where('source', RoundSource::Qr)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->whereHas('order', fn ($q) => $q->where('table_id', $table->id))
                ->count();
            if ($recent >= $max) {
                throw new OrderException('Terlalu banyak pesanan dari meja ini. Coba lagi beberapa menit lagi atau panggil staf.');
            }

            $rows = $this->items->build(is_array($data['items'] ?? null) ? array_values($data['items']) : [], $table->outlet_id);

            $order = Order::query()
                ->where('table_id', $table->id)
                ->whereIn('status', ['open', 'partially_paid'])
                ->lockForUpdate()->latest('id')->first();

            if (! $order) {
                $date = BusinessDate::for();
                $order = Order::create([
                    'outlet_id' => $table->outlet_id,
                    'number' => sprintf('ORD-%s-%04d', CarbonImmutable::parse($date)->format('ymd'), $this->counter->next($table->outlet_id, $date, 'order')),
                    'type' => OrderType::DineIn,
                    'table_id' => $table->id,
                    'status' => OrderStatus::Open,
                    'customer_name' => $name,
                    'business_date' => $date,
                ]);
            }

            $round = OrderRound::create([
                'order_id' => $order->id,
                'source' => RoundSource::Qr,
                'status' => RoundStatus::Pending,
                'customer_name' => $name,
                'last_alerted_at' => now(),
            ]);
            foreach ($rows as $row) {
                $round->items()->create($row + ['order_id' => $order->id, 'status' => 'pending']);
            }

            Audit::log('qr.submitted', $round, ['order' => $order->number, 'table' => $table->name, 'customer' => $name, 'items' => collect($rows)->map(fn ($r) => $r['qty'].'× '.$r['name_snapshot'])->implode(', ')], null);

            return $round;
        });

        // Optional: skip the cashier. If anything blocks it (e.g. portion stock ran out),
        // the round simply stays pending for the cashier to handle.
        $auto = false;
        if ((bool) $this->settings->get('qr.auto_confirm', false)) {
            try {
                $this->confirm->handle($round, null);
                $auto = true;
            } catch (OrderException) {
                $auto = false;
            }
        }

        $order = $round->order;
        Realtime::dispatch(new QrOrderSubmitted($order->outlet_id, $order->id, $round->id, $name, $order->table?->name, $auto));

        return $round->refresh();
    }
}
