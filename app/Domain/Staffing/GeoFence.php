<?php

namespace App\Domain\Staffing;

use App\Domain\Settings\Models\Outlet;
use App\Domain\Settings\SettingsRepository;

/**
 * Attendance counts only when the kiosk device is within a radius of the cafe (default 500 m). The device's own GPS says where
 * it is, so this keeps a clock-in from being made away from the cafe but is not proof against a device that fakes its location.
 */
class GeoFence
{
    public function __construct(private SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('attendance.geofence', false);
    }

    public function radius(): int
    {
        return max(50, (int) $this->settings->get('attendance.radius_m', 500));
    }

    /**
     * @return array{ok: true, distance: int|null}|array{ok: false, reason: 'geo_not_set'|'geo_missing'|'geo_weak'|'out_of_area', distance: int|null}
     */
    public function check(int $outletId, ?float $lat, ?float $lng, ?float $accuracy): array
    {
        if (! $this->enabled()) {
            return ['ok' => true, 'distance' => $lat !== null && $lng !== null ? $this->distanceFromOutlet($outletId, $lat, $lng) : null];
        }
        $outlet = Outlet::query()->find($outletId);
        if ($outlet === null || $outlet->latitude === null || $outlet->longitude === null) {
            return ['ok' => false, 'reason' => 'geo_not_set', 'distance' => null];
        }
        if ($lat === null || $lng === null || $accuracy === null) {
            return ['ok' => false, 'reason' => 'geo_missing', 'distance' => null];
        }
        $distance = (int) round(self::distance($lat, $lng, (float) $outlet->latitude, (float) $outlet->longitude));
        if ($accuracy > $this->radius()) {
            return ['ok' => false, 'reason' => 'geo_weak', 'distance' => $distance];
        }

        return $distance > $this->radius() ? ['ok' => false, 'reason' => 'out_of_area', 'distance' => $distance] : ['ok' => true, 'distance' => $distance];
    }

    private function distanceFromOutlet(int $outletId, float $lat, float $lng): ?int
    {
        $o = Outlet::query()->find($outletId);

        return $o === null || $o->latitude === null || $o->longitude === null ? null : (int) round(self::distance($lat, $lng, (float) $o->latitude, (float) $o->longitude));
    }

    /** Great-circle distance in metres (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
