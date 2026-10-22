<?php

namespace App\Domain\Payment\Online;

use App\Domain\Notification\Notifier;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Enums\BillStatus;
use App\Domain\Payment\Enums\PaymentMethodType;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\OnlinePayment;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shift\Models\Shift;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pay-from-phone QRIS. A guest asks for a code for the table's bill; the gateway tells us when it is paid
 * (webhook, with polling as a safety net) and the money is booked as a normal QRIS payment into the open shift,
 * received by the system user "Pembayaran Online". If it cannot be booked (no open shift, bill changed) the
 * charge stays "paid, not applied" and managers are alerted.
 */
class OnlinePaymentService
{
    public function __construct(private SettingsRepository $settings, private RecordPaymentAction $record) {}

    public function enabled(): bool
    {
        if (! (bool) $this->settings->get('qris.enabled', false)) {
            return false;
        }

        try {
            $this->gateway();

            return true;
        } catch (OrderException) {
            return false;
        }
    }

    public function gateway(?string $name = null): QrisGateway
    {
        $name ??= (string) $this->settings->get('qris.provider', 'midtrans');
        if ($name === 'fake') {
            return new FakeGateway;
        }
        $key = (string) $this->settings->get('qris.midtrans_server_key', '');
        if ($name !== 'midtrans' || $key === '') {
            throw new OrderException('QRIS online belum disiapkan di Pengaturan.');
        }

        return new MidtransGateway($key, (bool) $this->settings->get('qris.production', false));
    }

    /** The code a guest scans for this table's order (an unexpired one is reused). */
    public function start(Order $order): OnlinePayment
    {
        $gateway = $this->gateway();
        $minutes = max(3, (int) $this->settings->get('qris.expiry_minutes', 15));

        $op = DB::transaction(function () use ($order, $gateway) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isEditable()) {
                throw new OrderException('Pesanan ini sudah selesai.');
            }
            if ($order->rounds()->where('status', 'pending')->exists()) {
                throw new OrderException('Pesanan Anda belum dikonfirmasi kasir. Tunggu sebentar.');
            }
            $bills = $order->bills()->where('status', BillStatus::Unpaid->value)->get();
            $bill = $bills->first();
            if ($bills->count() !== 1 || $bill === null || $bill->is_unallocated || $bill->remaining() <= 0) {
                throw new OrderException('Tagihan meja ini dipisah atau belum siap. Silakan bayar di kasir.');
            }
            $reuse = OnlinePayment::query()->where('bill_id', $bill->id)->where('status', 'pending')->where('amount', $bill->remaining())
                ->where('expires_at', '>', now()->addMinute())->whereNotNull('qr_string')->latest('id')->first();
            if ($reuse !== null) {
                return $reuse;
            }

            return OnlinePayment::create([
                'public_id' => strtolower((string) Str::ulid()), 'outlet_id' => $order->outlet_id, 'order_id' => $order->id, 'bill_id' => $bill->id,
                'provider' => $gateway->name(), 'amount' => $bill->remaining(), 'status' => 'pending',
            ]);
        });
        if ($op->qr_string !== null) {
            return $op;
        }

        try {
            $made = $gateway->charge($op->gatewayOrderId(), $op->amount, $minutes);
        } catch (Throwable $e) {
            report($e);
            $op->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 240)]);
            throw new OrderException('QRIS belum bisa dibuat saat ini. Silakan bayar di kasir.');
        }
        $op->update(['provider_ref' => $made['provider_ref'], 'qr_string' => $made['qr_string'], 'expires_at' => $made['expires_at']]);

        return $op;
    }

    /**
     * Webhook entry. False when the payload is not genuine.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleNotification(string $provider, array $payload): bool
    {
        try {
            $gateway = $this->gateway($provider);
        } catch (OrderException) {
            return false;
        }
        $note = $gateway->parseNotification($payload);
        if ($note === null) {
            return false;
        }
        $op = str_starts_with($note['order_id'], 'pos-') ? OnlinePayment::query()->where('public_id', substr($note['order_id'], 4))->first() : null;
        if ($op === null) {
            return true; // not ours (or test ping): acknowledge so the gateway stops retrying
        }
        $this->record($op, $note['status'], $note['amount']);

        return true;
    }

    /** Polling safety net used while a guest waits: asks the gateway at most every few seconds. */
    public function refresh(OnlinePayment $op): OnlinePayment
    {
        if ($op->status === 'pending' && $op->provider !== 'fake' && Cache::add("online-poll:{$op->id}", 1, 5)) {
            try {
                $this->record($op, $this->gateway($op->provider)->fetchStatus($op->gatewayOrderId()), $op->amount);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $op->refresh();
    }

    /** Fake gateway only: the manager plays the part of the customer. */
    public function simulatePaid(OnlinePayment $op): void
    {
        $this->record($op, 'paid', $op->amount);
    }

    private function record(OnlinePayment $op, string $status, int $amount): void
    {
        $op = OnlinePayment::query()->findOrFail($op->id);
        if ($op->status === 'paid') {
            $this->apply($op);

            return;
        }
        if ($status === 'paid') {
            if ($amount !== $op->amount) {
                $op->update(['status' => 'failed', 'error' => "Nominal dari gateway ({$amount}) berbeda dari tagihan ({$op->amount}). Cek di dashboard gateway."]);
                $this->alert($op, 'Nominal QRIS tidak cocok');

                return;
            }
            $op->update(['status' => 'paid', 'paid_at' => now()]);
            $this->apply($op);
        } elseif (in_array($status, ['expired', 'failed'], true) && $op->status === 'pending') {
            $op->update(['status' => $status]);
        }
    }

    /** Books a paid charge as a payment. Safe to call again until it succeeds. */
    public function apply(OnlinePayment $op): void
    {
        if ($op->status !== 'paid' || $op->applied_at !== null) {
            return;
        }
        try {
            DB::transaction(function () use ($op) {
                $op = OnlinePayment::query()->lockForUpdate()->findOrFail($op->id);
                if ($op->applied_at !== null) {
                    return;
                }
                $bill = Bill::query()->findOrFail($op->bill_id);
                if ($bill->status === BillStatus::Paid || $bill->remaining() < $op->amount) {
                    throw new OrderException('Tagihan sudah lunas atau berubah setelah pelanggan membayar. Perlu pengembalian dana lewat gateway.');
                }
                $shift = Shift::query()->where('outlet_id', $op->outlet_id)->whereNull('closed_at')->latest('opened_at')->first()
                    ?? throw new OrderException('Tidak ada shift terbuka untuk mencatat pembayaran. Buka shift, lalu terapkan ulang.');
                $method = PaymentMethod::query()->where('outlet_id', $op->outlet_id)->where('type', PaymentMethodType::Qris->value)->where('is_active', true)->orderBy('sort')->first()
                    ?? throw new OrderException('Tidak ada metode bayar bertipe QRIS yang aktif.');

                $payment = $this->record->handle($bill, OnlineActor::user(), $method->id, $op->amount, "online-{$op->id}", $op->provider_ref, $shift);
                $op->update(['payment_id' => $payment->id, 'applied_at' => now(), 'error' => null]);
                Audit::log('payment.online', $op, ['order' => $op->order->number, 'amount' => $op->amount, 'provider' => $op->provider], OnlineActor::user());
            });
            Notifier::later(fn (Notifier $n) => $n->resolve($op->outlet_id, "online:{$op->id}"));
        } catch (OrderException $e) {
            $op->update(['error' => mb_substr($e->getMessage(), 0, 240)]);
            $this->alert($op, 'QRIS sudah dibayar tetapi belum tercatat');
        }
    }

    private function alert(OnlinePayment $op, string $title): void
    {
        Notifier::later(fn (Notifier $n) => $n->notify(
            $op->outlet_id, 'online.unapplied', $title, 'Rp '.number_format($op->amount, 0, ',', '.').' · '.($op->error ?? ''),
            '/admin/online-payments', 'danger', ['manage-online-payments'], null, "online:{$op->id}",
        ));
    }

    /** Scheduled: expire stale codes, check the gateway for ones that may have been paid, retry unapplied ones. */
    public function sweep(): int
    {
        $n = 0;
        OnlinePayment::query()->where('status', 'pending')->where('created_at', '<', now()->subMinutes(2))->whereNotNull('qr_string')->limit(100)->get()->each(function (OnlinePayment $op) use (&$n) {
            if ($op->provider !== 'fake') {
                $this->refresh($op);
            }
            $op->refresh();
            if ($op->status === 'pending' && $op->expires_at !== null && $op->expires_at->isPast()) {
                $op->update(['status' => 'expired']);
            }
            $n++;
        });
        OnlinePayment::query()->where('status', 'paid')->whereNull('applied_at')->limit(100)->get()->each(function (OnlinePayment $op) use (&$n) {
            $this->apply($op);
            $n++;
        });

        return $n;
    }
}
