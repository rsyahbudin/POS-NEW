<?php

namespace App\Support;

use App\Domain\Settings\Models\Outlet;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The cafe's own logo for places outside the app UI, such as the browser tab icon. */
final class Branding
{
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
