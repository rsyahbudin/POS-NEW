<?php

namespace App\Support;

use App\Domain\Settings\Models\Outlet;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The cafe's own logo for places outside the app UI, such as the browser tab icon. */
final class Branding
{
    /** @return array{name: string, logo: string|null} the cafe's name and logo for the sign-in pages and other public screens */
    public static function profile(): array
    {
        try {
            $o = Outlet::query()->orderBy('id')->first(['name', 'logo_path']);

            return ['name' => $o?->name ?? (string) config('app.name'), 'logo' => $o?->logo_path ? self::icon() : null];
        } catch (Throwable) {
            return ['name' => (string) config('app.name'), 'logo' => null];
        }
    }

    /** URL of the outlet logo (with a cache-busting version), or null when none is uploaded. */
    public static function icon(): ?string
    {
        try {
            $path = Outlet::query()->orderBy('id')->value('logo_path');
            if (! $path) {
                return null;
            }
            $version = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : 0;

            return MediaUrl::public($path).'?v='.$version;
        } catch (Throwable) {
            return null; // never break a page over an icon
        }
    }
}
