<?php

namespace App\Domain\Payment\Online;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Midtrans Core API: dynamic QRIS (acquirer GoPay, payable from any QRIS wallet or mobile banking). */
class MidtransGateway implements QrisGateway
{
    public function __construct(private string $serverKey, private bool $production) {}

    public function name(): string
    {
        return 'midtrans';
    }

    private function base(): string
    {
        return $this->production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    public function charge(string $orderId, int $amount, int $expiryMinutes): array
    {
        $res = Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(15)->post($this->base().'/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
            'qris' => ['acquirer' => 'gopay'],
            'custom_expiry' => ['expiry_duration' => $expiryMinutes, 'unit' => 'minute'],
        ]);
        $body = $res->json();
        $code = (string) ($body['status_code'] ?? '');
        if (! is_array($body) || ! in_array($code, ['200', '201'], true) || empty($body['qr_string'])) {
            throw new RuntimeException('Midtrans menolak: '.(is_array($body) ? (string) ($body['status_message'] ?? 'tanpa pesan') : 'respons tidak terbaca'));
        }
        $expires = isset($body['expiry_time']) ? CarbonImmutable::parse((string) $body['expiry_time'], 'Asia/Jakarta') : CarbonImmutable::now()->addMinutes($expiryMinutes);

        return ['provider_ref' => (string) ($body['transaction_id'] ?? $orderId), 'qr_string' => (string) $body['qr_string'], 'expires_at' => $expires];
    }

    public function parseNotification(array $payload): ?array
    {
        $order = (string) ($payload['order_id'] ?? '');
        $code = (string) ($payload['status_code'] ?? '');
        $gross = (string) ($payload['gross_amount'] ?? '');
        $expected = hash('sha512', $order.$code.$gross.$this->serverKey);
        if ($order === '' || ! hash_equals($expected, (string) ($payload['signature_key'] ?? ''))) {
            return null;
        }

        return ['order_id' => $order, 'status' => $this->map((string) ($payload['transaction_status'] ?? '')), 'amount' => (int) round((float) $gross)];
    }

    public function fetchStatus(string $orderId): string
    {
        $body = Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(15)->get($this->base()."/v2/{$orderId}/status")->json();

        return $this->map(is_array($body) ? (string) ($body['transaction_status'] ?? '') : '');
    }

    private function map(string $status): string
    {
        return match ($status) {
            'settlement', 'capture' => 'paid',
            'expire' => 'expired',
            'cancel', 'deny', 'failure' => 'failed',
            default => 'pending',
        };
    }
}
