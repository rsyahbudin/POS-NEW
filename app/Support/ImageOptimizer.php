<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Product photos: converted to WebP, max 800 px, <= 120 KB (PRD 6.2). Wider images such as the QR banner pass their own limits. */
class ImageOptimizer
{
    public function store(UploadedFile $file, string $dir = 'products', int $maxSide = 800, int $maxBytes = 120 * 1024): string
    {
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw new RuntimeException('File gambar tidak valid.');
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, $maxSide / max($w, $h));
        $img = $scale < 1 ? imagescale($src, (int) round($w * $scale), (int) round($h * $scale)) : $src;
        if ($img === false) {
            throw new RuntimeException('Gambar tidak bisa diperkecil.');
        }
        imagepalettetotruecolor($img);

        $data = '';
        foreach ([82, 72, 62, 52, 42, 32] as $quality) {
            ob_start();
            imagewebp($img, null, $quality);
            $data = (string) ob_get_clean();
            if (strlen($data) <= $maxBytes) {
                break;
            }
        }

        $path = $dir.'/'.bin2hex(random_bytes(8)).'.webp';
        Storage::disk('public')->put($path, $data);

        return $path;
    }
}
