<?php

namespace App\Domain\Payment\Online;

use Carbon\CarbonImmutable;

/** Trial gateway: no money moves. A manager marks a code as paid from the Pembayaran Online page. */
class FakeGateway implements QrisGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function charge(string $orderId, int $amount, int $expiryMinutes): array
    {
        return ['provider_ref' => 'fake-'.$orderId, 'qr_string' => "SIMULASI-QRIS|{$orderId}|{$amount}", 'expires_at' => CarbonImmutable::now()->addMinutes($expiryMinutes)];
    }

    public static function signature(string $orderId, string $status, int $amount): string
    {
        return hash_hmac('sha256', "{$orderId}|{$status}|{$amount}", (string) config('app.key'));
    }

    public function parseNotification(array $payload): ?array
    {
        $order = (string) ($payload['order_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);
        if (! hash_equals(self::signature($order, $status, $amount), (string) ($payload['signature'] ?? ''))) {
            return null;
        }

        return ['order_id' => $order, 'status' => $status, 'amount' => $amount];
    }

    public function fetchStatus(string $orderId): string
    {
        return 'pending';
    }
}
