<?php

namespace App\Domain\Printing;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Models\Bill;
use App\Domain\Printing\Events\PrintJobCreated;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Http\Presenters\ReceiptPresenter;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns receipts, bills and kitchen/bar tickets into print jobs for the printers set up in
 * Admin > Printer. A device running the print station page picks the jobs up and prints them.
 * With no printer configured for a kind nothing is queued and the cashier's own device prints, as before.
 */
class PrintDispatcher
{
    /** Runs after the surrounding transaction commits and never breaks the order or payment. */
    public static function later(Closure $fn): void
    {
        DB::afterCommit(function () use ($fn) {
            try {
                $fn(app(self::class));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** @return array{receipt: bool, bill: bool, ticket: bool} */
    public static function routing(int $outletId): array
    {
        return Cache::remember("print.routing.{$outletId}", 300, fn () => [
            'receipt' => Printer::query()->where('outlet_id', $outletId)->where('is_active', true)->where('kind', 'receipt')->exists(),
            'bill' => Printer::query()->where('outlet_id', $outletId)->where('is_active', true)->where('kind', 'bill')->exists(),
            'ticket' => Printer::query()->where('outlet_id', $outletId)->where('is_active', true)->where('kind', 'ticket')->exists(),
        ]);
    }

    public static function forgetRouting(int $outletId): void
    {
        Cache::forget("print.routing.{$outletId}");
    }

    public function receipt(Bill $bill, bool $copy = false): int
    {
        $payload = ReceiptPresenter::forBill($bill, $copy);

        return $this->queue($bill->order->outlet_id, 'receipt', $bill->order->number, $payload, $this->printers($bill->order->outlet_id, 'receipt'));
    }

    /** Bill for the guest (pre-bill), usually printed by the waiter's printer. */
    public function bill(Order $order): int
    {
        $count = 0;
        $bills = $order->bills()->where('status', BillStatus::Unpaid->value)->where('is_unallocated', false)->get();
        foreach ($bills as $bill) {
            $payload = ReceiptPresenter::forBill($bill) + ['title' => 'TAGIHAN'];
            $count += $this->queue($order->outlet_id, 'bill', $order->number, $payload, $this->printers($order->outlet_id, 'bill'));
        }

        return $count;
    }

    /** One ticket per station that has items in the round. */
    public function tickets(OrderRound $round): int
    {
        $round->loadMissing('order.table', 'items');
        $order = $round->order;
        $items = $round->items->filter(fn ($i) => in_array($i->status->value, ['new', 'preparing'], true));
        $count = 0;

        foreach ($items->groupBy(fn ($i) => $i->station_id ?? 0) as $stationId => $group) {
            $printers = $this->printers($order->outlet_id, 'ticket')->filter(fn (Printer $p) => $p->station_id === null || $p->station_id === (int) $stationId);
            $payload = [
                'label' => $order->table->name ?? ($order->call_number ? ($order->type->value === 'dine_in' ? "Dine-in {$order->call_number}" : "Takeaway {$order->call_number}") : ($order->platform ? "Online {$order->platform}" : $order->number)),
                'number' => $order->number,
                'time' => now()->setTimezone('Asia/Jakarta')->format('H:i'),
                'items' => $group->map(fn ($i) => [
                    'qty' => $i->qty, 'name' => $i->name_snapshot, 'note' => $i->note,
                    'modifiers' => collect($i->modifiers_snapshot ?? [])->map(fn ($m) => (string) $m['name'])->all(),
                ])->values()->all(),
            ];
            $count += $this->queue($order->outlet_id, 'ticket', $order->number, $payload, $printers);
        }

        return $count;
    }

    /** @return Collection<int, Printer> */
    private function printers(int $outletId, string $kind): Collection
    {
        return Printer::query()->where('outlet_id', $outletId)->where('is_active', true)->where('kind', $kind)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, Printer>  $printers
     */
    private function queue(int $outletId, string $kind, string $reference, array $payload, Collection $printers): int
    {
        foreach ($printers as $printer) {
            PrintJob::create([
                'outlet_id' => $outletId, 'printer_id' => $printer->id, 'kind' => $kind, 'reference' => $reference,
                'payload' => $payload + ['copies' => $printer->copies, 'business_date' => BusinessDate::for()],
            ]);
            try {
                event(new PrintJobCreated($outletId, $printer->id));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $printers->count();
    }
}
