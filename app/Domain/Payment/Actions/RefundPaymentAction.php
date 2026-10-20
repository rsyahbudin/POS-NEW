<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Accounting\Actions\PostJournalAction;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethodType;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Shift\ShiftResolver;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Refund after payment: manager/owner only. The original order is untouched;
 * a reversing journal (proportional to the original) is posted.
 */
class RefundPaymentAction
{
    public function __construct(private PostJournalAction $journal, private ShiftResolver $shifts) {}

    public function handle(Payment $payment, User $actor, int $amount, string $reason, string $idempotencyKey): Refund
    {
        if (! $actor->can('refund')) {
            throw new OrderException('Hanya manager atau owner yang boleh refund.');
        }
        if (trim($reason) === '') {
            throw new OrderException('Alasan refund wajib diisi.');
        }
        if ($amount <= 0) {
            throw new OrderException('Nominal refund harus lebih dari 0.');
        }

        return DB::transaction(function () use ($payment, $actor, $amount, $reason, $idempotencyKey) {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $payment = Payment::query()->lockForUpdate()->with('method')->findOrFail($payment->id);
            if ($amount > $payment->amount - $payment->refunded_total) {
                throw new OrderException('Nominal refund melebihi sisa pembayaran.');
            }

            $isCash = $payment->method->type === PaymentMethodType::Cash;
            $shift = $this->shifts->forUser($actor);
            if ($isCash && ! $shift) {
                throw new OrderException('Buka shift dulu untuk refund tunai (uang keluar dari laci).');
            }

            $refund = Refund::create([
                'payment_id' => $payment->id, 'order_id' => $order->id, 'shift_id' => $isCash ? $shift->id : null,
                'amount' => $amount, 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'approved_by' => $actor->id,
            ]);

            $this->postReversal($payment, $order, $refund, $amount, $actor);

            $payment->update(['refunded_total' => $payment->refunded_total + $amount]);
            $order->refunded_total += $amount;
            $order->update([
                'refunded_total' => $order->refunded_total,
                'status' => $order->refunded_total >= $order->paid_total ? OrderStatus::Refunded : $order->status,
            ]);

            Audit::log('payment.refunded', $refund, [
                'order' => $order->number, 'amount' => $amount, 'reason' => $reason,
            ], $actor);

            return $refund;
        });
    }

    private function postReversal(Payment $payment, Order $order, Refund $refund, int $amount, User $actor): void
    {
        $bill = Bill::query()->findOrFail($payment->bill_id);
        $original = JournalEntry::query()
            ->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->whereNull('reverses')
            ->with('lines')->firstOrFail();

        $paymentAccounts = Payment::query()->where('bill_id', $bill->id)->pluck('account_id')->all();
        $component = $original->lines->reject(fn ($l) => $l->debit > 0 && in_array($l->account_id, $paymentAccounts, true));

        // Scale every non-cash line by refund / bill total; cash line absorbs the net so the entry balances.
        $scaled = [];
        $net = 0; // credit-positive
        foreach ($component as $l) {
            $value = (int) round(($l->credit - $l->debit) * $amount / $bill->grand_total);
            $scaled[$l->account_id] = ($scaled[$l->account_id] ?? 0) + $value;
            $net += $value;
        }
        $diff = $amount - $net;
        if ($diff !== 0) {
            $revenueLine = $component->sortByDesc('credit')->first() ?? throw new OrderException('Jurnal asli tidak punya baris pendapatan.');
            $scaled[$revenueLine->account_id] += $diff;
        }

        $lines = [['account_id' => $payment->account_id, 'credit' => $amount]];
        foreach ($scaled as $accountId => $creditPositive) {
            // Reverse: an original credit becomes a debit.
            $lines[] = $creditPositive >= 0
                ? ['account_id' => $accountId, 'debit' => $creditPositive]
                : ['account_id' => $accountId, 'credit' => -$creditPositive];
        }

        $this->journal->handle(BusinessDate::for(), "Refund {$order->number}", $lines, $refund, $actor->id, false, $order->outlet_id);
    }
}
