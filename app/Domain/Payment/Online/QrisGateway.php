<?php

namespace App\Domain\Payment\Online;

use Carbon\CarbonImmutable;

/** A payment gateway that issues dynamic QRIS codes and tells us when they are paid. */
interface QrisGateway
{
    public function name(): string;

    /**
     * @return array{provider_ref: string, qr_string: string, expires_at: CarbonImmutable}
     */
    public function charge(string $orderId, int $amount, int $expiryMinutes): array;

    /**
     * Checks a webhook payload. Null when it is not genuine.
     *
     * @param  array<string, mixed>  $payload
     * @return array{order_id: string, status: string, amount: int}|null status: paid | pending | expired | failed
     */
    public function parseNotification(array $payload): ?array;

    /** Asks the gateway directly: paid | pending | expired | failed. */
    public function fetchStatus(string $orderId): string;
}
