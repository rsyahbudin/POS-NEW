<?php

namespace App\Support;

final class Str
{
    /** File-name friendly: lowercase, dashes, no punctuation. */
    public static function slugName(string $name): string
    {
        return \Illuminate\Support\Str::slug($name) ?: 'laporan';
    }
}
