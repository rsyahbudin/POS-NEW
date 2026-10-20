<?php

namespace App\Support;

/**
 * Uploaded files live on the `public` disk (symlinked at public/storage).
 * Relative URLs keep working whatever host/port the app is served from.
 */
final class MediaUrl
{
    public static function public(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}
