<?php

namespace App\Domain\Printing;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The print bridge is a small program on an always-on device in the cafe (mini PC, Raspberry Pi, old laptop).
 * Tablets and iPads cannot open a raw connection to a LAN printer, and the server on a VPS cannot reach the cafe's
 * network, so the bridge pulls the queued jobs of network printers and sends them to the printers by IP.
 */
class PrintBridge
{
    private const SETTING = 'print.bridge_token_hash';

    private const SEEN = 'print.bridge.seen_at';

    /** A bridge that has not asked for jobs for this long is treated as offline. */
    public const ONLINE_SECONDS = 30;

    public function __construct(private SettingsRepository $settings) {}

    /** Makes a new token (the old one stops working) and returns it; only its hash is kept. */
    public function issue(): string
    {
        $token = 'pb_'.Str::random(40);
        $this->settings->set(self::SETTING, hash('sha256', $token));

        return $token;
    }

    public function accepts(string $token): bool
    {
        $hash = $this->settings->get(self::SETTING);

        return $token !== '' && is_string($hash) && $hash !== '' && hash_equals($hash, hash('sha256', $token));
    }

    public function hasToken(): bool
    {
        return (bool) $this->settings->get(self::SETTING);
    }

    public function seen(): void
    {
        Cache::put(self::SEEN, now()->toIso8601String(), 86400 * 30);
    }

    public function lastSeen(): ?Carbon
    {
        $at = Cache::get(self::SEEN);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    public function online(): bool
    {
        $at = $this->lastSeen();

        return $at !== null && $at->diffInSeconds(now(), true) <= self::ONLINE_SECONDS;
    }
}
