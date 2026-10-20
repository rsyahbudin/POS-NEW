<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value settings stored in `settings` (JSONB) and cached.
 */
class SettingsRepository
{
    public const DEFAULTS = [
        'operations.business_day_start' => '04:00',
        'pricing.rounding' => 0, // 0 | 100 | 500
        'pricing.price_mode' => 'exclusive', // exclusive | inclusive (informational; per tax rule flag applies)
        'discount.cashier_limit_percent' => 10,
        'qr.confirm_alert_seconds' => 180,
        'qr.max_submits_per_window' => 5,
        'qr.window_minutes' => 10,
        'kitchen.warn_minutes' => 10,
        'kitchen.danger_minutes' => 20,
        'receipt.footer' => 'Terima kasih atas kunjungan Anda',
        'telegram.bot_token' => null,
        'telegram.chat_id' => null,
    ];

    private const CACHE_KEY = 'settings.all';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::query()->pluck('value', 'key')->map(fn ($v) => $v['v'] ?? null)->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function withDefaults(): array
    {
        return array_merge(self::DEFAULTS, $this->all());
    }
}
