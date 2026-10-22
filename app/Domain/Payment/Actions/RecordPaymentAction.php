<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Floor\Actions\SyncTableStatusAction;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderPaid;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\BillJournal;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Enums\PaymentMethodType;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Printing\PrintDispatcher;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftResolver;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment against a bill. Idempotent by key, row-locked, and the
 * journal is posted in the same transaction as the money it describes.
 */
class RecordPaymentAction
{
    public function __construct(
        private PostJournalAction $journal,
        private BillJournal $billJournal,
        private SyncTableStatusAction $syncTable,
        private ShiftResolver $shifts,
    ) {}

    /**
     * @param  int  $amount  amount handed over (cash: tendered, may exceed the bill) or exact amount (non-cash)
     */
    public function handle(Bill $bill, User $user, int $methodId, int $amount, string $idempotencyKey, ?string $reference = null, ?Shift $intoShift = null): Payment
    {
        if ($amount <= 0) {
            throw new OrderException('Nominal pembayaran harus lebih dari 0.');
        }

        return DB::transaction(function () use ($bill, $user, $methodId, $amount, $idempotencyKey, $reference, $intoShift) {
            $order = Order::query()->lockForUpdate()->findOrFail($bill->order_id);

            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->bill_id !== $bill->id) {
                    throw new OrderException('Kunci idempotensi sudah dipakai untuk tagihan lain.');
                }

                return $existing;
            }

            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $resolved = $intoShift ?? $this->shifts->forUser($user);
            $shift = ($resolved ? Shift::query()->lockForUpdate()->find($resolved->id) : null)
                ?? throw new OrderException('Buka shift dulu sebelum menerima pembayaran.');

            if (! $order->status->isEditable()) {
                throw new OrderException('Order sudah lunas atau dibatalkan.');
            }
            if ($bill->status !== BillStatus::Unpaid) {
                throw new OrderException('Tagihan ini sudah lunas.');
            }
            if ($order->rounds()->where('status', 'pending')->exists()) {
                throw new OrderException('Ada pesanan QR belum dikonfirmasi. Proses dulu sebelum membayar.');
            }
            if ($order->bills()->where('is_unallocated', true)->exists()) {
                throw new OrderException('Alokasikan item baru ke tagihan dulu.');
            }

            $method = PaymentMethod::query()->where('outlet_id', $order->outlet_id)->where('is_active', true)->find($methodId)
                ?? throw new OrderException('Metode pembayaran tidak valid.');

            $remaining = $bill->remaining();
            $isCash = $method->type === PaymentMethodType::Cash;
            if (! $isCash && $amount > $remaining) {
                throw new OrderException('Nominal melebihi sisa tagihan.');
            }
            $applied = min($amount, $remaining);

            $payment = Payment::create([
                'order_id' => $order->id,
                'bill_id' => $bill->id,
                'payment_method_id' => $method->id,
                'account_id' => $method->account_id,
                'amount' => $applied,
                'tendered' => $amount,
                'change' => $isCash ? $amount - $applied : 0,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'shift_id' => $shift->id,
                'received_by' => $user->id,
            ]);

            Audit::log('payment.received', $payment, ['order' => $order->number, 'bill' => $bill->label, 'method' => $method->name, 'amount' => $applied, 'tendered' => $amount, 'change' => max(0, $amount - $applied)], $user);
            $bill->paid_total += $applied;
            $fullyPaid = $bill->paid_total >= $bill->grand_total;
            $bill->update([
                'paid_total' => $bill->paid_total,
                'status' => $fullyPaid ? BillStatus::Paid : BillStatus::Unpaid,
                'paid_at' => $fullyPaid ? now() : null,
            ]);

            if ($fullyPaid) {
                $bill->load('payments', 'order');
                $this->journal->handle(
                    BusinessDate::for(), "Penjualan {$order->number}".($bill->label ? " ({$bill->label})" : ''),
                    $this->billJournal->lines($bill), $bill, $user->id, false, $order->outlet_id,
                );
            }

            $this->updateOrder($order, $shift);

            if ($fullyPaid) {
                PrintDispatcher::later(fn (PrintDispatcher $d) => $d->receipt($bill->fresh() ?? $bill));
            }

            return $payment;
        });
    }

    private function updateOrder(Order $order, Shift $shift): void
    {
        $paidTotal = (int) Payment::query()->where('order_id', $order->id)->sum('amount');
        $allPaid = ! $order->bills()->where('status', '!=', BillStatus::Paid->value)->exists();

        if ($allPaid) {
            $order->update([
                'paid_total' => $paidTotal, 'status' => $order->status->transitionTo(OrderStatus::Paid),
                'closed_at' => now(), 'shift_id' => $order->shift_id ?? $shift->id,
            ]);
            $this->syncTable->handle($order->table_id);
            Realtime::dispatch(new OrderPaid($order->outlet_id, $order->id, $order->table?->public_id));
        } else {
            $status = $order->status === OrderStatus::Open ? OrderStatus::PartiallyPaid : $order->status;
            $order->update(['paid_total' => $paidTotal, 'status' => $status, 'shift_id' => $order->shift_id ?? $shift->id]);
            $this->syncTable->handle($order->table_id);
        }
    }
}
