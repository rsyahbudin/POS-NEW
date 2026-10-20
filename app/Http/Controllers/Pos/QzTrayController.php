<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Serves the QZ Tray certificate and signs print requests server-side so cashiers never see a permission dialog. */
class QzTrayController extends Controller
{
    public function certificate(): Response
    {
        $path = (string) config('pos.qz.certificate');
        abort_unless(is_file($path), 503, 'Sertifikat QZ Tray belum dibuat. Jalankan: php artisan pos:qz-keys');

        return response((string) file_get_contents($path), 200, ['Content-Type' => 'text/plain']);
    }

    public function sign(Request $request): JsonResponse|Response
    {
        $data = $request->validate(['request' => 'required|string|max:20000']);
        $path = (string) config('pos.qz.private_key');
        if (! is_file($path)) {
            return response()->json(['message' => 'Kunci QZ Tray belum dibuat.'], 503);
        }

        $key = openssl_pkey_get_private((string) file_get_contents($path));
        if ($key === false || ! openssl_sign($data['request'], $signature, $key, OPENSSL_ALGO_SHA512)) {
            return response()->json(['message' => 'Gagal menandatangani permintaan cetak.'], 500);
        }

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }
}
